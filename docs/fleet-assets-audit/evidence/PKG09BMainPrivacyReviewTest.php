<?php

use App\Models\Asset;
use App\Models\FleetTrip;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Reporting\ReportRuns;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

final class PKG09BMainPrivacyReviewTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main_pkg09b_review_20260928';
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
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function assertSnapshotDeniedAfter(string $field): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $actor = User::factory()->create(['approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'admin')->value('id'));
        $site = Site::factory()->create();
        $asset = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id, 'home_site_id' => $site->id, 'client_id' => null]);
        $trip = FleetTrip::create(['asset_id' => $asset->id, 'started_at' => '2026-09-25 10:00:00', 'ended_at' => '2026-09-25 11:00:00', 'distance_km' => 42.5, 'duration_s' => 3600, 'status' => 'closed', 'is_personal' => false, 'consent_blocked' => false]);
        $definition = ['version' => 1, 'name' => 'Main privacy regression', 'source' => 'journeys',
            'columns' => ['reference', 'date', 'distance'], 'date_from' => '2026-09-20', 'date_to' => '2026-09-27',
            'site_ids' => [], 'resource_ids' => [], 'subject_id' => null, 'match' => 'all', 'filters' => [], 'groups' => ['date'],
            'measures' => [['id' => 'm1', 'label' => 'Rows', 'operation' => 'count', 'field' => null, 'formula' => null, 'decimals' => 0, 'unit' => 'count']],
            'layout' => 'table', 'sort' => 'group', 'direction' => 'asc', 'limit' => 20, 'precision' => 'redacted', 'comparison' => false];
        $runs = app(ReportRuns::class);
        $run = $runs->queue($actor, $definition, 'Synthetic privacy regression');
        $runs->execute($run);
        $run->refresh();
        $this->assertSame('ready', $run->status);
        $this->assertSame(1, $runs->result($actor, $run)['result']['row_count']);
        $trip->update([$field => true]);
        // Newly generated rows must hide the same canonical trip.
        $fresh = $runs->queue($actor, $definition, 'Synthetic current-authority control');
        $runs->execute($fresh);
        $this->assertSame(0, $runs->result($actor, $fresh->refresh())['result']['row_count']);
        $this->actingAs($actor)->getJson('/report-builder/runs/'.$run->id)->assertForbidden();
    }

    public function test_cached_journey_report_is_denied_after_trip_becomes_personal(): void
    {
        $this->assertSnapshotDeniedAfter('is_personal');
    }

    public function test_cached_journey_report_is_denied_after_trip_consent_is_blocked(): void
    {
        $this->assertSnapshotDeniedAfter('consent_blocked');
    }
}
