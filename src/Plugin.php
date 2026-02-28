<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\elements\conditions\assets\AssetCondition;
use craft\elements\db\AssetQuery;
use craft\events\DefineFieldLayoutElementsEvent;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\events\TemplateEvent;
use craft\fieldlayoutelements\assets\AltField;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\services\Assets;
use craft\services\Gc;
use craft\web\UrlManager;
use craft\web\View;
use Psr\Log\LogLevel;
use Throwable;
use vitordiniz22\craftlens\actions\FindDuplicatesAction;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\conditions\AiConfidenceConditionRule;
use vitordiniz22\craftlens\conditions\ContainsBrandLogoConditionRule;
use vitordiniz22\craftlens\conditions\ContainsPeopleConditionRule;
use vitordiniz22\craftlens\conditions\HasAiTagsConditionRule;
use vitordiniz22\craftlens\conditions\HasGpsCoordinatesConditionRule;
use vitordiniz22\craftlens\conditions\LensStatusConditionRule;
use vitordiniz22\craftlens\conditions\NsfwFlaggedConditionRule;
use vitordiniz22\craftlens\conditions\StockProviderConditionRule;
use vitordiniz22\craftlens\conditions\WatermarkFlaggedConditionRule;
use vitordiniz22\craftlens\conditions\WatermarkTypeConditionRule;
use vitordiniz22\craftlens\fieldlayoutelements\LensAnalysisElement;
use vitordiniz22\craftlens\jobs\RebuildSearchIndexJob;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\AiProviderService;
use vitordiniz22\craftlens\services\AnalysisEditService;
use vitordiniz22\craftlens\services\AssetAnalysisService;
use vitordiniz22\craftlens\services\BulkProcessingStatusService;
use vitordiniz22\craftlens\services\ColorAggregationService;
use vitordiniz22\craftlens\services\ContentStorageService;
use vitordiniz22\craftlens\services\DuplicateDetectionService;
use vitordiniz22\craftlens\services\ExifExtractionService;
use vitordiniz22\craftlens\services\ExifMetadataService;
use vitordiniz22\craftlens\services\LogService;
use vitordiniz22\craftlens\services\PricingService;
use vitordiniz22\craftlens\services\ReviewService;
use vitordiniz22\craftlens\services\SearchIndexService;
use vitordiniz22\craftlens\services\SearchService;
use vitordiniz22\craftlens\services\SetupStatusService;
use vitordiniz22\craftlens\services\SiteContentService;
use vitordiniz22\craftlens\services\StatisticsService;
use vitordiniz22\craftlens\services\TagAggregationService;
use vitordiniz22\craftlens\twig\LensTwigExtension;
use vitordiniz22\craftlens\web\assets\lens\LensAsset;
use vitordiniz22\craftlens\web\assets\lens\LensAssetActionsAsset;
use vitordiniz22\craftlens\web\assets\lens\LensBulkAsset;
use vitordiniz22\craftlens\web\assets\lens\LensLogsAsset;
use vitordiniz22\craftlens\web\assets\lens\LensReviewAsset;
use vitordiniz22\craftlens\web\assets\lens\LensSearchAsset;
use vitordiniz22\craftlens\web\assets\lens\LensSemanticSelectorAsset;
use yii\base\Event;
use yii\web\Response;

/**
 * Lens plugin - AI Asset Intelligence for Craft CMS
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read AiProviderService $aiProvider
 * @property-read AnalysisEditService $analysisEdit
 * @property-read AssetAnalysisService $assetAnalysis
 * @property-read ContentStorageService $contentStorage
 * @property-read PricingService $pricing
 * @property-read ReviewService $review
 * @property-read TagAggregationService $tagAggregation
 * @property-read ColorAggregationService $colorAggregation
 * @property-read StatisticsService $statistics
 * @property-read DuplicateDetectionService $duplicateDetection
 * @property-read SearchService $search
 * @property-read SetupStatusService $setupStatus
 * @property-read SiteContentService $siteContent
 * @property-read ExifExtractionService $exifExtraction
 * @property-read ExifMetadataService $exifMetadata
 * @property-read BulkProcessingStatusService $bulkProcessingStatus
 * @property-read LogService $log
 * @property-read SearchIndexService $searchIndex
 * @author Vitor Diniz <vitordiniz22@gmail.com>
 * @copyright Vitor Diniz
 * @license Proprietary
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function isDevInstall(): bool
    {
        $basePath = self::getInstance()->getBasePath();
        return str_contains($basePath, DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR);
    }

    public static function config(): array
    {
        return [
            'components' => [
                'aiProvider' => AiProviderService::class,
                'analysisEdit' => AnalysisEditService::class,
                'assetAnalysis' => AssetAnalysisService::class,
                'contentStorage' => ContentStorageService::class,
                'bulkProcessingStatus' => BulkProcessingStatusService::class,
                'pricing' => PricingService::class,
                'review' => ReviewService::class,
                'tagAggregation' => TagAggregationService::class,
                'colorAggregation' => ColorAggregationService::class,
                'statistics' => StatisticsService::class,
                'duplicateDetection' => DuplicateDetectionService::class,
                'search' => SearchService::class,
                'setupStatus' => SetupStatusService::class,
                'siteContent' => SiteContentService::class,
                'exifExtraction' => ExifExtractionService::class,
                'exifMetadata' => ExifMetadataService::class,
                'log' => LogService::class,
                'searchIndex' => SearchIndexService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'lens',
            'categories' => ['lens'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
        ]);

        // Register console controllers
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'vitordiniz22\\craftlens\\console\\controllers';
        }

        $this->attachEventHandlers();

        Craft::$app->onInit(function() {
            $this->registerCpRoutes();
            $this->registerTwigExtensions();
            $this->maybeQueueSearchIndexRebuild();
        });
    }

    /**
     * Auto-queue a background search index rebuild when the index table exists
     * but is empty and there are analyzed assets (e.g. after a fresh deploy to
     * an existing install or after re-installing the plugin).
     */
    private function maybeQueueSearchIndexRebuild(): void
    {
        try {
            if (!$this->searchIndex->isIndexPopulated()) {
                $hasAnalyzedAssets = AssetAnalysisRecord::find()
                    ->where(['NOT', ['processedAt' => null]])
                    ->exists();

                if ($hasAnalyzedAssets) {
                    Craft::$app->getQueue()->push(new RebuildSearchIndexJob());
                }
            }
        } catch (Throwable) {
            // Table may not exist yet (fresh install before migration runs); ignore silently
        }
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('lens', 'Lens');
        $isConfigured = $this->setupStatus->isAiProviderConfigured();

        $item['subnav'] = [
            'dashboard' => [
                'label' => Craft::t('lens', 'Dashboard'),
                'url' => 'lens/dashboard',
            ],
        ];

        if ($isConfigured) {
            $item['subnav']['search'] = [
                'label' => Craft::t('lens', 'Asset Browser'),
                'url' => 'lens/search',
            ];
            $item['subnav']['review'] = [
                'label' => Craft::t('lens', 'Review Queue'),
                'url' => 'lens/review',
            ];
            $item['subnav']['bulk'] = [
                'label' => Craft::t('lens', 'Bulk Processing'),
                'url' => 'lens/bulk',
            ];
        }

        $item['subnav']['settings'] = [
            'label' => Craft::t('lens', 'Settings'),
            'url' => 'lens/settings',
        ];

        if (self::isDevInstall()) {
            $item['subnav']['logs'] = [
                'label' => Craft::t('lens', 'Logs'),
                'url' => 'lens/logs',
            ];

            $errorCount = $this->log->getRecentErrorCount(24);
            if ($errorCount > 0) {
                $item['subnav']['logs']['badgeCount'] = $errorCount;
            }
        }

        if ($isConfigured) {
            $pendingCount = $this->review->getPendingReviewCount();

            if ($pendingCount > 0) {
                $item['badgeCount'] = $pendingCount;
                $item['subnav']['review']['badgeCount'] = $pendingCount;
            }
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    public function getSettingsResponse(): Response
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('lens/settings'));
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('lens/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        $this->registerAssetEventHandlers();
        $this->registerConditionRuleHandlers();
        $this->registerAssetQueryBehavior();
        $this->registerAssetSidebarHandler();
        $this->registerFieldLayoutElements();
        $this->registerElementActions();
        $this->registerSemanticSearch();
        $this->registerAssetBundle();
        $this->registerGarbageCollection();
    }

    private function registerGarbageCollection(): void
    {
        if (!self::isDevInstall()) {
            return;
        }

        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->log->cleanup(30);
            }
        );
    }

    private function registerAssetBundle(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if (!Craft::$app->getRequest()->getIsCpRequest() || !str_starts_with($event->template, 'lens/')) {
                    return;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(LensAsset::class);

                $template = $event->template;
                if (str_starts_with($template, 'lens/_review')) {
                    $view->registerAssetBundle(LensReviewAsset::class);
                    $view->registerAssetBundle(LensAssetActionsAsset::class);
                } elseif (str_starts_with($template, 'lens/_search')) {
                    $view->registerAssetBundle(LensSearchAsset::class);
                } elseif (str_starts_with($template, 'lens/_bulk')) {
                    $view->registerAssetBundle(LensBulkAsset::class);
                } elseif (str_starts_with($template, 'lens/_logs')) {
                    $view->registerAssetBundle(LensLogsAsset::class);
                }
            }
        );
    }

    private function registerAssetEventHandlers(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->propagating) {
                    return;
                }

                if ($this->assetAnalysis->shouldAutoProcessOnUpload($asset, $event->isNew)) {
                    $this->assetAnalysis->queueAsset($asset);
                    return;
                }

                if (!$event->isNew) {
                    $record = $this->assetAnalysis->getAnalysis($asset->id);
                    if ($record !== null) {
                        try {
                            $this->searchIndex->reindexField($record, 'title');
                            $this->searchIndex->reindexField($record, 'alt');
                        } catch (Throwable $e) {
                            Logger::warning(LogCategory::SearchIndex, 'Title/alt reindex failed on asset save: ' . $e->getMessage(), assetId: $asset->id);
                        }
                    }
                }
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                $this->assetAnalysis->deleteAnalysis($asset->id);
            }
        );

        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function(ReplaceAssetEvent $event) {
                /** @var Asset $asset */
                $asset = $event->asset;

                if (!$this->getSettings()->reprocessOnFileReplace) {
                    return;
                }

                $this->assetAnalysis->queueReprocessForFileChange($asset);
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_AFTER_RESTORE,
            function(Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (!$this->getSettings()->autoProcessOnUpload) {
                    return;
                }

                if ($this->assetAnalysis->shouldProcessForReplace($asset)) {
                    $existingAnalysis = $this->assetAnalysis->getAnalysis($asset->id);

                    if ($existingAnalysis === null) {
                        $this->assetAnalysis->queueAsset($asset);
                    }
                }
            }
        );
    }

    private function registerConditionRuleHandlers(): void
    {
        Event::on(
            AssetCondition::class,
            AssetCondition::EVENT_REGISTER_CONDITION_RULES,
            function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = HasAiTagsConditionRule::class;
                $event->conditionRules[] = ContainsPeopleConditionRule::class;
                $event->conditionRules[] = AiConfidenceConditionRule::class;
                $event->conditionRules[] = LensStatusConditionRule::class;
                $event->conditionRules[] = NsfwFlaggedConditionRule::class;
                // Watermark & Brand Detection condition rules
                $event->conditionRules[] = WatermarkFlaggedConditionRule::class;
                $event->conditionRules[] = WatermarkTypeConditionRule::class;
                $event->conditionRules[] = StockProviderConditionRule::class;
                $event->conditionRules[] = ContainsBrandLogoConditionRule::class;

                // EXIF/GPS condition rules
                $event->conditionRules[] = HasGpsCoordinatesConditionRule::class;
            }
        );
    }

    private function registerAssetQueryBehavior(): void
    {
        Event::on(
            AssetQuery::class,
            AssetQuery::EVENT_DEFINE_BEHAVIORS,
            function(Event $event) {
                $event->sender->attachBehaviors([
                    'lens' => AssetQueryBehavior::class,
                ]);
            }
        );
    }

    private function registerAssetSidebarHandler(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_METADATA,
            function(DefineMetadataEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->kind !== Asset::KIND_IMAGE) {
                    return;
                }

                $currentUser = Craft::$app->getUser()->getIdentity();

                if ($currentUser === null || !$currentUser->can('accessPlugin-lens')) {
                    return;
                }

                if ($this->hasFieldLayoutElement($asset)) {
                    return;
                }

                Craft::$app->getView()->registerAssetBundle(LensAssetActionsAsset::class);

                $html = Craft::$app->view->renderTemplate(
                    'lens/_components/asset-sidebar.twig',
                    [
                        'asset' => $asset,
                        'settings' => $this->getSettings(),
                    ]
                );

                $event->metadata[Craft::t('lens', 'Lens AI')] = $html;
            }
        );
    }

    private function hasFieldLayoutElement(Asset $asset): bool
    {
        return $this->fieldLayoutContains($asset, LensAnalysisElement::class);
    }

    public function hasAltFieldInLayout(Asset $asset): bool
    {
        return $this->fieldLayoutContains($asset, AltField::class);
    }

    private function fieldLayoutContains(Asset $asset, string $className): bool
    {
        $fieldLayout = $asset->getFieldLayout();

        if ($fieldLayout === null) {
            return false;
        }

        foreach ($fieldLayout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if ($element instanceof $className) {
                    return true;
                }
            }
        }

        return false;
    }

    private function registerFieldLayoutElements(): void
    {
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_UI_ELEMENTS,
            function(DefineFieldLayoutElementsEvent $event) {
                if ($event->sender->type !== Asset::class) {
                    return;
                }

                $event->elements[] = LensAnalysisElement::class;
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['lens'] = 'lens/dashboard/index';
                $event->rules['lens/dashboard'] = 'lens/dashboard/index';
                $event->rules['lens/review'] = 'lens/review/index';
                $event->rules['lens/review/focus'] = 'lens/review/focus';
                $event->rules['lens/review/bulk'] = 'lens/review/bulk';
                $event->rules['lens/review/<analysisId:\d+>'] = 'lens/review/view';
                $event->rules['lens/review/approve'] = 'lens/review/approve';
                $event->rules['lens/review/reject'] = 'lens/review/reject';
                $event->rules['lens/review/bulk-approve'] = 'lens/review/bulk-approve';
                $event->rules['lens/review/bulk-reject'] = 'lens/review/bulk-reject';
                $event->rules['lens/review/skip'] = 'lens/review/skip';
                $event->rules['lens/analysis/reprocess'] = 'lens/analysis/reprocess';
                $event->rules['lens/analysis/update-field'] = 'lens/analysis/update-field';
                $event->rules['lens/analysis/revert-field'] = 'lens/analysis/revert-field';
                $event->rules['lens/analysis/update-tags'] = 'lens/analysis/update-tags';
                $event->rules['lens/analysis/update-colors'] = 'lens/analysis/update-colors';
                $event->rules['lens/analysis/tag-suggestions'] = 'lens/analysis/tag-suggestions';
                $event->rules['lens/analysis/get-status'] = 'lens/analysis/get-status';
                $event->rules['lens/analysis/apply-title'] = 'lens/analysis/apply-title';
                $event->rules['lens/analysis/apply-focal-point'] = 'lens/analysis/apply-focal-point';
                $event->rules['lens/bulk'] = 'lens/bulk/index';
                $event->rules['lens/bulk/process'] = 'lens/bulk/process';
                $event->rules['lens/bulk/status'] = 'lens/bulk/status';
                $event->rules['lens/bulk/retry-failed'] = 'lens/bulk/retry-failed';
                $event->rules['lens/bulk/cancel'] = 'lens/bulk/cancel';
                $event->rules['lens/bulk/progress'] = 'lens/bulk/progress';
                $event->rules['lens/bulk/dismiss'] = 'lens/bulk/dismiss';
                $event->rules['lens/semantic-search/search'] = 'lens/semantic-search/search';
                $event->rules['lens/search'] = 'lens/search/index';
                $event->rules['lens/search/resolve-duplicate'] = 'lens/search/resolve-duplicate';
                $event->rules['lens/search/export'] = 'lens/search/export';
                $event->rules['lens/logs'] = 'lens/log/index';
                $event->rules['lens/logs/retry'] = 'lens/log/retry';
                $event->rules['lens/logs/delete-all'] = 'lens/log/delete-all';
                $event->rules['lens/settings'] = 'lens/settings/index';
                $event->rules['lens/settings/save'] = 'lens/settings/save';
            }
        );
    }

    private function registerTwigExtensions(): void
    {
        Craft::$app->view->registerTwigExtension(new LensTwigExtension());
    }

    private function registerElementActions(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $event->actions[] = FindDuplicatesAction::class;
            }
        );
    }

    private function registerSemanticSearch(): void
    {
        if (!$this->getSettings()->enableSemanticSearch) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if (!Craft::$app->getRequest()->getIsCpRequest()) {
                    return;
                }

                Craft::$app->getView()->registerAssetBundle(LensSemanticSelectorAsset::class);
            }
        );
    }
}
