<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\FleetTrip;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Fleet\Data\VehicleReadinessAssessment;
use App\Services\Fleet\FleetOverviewService;
use App\Services\Fleet\VehicleBookingAccessService;
use App\Services\Fleet\VehicleLocationService;
use App\Services\Fleet\VehicleReadinessService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Single operating organisation: records are bounded by role and approved Site. */
class FleetOverviewContractTest extends TestCase
{
    use RefreshDatabase;

    private bool $rbacSeeded = false;

    private function viewer(Site $site, array $permissions): User
    {
        // The seeder reconciles roles on existing users. Seed once so creating
        // a second viewer cannot silently grant the first viewer new access.
        if (! $this->rbacSeeded) {
            $this->seed(RbacSeeder::class);
            $this->rbacSeeded = true;
        }
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true,
            'start_date' => now()->subMonth(), 'end_date' => null,
        ]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key],
                ['description' => $key, 'group' => explode('.', $key)[0]]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    public function test_overview_projects_only_authorised_site_records_and_no_resident_or_finance_payload(): void
    {
        $local = Site::factory()->create(['name' => 'Approved Site']);
        $foreign = Site::factory()->create(['name' => 'Other Site']);
        $viewer = $this->viewer($local, ['fleet.viewAny', 'assets.viewAny']);
        $one = Asset::factory()->vehicle()->forSite($local)->create(['name' => 'Local Van']);
        $other = Asset::factory()->vehicle()->forSite($foreign)->create(['name' => 'Foreign Van']);
        FleetVehicleBooking::factory()->create([
            'asset_id' => $other->id, 'status' => 'checked_out',
            'starts_at' => now()->subDay(), 'ends_at' => now()->subHour(),
        ]);

        $this->actingAs($viewer)->get('/fleet-assets')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('overview.vehicles', 1)
                ->where('overview.vehicles.0.id', $one->id)
                ->has('overview.bookings', 0)
                ->has('overview.attention', 1)
                ->where('overview.attention.0.category', 'evidence')
                ->where('overview.attention.0.resource', 'Local Van')
                ->missing('stats')->missing('recent_alerts')
                ->missing('today_outings')->missing('recent_signals')
            );
    }

    public function test_all_site_vehicle_read_does_not_open_foreign_booking_or_position(): void
    {
        $local = Site::factory()->create();
        $foreign = Site::factory()->create();
        $viewer = $this->viewer($local, ['fleet.viewAny', 'fleet.vehicles.viewAllSites']);
        Asset::factory()->vehicle()->forSite($local)->create(['name' => 'Approved Fleet Van']);
        $other = Asset::factory()->vehicle()->forSite($foreign)->create(['name' => 'Foreign Fleet Van']);
        FleetVehicleBooking::factory()->create([
            'asset_id' => $other->id, 'status' => 'checked_out',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);

        $this->actingAs($viewer)->get('/fleet-assets')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overview.vehicles', 2)
                ->has('overview.bookings', 0)
                ->where('overview.vehicles.1.availability', 'Unknown')
                ->where('overview.vehicles.1.readiness_note', 'Site record access limited')
                ->where('overview.vehicles.1.location', null)
            );
    }

    public function test_assigned_asset_permission_does_not_open_wider_fleet(): void
    {
        $local = Site::factory()->create();
        $viewer = $this->viewer($local, ['assets.viewAssigned']);
        Asset::factory()->vehicle()->forSite($local)->create();

        $this->actingAs($viewer)->get('/fleet-assets')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overview.vehicles', 0)
                ->has('overview.bookings', 0)
                ->where('overview.can.fleet', false)
            );
    }

    public function test_booking_windows_use_utc_bounds_and_count_a_23_hour_auckland_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 09:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['fleet.viewAny']);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $day = CarbonImmutable::parse('2026-09-27 00:00', 'Pacific/Auckland');
        $booking = FleetVehicleBooking::factory()->create([
            'asset_id' => $vehicle->id, 'status' => 'approved',
            'starts_at' => $day->utc(), 'ends_at' => $day->addDay()->utc(),
        ]);
        FleetVehicleBooking::factory()->create([
            'asset_id' => $vehicle->id, 'status' => 'approved',
            'starts_at' => $day->addDays(7)->utc(), 'ends_at' => $day->addDays(7)->addHour()->utc(),
        ]);
        $data = app(FleetOverviewService::class)->present($viewer);
        $this->assertSame([$booking->id], $data['bookings']->pluck('id')->all());
        $hours = collect($data['booked_hours'])->where('date', '2026-09-27')->first();
        $this->assertEquals(23, $hours['hours']);
        $this->assertSame($vehicle->id, $hours['asset_id']);
    }

    public function test_availability_accounts_for_current_reservations_hidden_bookings_and_unavailable_periods(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 09:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $viewer = $this->viewer($site, ['fleet.viewAny']);
        $vehicles = collect(range(0, 4))->map(fn ($i) => Asset::factory()->vehicle()->forSite($site)->create(['name' => 'Van '.$i]));
        $this->mock(VehicleReadinessService::class)->shouldReceive('projections')->andReturn(
            $vehicles->mapWithKeys(fn ($vehicle) => [$vehicle->id => $this->ready()])->all()
        );
        foreach ([1, 2, 3] as $index) {
            FleetVehicleBooking::factory()->create([
                'asset_id' => $vehicles[$index]->id, 'status' => $index === 3 ? 'checked_out' : 'approved',
                'pickup_site_id' => $index === 2 ? $foreign->id : $site->id,
                'starts_at' => now()->subHours(2), 'ends_at' => $index === 3 ? now()->subHour() : now()->addHour(),
            ]);
        }
        FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $vehicles[4]->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'state' => 'active', 'reason' => 'Calendar block', 'created_by_user_id' => $viewer->id,
        ]);
        $data = app(FleetOverviewService::class)->present($viewer);
        $this->assertSame(['Available now', 'Unknown', 'Unknown', 'In use', 'Unknown'], $data['vehicles']->pluck('availability')->all());
        $this->assertNotContains($vehicles[2]->id, $data['bookings']->pluck('asset_id')->all());
        $this->assertCount(1, $data['attention']);
        $this->assertSame('returns', $data['attention'][0]['category']);
    }

    public function test_a_failed_booking_feed_does_not_clear_a_known_hold_or_claim_availability(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['fleet.viewAny']);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $this->mock(VehicleReadinessService::class)->shouldReceive('projections')->andReturn([$vehicle->id => $this->ready([12])]);
        $bookings = \Mockery::mock(VehicleBookingAccessService::class, [app(SecurityDevicesAccessService::class)])->makePartial();
        $bookings->shouldReceive('accessibleBookings')->andThrow(new \RuntimeException('Test feed unavailable'));
        $this->app->instance(VehicleBookingAccessService::class, $bookings);
        $data = app(FleetOverviewService::class)->present($viewer);
        $this->assertSame('Restricted', $data['vehicles'][0]['availability']);
        $this->assertSame('unavailable', collect($data['sources'])->firstWhere('name', 'Bookings')['state']);
        $this->assertNull(collect($data['sources'])->firstWhere('name', 'Bookings')['count']);
        $this->assertSame('restricted', $data['attention'][0]['category']);
    }

    public function test_overview_positions_withhold_consent_and_personal_trips(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['fleet.viewAny']);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $state = FleetVehicleStateSnapshot::query()->create([
            'asset_id' => $vehicle->id, 'latitude' => -41.28, 'longitude' => 174.77,
            'last_seen_at' => now()->subMinute(), 'status' => 'online', 'consent_blocked' => false,
        ]);
        $service = app(VehicleLocationService::class);
        $this->assertNotNull($service->lastPermittedPosition($viewer, $vehicle));
        $state->update(['consent_blocked' => true]);
        $this->assertNull($service->lastPermittedPosition($viewer, $vehicle));
        $state->update(['consent_blocked' => false]);
        FleetTrip::query()->create([
            'asset_id' => $vehicle->id, 'started_at' => now()->subHour(), 'ended_at' => now(),
            'status' => 'closed', 'is_personal' => true, 'consent_blocked' => false,
        ]);
        $this->assertNull($service->lastPermittedPosition($viewer, $vehicle));
    }

    public function test_receipts_are_pending_only_for_manageable_active_assignments_and_confirmation_is_audited(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $viewer = $this->viewer($site, ['assets.viewAny', 'assets.assignments.manage']);
        $asset = Asset::factory()->forSite($site)->create(['name' => 'Local Kit']);
        $other = Asset::factory()->forSite($foreign)->create(['name' => 'Hidden Kit']);
        $pending = AssetAssignment::query()->create(['asset_id' => $asset->id, 'assignee_type' => 'staff',
            'assignee_id' => $viewer->id, 'assigned_at' => now()->subHour()]);
        $hidden = AssetAssignment::query()->create(['asset_id' => $other->id, 'assignee_type' => 'staff',
            'assignee_id' => $viewer->id, 'assigned_at' => now()->subHour()]);

        $feed = app(FleetOverviewService::class)->present($viewer)['receipts'];
        $this->assertSame('loaded', $feed['state']);
        $this->assertSame(1, $feed['count']);
        $this->assertSame(1, app(FleetOverviewService::class)->present($viewer, $site->id)['receipts']['count']);
        $this->assertSame($pending->id, $feed['rows'][0]['id']);
        $this->assertArrayNotHasKey('assignee_id', $feed['rows'][0]);

        $this->actingAs($viewer)->post("/assets/{$other->id}/assignments/{$hidden->id}/confirm-receipt", [
            'verified_received' => true,
        ])->assertNotFound();
        $this->assertNull($hidden->fresh()->receipt_confirmed_at);

        $this->actingAs($viewer)->post("/assets/{$asset->id}/assignments/{$pending->id}/confirm-receipt", [
            'verified_received' => true, 'receipt_note' => 'Checked handover record.',
        ])->assertRedirect();
        $this->assertNotNull($pending->fresh()->receipt_confirmed_at);
        $this->assertSame($viewer->id, $pending->fresh()->receipt_confirmed_by_user_id);
        $this->assertSame('Checked handover record.', $pending->fresh()->receipt_note);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'assets.assignment.receipt_confirmed', 'auditable_id' => $asset->id, 'user_id' => $viewer->id,
        ]);
        $this->actingAs($viewer)->get("/fleet-assets/assets/{$asset->id}?tab=assignments")
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/assets/show')
                ->where('can_manage_assignments', true)
                ->where('asset.current_assignment.id', $pending->id)
                ->where('asset.current_assignment.receipt_note', 'Checked handover record.'));
        $this->assertSame(0, app(FleetOverviewService::class)->present($viewer)['receipts']['count']);

        $this->actingAs($viewer)->post("/assets/{$asset->id}/assignments/{$pending->id}/confirm-receipt", [
            'verified_received' => true,
            'receipt_note' => 'An attempted replacement note.',
        ])->assertRedirect();
        $this->assertSame('Checked handover record.', $pending->fresh()->receipt_note);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'assets.assignment.receipt_confirmed')->where('auditable_id', $asset->id)->count());
    }

    public function test_receipt_queue_pages_and_tracks_approved_site_and_search(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->viewer($site, ['assets.viewAny', 'assets.assignments.manage']);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $ids = [];
        foreach (range(1, 7) as $number) {
            $asset = Asset::factory()->forSite($site)->create(['name' => "Queue Kit {$number}"]);
            $ids[] = AssetAssignment::query()->create(['asset_id' => $asset->id, 'assignee_type' => 'staff',
                'assignee_id' => $viewer->id, 'assigned_at' => now()->subHours(8 - $number)])->id;
        }
        $other = Asset::factory()->forSite($otherSite)->create(['name' => 'Other Site Kit']);
        AssetAssignment::query()->create(['asset_id' => $other->id, 'assignee_type' => 'staff',
            'assignee_id' => $viewer->id, 'assigned_at' => now()->subMinutes(10)]);

        $first = app(FleetOverviewService::class)->present($viewer, $site->id)['receipts'];
        $second = app(FleetOverviewService::class)->present($viewer, $site->id, '', 2)['receipts'];
        $this->assertSame(7, $first['count']);
        $this->assertSame(2, $first['pages']);
        $this->assertSame($ids[0], $first['rows'][0]['id']);
        $this->assertCount(5, $first['rows']);
        $this->assertSame(2, $second['page']);
        $this->assertSame([$ids[5], $ids[6]], $second['rows']->pluck('id')->all());
        $this->assertSame(1, app(FleetOverviewService::class)->present($viewer, $site->id, 'Kit 7')['receipts']['count']);
        $this->assertSame(1, app(FleetOverviewService::class)->present($viewer, $otherSite->id)['receipts']['count']);
        $this->actingAs($viewer)->get("/fleet-assets?site={$site->id}&q=Kit%207&receipt_page=2")
            ->assertInertia(fn (Assert $page) => $page->where('overview.receipts.count', 1)
                ->where('overview.receipts.page', 1)->has('overview.receipts.rows', 1));
    }

    public function test_future_assignment_cannot_be_receipt_confirmed_or_enter_pending_queue(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['assets.viewAny', 'assets.assignments.manage']);
        $asset = Asset::factory()->forSite($site)->create();
        $future = AssetAssignment::query()->create(['asset_id' => $asset->id, 'assignee_type' => 'staff',
            'assignee_id' => $viewer->id, 'assigned_at' => now()->addDay()]);
        $this->assertSame(0, app(FleetOverviewService::class)->present($viewer)['receipts']['count']);
        $this->actingAs($viewer)->post("/assets/{$asset->id}/assignments/{$future->id}/confirm-receipt", [
            'verified_received' => true,
        ])->assertSessionHasErrors('assignment');
        $this->assertNull($future->fresh()->receipt_confirmed_at);
    }

    public function test_released_assignment_cannot_be_confirmed_and_viewer_without_permission_gets_no_receipt_count(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['assets.viewAny']);
        $manager = $this->viewer($site, ['assets.viewAny', 'assets.assignments.manage']);
        $asset = Asset::factory()->forSite($site)->create();
        $assignment = AssetAssignment::query()->create(['asset_id' => $asset->id, 'assignee_type' => 'staff',
            'assignee_id' => $viewer->id, 'assigned_at' => now()->subDay(), 'released_at' => now()]);
        $feed = app(FleetOverviewService::class)->present($viewer)['receipts'];
        $this->assertSame('no_access', $feed['state']);
        $this->assertNull($feed['count']);
        $this->assertSame([], $feed['rows']);
        $this->assertSame(0, app(FleetOverviewService::class)->present($manager)['receipts']['count']);
        $this->actingAs($manager)->post("/assets/{$asset->id}/assignments/{$assignment->id}/confirm-receipt", [
            'verified_received' => true,
        ])->assertSessionHasErrors('assignment');
        $this->assertNull($assignment->fresh()->receipt_confirmed_at);
        $this->actingAs($viewer)->post("/assets/{$asset->id}/assignments/{$assignment->id}/confirm-receipt", [
            'verified_received' => true,
        ])->assertForbidden();
    }

    public function test_asset_read_without_assignment_authority_hides_other_recipient_details(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['assets.viewAny']);
        $recipient = User::factory()->create();
        $asset = Asset::factory()->forSite($site)->create();
        AssetAssignment::query()->create(['asset_id' => $asset->id, 'assignee_type' => 'staff',
            'assignee_id' => $recipient->id, 'assigned_at' => now()->subHour(),
            'purpose' => 'Private purpose', 'receipt_note' => 'Private handover note']);

        $this->actingAs($viewer)->get("/fleet-assets/assets/{$asset->id}?tab=assignments")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_manage_assignments', false)
                ->where('asset.current_assignment.assignee.id', null)
                ->where('asset.current_assignment.assignee.name', 'Recipient details restricted')
                ->where('asset.current_assignment.purpose', null)
                ->where('asset.current_assignment.receipt_note', null));
    }

    public function test_assignment_manager_cannot_read_moved_recipient_or_private_receipt_fields(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $manager = $this->viewer($site, ['assets.viewAny', 'assets.assignments.manage', 'staff.viewAny', 'hazards.view']);
        $recipient = $this->viewer($site, ['assets.viewAny']);
        $asset = Asset::factory()->forSite($site)->create();
        $assignment = AssetAssignment::query()->create(['asset_id' => $asset->id, 'assignee_type' => 'staff',
            'assignee_id' => $recipient->id, 'assigned_at' => now()->subHour(),
            'purpose' => 'Private purpose', 'receipt_note' => 'Private handover note',
            'receipt_confirmed_at' => now()->subMinute(), 'receipt_confirmed_by_user_id' => $recipient->id]);
        $recipient->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id]);

        $this->actingAs($manager)->get("/fleet-assets/assets/{$asset->id}?tab=assignments")
            ->assertInertia(fn (Assert $page) => $page
                ->where('asset.current_assignment.id', $assignment->id)
                ->where('asset.current_assignment.recipient_visible', false)
                ->where('asset.current_assignment.assignee.id', null)
                ->where('asset.current_assignment.assignee.name', 'Recipient details restricted')
                ->where('asset.current_assignment.purpose', null)
                ->where('asset.current_assignment.receipt_note', null)
                ->where('asset.current_assignment.receipt_confirmed_by', 'Staff details restricted'));
    }

    public function test_saved_views_persist_on_the_account_and_reject_foreign_site_or_duplicate_names(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $viewer = $this->viewer($site, ['assets.viewAny']);
        $filters = [
            'view' => 'overview', 'site' => (string) $site->id, 'period' => 'week', 'q' => '',
            'attention' => 'all', 'due' => 'all', 'sort' => 'due', 'availability' => 'all',
            'mapType' => 'all', 'mapFresh' => 'all', 'agendaKind' => 'all', 'agendaDay' => 'all',
        ];
        $view = ['name' => 'My Site', 'filters' => $filters];
        $this->actingAs($viewer)->put('/settings/ui-preferences/fleet.overview.saved-views', [
            'value' => [$view],
        ])->assertSessionHasNoErrors();
        $this->assertEquals([$view], UserUiPreference::query()->where('user_id', $viewer->id)
            ->where('key', 'fleet.overview.saved-views')->sole()->value);
        $this->actingAs($viewer)->get('/fleet-assets')->assertInertia(fn (Assert $page) => $page
            ->has('saved_views', 1)->where('saved_views.0.name', 'My Site'));
        $otherViewer = $this->viewer($site, ['assets.viewAny']);
        $this->actingAs($otherViewer)->get('/fleet-assets')->assertInertia(fn (Assert $page) => $page
            ->has('saved_views', 0));

        $this->actingAs($viewer)->put('/settings/ui-preferences/fleet.overview.saved-views', [
            'value' => [$view, ['name' => 'my site', 'filters' => $filters]],
        ])->assertSessionHasErrors('value.1.name');
        $this->actingAs($viewer)->put('/settings/ui-preferences/fleet.overview.saved-views', [
            'value' => [['name' => 'Foreign', 'filters' => [...$filters, 'site' => (string) $foreign->id]]],
        ])->assertSessionHasErrors('value.0.filters.site');
        $this->assertEquals([$view], UserUiPreference::query()->where('user_id', $viewer->id)
            ->where('key', 'fleet.overview.saved-views')->sole()->value);
        $this->actingAs($viewer)->put('/settings/ui-preferences/fleet.overview.saved-views', [
            'value' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame([], UserUiPreference::query()->where('user_id', $viewer->id)
            ->where('key', 'fleet.overview.saved-views')->sole()->value);
    }

    private function ready(array $holds = []): VehicleReadinessAssessment
    {
        return new VehicleReadinessAssessment('ready', $holds === [], [], CarbonImmutable::now(), [], null, null, null, $holds, [], 'test');
    }
}
