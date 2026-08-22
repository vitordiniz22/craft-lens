<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\services;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

class BulkProcessingStatusServiceTest extends Unit
{
    use AnalysisRecordFixtures;

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    public function testFailedAssetIdsAreRestrictedToSelectedVolume(): void
    {
        $scoped = $this->createAssetFixture(
            'scoped-failure.jpg',
            analysisStatus: AnalysisStatus::Failed->value,
        );
        $outside = $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $volumeId = $this->ensureTestAssetVolume()['volumeId'];

        $failedAssetIds = Plugin::getInstance()->bulkProcessingStatus->getFailedAssetIds($volumeId);

        $this->assertContains($scoped->assetId, $failedAssetIds);
        $this->assertNotContains($outside->assetId, $failedAssetIds);
    }
}
