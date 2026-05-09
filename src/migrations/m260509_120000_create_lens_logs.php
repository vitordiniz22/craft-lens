<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\migrations;

use craft\db\Migration;

/**
 * Creates the lens_logs table for installs that ran an earlier Install.php
 * which only created it conditionally. No-op when the table already exists.
 */
class m260509_120000_create_lens_logs extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(Install::TABLE_LOGS) !== null) {
            return true;
        }

        Install::createLogsTable($this);
        Install::createLogsIndexes($this);
        Install::addLogsForeignKey($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Install::TABLE_LOGS);
        return true;
    }
}
