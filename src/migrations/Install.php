<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\migrations;

use craft\db\Migration;
use craft\db\Table;

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
    public const TABLE_EXIF_METADATA = '{{%lens_exif_metadata}}';
    public const TABLE_LOGS = '{{%lens_logs}}';

    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_EXIF_METADATA);
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
            'provider' => $this->string(50)->null(),
            'providerModel' => $this->string(50)->null(),

            // Alt text (editable)
            'altText' => $this->text()->null(),
            'altTextAi' => $this->text()->null(),
            'altTextConfidence' => $this->decimal(3, 2)->null(),
            'altTextEditedBy' => $this->integer()->null(),
            'altTextEditedAt' => $this->dateTime()->null(),

            // Suggested title (editable)
            'suggestedTitle' => $this->text()->null(),
            'suggestedTitleAi' => $this->text()->null(),
            'titleConfidence' => $this->decimal(3, 2)->null(),
            'suggestedTitleEditedBy' => $this->integer()->null(),
            'suggestedTitleEditedAt' => $this->dateTime()->null(),

            // Long description (moved from analysis_content, editable)
            'longDescription' => $this->text()->null(),
            'longDescriptionAi' => $this->text()->null(),
            'longDescriptionConfidence' => $this->decimal(3, 2)->null(),
            'longDescriptionEditedBy' => $this->integer()->null(),
            'longDescriptionEditedAt' => $this->dateTime()->null(),

            // Face detection (editable)
            'faceCount' => $this->integer()->notNull()->defaultValue(0),
            'faceCountAi' => $this->integer()->null(),
            'containsPeople' => $this->boolean()->notNull()->defaultValue(false),
            'containsPeopleAi' => $this->boolean()->null(),
            'faceCountEditedBy' => $this->integer()->null(),
            'faceCountEditedAt' => $this->dateTime()->null(),
            'containsPeopleEditedBy' => $this->integer()->null(),
            'containsPeopleEditedAt' => $this->dateTime()->null(),

            // NSFW detection (editable)
            'nsfwScore' => $this->decimal(5, 4)->null(),
            'nsfwScoreAi' => $this->decimal(5, 4)->null(),
            'nsfwCategories' => $this->json()->null(),
            'isFlaggedNsfw' => $this->boolean()->notNull()->defaultValue(false),
            'nsfwScoreEditedBy' => $this->integer()->null(),
            'nsfwScoreEditedAt' => $this->dateTime()->null(),

            // Watermark detection (editable)
            'hasWatermark' => $this->boolean()->notNull()->defaultValue(false),
            'hasWatermarkAi' => $this->boolean()->null(),
            'watermarkConfidence' => $this->decimal(5, 4)->null(),
            'watermarkType' => $this->string(30)->null(),
            'watermarkDetails' => $this->json()->null(),
            'hasWatermarkEditedBy' => $this->integer()->null(),
            'hasWatermarkEditedAt' => $this->dateTime()->null(),

            // Brand detection (editable)
            'containsBrandLogo' => $this->boolean()->notNull()->defaultValue(false),
            'containsBrandLogoAi' => $this->boolean()->null(),
            'detectedBrands' => $this->json()->null(),
            'containsBrandLogoEditedBy' => $this->integer()->null(),
            'containsBrandLogoEditedAt' => $this->dateTime()->null(),

            // Image quality scores
            'sharpnessScore' => $this->decimal(5, 4)->null(),
            'exposureScore' => $this->decimal(5, 4)->null(),
            'noiseScore' => $this->decimal(5, 4)->null(),
            'overallQualityScore' => $this->decimal(5, 4)->null(),
            // Focal point detection (editable, shared edit tracking for X+Y)
            'focalPointX' => $this->decimal(5, 4)->null(),
            'focalPointXAi' => $this->decimal(5, 4)->null(),
            'focalPointY' => $this->decimal(5, 4)->null(),
            'focalPointYAi' => $this->decimal(5, 4)->null(),
            'focalPointConfidence' => $this->decimal(5, 4)->null(),
            'focalPointEditedBy' => $this->integer()->null(),
            'focalPointEditedAt' => $this->dateTime()->null(),

            // Hashes for duplicate detection
            'perceptualHash' => $this->string(64)->null(),
            'fileContentHash' => $this->string(64)->null(),

            // Extracted text from image (editable)
            'extractedText' => $this->text()->null(),
            'extractedTextAi' => $this->text()->null(),
            'extractedTextEditedBy' => $this->integer()->null(),
            'extractedTextEditedAt' => $this->dateTime()->null(),

            // Content table flags
            'hasAnalysisContent' => $this->boolean()->notNull()->defaultValue(false),
            'hasExifMetadata' => $this->boolean()->notNull()->defaultValue(false),

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
            'rawResponse' => $this->json()->null(),
            'customPromptResult' => $this->text()->null(),
            'errorMessage' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // EXIF metadata table
        $this->createTable(self::TABLE_EXIF_METADATA, [
            'id' => $this->primaryKey(),
            'analysisId' => $this->integer()->notNull(),
            'assetId' => $this->integer()->notNull(),

            // Camera information
            'cameraMake' => $this->string(100)->null(),
            'cameraModel' => $this->string(100)->null(),
            'lens' => $this->string(200)->null(),
            'focalLength' => $this->string(20)->null(),
            'aperture' => $this->string(20)->null(),
            'shutterSpeed' => $this->string(20)->null(),
            'iso' => $this->integer()->null(),
            'exposureMode' => $this->string(30)->null(),

            // Date and dimensions
            'dateTaken' => $this->dateTime()->null(),
            'orientation' => $this->tinyInteger()->null(),
            'width' => $this->integer()->null(),
            'height' => $this->integer()->null(),

            // GPS coordinates
            'latitude' => $this->decimal(10, 8)->null(),
            'longitude' => $this->decimal(11, 8)->null(),
            'altitude' => $this->decimal(10, 2)->null(),

            // Raw data for debugging/future use
            'rawExif' => $this->json()->null(),

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
            'isAi' => $this->boolean()->notNull()->defaultValue(true),
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

    private function createIndexes(): void
    {
        // Asset analyses indexes
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['assetId'], true);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['status']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['containsPeople']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['faceCount']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['isFlaggedNsfw']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['hasWatermark']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['watermarkType']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['containsBrandLogo']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['overallQualityScore']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['sharpnessScore']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['exposureScore']);

        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['perceptualHash']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['fileContentHash']);
        // Content table flags indexes
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['hasAnalysisContent']);
        $this->createIndex(null, self::TABLE_ASSET_ANALYSES, ['hasExifMetadata']);

        // Analysis content indexes
        $this->createIndex(null, self::TABLE_ANALYSIS_CONTENT, ['analysisId'], true);

        // EXIF metadata indexes
        $this->createIndex(null, self::TABLE_EXIF_METADATA, ['analysisId'], true);
        $this->createIndex(null, self::TABLE_EXIF_METADATA, ['assetId']);
        $this->createIndex(null, self::TABLE_EXIF_METADATA, ['dateTaken']);
        $this->createIndex(null, self::TABLE_EXIF_METADATA, ['latitude', 'longitude']);

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
        $this->createIndex(null, self::TABLE_ASSET_COLORS, ['isAi']);

        // Duplicate groups indexes
        $this->createIndex(null, self::TABLE_DUPLICATE_GROUPS, ['canonicalAssetId', 'duplicateAssetId'], true);
        $this->createIndex(null, self::TABLE_DUPLICATE_GROUPS, ['hammingDistance']);
        $this->createIndex(null, self::TABLE_DUPLICATE_GROUPS, ['resolution']);

        // Logs indexes
        $this->createIndex(null, self::TABLE_LOGS, ['level']);
        $this->createIndex(null, self::TABLE_LOGS, ['category']);
        $this->createIndex(null, self::TABLE_LOGS, ['assetId']);
        $this->createIndex(null, self::TABLE_LOGS, ['dateCreated']);
        $this->createIndex(null, self::TABLE_LOGS, ['level', 'category', 'dateCreated']);
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

        // EditedBy foreign keys for editable fields
        $editedByColumns = [
            'altTextEditedBy',
            'suggestedTitleEditedBy',
            'longDescriptionEditedBy',
            'faceCountEditedBy',
            'containsPeopleEditedBy',
            'nsfwScoreEditedBy',
            'hasWatermarkEditedBy',
            'containsBrandLogoEditedBy',
            'focalPointEditedBy',
            'extractedTextEditedBy',
        ];

        foreach ($editedByColumns as $column) {
            $this->addForeignKey(
                null,
                self::TABLE_ASSET_ANALYSES,
                [$column],
                Table::USERS,
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

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

        // EXIF metadata foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_EXIF_METADATA,
            ['analysisId'],
            self::TABLE_ASSET_ANALYSES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_EXIF_METADATA,
            ['assetId'],
            Table::ELEMENTS,
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

        // Logs foreign keys
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
}
