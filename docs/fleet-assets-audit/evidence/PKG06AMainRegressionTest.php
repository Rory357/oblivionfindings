<?php

// Execute the exact candidate's corrective regressions without copying app code.
// Run from checkout6421 with its PHPUnit configuration and schema-only helper.
require_once getcwd().'/tests/Feature/FleetAssets/AssetRegisterWorkflowTest.php';

final class PKG06AMainRegressionTest extends \Tests\Feature\FleetAssets\AssetRegisterWorkflowTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main06a_correction_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}
}
