<?php

// Execute the submitted candidate's regression cases without editing its source.
// Run from checkout 8821 with phpunit.pkg06b.xml. Only this process's fresh,
// uniquely prefixed synthetic schema is created and removed by the base harness.
require_once getcwd().'/tests/Feature/FleetAssets/Pkg06bAssetProfileTest.php';

final class PKG06BMainReviewTest extends \Tests\Feature\FleetAssets\Pkg06bAssetProfileTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main06b_review_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }
}
