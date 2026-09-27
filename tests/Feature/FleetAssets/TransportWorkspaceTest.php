<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientTransportBooking;
use App\Models\FleetKeyLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetShiftHandover;
use App\Models\FleetVehicleBooking;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\Fleet\TransportWorkspacePresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransportWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->manager = $this->user($this->site);
    }

    public function test_request_assessment_retry_and_stale_edit_preserve_history(): void
    {
        $input = $this->demand();
        $id = $this->actingAs($this->manager)->postJson('/fleet-assets/transports/requests', $input)->assertOk()->json('id');
        $this->postJson('/fleet-assets/transports/requests', $input)->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/fleet-assets/transports/requests', [...$input, 'purpose' => 'Changed retry'])->assertConflict();
        $command = [...$input, 'command_key' => (string) Str::uuid(), 'action' => 'assess', 'expected_version' => 1];
        $this->postJson("/fleet-assets/transports/requests/{$id}/commands", $command)->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/fleet-assets/transports/requests/{$id}/commands", $command)->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/fleet-assets/transports/requests/{$id}/commands", [...$command, 'command_key' => (string) Str::uuid()])->assertConflict();
        $row = ClientTransportBooking::findOrFail($id);
        $this->assertSame('allocation', $row->workflow_state);
        $this->assertSame(2, $row->events()->count());
        $this->deleteJson("/operations/clients/{$this->client->id}/transport-bookings/{$id}")->assertStatus(409);
    }

    public function test_all_views_and_search_conceal_foreign_requests_and_direct_ids(): void
    {
        $local = $this->request();
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreign = ClientTransportBooking::create(['client_id' => $foreignClient->id, 'site_id' => $foreignSite->id,
            'purpose' => 'Private purpose', 'status' => 'requested', 'scheduled_at' => $local->scheduled_at, 'created_by' => $this->manager->id]);
        foreach (['overview', 'requests', 'planner', 'calendar', 'journeys', 'returns'] as $view) {
            $this->actingAs($this->manager)->get("/fleet-assets/transports/{$view}?from=2026-10-01&to=2026-10-01")
                ->assertOk()->assertInertia(fn (Assert $page) => $page->component('fleet-assets/transports/workspace')->has('records', 1)->where('records.0.id', $local->id));
        }
        foreach ([$foreign->id, 99999999] as $id) {
            $this->getJson("/fleet-assets/transports/requests/{$id}")->assertNotFound();
            $this->postJson("/fleet-assets/transports/requests/{$id}/commands", [])->assertNotFound();
            $this->getJson("/fleet-assets/transports/workspace/export?request_id={$id}")->assertNotFound();
        }
        $this->get('/fleet-assets/transports/overview?from=2026-10-01&search=Private')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records', 0));
        $this->get('/fleet-assets/transports/workspace/export?view=overview&from=2026-10-01')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_allocation_is_atomic_and_changes_keep_the_booking_identity(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'status' => 'active', 'seating_capacity' => 6]);
        $driver = $this->user($this->site);
        $room = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Reception key cabinet']);
        $payload = ['asset_id' => $asset->id, 'client_id' => $row->client_id, 'purpose' => $row->purpose,
            'starts_local' => '2026-10-01T09:00', 'ends_local' => '2026-10-01T10:00', 'driver_user_id' => $driver->id,
            'transport_request_id' => $row->id, 'transport_expected_version' => 1, 'pickup_site_id' => $this->site->id, 'return_site_id' => $this->site->id,
            'key_pickup_room_id' => $room->id, 'key_return_room_id' => $room->id, 'key_delivery_arrangement' => 'Driver collects and returns at reception'];
        $this->actingAs($this->manager)->postJson('/fleet-assets/bookings', [...$payload, 'client_id' => null])->assertNotFound();
        $this->postJson('/fleet-assets/bookings', [...$payload, 'key_return_room_id' => 999999])->assertNotFound();
        $this->assertNull($row->fresh()->fleet_booking_id);
        $key = (string) Str::uuid();
        $response = $this->withHeader('Idempotency-Key', $key)->postJson('/fleet-assets/bookings', $payload)->assertOk();
        $id = $response->json('booking.id');
        $this->assertSame($id, $row->fresh()->fleet_booking_id);
        $this->postJson('/fleet-assets/bookings', $payload)->assertOk()->assertJsonPath('booking.id', $id);
        $this->postJson('/fleet-assets/bookings', [...$payload, 'key_delivery_arrangement' => 'Different retry'])->assertConflict();
        $second = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'status' => 'active', 'seating_capacity' => 6]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson("/fleet-assets/bookings/{$id}", [...$payload,
            'asset_id' => $second->id, 'transport_expected_version' => 2, 'expected_version' => 1, 'reason' => 'Use the second vehicle'])->assertOk();
        $this->assertSame($id, $row->fresh()->fleet_booking_id);
        $this->assertEquals($second->id, FleetVehicleBooking::findOrFail($id)->asset_id);
        $this->assertSame(1, FleetVehicleBooking::count());
    }

    public function test_assigned_driver_can_see_linked_journey_when_requester_is_different_and_completion_requires_passenger_accounting(): void
    {
        $row = $this->request(true);
        $driver = $this->user($this->site);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id]);
        $booking = FleetVehicleBooking::create(['asset_id' => $asset->id, 'user_id' => $this->manager->id, 'driver_user_id' => $driver->id,
            'purpose' => 'Transport', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'checked_out']);
        $row->update(['fleet_booking_id' => $booking->id]);
        $journey = FleetResidentTransport::create(['asset_id' => $asset->id, 'booking_id' => $booking->id, 'transport_request_id' => $row->id,
            'resident_id' => $this->client->id, 'resident_name' => $this->client->full_name, 'site_id' => $this->site->id,
            'driver_user_id' => $driver->id, 'departed_at' => now()->subHour(), 'status' => 'in_progress', 'transport_type' => 'community', 'journey_uuid' => (string) Str::uuid(), 'version' => 1]);
        $this->assertSame($journey->id, app(ResidentTransportJourneyScope::class)->transportFor($driver, $journey->id)->id);
        $this->actingAs($driver)->postJson("/fleet-assets/transports/requests/{$row->id}/commands", [
            'action' => 'complete', 'command_key' => (string) Str::uuid(), 'expected_version' => 1])->assertUnprocessable();
        $this->assertSame('in_progress', $journey->fresh()->status);
        $this->assertNull($booking->fresh()->returned_at);
    }

    public function test_return_receipts_and_key_storage_are_independent_replay_safe_observations(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id]);
        $booking = FleetVehicleBooking::create(['asset_id' => $asset->id, 'user_id' => $this->manager->id, 'driver_user_id' => $this->manager->id,
            'purpose' => 'Transport', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'checked_out', 'odometer_out' => 100]);
        $row->update(['fleet_booking_id' => $booking->id]);
        FleetKeyLog::create(['asset_id' => $asset->id, 'booking_id' => $booking->id, 'site_id' => $this->site->id, 'user_id' => $this->manager->id, 'action' => 'checked_out', 'location' => 'with_driver']);
        $input = ['expected_version' => 1, 'odometer_in' => 110, 'condition_on_return' => 'No new concern', 'keys_received' => true];
        $this->actingAs($this->manager)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/fleet-assets/bookings/{$booking->id}/return", $input)->assertOk();
        $this->postJson("/fleet-assets/bookings/{$booking->id}/return", $input)->assertOk();
        $this->assertSame(2, FleetKeyLog::where('booking_id', $booking->id)->count());
        $this->assertNotSame('completed', $row->fresh()->status);
        $dto = app(TransportWorkspacePresenter::class)->row($this->manager, $row->fresh());
        $this->assertSame('keys', $dto['return_stage']);
        $this->assertCount(1, app(TransportWorkspacePresenter::class)->selected([$dto], 'returns', 'items'));
        $this->assertCount(1, app(TransportWorkspacePresenter::class)->selected([$dto], 'returns', 'keys'));
        $receipt = ['action' => 'receive_items', 'expected_version' => 2, 'command_key' => (string) Str::uuid(), 'items' => ['First aid kit']];
        $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $receipt)->assertOk();
        $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $receipt)->assertOk();
        $this->assertSame(1, $row->events()->where('action', 'receive_items')->count());
        $foreignRoom = SiteRoom::create(['site_id' => Site::factory()->create()->id, 'name' => 'Foreign storage']);
        $storage = ['action' => 'store_keys', 'expected_version' => 3, 'command_key' => (string) Str::uuid(), 'site_room_id' => $foreignRoom->id];
        $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $storage)->assertNotFound();
        $room = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Reception cabinet']);
        $storage['site_room_id'] = $room->id;
        $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $storage)->assertOk();
        $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $storage)->assertOk();
        $this->assertSame(3, FleetKeyLog::where('booking_id', $booking->id)->count());
        $this->assertSame('complete', app(TransportWorkspacePresenter::class)->row($this->manager, $row->fresh())['return_stage']);
        $this->assertNull($row->fresh()->journey);
    }

    public function test_a_changed_fleet_window_moves_the_transport_between_date_scopes(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id]);
        $booking = FleetVehicleBooking::create(['asset_id' => $asset->id, 'user_id' => $this->manager->id, 'purpose' => 'Moved appointment',
            'starts_at' => '2026-10-01 20:00:00', 'ends_at' => '2026-10-01 21:00:00', 'status' => 'pending']);
        $row->update(['fleet_booking_id' => $booking->id]);
        $this->actingAs($this->manager)->get('/fleet-assets/transports/overview?from=2026-10-01')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records', 0));
        $this->get('/fleet-assets/transports/overview?from=2026-10-02')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records', 1)->where('records.0.id', $row->id));
    }

    public function test_escort_capacity_is_validated_before_request_creation(): void
    {
        $this->actingAs($this->manager)->postJson('/fleet-assets/transports/requests', [...$this->demand(), 'escort_required' => true])->assertUnprocessable()->assertJsonValidationErrors('required_seats');
        $this->assertSame(0, ClientTransportBooking::count());
    }

    public function test_site_filter_values_are_parsed_and_scoped(): void
    {
        $row = $this->request();
        foreach (['all', (string) $this->site->id] as $site) {
            foreach (['overview', 'requests', 'planner', 'calendar', 'journeys', 'returns'] as $view) {
                $this->actingAs($this->manager)->get('/fleet-assets/transports/'.$view.'?'.http_build_query([
                    'from' => '2026-10-01', 'to' => '2026-10-01', 'site' => $site, 'search' => 'TR-'.$row->id,
                ]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('records', 1)->where('records.0.id', $row->id));
            }
        }
        $foreign = Site::factory()->create();
        $this->getJson('/fleet-assets/transports/overview?site='.$foreign->id)->assertNotFound();
        $this->getJson('/fleet-assets/transports/overview?site=invalid')->assertUnprocessable();
        $this->get('/fleet-assets/transports/workspace/export?view=overview&site=all&from=2026-10-01')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_calendar_scope_matches_all_visible_days_for_direct_links(): void
    {
        $this->request();
        $sunday = $this->request();
        $sunday->update(['scheduled_at' => CarbonImmutable::parse('2026-09-27 09:00', 'Pacific/Auckland')->utc(),
            'expected_return_at' => CarbonImmutable::parse('2026-09-27 10:00', 'Pacific/Auckland')->utc()]);
        foreach (['week' => ['2026-09-27', '2026-10-03', 2], 'day' => ['2026-10-01', '2026-10-01', 1],
            'month' => ['2026-09-27', '2026-11-07', 2], 'agenda' => ['2026-09-27', '2026-11-07', 2],
            'timeline' => ['2026-09-27', '2026-11-07', 2]] as $view => [$from, $to, $count]) {
            foreach ([[], ['from' => '2026-10-01', 'to' => '2026-10-01']] as $incomingRange) {
                $this->actingAs($this->manager)->get('/fleet-assets/transports/calendar?'.http_build_query([
                    ...$incomingRange, 'site' => $this->site->id, 'day' => '2026-10-01', 'queue' => $view,
                ]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.from', $from)
                    ->where('filters.to', $to)->where('calendarAnchor', '2026-10-01')->has('records', $count));
            }
        }
    }

    public function test_passenger_journey_completion_preserves_the_separate_vehicle_return(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'status' => 'active']);
        $booking = FleetVehicleBooking::create(['asset_id' => $asset->id, 'user_id' => $this->manager->id, 'driver_user_id' => $this->manager->id,
            'purpose' => 'Passenger transport', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'checked_out']);
        $row->update(['fleet_booking_id' => $booking->id, 'driver_id' => $this->manager->id]);
        $this->actingAs($this->manager);
        foreach (['depart', 'arrive', 'account', 'complete'] as $index => $action) {
            $command = ['action' => $action, 'expected_version' => $index + 1, 'command_key' => (string) Str::uuid()];
            $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $command)->assertOk();
            $this->postJson("/fleet-assets/transports/requests/{$row->id}/commands", $command)->assertOk();
        }
        $this->assertSame('completed', $row->fresh()->journey->status);
        $this->assertNotNull($row->fresh()->journey->passengers_accounted_at);
        $this->assertSame(1, FleetResidentTransport::where('transport_request_id', $row->id)->count());
        $this->assertSame('checked_out', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->returned_at);
        $this->assertSame(4, $row->events()->count());
    }

    public function test_booking_linked_shift_handover_retries_do_not_create_duplicate_custody_records(): void
    {
        $incoming = $this->user($this->site);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id]);
        $booking = FleetVehicleBooking::create(['asset_id' => $asset->id, 'user_id' => $this->manager->id,
            'purpose' => 'Transport', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => 'returned', 'returned_at' => now()]);
        $input = ['booking_id' => $booking->id, 'asset_id' => $asset->id, 'request_key' => (string) Str::uuid(),
            'incoming_user_id' => $incoming->id, 'exterior_condition' => 'good', 'interior_condition' => 'clean', 'keys_present' => true];
        $this->actingAs($this->manager)->post('/fleet-assets/handovers', $input)->assertRedirect();
        $this->post('/fleet-assets/handovers', $input)->assertRedirect();
        $this->assertSame(1, FleetShiftHandover::where('booking_id', $booking->id)->count());
        $this->postJson('/fleet-assets/handovers', [...$input, 'notes' => 'Different retry'])->assertConflict();
    }

    public function test_linked_booking_source_page_hands_actions_to_current_transport_record(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id]);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id,
            'pickup_site_id' => $this->site->id, 'return_site_id' => $this->site->id, 'user_id' => $this->manager->id]);
        $row->update(['fleet_booking_id' => $booking->id]);
        foreach (['pending', 'approved', 'checked_out', 'returned', 'cancelled'] as $status) {
            $booking->update(['status' => $status]);
            $this->actingAs($this->manager)->get("/fleet-assets/bookings/{$booking->id}")->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/bookings/show')
                    ->where('transport.href', "/fleet-assets/transports/requests/{$row->id}"));
        }
        $row->update(['fleet_booking_id' => null]);
        $this->get("/fleet-assets/bookings/{$booking->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('transport', null));
    }

    public function test_time_only_move_and_reviewed_undo_preserve_canonical_booking_fields(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'status' => 'active', 'seating_capacity' => 6]);
        $driver = $this->user($this->site);
        $room = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Locked key cabinet']);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id,
            'pickup_site_id' => $this->site->id, 'return_site_id' => $this->site->id, 'user_id' => $this->manager->id,
            'driver_user_id' => $driver->id, 'starts_at' => '2026-09-30 20:00:00', 'ends_at' => '2026-09-30 21:00:00',
            'status' => 'pending', 'purpose' => 'Distinct Fleet purpose', 'destination' => 'Distinct Fleet destination',
            'passengers' => 3, 'pickup_arrangement' => 'Distinct Fleet arrangement', 'notes' => 'Retain these source notes', 'lock_version' => 1]);
        $row->update(['fleet_booking_id' => $booking->id, 'lock_version' => 1, 'key_pickup_room_id' => $room->id,
            'key_return_room_id' => $room->id, 'key_delivery_arrangement' => 'Driver collects from the cabinet']);
        $fields = ['asset_id', 'user_id', 'driver_user_id', 'purpose', 'destination', 'passengers', 'pickup_arrangement', 'notes', 'pickup_site_id', 'return_site_id'];
        $before = $booking->fresh()->only($fields);
        $payload = ['reschedule_only' => true, 'transport_expected_version' => 1, 'expected_version' => 1,
            'starts_local' => '2026-10-02T09:00', 'ends_local' => '2026-10-02T10:00', 'starts_offset' => '+13:00', 'ends_offset' => '+13:00', 'reason' => 'Move to Friday'];
        $this->actingAs($this->manager)->withHeader('Idempotency-Key', (string) Str::uuid());
        $this->putJson("/fleet-assets/bookings/{$booking->id}", $payload)->assertOk();
        $this->assertSame($before, $booking->fresh()->only($fields));
        $this->assertSame('2026-10-01 20:00:00', $booking->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->putJson("/fleet-assets/bookings/{$booking->id}", $payload)->assertOk();
        $this->assertSame(2, $booking->fresh()->lock_version);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson("/fleet-assets/bookings/{$booking->id}", $payload)->assertConflict();
        $undo = [...$payload, 'transport_expected_version' => $row->fresh()->lock_version, 'expected_version' => 2,
            'starts_local' => '2026-10-01T09:00', 'ends_local' => '2026-10-01T10:00', 'reason' => 'Reviewed undo'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson("/fleet-assets/bookings/{$booking->id}", $undo)->assertOk();
        $this->assertSame($before, $booking->fresh()->only($fields));
        $this->assertSame('2026-09-30 20:00:00', $booking->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_linked_source_commands_keep_retry_version_and_key_requirements(): void
    {
        $row = $this->request(true);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'status' => 'active', 'seating_capacity' => 6]);
        $driver = $this->user($this->site);
        $room = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Key cabinet']);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id,
            'pickup_site_id' => $this->site->id, 'return_site_id' => $this->site->id, 'user_id' => $this->manager->id,
            'driver_user_id' => $driver->id, 'starts_at' => '2026-09-30 20:00:00', 'ends_at' => '2026-09-30 21:00:00', 'status' => 'approved', 'lock_version' => 1]);
        $row->update(['fleet_booking_id' => $booking->id, 'key_pickup_room_id' => $room->id, 'key_return_room_id' => $room->id, 'key_delivery_arrangement' => 'Collect from reception']);
        $this->actingAs($this->manager)->postJson("/fleet-assets/bookings/{$booking->id}/checkout", [])->assertUnprocessable()->assertJsonValidationErrors(['command_key', 'expected_version']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/fleet-assets/bookings/{$booking->id}/checkout", ['expected_version' => 1, 'keys_handed' => false])->assertUnprocessable();
        $this->assertSame('approved', $booking->fresh()->status);
        $this->assertSame(1, $booking->fresh()->lock_version);
        $cancel = ['expected_version' => 1, 'reason' => 'Transport is no longer required'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/fleet-assets/bookings/{$booking->id}/cancel", $cancel)->assertOk();
        $this->postJson("/fleet-assets/bookings/{$booking->id}/cancel", $cancel)->assertOk();
        $this->assertSame(2, $booking->fresh()->lock_version);
        $this->postJson("/fleet-assets/bookings/{$booking->id}/cancel", [...$cancel, 'reason' => 'Different payload'])->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/fleet-assets/bookings/{$booking->id}/cancel", $cancel)->assertConflict();
        $unlinked = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id, 'pickup_site_id' => $this->site->id, 'return_site_id' => $this->site->id, 'user_id' => $this->manager->id, 'status' => 'pending']);
        $this->flushHeaders()->postJson("/fleet-assets/bookings/{$unlinked->id}/cancel", ['reason' => 'Unlinked source regression'])->assertOk();
        $this->assertSame('cancelled', $unlinked->fresh()->status);
    }

    public function test_location_choices_and_address_search_keep_transport_scope_and_provider_context_separate(): void
    {
        Http::preventStrayRequests();
        config(['fleet.maps.address_search_cache_store' => 'database', 'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'mysql']);
        $this->site->update(['address_line_1' => '10 Example Road', 'city' => 'Auckland']);
        $foreign = Site::factory()->create(['name' => 'Hidden place', 'address_line_1' => 'Private address']);
        $foreignClient = Client::factory()->create(['site_id' => $foreign->id]);
        $this->actingAs($this->manager)->getJson('/fleet-assets/transports/workspace/options')
            ->assertOk()->assertJsonFragment(['id' => $this->site->id, 'name' => $this->site->name, 'address' => implode(', ', array_filter(['10 Example Road', $this->site->address_line_2, $this->site->suburb, 'Auckland', $this->site->postcode]))])
            ->assertDontSee('Private address')->assertHeader('Cache-Control', 'no-store, private');
        $url = '/fleet-assets/transports/workspace/address-search';
        $this->postJson($url, ['client_id' => $foreignClient->id, 'q' => 'Hidden place'])->assertNotFound();
        $this->postJson($url, ['client_id' => $this->client->id, 'q' => str_repeat('x', 201)])->assertUnprocessable();
        Http::assertNothingSent();
        $query = 'Public library '.Str::uuid();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Public library, Auckland', 'lat' => '-36.85', 'lon' => '174.76', 'address' => []],
        ])]);
        $this->postJson($url, ['client_id' => $this->client->id, 'q' => $query, 'passenger_notes' => 'Never forward'])
            ->assertOk()->assertJsonPath('results.0.display_name', 'Public library, Auckland')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => $request['q'] === $query && array_keys($request->data()) === ['q', 'format', 'addressdetails', 'limit', 'countrycodes']);
        $this->assertSame(1, count(Http::recorded()));
    }

    public function test_address_search_distinguishes_provider_failure(): void
    {
        config(['fleet.maps.address_search_cache_store' => 'database', 'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'mysql']);
        Http::preventStrayRequests();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 503)]);
        $this->actingAs($this->manager)->postJson('/fleet-assets/transports/workspace/address-search', ['client_id' => $this->client->id, 'q' => 'Unavailable '.Str::uuid()])
            ->assertStatus(503)->assertJsonMissing(['results' => []]);
    }

    public function test_address_search_discards_results_after_access_is_revoked(): void
    {
        config(['fleet.maps.address_search_cache_store' => 'database', 'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'mysql']);
        Http::preventStrayRequests();
        Http::fake(function () {
            $this->client->update(['site_id' => Site::factory()->create()->id]);

            return Http::response([['display_name' => 'Discard these results', 'address' => []]]);
        });
        $this->actingAs($this->manager)->postJson('/fleet-assets/transports/workspace/address-search', ['client_id' => $this->client->id, 'q' => 'Revoked '.Str::uuid()])
            ->assertNotFound()->assertDontSee('Discard these results');
    }

    private function request(bool $assessed = false): ClientTransportBooking
    {
        return ClientTransportBooking::create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'purpose' => 'Community appointment', 'pickup_location' => 'Reception', 'destination' => 'Community centre',
            'scheduled_at' => '2026-09-30 20:00:00', 'expected_return_at' => '2026-09-30 21:00:00',
            'required_seats' => 2, 'wheelchair_required' => false, 'escort_required' => false, 'return_trip' => true,
            'equipment_required' => ['First aid kit'], 'status' => 'requested', 'created_by' => $this->manager->id,
            'workflow_state' => $assessed ? 'allocation' : 'assessment', 'assessed_at' => $assessed ? now() : null,
            'assessed_by' => $assessed ? $this->manager->id : null]);
    }

    private function demand(): array
    {
        return ['client_id' => $this->client->id, 'purpose' => 'Community appointment', 'pickup_location' => 'Reception',
            'destination' => 'Community centre', 'starts_local' => '2026-10-01T09:00', 'ends_local' => '2026-10-01T10:00',
            'required_seats' => 2, 'wheelchair_required' => false, 'escort_required' => false, 'return_trip' => true,
            'equipment_required' => [], 'command_key' => (string) Str::uuid()];
    }

    private function user(Site $site): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'is_active' => true,
            'start_date' => today()->subYear(), 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);
        foreach (['fleet.viewAny', 'fleet.manage', 'clients.viewAny', 'clients.update'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'fleet', 'module' => 'fleet']);
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        return $user;
    }
}
