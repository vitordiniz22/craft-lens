<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\migrations;

use craft\db\Migration;
use craft\db\Table;
use vitordiniz22\craftlens\Plugin;

/**
 * Install migration for the Lens plugin.
 */
class Install extends Migration
{
    public const TABLE_ASSET_ANALYSES = '{{%lens_asset_analyses}}';
    public const TABLE_ASSET_TAGS = '{{%lens_asset_tags}}';
    public const TABLE_ASSET_COLORS = '{{%lens_asset_colors}}';
    public const TABLE_DUPLICATE_GROUPS = '{{%lens_duplicate_groups}}';
    public const TABLE_ANALYSIS_CONTENT = '{{%lens_analysis_content}}';
    public const TABLE_ANALYSIS_SITE_CONTENT = '{{%lens_analysis_site_content}}';
    public const TABLE_LOGS = '{{%lens_logs}}';
    public const TABLE_SEARCH_INDEX = '{{%lens_search_index}}';

    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_SEARCH_INDEX);
        $this->dropTableIfExists(self::TABLE_ANALYSIS_SITE_CONTENT);
        $this->dropTableIfExists(self::TABLE_ANALYSIS_CONTENT);
        $this->dropTableIfExists(self::TABLE_LOGS);
        $this->dropTableIfExists(self::TABLE_DUPLICATE_GROUPS);
        $this->dropTableIfExists(self::TABLE_ASSET_COLORS);
        $this->dropTableIfExists(self::TABLE_ASSET_TAGS);
        $this->dropTableIfExists(self::TABLE_ASSET_ANALYSES);

        return true;
    }

    private function createTables(): void
    {
        // Main analysis table
        $this->createTable(self::TABLE_ASSET_ANALYSES, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'queueJobId' => $this->string(20)->null(),
            'previousStatus' => $this->string(20)->null(),
            'provider' => $this->string(50)->null(),
            'providerModel' => $this->string(50)->null(),

            // Alt text (editable)
            'altText' => $this->text()->null(),
            'altTextAi' => $this->text()->null(),
            'altTextConfidence' => $this->decimal(3, 2)->null(),

            // Suggested title (editable)
            'suggestedTitle' => $this->text()->null(),
            'suggestedTitleAi' => $this->text()->null(),
            'titleConfidence' => $this->decimal(3, 2)->null(),

            // Long description (moved from analysis_content, editable)
            'longDescription' => $this->text()->null(),
            'longDescriptionAi' => $this->text()->null(),
            'longDescriptionConfidence' => $this->decimal(3, 2)->null(),

            // Face detection (editable)
            'faceCount' => $this->integer()->notNull()->defaultValue(0),
            'faceCountAi' => $this->integer()->null(),
            'containsPeople' => $this->boolean()->notNull()->defaultValue(false),
            'containsPeopleAi' => $this->boolean()->null(),
            'containsPeopleConfidence' => $this->decimal(3, 2)->null(),

            // NSFW detection (editable)
            'nsfwScore' => $this->decimal(5, 4)->null(),
            'nsfwScoreAi' => $this->decimal(5, 4)->null(),
            'nsfwConfidence' => $this->decimal(5, 4)->null(),
            'nsfwCategories' => $this->json()->null(),

            // Watermark detection (editable)
            'hasWatermark' => $this->boolean()->notNull()->defaultValue(false),
            'hasWatermarkAi' => $this->boolean()->null(),
            'watermarkConfidence' => $this->decimal(5, 4)->null(),
            'watermarkType' => $this->string(30)->null(),
            'watermarkDetails' => $this->json()->null(),

            // Brand detection (editable)
            'containsBrandLogo' => $this->boolean()->notNull()->defaultValue(false),
            'containsBrandLogoAi' => $this->boolean()->null(),
            'containsBrandLogoConfidence' => $this->decimal(3, 2)->null(),
            'detectedBrands' => $this->json()->null(),

            // Image quality scores (sharpness/exposure/noise computed locally via Imagick)
            'sharpnessScore' => $this->decimal(5, 4)->null(),
            'exposureScore' => $this->decimal(5, 4)->null(),
            'noiseScore' => $this->decimal(5, 4)->null(),
            'overallQualityScore' => $this->decimal(5, 4)->null(),
            'compressionQuality' => $this->tinyInteger()->unsigned()->null(),
            'colorProfile' => $this->string(20)->null(),
            // Focal point detection (editable)
            'focalPointX' => $this->decimal(5, 4)->null(),
            'focalPointXAi' => $this->decimal(5, 4)->null(),
            'focalPointY' => $this->decimal(5, 4)->null(),
            'focalPointYAi' => $this->decimal(5, 4)->null(),
            'focalPointConfidence' => $this->decimal(5, 4)->null(),

            // Hashes for duplicate detection
            'perceptualHash' => $this->string(64)->null(),
            'fileContentHash' => $this->string(64)->null(),

            // Extracted text from image (editable)
            'extractedText' => $this->text()->null(),
            'extractedTextAi' => $this->text()->null(),

            // Token usage and cost
            'inputTokens' => $this->integer()->null(),
            'outputTokens' => $this->integer()->null(),
            'actualCost' => $this->decimal(10, 6)->null(),

            // Timestamps and audit
            'processedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Analysis content table
        $this->createTable(self::TABLE_ANALYSIS_CONTENT, [
            'id' => $this->primaryKey(),
            'analysisId' => $this->integer()->notNull(),
            'errorMessage' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Asset tags table
        $this->createTable(self::TABLE_ASSET_TAGS, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'analysisId' => $this->integer()->notNull(),
            'tag' => $this->string(255)->notNull(),
            'tagNormalized' => $this->string(255)->notNull(),
            'confidence' => $this->decimal(5, 4)->null(),
            'isAi' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Asset colors table
        $this->createTable(self::TABLE_ASSET_COLORS, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'analysisId' => $this->integer()->notNull(),
            'hex' => $this->string(7)->notNull(),
            'percentage' => $this->decimal(5, 4)->null(),
            'isAutoGenerated' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Duplicate groups table (unchanged)
        $this->createTable(self::TABLE_DUPLICATE_GROUPS, [
            'id' => $this->primaryKey(),
            'canonicalAssetId' => $this->integer()->notNull(),
            'duplicateAssetId' => $this->integer()->notNull(),
            'hammingDistance' => $this->smallInteger()->notNull(),
            'similarity' => $this->decimal(5, 4)->notNull(),
            'resolvedAt' => $this->dateTime()->null(),
            'resolvedBy' => $this->integer()->null(),
            'resolution' => $this->string(20)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Per-site content for multilingual alt text and title
        $this->createTable(self::TABLE_ANALYSIS_SITE_CONTENT, [
            'id' => $this->primaryKey(),
            'analysisId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'language' => $this->string(10)->notNull(),

            // Alt text (editable, per-site)
            'altText' => $this->text()->null(),
            'altTextAi' => $this->text()->null(),
            'altTextConfidence' => $this->decimal(3, 2)->null(),

            // Suggested title (editable, per-site)
            'suggestedTitle' => $this->text()->null(),
            'suggestedTitleAi' => $this->text()->null(),
            'titleConfidence' => $this->decimal(3, 2)->null(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        if (Plugin::isDevInstall()) {
            $this->createTable(self::TABLE_LOGS, [
                'id' => $this->primaryKey(),
                'level' => $this->string(10)->notNull(),
                'category' => $this->string(40)->notNull(),
                'message' => $this->text()->notNull(),
                'assetId' => $this->integer()->null(),
                'provider' => $this->string(50)->null(),
                'jobType' => $this->string(100)->null(),
                'isRetryable' => $this->boolean()->notNull()->defaultValue(false),
                'retryJobData' => $this->json()->null(),
                'httpStatusCode' => $this->smallInteger()->null(),
                'responseTimeMs' => $this->integer()->null(),
                'inputTokens' => $this->integer()->null(),
                'outputTokens' => $this->integer()->null(),
                'requestPayload' => $this->json()->null(),
                'responsePayload' => $this->json()->null(),
                'stackTrace' => $this->text()->null(),
                'context' => $this->json()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        // Full-text search index (pre-stemmed tokens with BM25 scoring data)
        $this->createTable(self::TABLE_SEARCH_INDEX, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'analysisId' => $this->integer()->notNull(),
            'token' => $this->string(100)->notNull(),
            'tokenRaw' => $this->string(100)->notNull(),
            'field' => $this->string(30)->notNull(),
            'fieldWeight' => $this->decimal(3, 2)->notNull(),
            'tf' => $this->smallInteger()->notNull()->defaultValue(1),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // Asset analyses indexes
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['assetId'], true);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['status']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['containsPeople']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['faceCount']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['nsfwScore']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['hasWatermark']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['watermarkType']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['containsBrandLogo']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['overallQualityScore']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['sharpnessScore']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['exposureScore']);

        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['perceptualHash']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['fileContentHash']);
        // Analysis content indexes
        $this->createIndex(null, self::TABLE_ANALYSIS_CONTENT, ['analysisId'], true);

        // Asset tags indexes
        $this->createIndex(null, self::TABLE_ASSET_TAGS, ['assetId']);
        $this->createIndex(null, self::TABLE_ASSET_TAGS, ['tagNormalized']);
        $this->createIndex(null, self::TABLE_ASSET_TAGS, ['confidence']);
        $this->createIndex(null, self::TABLE_ASSET_TAGS, ['analysisId']);
        $this->createIndex(null, self::TABLE_ASSET_TAGS, ['tagNormalized', 'assetId']);
        $this->createIndex(null, self::TABLE_ASSET_TAGS, ['isAi']);

        // Asset colors indexes
        $this->createIndex(null, self::TABLE_ASSET_COLORS, ['assetId']);
        $this->createIndex(null, self::TABLE_ASSET_COLORS, ['analysisId']);
        $this->createIndex(null, self::TABLE_ASSET_COLORS, ['hex']);
        $this->createIndex(null, self::TABLE_ASSET_COLORS, ['isAutoGenerated']);

        // Analysis site content indexes
        $this->createIndex(null, self::TABLE_ANALYSIS_SITE_CONTENT, ['analysisId', 'siteId'], true);
        $this->createIndex(null, self::TABLE_ANALYSIS_SITE_CONTENT, ['siteId']);
        $this->createIndex(null, self::TABLE_ANALYSIS_SITE_CONTENT, ['language']);

        // Duplicate groups indexes
        $this->createIndex(null, self::TABLE_DUPLICATE_GROUPS, ['canonicalAssetId', 'duplicateAssetId'], true);
        $this->createIndex(null, self::TABLE_DUPLICATE_GROUPS, ['hammingDistance']);
        $this->createIndex(null, self::TABLE_DUPLICATE_GROUPS, ['resolution']);

        // Logs indexes — only in development environments
        if (Plugin::isDevInstall()) {
            $this->createIndex(null, self::TABLE_LOGS, ['level']);
            $this->createIndex(null, self::TABLE_LOGS, ['category']);
            $this->createIndex(null, self::TABLE_LOGS, ['assetId']);
            $this->createIndex(null, self::TABLE_LOGS, ['dateCreated']);
            $this->createIndex(null, self::TABLE_LOGS, ['level', 'category', 'dateCreated']);
        }

        // Search index indexes — token is the primary lookup column
        $this->createIndex(null, self::TABLE_SEARCH_INDEX, ['token']);
        $this->createIndex(null, self::TABLE_SEARCH_INDEX, ['token', 'assetId']);
        $this->createIndex(null, self::TABLE_SEARCH_INDEX, ['assetId']);
        $this->createIndex(null, self::TABLE_SEARCH_INDEX, ['analysisId']);
    }

    private function addForeignKeys(): void
    {
        // Asset analyses foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_ASSET_ANALYSES,
            ['assetId'],
            Table::ELEMENTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Analysis content foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_ANALYSIS_CONTENT,
            ['analysisId'],
            self::TABLE_ASSET_ANALYSES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Asset tags foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_ASSET_TAGS,
            ['assetId'],
            Table::ELEMENTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_ASSET_TAGS,
            ['analysisId'],
            self::TABLE_ASSET_ANALYSES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Asset colors foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_ASSET_COLORS,
            ['assetId'],
            Table::ELEMENTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_ASSET_COLORS,
            ['analysisId'],
            self::TABLE_ASSET_ANALYSES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Analysis site content foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_ANALYSIS_SITE_CONTENT,
            ['analysisId'],
            self::TABLE_ASSET_ANALYSES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_ANALYSIS_SITE_CONTENT,
            ['siteId'],
            Table::SITES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Duplicate groups foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_DUPLICATE_GROUPS,
            ['canonicalAssetId'],
            Table::ELEMENTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_DUPLICATE_GROUPS,
            ['duplicateAssetId'],
            Table::ELEMENTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_DUPLICATE_GROUPS,
            ['resolvedBy'],
            Table::USERS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Logs foreign keys — only in development environments
        if (Plugin::isDevInstall()) {
            $this->addForeignKey(
                null,
                self::TABLE_LOGS,
                ['assetId'],
                Table::ELEMENTS,
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

        // Search index foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_SEARCH_INDEX,
            ['assetId'],
            Table::ELEMENTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_SEARCH_INDEX,
            ['analysisId'],
            self::TABLE_ASSET_ANALYSES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }
}
