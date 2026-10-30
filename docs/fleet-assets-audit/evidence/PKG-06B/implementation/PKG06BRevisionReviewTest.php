<?php

// Run the exact Profile source cases on a fresh owned schema, using the
// repository's PDO schema loader. No sibling pruning or preview mutation.
require_once getcwd().'/tests/Feature/FleetAssets/Pkg06bAssetProfileTest.php';

final class PKG06BRevisionReviewTest extends \Tests\Feature\FleetAssets\Pkg06bAssetProfileTest
{
    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }
}
