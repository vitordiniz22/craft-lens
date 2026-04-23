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
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineFieldLayoutElementsEvent;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementDefaultTableAttributesEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\events\TemplateEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\services\Assets;
use craft\services\Gc;
use craft\services\Search as SearchService_Craft;
use craft\web\UrlManager;
use craft\web\View;
use Psr\Log\LogLevel;
use vitordiniz22\craftlens\actions\AnalyzeAssetsAction;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use vitordiniz22\craftlens\conditions\ContainsBrandLogoConditionRule;
use vitordiniz22\craftlens\conditions\ContainsPeopleConditionRule;
use vitordiniz22\craftlens\conditions\FaceCountConditionRule;
use vitordiniz22\craftlens\conditions\FileSizeConditionRule;
use vitordiniz22\craftlens\conditions\FileTooLargeConditionRule;
use vitordiniz22\craftlens\conditions\HasDuplicatesConditionRule;
use vitordiniz22\craftlens\conditions\HasFocalPointConditionRule;
use vitordiniz22\craftlens\conditions\HasTextInImageConditionRule;
use vitordiniz22\craftlens\conditions\LensColorConditionRule;
use vitordiniz22\craftlens\conditions\LensStatusConditionRule;
use vitordiniz22\craftlens\conditions\LensTagsAllConditionRule;
use vitordiniz22\craftlens\conditions\LensTagsAnyConditionRule;
use vitordiniz22\craftlens\conditions\NsfwFlaggedConditionRule;
use vitordiniz22\craftlens\conditions\NsfwScoreConditionRule;
use vitordiniz22\craftlens\conditions\ProcessedDateConditionRule;
use vitordiniz22\craftlens\conditions\ProviderConditionRule;
use vitordiniz22\craftlens\conditions\ProviderModelConditionRule;
use vitordiniz22\craftlens\conditions\QualityIssueConditionRule;
use vitordiniz22\craftlens\conditions\SimilarToConditionRule;
use vitordiniz22\craftlens\conditions\StockProviderConditionRule;
use vitordiniz22\craftlens\conditions\UnprocessedConditionRule;
use vitordiniz22\craftlens\conditions\WatermarkFlaggedConditionRule;
use vitordiniz22\craftlens\conditions\WatermarkTypeConditionRule;
use vitordiniz22\craftlens\fieldlayoutelements\LensAnalysisElement;
use vitordiniz22\craftlens\helpers\AssetTableAttributes;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\helpers\FieldLayoutHelper;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\services\AiProviderService;
use vitordiniz22\craftlens\services\AnalysisCancellationService;
use vitordiniz22\craftlens\services\AnalysisEditService;
use vitordiniz22\craftlens\services\AssetAnalysisService;
use vitordiniz22\craftlens\services\BulkProcessingStatusService;
use vitordiniz22\craftlens\services\ColorAggregationService;
use vitordiniz22\craftlens\services\ContentStorageService;
use vitordiniz22\craftlens\services\DuplicateDetectionService;
use vitordiniz22\craftlens\services\LogService;
use vitordiniz22\craftlens\services\NativeSearchEnhancementService;
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
use vitordiniz22\craftlens\web\assets\lens\LensAssetIndexAsset;
use vitordiniz22\craftlens\web\assets\lens\LensBulkAsset;
use vitordiniz22\craftlens\web\assets\lens\LensLogsAsset;
use vitordiniz22\craftlens\web\assets\lens\LensReviewAsset;
use vitordiniz22\craftlens\web\assets\lens\LensSemanticSelectorAsset;
use vitordiniz22\craftlens\web\assets\lens\LensSettingsAsset;
use yii\base\Event;
use yii\web\ForbiddenHttpException;
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
 * @property-read NativeSearchEnhancementService $nativeSearchEnhancement
 * @property-read SetupStatusService $setupStatus
 * @property-read SiteContentService $siteContent
 * @property-read BulkProcessingStatusService $bulkProcessingStatus
 * @property-read LogService $log
 * @property-read SearchIndexService $searchIndex
 * @property-read bool $isPro
 * @property-read bool $isLite
 * @author Vitor Diniz <vitordiniz22@gmail.com>
 * @copyright Vitor Diniz
 * @license Proprietary
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public function getIsPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    public function getIsLite(): bool
    {
        return $this->is(self::EDITION_LITE);
    }

    public function requireProEdition(): void
    {
        if (!$this->getIsPro()) {
            throw new ForbiddenHttpException(
                Craft::t('lens', 'This feature requires Lens Pro.')
            );
        }
    }

    /**
     * Removes LensAnalysisElement from all asset volume field layouts
     * so they don't become orphaned ghost entries after the plugin is gone.
     * Updates both the DB config column (saveLayout) and project config YAML (saveVolume).
     */
    protected function beforeUninstall(): void
    {
        parent::beforeUninstall();

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $fieldLayout = $volume->getFieldLayout();
            $modified = false;

            foreach ($fieldLayout->getTabs() as $tab) {
                $elements = $tab->getElements();
                $filtered = array_filter($elements, fn($el) => !$el instanceof LensAnalysisElement);

                if (count($filtered) !== count($elements)) {
                    $tab->setElements(array_values($filtered));
                    $modified = true;
                }
            }

            if ($modified) {
                Craft::$app->getFields()->saveLayout($fieldLayout, false);
                Craft::$app->getVolumes()->saveVolume($volume, false);
            }
        }
    }

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
                'nativeSearchEnhancement' => NativeSearchEnhancementService::class,
                'setupStatus' => SetupStatusService::class,
                'siteContent' => SiteContentService::class,
                'log' => LogService::class,
                'searchIndex' => SearchIndexService::class,
                'analysisCancellation' => AnalysisCancellationService::class,
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
        });
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

        if ($this->getIsPro() && $isConfigured) {
            if ($this->getSettings()->requireReviewBeforeApply) {
                $item['subnav']['review'] = [
                    'label' => Craft::t('lens', 'Review Queue'),
                    'url' => 'lens/review',
                ];
            }
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

        if ($this->getIsPro() && $isConfigured && $this->getSettings()->requireReviewBeforeApply) {
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

    private function attachEventHandlers(): void
    {
        $this->registerAssetEventHandlers();
        $this->registerConditionRuleHandlers();
        $this->registerAssetQueryBehavior();
        $this->registerAssetSources();
        $this->registerAssetSidebarHandler();
        $this->registerAssetIndexExtensions();
        $this->registerFieldLayoutElements();
        $this->registerElementActions();
        $this->registerSemanticSearch();
        $this->registerAssetBundle();
        $this->registerGarbageCollection();
    }

    /**
     * Hook sort options, table columns, default source columns, and cell HTML
     * rendering into Craft's native asset index. All rendering lives in
     * AssetTableAttributes; this just wires the events.
     */
    private function registerAssetIndexExtensions(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_SORT_OPTIONS,
            function(RegisterElementSortOptionsEvent $event) {
                foreach (AssetTableAttributes::sortOptions() as $option) {
                    $event->sortOptions[] = $option;
                }
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                foreach (AssetTableAttributes::tableAttributes() as $key => $def) {
                    $event->tableAttributes[$key] = $def;
                }
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_DEFAULT_TABLE_ATTRIBUTES,
            function(RegisterElementDefaultTableAttributesEvent $event) {
                if (!str_starts_with($event->source, 'lens:')) {
                    return;
                }

                $columns = ['filename', 'location'];

                foreach (AssetTableAttributes::defaultAttributes($event->source) as $attr) {
                    if (!in_array($attr, $columns, true)) {
                        $columns[] = $attr;
                    }
                }

                $columns[] = 'link';

                $event->tableAttributes = $columns;
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                $html = AssetTableAttributes::attributeHtml($asset, $event->attribute);

                if ($html !== null) {
                    $event->html = $html;
                }
            }
        );
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
                if (!Craft::$app->getRequest()->getIsCpRequest()) {
                    return;
                }

                $view = Craft::$app->getView();
                $template = $event->template;

                if ($template === 'assets/_index') {
                    $view->registerAssetBundle(LensAssetIndexAsset::class);
                    return;
                }

                if (!str_starts_with($template, 'lens/')) {
                    return;
                }

                $view->registerAssetBundle(LensAsset::class);

                $featureBundles = match (true) {
                    str_starts_with($template, 'lens/_review') => [LensReviewAsset::class, LensAssetActionsAsset::class],
                    str_starts_with($template, 'lens/_bulk') => [LensBulkAsset::class],
                    str_starts_with($template, 'lens/_logs') => [LensLogsAsset::class],
                    str_starts_with($template, 'lens/_settings') => [LensSettingsAsset::class],
                    default => [],
                };

                foreach ($featureBundles as $bundle) {
                    $view->registerAssetBundle($bundle);
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

                if (!$this->getSettings()->autoProcessOnUpload) {
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
                $event->conditionRules[] = NsfwFlaggedConditionRule::class;
                $event->conditionRules[] = NsfwScoreConditionRule::class;
                $event->conditionRules[] = HasFocalPointConditionRule::class;
                $event->conditionRules[] = ContainsPeopleConditionRule::class;
                $event->conditionRules[] = FaceCountConditionRule::class;
                $event->conditionRules[] = WatermarkFlaggedConditionRule::class;
                $event->conditionRules[] = ContainsBrandLogoConditionRule::class;
                $event->conditionRules[] = WatermarkTypeConditionRule::class;
                $event->conditionRules[] = FileTooLargeConditionRule::class;
                $event->conditionRules[] = FileSizeConditionRule::class;
                $event->conditionRules[] = QualityIssueConditionRule::class;
                $event->conditionRules[] = ProcessedDateConditionRule::class;
                $event->conditionRules[] = UnprocessedConditionRule::class;
                $event->conditionRules[] = ProviderConditionRule::class;
                $event->conditionRules[] = ProviderModelConditionRule::class;

                if ($this->getIsPro()) {
                    $event->conditionRules[] = LensStatusConditionRule::class;
                    $event->conditionRules[] = StockProviderConditionRule::class;
                    $event->conditionRules[] = HasTextInImageConditionRule::class;
                    $event->conditionRules[] = LensTagsAnyConditionRule::class;
                    $event->conditionRules[] = LensTagsAllConditionRule::class;
                    $event->conditionRules[] = LensColorConditionRule::class;
                    $event->conditionRules[] = HasDuplicatesConditionRule::class;
                    $event->conditionRules[] = SimilarToConditionRule::class;
                }
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

    private function registerAssetSources(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_SOURCES,
            function(RegisterElementSourcesEvent $event) {
                $iconPath = $this->getBasePath() . '/icon-mask.svg';

                if ($event->context === 'index') {
                    $enabledVolumeIds = $this->getSettings()->getEnabledVolumeIds();

                    if (empty($enabledVolumeIds)) {
                        return;
                    }

                    $volumeScope = ['kind' => 'image', 'volumeId' => $enabledVolumeIds];

                    $sourceDefinitions = ['all' => ['All Images', $volumeScope]];

                    if ($this->getIsPro() && $this->getSettings()->requireReviewBeforeApply) {
                        $sourceDefinitions['needs-review'] = ['Needs Review', ['lensStatus' => 'pending_review'] + $volumeScope];
                    }

                    $sourceDefinitions += [
                        'not-analysed' => ['Not Analysed', ['lensStatus' => 'untagged'] + $volumeScope],
                        'failed' => ['Failed Analyses', ['lensStatus' => 'failed'] + $volumeScope],
                        'missing-alt-text' => ['Missing Alt Text', ['hasAlt' => false] + $volumeScope],
                        'nsfw-flagged' => ['NSFW Flagged', ['lensNsfwFlagged' => true] + $volumeScope],
                        'missing-focal-point' => ['Missing Focal Point', ['lensHasFocalPoint' => false] + $volumeScope],
                        'contains-people' => ['Contains People', ['lensContainsPeople' => true] + $volumeScope],
                        'has-watermark' => ['Has Watermark', ['lensHasWatermark' => true] + $volumeScope],
                        'has-brand-logo' => ['Has Brand Logo', ['lensContainsBrandLogo' => true] + $volumeScope],
                    ];

                    if ($this->getIsPro() && DuplicateSupport::isAvailable()) {
                        $sourceDefinitions['has-duplicates'] = ['Has Duplicates', ['lensHasDuplicates' => true] + $volumeScope];
                    }

                    foreach ($sourceDefinitions as $key => [$label, $criteria]) {
                        $event->sources[] = [
                            'key' => "lens:{$key}",
                            'label' => Craft::t('lens', $label),
                            'criteria' => $criteria,
                            'hasThumbs' => true,
                            'defaultSort' => ['dateCreated', 'desc'],
                            'iconMask' => $iconPath,
                        ];
                    }
                }
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

                if (!$this->getSettings()->isVolumeEnabled($asset->volumeId)) {
                    return;
                }

                if (FieldLayoutHelper::hasAnalysisElement($asset)) {
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
                $event->rules['lens/review/bulk'] = 'lens/review/bulk';
                $event->rules['lens/review/<analysisId:\d+>'] = 'lens/review/view';
                $event->rules['lens/review/approve'] = 'lens/review/approve';
                $event->rules['lens/review/reject'] = 'lens/review/reject';
                $event->rules['lens/review/bulk-approve'] = 'lens/review/bulk-approve';
                $event->rules['lens/review/bulk-reject'] = 'lens/review/bulk-reject';
                $event->rules['lens/analysis/reprocess'] = 'lens/analysis/reprocess';
                $event->rules['lens/analysis/cancel'] = 'lens/analysis/cancel';
                $event->rules['lens/analysis/update-field'] = 'lens/analysis/update-field';
                $event->rules['lens/analysis/revert-field'] = 'lens/analysis/revert-field';
                $event->rules['lens/analysis/update-tags'] = 'lens/analysis/update-tags';
                $event->rules['lens/analysis/update-colors'] = 'lens/analysis/update-colors';
                $event->rules['lens/analysis/tag-suggestions'] = 'lens/analysis/tag-suggestions';
                $event->rules['lens/analysis/get-status'] = 'lens/analysis/get-status';
                $event->rules['lens/analysis/apply-title'] = 'lens/analysis/apply-title';
                $event->rules['lens/analysis/apply-alt'] = 'lens/analysis/apply-alt';
                $event->rules['lens/analysis/apply-focal-point'] = 'lens/analysis/apply-focal-point';
                $event->rules['lens/bulk'] = 'lens/bulk/index';
                $event->rules['lens/bulk/process'] = 'lens/bulk/process';
                $event->rules['lens/bulk/retry-failed'] = 'lens/bulk/retry-failed';
                $event->rules['lens/bulk/cancel'] = 'lens/bulk/cancel';
                $event->rules['lens/bulk/progress'] = 'lens/bulk/progress';
                $event->rules['lens/bulk/dismiss'] = 'lens/bulk/dismiss';
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
                if (!$this->getIsPro()) {
                    return;
                }

                if (!$this->sourceIsLensEligible($event->source)) {
                    return;
                }

                $event->actions[] = AnalyzeAssetsAction::class;
            }
        );
    }

    private function sourceIsLensEligible(string $source): bool
    {
        if (str_starts_with($source, 'lens:')) {
            return true;
        }

        $volumeId = $this->resolveVolumeIdFromSource($source);

        if ($volumeId === null) {
            return true;
        }

        return $this->getSettings()->isVolumeEnabled($volumeId);
    }

    private function resolveVolumeIdFromSource(string $source): ?int
    {
        if (str_starts_with($source, 'volume:')) {
            $uid = substr($source, 7);
            $volume = Craft::$app->getVolumes()->getVolumeByUid($uid);
            return $volume?->id;
        }

        if (str_starts_with($source, 'folder:')) {
            $uid = substr($source, 7);
            $folder = Craft::$app->getAssets()->getFolderByUid($uid);
            return $folder?->volumeId;
        }

        return null;
    }

    private function registerSemanticSearch(): void
    {
        if (!$this->getIsPro() || !$this->getSettings()->enableSemanticSearch) {
            return;
        }

        Event::on(
            SearchService_Craft::class,
            SearchService_Craft::EVENT_AFTER_SEARCH,
            [$this->nativeSearchEnhancement, 'mergeScores'],
        );

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
