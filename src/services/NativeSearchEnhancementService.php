<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use craft\events\SearchEvent;
use craft\search\SearchQueryTerm;
use craft\search\SearchQueryTermGroup;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\base\Component;

/**
 * Bridges Lens's BM25 index into Craft's native search pipeline.
 *
 * Listens to `craft\services\Search::EVENT_AFTER_SEARCH` (wired up in
 * `Plugin::registerSemanticSearch()`) and, for Asset searches, merges Lens
 * BM25 matches into Craft's score map. The merge respects every filter
 * already applied to the element query (volume, source, kind, site,
 * condition hud, `AssetQueryBehavior` Lens filters) by running the Lens
 * IDs back through a clone of that query.
 *
 * Keeping the glue here rather than in `SearchIndexService` isolates the
 * Craft-event coupling from the BM25 mechanics, and gives `Plugin.php` a
 * one-line event registration.
 */
class NativeSearchEnhancementService extends Component
{
    /**
     * Minimum characters per term to be considered by BM25. Mirrors the
     * threshold used elsewhere in Lens's search stack.
     */
    private const MIN_TERM_LENGTH = 2;

    /**
     * Scale factor applied when normalizing BM25 scores into Craft's
     * roughly integer 0-100 scoring range.
     */
    private const SCORE_SCALE = 100;

    /**
     * `Search::EVENT_AFTER_SEARCH` handler. No-op for non-Asset element
     * types and for queries with no usable terms.
     */
    public function mergeScores(SearchEvent $event): void
    {
        if ($event->elementQuery->elementType !== Asset::class) {
            return;
        }

        $terms = $this->extractSearchTerms($event->query->getTokens());
        if (empty($terms)) {
            return;
        }

        try {
            $lensScores = Plugin::getInstance()->searchIndex->search($terms);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'Lens score merge failed: ' . $e->getMessage());
            return;
        }

        if (empty($lensScores)) {
            return;
        }

        // Enforce the element query's existing filters on the Lens side of
        // the merge. Any asset that wouldn't have passed Craft's native
        // search must not be added just because Lens matched it.
        $allowedIds = (clone $event->elementQuery)
            ->id(array_keys($lensScores))
            ->ids();

        if (empty($allowedIds)) {
            return;
        }

        $siteId = $this->resolveEventSiteId($event);
        $maxBm25 = max($lensScores) ?: 1.0;
        $scores = $event->scores ?? [];

        foreach ($allowedIds as $assetId) {
            $assetId = (int) $assetId;
            if (!isset($lensScores[$assetId])) {
                continue;
            }
            $key = "{$assetId}-{$siteId}";
            $normalized = (int) round(($lensScores[$assetId] / $maxBm25) * self::SCORE_SCALE);
            $scores[$key] = max($scores[$key] ?? 0, $normalized);
        }

        arsort($scores);
        $event->scores = $scores;
    }

    /**
     * Flatten a Craft `SearchQuery` token list into raw term strings the
     * Lens BM25 index can consume. Excluded terms (`-foo`) and terms
     * shorter than the minimum are dropped.
     *
     * @param array<SearchQueryTerm|SearchQueryTermGroup> $tokens
     * @return string[]
     */
    private function extractSearchTerms(array $tokens): array
    {
        $terms = [];

        foreach ($tokens as $token) {
            if ($token instanceof SearchQueryTermGroup) {
                foreach ($token->terms as $groupTerm) {
                    if ($this->isIncludableTerm($groupTerm)) {
                        $terms[] = $groupTerm->term;
                    }
                }
            } elseif ($token instanceof SearchQueryTerm && $this->isIncludableTerm($token)) {
                $terms[] = $token->term;
            }
        }

        return $terms;
    }

    private function isIncludableTerm(SearchQueryTerm $term): bool
    {
        return !$term->exclude
            && $term->term !== null
            && strlen($term->term) >= self::MIN_TERM_LENGTH;
    }

    private function resolveEventSiteId(SearchEvent $event): int
    {
        $rawSiteId = $event->elementQuery->siteId;
        if (is_array($rawSiteId)) {
            $rawSiteId = $rawSiteId[0] ?? null;
        }

        $siteId = (int) ($rawSiteId ?? 0);
        if ($siteId > 0 && Craft::$app->getSites()->getSiteById($siteId) !== null) {
            return $siteId;
        }

        return Craft::$app->getSites()->getPrimarySite()->id;
    }
}
