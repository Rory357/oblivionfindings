<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;

class MedicationPaperRecoveryMigrationTest extends TestCase
{
    public function test_recovery_migration_refuses_destructive_rollback_without_touching_a_database(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_06_010020_create_medication_paper_stock_evidence.php';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable actual facts, stock reviews and clinical authority evidence must be retained');
        $migration->down();
    }
}
