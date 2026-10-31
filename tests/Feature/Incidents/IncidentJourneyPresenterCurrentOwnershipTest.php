<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyPresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** STAGED ONLY. Real old RR + independent committed move. No runner/admission claim. */
final class IncidentJourneyPresenterCurrentOwnershipTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }

    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
            || ! str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
            $this->markTestSkipped('Requires a separately admitted disposable MySQL test database.');
        }
        $this->migrateDisposableDatabase();
    }

    public static function ownershipMoves(): array
    {
        return ['shift fallback' => ['shift'], 'client fallback' => ['client'], 'canonical review precedence' => ['review']];
    }

    #[DataProvider('ownershipMoves')]
    public function test_old_rr_cannot_seed_standalone_original_from_stale_nested_ownership(string $family): void
    {
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $siteA = Site::factory()->create(['is_active' => true]);
        $siteB = Site::factory()->create(['is_active' => true]);
        $viewer = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [(int) $siteB->id], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null]);
        $viewer->permissionOverrides()->attach(Permission::where('key', 'incidents.viewAny')->sole(), ['allowed' => true]);
        $this->actingAs($viewer);
        $client = Client::factory()->create(['site_id' => $siteA->id]);
        $shift = $family === 'shift' ? Shift::factory()->create(['client_id' => $client->id, 'site_id' => $siteA->id]) : null;
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create(['client_id' => $client->id,
            'site_id' => null, 'shift_id' => $shift?->id, 'hs_event_id' => null, 'control_room_alert_id' => null,
            'type' => 'other', 'is_hr_confidential' => false, 'title' => 'Lawful no-CR current body']));
        $review = null;
        if ($family === 'review') {
            $review = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => ClientIncident::class, 'source_id' => $incident->id,
                'client_id' => $client->id, 'site_id' => $siteA->id, 'control_room_alert_id' => null, 'event_category' => HsEvent::CATEGORY_INCIDENT,
                'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $incident->id, HsEvent::CATEGORY_INCIDENT)]));
            ClientIncident::withoutEvents(fn () => $incident->update(['hs_event_id' => $review->id]));
        }
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before the independent connection moves ownership.');
        $mainName = (string) config('database.default');
        $moveName = 'journey_current_ownership_move';
        config(['database.connections.'.$moveName => config('database.connections.'.$mainName)]);
        DB::purge($moveName);
        $main = DB::connection(); $move = DB::connection($moveName);
        $originalIsolation = $main->selectOne('SELECT @@transaction_isolation AS level')->level;
        $table = match ($family) { 'shift' => 'shifts', 'client' => 'clients', 'review' => 'hs_events' };
        $id = match ($family) { 'shift' => $shift->id, 'client' => $client->id, 'review' => $review->id };
        $metadata = []; $statements = [];
        ClientIncident::retrieved(function (ClientIncident $row) use (&$metadata, $incident): void {
            $raw = $row->getRawOriginal();
            if ((int) $row->id === (int) $incident->id && array_key_exists('_effective_site_id', $raw)
                && array_key_exists('_journey_ownership_fingerprint', $raw)) {
                $metadata[] = ['site' => (int) $raw['_effective_site_id'], 'fingerprint' => $raw['_journey_ownership_fingerprint']];
            }
        });
        DB::listen(function ($query) use (&$statements): void { $statements[] = $query->sql; });
        try {
            $main->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $main->beginTransaction();
            // Establish the old nonlocking consistent snapshot BEFORE original presenter discovery.
            $this->assertSame((int) $siteA->id, (int) $main->table($table)->where('id', $id)->value('site_id'));
            $move->transaction(fn () => $move->table($table)->where('id', $id)->update(['site_id' => $siteB->id]));
            $this->assertSame((int) $siteB->id, (int) $move->table($table)->where('id', $id)->value('site_id'));
            $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, null, $review), $viewer);
            $this->assertNotEmpty($metadata, 'Must observe actual initial alias values, not only inspect a final DTO.');
            foreach ($metadata as $original) { $this->assertSame((int) $siteB->id, $original['site'], 'Every initial nested block must see the independently committed current ownership.'); }
            $this->assertSame('readable', $payload['linked_access']['incident']);
            $this->assertSame('Lawful no-CR current body', $payload['incident']['narrative']['title']);
            $this->assertFalse($viewer->canDo('controlRoom.alerts.view'));
            $this->assertNull($payload['control_room']);
            // Production code must not repair stale subqueries by changing the caller isolation or rebasing its snapshot.
            $this->assertSame((int) $siteA->id, (int) $main->table($table)->where('id', $id)->value('site_id'));
            $this->assertSame('REPEATABLE-READ', $main->selectOne('SELECT @@transaction_isolation AS level')->level);
            $discovery = collect($statements)->first(fn ($sql) => str_contains($sql, ' as _journey_ownership_fingerprint'));
            $this->assertNotNull($discovery);
            $this->assertSame(7, substr_count($discovery, 'FOR SHARE NOWAIT'), 'Both private raw expression builders contain seven explicitly current nested blocks.');
        } finally {
            while ($main->transactionLevel() > 0) { $main->rollBack(); }
            $allowedIsolation = ['REPEATABLE-READ' => 'REPEATABLE READ', 'READ-COMMITTED' => 'READ COMMITTED',
                'READ-UNCOMMITTED' => 'READ UNCOMMITTED', 'SERIALIZABLE' => 'SERIALIZABLE'];
            if (! isset($allowedIsolation[$originalIsolation])) { throw new \LogicException('Unexpected test isolation; do not compose arbitrary SQL.'); }
            $main->statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$allowedIsolation[$originalIsolation]);
            DB::disconnect($moveName); DB::purge($moveName);
        }
    }
}
