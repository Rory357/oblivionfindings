<?php

require_once getcwd().'/tests/Feature/FleetAssets/FleetOverviewContractTest.php';

/** Independently rerun the published Overview contract against current Main. */
final class PKG09AMainCurrentOverviewTest extends Tests\Feature\FleetAssets\FleetOverviewContractTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main_pkg09a_review_20260928';
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
        // These HTTP/service contracts do not run the independent local SSR server.
        // Keep every product assertion and external-request denial intact.
        config(['inertia.ssr.enabled' => false]);
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Queue::fake();
    }
}
