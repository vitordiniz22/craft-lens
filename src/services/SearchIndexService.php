<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MultisiteHelper;
use vitordiniz22\craftlens\helpers\Stemmer;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\records\AnalysisSiteContentRecord;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\base\Component;
use yii\db\Query;

/**
 * Search Index Service.
 *
 * Manages the `lens_search_index` table: a token-based full-text index with
 * pre-stemmed terms and BM25 relevance scoring. Tokens are stemmed using the
 * primary site's language (via wamania/php-stemmer) so that "animals" and
 * "animal" resolve to the same token.
 *
 * Indexes primary-site content and per-language translations from
 * `lens_analysis_site_content`. AI original values are indexed when they
 * differ from the user-edited value (dual-column pattern).
 */
class SearchIndexService extends Component
{
    /** BM25 tuning constant k1 — controls term frequency saturation */
    private const K1 = 1.2;

    private const MIN_FUZZY_TERM_LENGTH = 4;
    private const FUZZY_CANDIDATE_LIMIT = 500;
    private const FIELD_WEIGHTS = [
        'tag' => 1.50,
        'title' => 1.30,
        'suggestedTitle' => 1.20,
        'altText' => 1.10,
        'alt' => 1.10,
        'suggestedTitleAi' => 0.90,
        'altTextAi' => 0.85,
        'longDescription' => 0.80,
        'extractedTextAi' => 0.70,
        'longDescriptionAi' => 0.60,
        'siteTitle' => 1.00,
        'siteAltText' => 0.90,
    ];
    private const AI_FIELD_MAP = [
        'suggestedTitle' => 'suggestedTitleAi',
        'altText' => 'altTextAi',
        'longDescription' => 'longDescriptionAi',
    ];

    // -------------------------------------------------------------------------
    // Indexing
    // -------------------------------------------------------------------------

    /**
     * Fully re-indexes a single asset: deletes existing rows then inserts fresh
     * tokens for all text fields and tags (primary-site content only).
     *
     * `extractedTextAi` is stored as a JSON array of regions. For BM25 scoring
     * an array is equivalent to its regions joined by newlines — the stemmer
     * tokenizes across whitespace and TF counts come out identical — so every
     * read of this column is flattened with `implode("\n", ...)` before it
     * reaches `buildTokenRows()`.
     */
    public function indexAsset(AssetAnalysisRecord $record): void
    {
        $start = microtime(true);

        Logger::info(
            LogCategory::SearchIndex,
            'Starting full index for asset',
            assetId: $record->assetId,
            context: ['analysisId' => $record->id]
        );

        // Remove stale tokens
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_SEARCH_INDEX, ['assetId' => $record->assetId])
            ->execute();

        Logger::info(
            LogCategory::SearchIndex,
            'Deleted stale tokens',
            assetId: $record->assetId,
            context: ['deletedRows' => $deleted]
        );

        $rows = [];
        $fieldCounts = [];

        // --- Text fields from the analysis record ---
        $textFields = [
            'altText' => $record->altText,
            'suggestedTitle' => $record->suggestedTitle,
            'longDescription' => $record->longDescription,
            'extractedTextAi' => is_array($record->extractedTextAi) ? implode("\n", $record->extractedTextAi) : null,
        ];

        foreach ($textFields as $field => $value) {
            if (!empty($value)) {
                $fieldRows = $this->buildTokenRows($record->assetId, $record->id, $field, $value);
                $fieldCounts[$field] = count($fieldRows);
                array_push($rows, ...$fieldRows);
            } else {
                $fieldCounts[$field] = 0;
            }
        }

        // --- Asset title and native alt from elements_sites (primary site) ---
        $titleRows = $this->buildTitleRows($record->assetId, $record->id);
        $fieldCounts['title'] = count($titleRows);
        array_push($rows, ...$titleRows);

        $altRows = $this->buildAltRows($record->assetId, $record->id);
        $fieldCounts['alt'] = count($altRows);
        array_push($rows, ...$altRows);

        // --- AI original values (only when edited) ---
        $aiRows = $this->buildAiFieldRows($record);
        $fieldCounts['aiFields'] = count($aiRows);
        array_push($rows, ...$aiRows);

        // --- Tags ---
        $tagRows = $this->buildTagRows($record->assetId, $record->id);
        $fieldCounts['tag'] = count($tagRows);
        array_push($rows, ...$tagRows);

        // --- Site content translations ---
        $siteRows = $this->buildSiteContentRows($record->assetId, $record->id);
        $fieldCounts['siteContent'] = count($siteRows);
        array_push($rows, ...$siteRows);

        if (!empty($rows)) {
            $this->batchInsert($rows);
        }

        $duration = round((microtime(true) - $start) * 1000);

        Logger::info(
            LogCategory::SearchIndex,
            'Full index complete',
            assetId: $record->assetId,
            context: [
                'totalTokens' => count($rows),
                'fieldCounts' => $fieldCounts,
                'durationMs' => $duration,
            ]
        );
    }

    /**
     * Re-indexes a single field for an asset. For 'title' and 'alt' fields,
     * fetches the current value from Craft's native tables (not the analysis record).
     *
     * When the field has a corresponding AI column (dual-column pattern),
     * the AI field is also re-indexed (added if edited, removed if reverted).
     */
    public function reindexField(AssetAnalysisRecord $record, string $field): void
    {
        Logger::info(
            LogCategory::SearchIndex,
            'Reindexing field',
            assetId: $record->assetId,
            context: ['field' => $field, 'analysisId' => $record->id]
        );

        // Determine which fields to delete and rebuild
        $fieldsToDelete = [$field];
        $aiField = self::AI_FIELD_MAP[$field] ?? null;

        if ($aiField !== null) {
            $fieldsToDelete[] = $aiField;
        }

        // Remove existing tokens for this field (and its AI counterpart)
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_SEARCH_INDEX, [
                'assetId' => $record->assetId,
                'field' => $fieldsToDelete,
            ])
            ->execute();

        Logger::info(
            LogCategory::SearchIndex,
            'Deleted field tokens',
            assetId: $record->assetId,
            context: ['fields' => $fieldsToDelete, 'deletedRows' => $deleted]
        );

        if ($field === 'title') {
            $rows = $this->buildTitleRows($record->assetId, $record->id);
        } elseif ($field === 'alt') {
            $rows = $this->buildAltRows($record->assetId, $record->id);
        } elseif (isset(self::FIELD_WEIGHTS[$field]) && $field !== 'tag') {
            $value = $record->$field ?? '';
            if (is_array($value)) {
                $value = implode("\n", $value);
            }
            $rows = !empty($value)
                ? $this->buildTokenRows($record->assetId, $record->id, $field, $value)
                : [];
        } else {
            Logger::info(
                LogCategory::SearchIndex,
                'Skipping unknown field for reindex',
                assetId: $record->assetId,
                context: ['field' => $field]
            );
            return;
        }

        // Also index AI original value if field has been edited
        if ($aiField !== null && $record->isFieldEdited($field)) {
            $aiValue = $record->{$aiField} ?? '';
            if (is_array($aiValue)) {
                $aiValue = implode("\n", $aiValue);
            }

            if (!empty($aiValue)) {
                $aiRows = $this->buildTokenRows($record->assetId, $record->id, $aiField, $aiValue);
                array_push($rows, ...$aiRows);
            }
        }

        if (!empty($rows)) {
            $this->batchInsert($rows);
        }

        Logger::info(
            LogCategory::SearchIndex,
            'Field reindex complete',
            assetId: $record->assetId,
            context: ['field' => $field, 'newTokens' => count($rows)]
        );
    }

    /**
     * Re-indexes all tags for an asset (deletes existing tag tokens, inserts fresh).
     */
    public function reindexTags(AssetAnalysisRecord $record): void
    {
        Logger::info(
            LogCategory::SearchIndex,
            'Reindexing tags',
            assetId: $record->assetId,
            context: ['analysisId' => $record->id]
        );

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_SEARCH_INDEX, [
                'assetId' => $record->assetId,
                'field' => 'tag',
            ])
            ->execute();

        $rows = $this->buildTagRows($record->assetId, $record->id);

        if (!empty($rows)) {
            $this->batchInsert($rows);
        }

        Logger::info(
            LogCategory::SearchIndex,
            'Tag reindex complete',
            assetId: $record->assetId,
            context: [
                'deletedRows' => $deleted,
                'newTokens' => count($rows),
            ]
        );
    }

    /**
     * Removes all search index rows for an asset.
     * Call BEFORE deleting the analysis record to avoid FK violations.
     */
    public function deleteIndex(int $assetId): void
    {
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_SEARCH_INDEX, ['assetId' => $assetId])
            ->execute();

        Logger::info(
            LogCategory::SearchIndex,
            'Deleted index for asset',
            assetId: $assetId,
            context: ['deletedRows' => $deleted]
        );
    }

    /**
     * Rebuilds the entire search index from scratch.
     * Truncates the table, then re-indexes every analyzed asset.
     *
     * @param callable|null $progress Callback(int $current, int $total) for CLI output
     * @return int Number of assets indexed
     */
    public function rebuildAll(?callable $progress = null): int
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'lens-search-index-rebuild';

        if (!$mutex->acquire($lockName, 0)) {
            Logger::info(LogCategory::SearchIndex, 'rebuildAll() skipped — another rebuild is already running');
            return 0;
        }

        // Reset the language cache so a long-lived queue worker always detects
        // the current primary site language rather than reusing a stale value.
        Stemmer::reset();

        $start = microtime(true);

        Logger::info(LogCategory::SearchIndex, 'Starting full search index rebuild');

        try {
            Craft::$app->getDb()->createCommand()
                ->truncateTable(Install::TABLE_SEARCH_INDEX)
                ->execute();

            Logger::info(LogCategory::SearchIndex, 'Search index truncated');

            $query = AssetAnalysisRecord::find()
                ->where(['NOT', ['processedAt' => null]]);

            $total = (int) $query->count();
            $indexed = 0;

            Logger::info(
                LogCategory::SearchIndex,
                'Rebuilding index for analyzed assets',
                context: ['total' => $total]
            );

            foreach ($query->batch(100) as $batch) {
                foreach ($batch as $record) {
                    try {
                        $this->indexAsset($record);
                        $indexed++;

                        if ($progress !== null) {
                            $progress($indexed, $total);
                        }
                    } catch (\Throwable $e) {
                        Logger::warning(
                            LogCategory::SearchIndex,
                            'Failed to index asset during rebuild',
                            assetId: $record->assetId,
                            context: ['error' => $e->getMessage()]
                        );
                    }
                }
            }

            $duration = round((microtime(true) - $start) * 1000);

            Logger::info(
                LogCategory::SearchIndex,
                'Search index rebuild complete',
                context: [
                    'indexedAssets' => $indexed,
                    'totalAssets' => $total,
                    'durationMs' => $duration,
                ]
            );

            return $indexed;
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * Re-indexes site content translations for a specific site.
     * Call after updating or reverting a site content field.
     */
    public function reindexSiteContent(AssetAnalysisRecord $record, int $siteId): void
    {
        $siteRecord = AnalysisSiteContentRecord::find()
            ->where(['analysisId' => $record->id, 'siteId' => $siteId])
            ->one();

        if ($siteRecord === null) {
            return;
        }

        $lang = MultisiteHelper::getBaseLanguage($siteRecord->language);

        Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_SEARCH_INDEX, [
                'assetId' => $record->assetId,
                'field' => ["siteTitle:{$lang}", "siteAltText:{$lang}"],
            ])
            ->execute();

        $rows = $this->buildSingleSiteContentRows($record->assetId, $record->id, $siteRecord);

        if (!empty($rows)) {
            $this->batchInsert($rows);
        }

        Logger::info(
            LogCategory::SearchIndex,
            'Site content reindex complete',
            assetId: $record->assetId,
            context: ['siteId' => $siteId, 'language' => $lang, 'newTokens' => count($rows)]
        );
    }

    // -------------------------------------------------------------------------
    // Searching
    // -------------------------------------------------------------------------

    /**
     * BM25 search. Returns [assetId => float score] sorted by score DESC.
     *
     * @param string[] $rawTerms   Un-stemmed search terms
     * @param int      $limit      Max assets to return
     * @return array<int, float>
     */
    public function search(array $rawTerms, int $limit = 500): array
    {
        if (empty($rawTerms)) {
            return [];
        }

        // Stem each term
        $stemmedTerms = array_values(array_unique(array_filter(
            array_map(fn(string $t) => Stemmer::stem(mb_strtolower(trim($t), 'UTF-8')), $rawTerms),
            fn(string $t) => strlen($t) >= 2
        )));

        if (empty($stemmedTerms)) {
            return [];
        }

        Logger::info(
            LogCategory::SearchIndex,
            'BM25 search started',
            context: ['rawTerms' => $rawTerms, 'stemmedTerms' => $stemmedTerms]
        );

        // 1. Exact matches
        $rows = (new Query())
            ->select(['assetId', 'token', 'field', 'fieldWeight', 'tf'])
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['token' => $stemmedTerms])
            ->all();

        // 2. Fuzzy fallback for any term that got zero exact results
        $matchedTokens = array_unique(array_column($rows, 'token'));

        foreach ($stemmedTerms as $term) {
            if (!in_array($term, $matchedTokens, true)) {
                $fuzzyRows = $this->fuzzyLookup($term);
                if (!empty($fuzzyRows)) {
                    array_push($rows, ...$fuzzyRows);
                }
            }
        }

        if (empty($rows)) {
            Logger::info(
                LogCategory::SearchIndex,
                'BM25 search returned no results',
                context: ['stemmedTerms' => $stemmedTerms]
            );
            return [];
        }

        // 3. Compute IDF for each token
        $totalAssets = (int) (new Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->select('COUNT(DISTINCT assetId)')
            ->scalar();

        $tokenDf = [];
        foreach ($rows as $row) {
            $tokenDf[$row['token']] ??= 0;
        }

        if (!empty($tokenDf)) {
            $dfResults = (new Query())
                ->select(['token', 'COUNT(DISTINCT assetId) AS df'])
                ->from(Install::TABLE_SEARCH_INDEX)
                ->where(['token' => array_keys($tokenDf)])
                ->groupBy(['token'])
                ->all();

            foreach ($dfResults as $dfRow) {
                $tokenDf[$dfRow['token']] = (int) $dfRow['df'];
            }
        }

        // 4. Compute BM25 scores
        $scores = [];

        foreach ($rows as $row) {
            $assetId = (int) $row['assetId'];
            $tf = (int) $row['tf'];
            $weight = (float) $row['fieldWeight'];
            $df = $tokenDf[$row['token']] ?? 1;

            $N = max($totalAssets, 1);
            $idf = log(($N - $df + 0.5) / ($df + 0.5) + 1);
            $tfScore = ($tf * (self::K1 + 1)) / ($tf + self::K1);

            $scores[$assetId] = ($scores[$assetId] ?? 0.0) + ($idf * $tfScore * $weight);
        }

        arsort($scores);

        $result = array_slice($scores, 0, $limit, true);

        Logger::info(
            LogCategory::SearchIndex,
            'BM25 search complete',
            context: [
                'stemmedTerms' => $stemmedTerms,
                'resultCount' => count($result),
                'usedFuzzy' => count($result) !== count(array_intersect(
                    array_unique(array_column($rows, 'token')),
                    $stemmedTerms
                )),
            ]
        );

        return $result;
    }

    /**
     * Fuzzy token lookup for a single stemmed term.
     *
     * Tier 1: prefix LIKE 'term%' (fast, uses B-tree index)
     * Tier 2: Levenshtein distance <= 1 (only for terms >= 4 chars)
     *
     * Returns index rows (same structure as TABLE_SEARCH_INDEX columns).
     */
    public function fuzzyLookup(string $stemmedTerm): array
    {
        // Tier 1: prefix match
        $prefixRows = (new Query())
            ->select(['assetId', 'token', 'field', 'fieldWeight', 'tf'])
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['LIKE', 'token', $stemmedTerm . '%', false])
            ->all();

        if (!empty($prefixRows)) {
            Logger::info(
                LogCategory::SearchIndex,
                'Fuzzy prefix match found',
                context: ['term' => $stemmedTerm, 'matchCount' => count($prefixRows)]
            );
            return $prefixRows;
        }

        // Tier 2: Levenshtein (only worthwhile for terms >= 4 chars)
        if (mb_strlen($stemmedTerm, 'UTF-8') < self::MIN_FUZZY_TERM_LENGTH) {
            return [];
        }

        $prefix2 = mb_substr($stemmedTerm, 0, 2, 'UTF-8');
        $termLen = mb_strlen($stemmedTerm, 'UTF-8');

        // Levenshtein distance ≤ 1 is impossible between strings whose lengths
        // differ by more than 1, so constrain the query to that range.
        // The LIMIT 500 is a safety net for pathological indexes.
        $candidates = (new Query())
            ->select(['assetId', 'token', 'field', 'fieldWeight', 'tf'])
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['LIKE', 'token', $prefix2 . '%', false])
            ->andWhere(['>=', new \yii\db\Expression('LENGTH(token)'), $termLen - 1])
            ->andWhere(['<=', new \yii\db\Expression('LENGTH(token)'), $termLen + 1])
            ->limit(self::FUZZY_CANDIDATE_LIMIT)
            ->all();

        $matched = array_filter($candidates, function (array $row) use ($stemmedTerm): bool {
            return levenshtein($row['token'], $stemmedTerm) <= 1;
        });

        if (!empty($matched)) {
            Logger::info(
                LogCategory::SearchIndex,
                'Fuzzy Levenshtein match found',
                context: ['term' => $stemmedTerm, 'matchCount' => count($matched)]
            );
        }

        return array_values($matched);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build token rows for a text field value.
     *
     * @return array<array<string, mixed>>
     */
    private function buildTokenRows(int $assetId, int $analysisId, string $field, string $value): array
    {
        $weight = self::FIELD_WEIGHTS[$field] ?? 1.0;
        $pairs = Stemmer::tokenizeAndStem($value);

        if (empty($pairs)) {
            return [];
        }

        // Count TF per stemmed token
        $tfMap = [];
        foreach ($pairs as [$raw, $stemmed]) {
            $tfMap[$stemmed] ??= ['raw' => $raw, 'count' => 0];
            $tfMap[$stemmed]['count']++;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($tfMap as $stemmed => $entry) {
            $raw = $entry['raw'];
            $count = $entry['count'];
            $rows[] = [
                'assetId' => $assetId,
                'analysisId' => $analysisId,
                'token' => $stemmed,
                'tokenRaw' => $raw,
                'field' => $field,
                'fieldWeight' => $weight,
                'tf' => $count,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ];
        }

        return $rows;
    }

    /**
     * Fetch the primary-site asset title and build token rows.
     *
     * @return array<array<string, mixed>>
     */
    private function buildTitleRows(int $assetId, int $analysisId): array
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $title = (new Query())
            ->select(['title'])
            ->from('{{%elements_sites}}')
            ->where(['elementId' => $assetId, 'siteId' => $primarySiteId])
            ->scalar();

        if (empty($title)) {
            Logger::info(
                LogCategory::SearchIndex,
                'No title found in elements_sites for asset — title tokens skipped',
                assetId: $assetId,
                context: ['siteId' => $primarySiteId]
            );
            return [];
        }

        return $this->buildTokenRows($assetId, $analysisId, 'title', (string) $title);
    }

    /**
     * Fetch the primary-site native alt text and build token rows.
     *
     * Craft 5 stores alt in two places: assets_sites.alt (per-site override)
     * and assets.alt (default fallback). We check per-site first.
     *
     * @return array<array<string, mixed>>
     */
    private function buildAltRows(int $assetId, int $analysisId): array
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $alt = (new Query())
            ->select(['alt'])
            ->from('{{%assets_sites}}')
            ->where(['assetId' => $assetId, 'siteId' => $primarySiteId])
            ->scalar();

        if (empty($alt)) {
            $alt = (new Query())
                ->select(['alt'])
                ->from('{{%assets}}')
                ->where(['id' => $assetId])
                ->scalar();
        }

        if (empty($alt)) {
            return [];
        }

        return $this->buildTokenRows($assetId, $analysisId, 'alt', (string) $alt);
    }

    /**
     * Fetch all tags for an analysis and build token rows.
     *
     * Each tag is treated as a single token (whole-tag match) as well as
     * individual word tokens when the tag contains multiple words.
     *
     * @return array<array<string, mixed>>
     */
    private function buildTagRows(int $assetId, int $analysisId): array
    {
        $tags = (new Query())
            ->select(['tag'])
            ->from(Install::TABLE_ASSET_TAGS)
            ->where(['analysisId' => $analysisId])
            ->column();

        if (empty($tags)) {
            return [];
        }

        $rows = [];
        foreach ($tags as $tag) {
            $tagRows = $this->buildTokenRows($assetId, $analysisId, 'tag', (string) $tag);
            array_push($rows, ...$tagRows);
        }

        return $rows;
    }

    /**
     * Build token rows for AI original values that differ from the edited value.
     *
     * @return array<array<string, mixed>>
     */
    private function buildAiFieldRows(AssetAnalysisRecord $record): array
    {
        $rows = [];

        foreach (self::AI_FIELD_MAP as $editedField => $aiField) {
            if (!$record->isFieldEdited($editedField)) {
                continue;
            }

            $aiValue = $record->{$aiField} ?? '';

            if (is_array($aiValue)) {
                $aiValue = implode("\n", $aiValue);
            }

            if (!empty($aiValue)) {
                $fieldRows = $this->buildTokenRows($record->assetId, $record->id, $aiField, $aiValue);
                array_push($rows, ...$fieldRows);
            }
        }

        return $rows;
    }

    /**
     * Build token rows for all site content translations.
     *
     * @return array<array<string, mixed>>
     */
    private function buildSiteContentRows(int $assetId, int $analysisId): array
    {
        $siteRecords = AnalysisSiteContentRecord::find()
            ->where(['analysisId' => $analysisId])
            ->all();

        if (empty($siteRecords)) {
            return [];
        }

        $rows = [];
        foreach ($siteRecords as $siteRecord) {
            $siteRows = $this->buildSingleSiteContentRows($assetId, $analysisId, $siteRecord);
            array_push($rows, ...$siteRows);
        }

        return $rows;
    }

    /**
     * Build token rows for a single site content record, using language-specific stemming.
     * Indexes both edited and AI original values (when different).
     *
     * @return array<array<string, mixed>>
     */
    private function buildSingleSiteContentRows(int $assetId, int $analysisId, AnalysisSiteContentRecord $siteRecord): array
    {
        $lang = MultisiteHelper::getBaseLanguage($siteRecord->language);
        $rows = [];

        $siteFields = [
            'suggestedTitle' => "siteTitle:{$lang}",
            'altText' => "siteAltText:{$lang}",
        ];

        foreach ($siteFields as $sourceField => $indexField) {
            $value = $siteRecord->{$sourceField} ?? '';

            if (!empty($value)) {
                $fieldRows = $this->buildTokenRowsForLanguage($assetId, $analysisId, $indexField, $value, $lang);
                array_push($rows, ...$fieldRows);
            }

            if ($siteRecord->isFieldEdited($sourceField)) {
                $aiValue = $siteRecord->{$sourceField . 'Ai'} ?? '';

                if (!empty($aiValue)) {
                    $aiRows = $this->buildTokenRowsForLanguage($assetId, $analysisId, $indexField, $aiValue, $lang);
                    array_push($rows, ...$aiRows);
                }
            }
        }

        return $rows;
    }

    /**
     * Build token rows using a language-specific stemmer.
     *
     * @return array<array<string, mixed>>
     */
    private function buildTokenRowsForLanguage(int $assetId, int $analysisId, string $field, string $value, string $language): array
    {
        // Use base field name (without language suffix) for weight lookup
        $baseField = preg_replace('/:.+$/', '', $field);
        $weight = self::FIELD_WEIGHTS[$baseField] ?? 1.0;
        $pairs = Stemmer::tokenizeAndStemForLanguage($value, $language);

        if (empty($pairs)) {
            return [];
        }

        $tfMap = [];

        foreach ($pairs as [$raw, $stemmed]) {
            $tfMap[$stemmed] ??= ['raw' => $raw, 'count' => 0];
            $tfMap[$stemmed]['count']++;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($tfMap as $stemmed => $entry) {
            $rows[] = [
                'assetId' => $assetId,
                'analysisId' => $analysisId,
                'token' => $stemmed,
                'tokenRaw' => $entry['raw'],
                'field' => $field,
                'fieldWeight' => $weight,
                'tf' => $entry['count'],
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ];
        }

        return $rows;
    }

    /**
     * Batch-insert token rows.
     *
     * @param array<array<string, mixed>> $rows
     */
    private function batchInsert(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        Craft::$app->getDb()->createCommand()->batchInsert(
            Install::TABLE_SEARCH_INDEX,
            ['assetId', 'analysisId', 'token', 'tokenRaw', 'field', 'fieldWeight', 'tf', 'dateCreated', 'dateUpdated', 'uid'],
            array_map(fn(array $r) => [
                $r['assetId'],
                $r['analysisId'],
                $r['token'],
                $r['tokenRaw'],
                $r['field'],
                $r['fieldWeight'],
                $r['tf'],
                $r['dateCreated'],
                $r['dateUpdated'],
                $r['uid'],
            ], $rows)
        )->execute();
    }
}
