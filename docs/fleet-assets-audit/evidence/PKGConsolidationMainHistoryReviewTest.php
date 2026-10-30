<?php

require_once getcwd().'/tests/Feature/Reporting/OperationalReportsTest.php';

// Independent execution wrapper: preserve the reviewed report assertions while
// isolating the combined-candidate database and optional local SSR dependency.
final class PKGConsolidationMainHistoryReviewTest extends \Tests\Feature\Reporting\OperationalReportsTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main_combined_history_20260928';
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
        config(['inertia.ssr.enabled' => false]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Queue::fake()->except([\App\Jobs\GenerateOperationalReport::class]);
    }
}
