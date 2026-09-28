<?php

// Reuse the original failing privacy probe unchanged, independently of the fix.
require_once getcwd().'/docs/fleet-assets-audit/evidence/CONSOLIDATION-QA/LegacyMapProjectionProbeTest.php';

final class PKGConsolidationMainMapReviewTest extends \Tests\Feature\FleetAssets\LegacyMapProjectionProbeTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main_combined_map_20260928';
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
        \Illuminate\Support\Facades\Queue::fake();
        putenv('CONSOLIDATION_PROBE_OUTPUT=C:/Users/steph/Herd/oblivionfindings/docs/fleet-assets-audit/evidence/MAIN-combined-map-observations.jsonl');
    }
}
