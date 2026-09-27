<?php

require_once getcwd().'/tests/Feature/FleetAssets/Pkg06bAssetProfileTest.php';

/** Runs the candidate's real endpoint regressions in Main's own disposable database. */
final class PKG03MainAssetFinanceReviewTest extends Tests\Feature\FleetAssets\Pkg06bAssetProfileTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main_pkg03_review_20260928';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Queue::fake();
    }
}
