<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerCheckIn;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerCoverageService;
use App\Services\HealthSafety\LoneWorkerSessionAccessService;
use App\Services\HealthSafety\LoneWorkerSessionScope;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LoneWorkerAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $manager;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config()->set('app.worker_timezone', 'Pacific/Auckland');
        $this->travelTo(Carbon::parse('2026-10-07T12:30:00Z'));
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->manager = $this->staff($this->site, ['hazards.view', 'hazards.manage']);
        $this->worker = $this->staff($this->site);
    }

    private function staff(Site $site, array $permissions = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        $this->grant($user, $permissions);

        return $user;
    }

    private function grant(User $user, array $permissions): void
    {
        foreach (Permission::whereIn('key', $permissions)->get() as $permission) {
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
    }

    private function sessionSource(array $extra = []): LoneWorkerSession
    {
        return LoneWorkerSession::createQuietly(array_merge(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'started_at' => now()->subMinutes(5), 'expected_end_at' => now()->addHour(), 'check_in_interval_minutes' => 30, 'status' => 'active'], $extra));
    }

    private function shift(User $worker, bool $required = true, array $extra = []): Shift
    {
        return Shift::createQuietly(array_merge(['user_id' => $worker->id, 'site_id' => $this->site->id, 'client_id' => $this->client->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'status' => 'in_progress', 'is_lone_worker' => $required, 'is_on_call' => false, 'created_by' => $this->manager->id], $extra));
    }

    private function access(): LoneWorkerSessionAccessService
    {
        return app(LoneWorkerSessionAccessService::class);
    }

    private function coverage(): LoneWorkerCoverageService
    {
        return app(LoneWorkerCoverageService::class);
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected current source authority denial.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 404]);
        }
    }

    public function test_client_pickers_require_independent_current_client_read_and_site_authority(): void
    {
        $this->assertCount(0, $this->access()->pickerOptions($this->manager)['clients']);
        $this->grant($this->manager, ['clients.viewAssigned']);
        $this->client->supportWorkers()->attach($this->manager->id);
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $foreign->supportWorkers()->attach($this->manager->id);
        $this->manager->load('permissionOverrides');
        $clients = $this->access()->pickerOptions($this->manager)['clients'];
        $this->assertSame([$this->client->id], $clients->pluck('id')->all());
        $this->assertSame(['id', 'name', 'first_name', 'last_name', 'site_id'], array_keys($clients->first()));
        $this->client->supportWorkers()->detach($this->manager->id);
        $this->assertCount(0, $this->access()->pickerOptions($this->manager)['clients']);
        $this->grant($this->worker, ['clients.viewAny']);
        $this->assertCount(0, $this->access()->pickerOptions($this->worker)['clients']);
    }

    public function test_owned_shift_picker_hides_denied_client_identity_without_changing_its_canonical_source(): void
    {
        $shift = $this->shift($this->worker);
        $source = $this->sessionSource(['shift_id' => $shift->id, 'client_id' => $shift->client_id]);
        $this->assertSame($source->id, $this->access()->recordForViewer($source, $this->worker)->id);
        $this->worker->load('roles.permissions', 'permissionOverrides');
        $picker = collect($this->access()->pickerOptions($this->worker)['shifts'])->firstWhere('id', $shift->id);
        $this->assertNull($picker['client_id']);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'client_id' => $this->client->id]);
        $this->grant($this->worker, ['clients.viewAssigned']);
        $this->client->supportWorkers()->attach($this->worker->id);
        $this->assertSame($this->client->id, collect($this->access()->pickerOptions($this->worker)['shifts'])->firstWhere('id', $shift->id)['client_id']);
        $this->client->supportWorkers()->detach($this->worker->id);
        $this->assertNull(collect($this->access()->pickerOptions($this->worker)['shifts'])->firstWhere('id', $shift->id)['client_id']);
        $this->assertTrue($this->access()->commandCan($source, $this->worker)['check_in']);
        $this->assertDatabaseHas('lone_worker_sessions', ['id' => $source->id, 'client_id' => $this->client->id]);
    }

    public function test_coverage_choices_preserve_required_population_but_hide_independently_denied_client_details(): void
    {
        $this->client->update(['first_name' => 'RestrictedCoverage', 'last_name' => 'ClientIdentity']);
        $shift = $this->shift($this->worker);
        $choices = $this->coverage()->monitorableShifts($this->manager);
        $this->assertSame(1, $choices['unmonitored_lone']);
        $this->assertNull(collect($choices['shifts'])->firstWhere('id', $shift->id)['client']);
        $this->assertStringNotContainsString('RestrictedCoverage', json_encode($choices));
        $this->assertSame(1, $this->coverage()->summary($this->manager)['expected_count']);
        $this->grant($this->manager, ['clients.viewAssigned']);
        $this->client->supportWorkers()->attach($this->manager->id);
        $this->assertSame($this->client->id, collect($this->coverage()->monitorableShifts($this->manager)['shifts'])->firstWhere('id', $shift->id)['client']['id']);
        $this->client->supportWorkers()->detach($this->manager->id);
        $this->assertNull(collect($this->coverage()->monitorableShifts($this->manager)['shifts'])->firstWhere('id', $shift->id)['client']);
        $this->assertSame(1, $this->coverage()->summary($this->manager)['expected_count']);
    }

    public function test_manage_only_owner_retains_self_commands_but_cannot_use_management_authority(): void
    {
        $this->grant($this->worker, ['hazards.manage']);
        $row = $this->sessionSource();
        $can = $this->access()->commandCan($row, $this->worker);
        $this->assertTrue($can['check_in']);
        $this->assertFalse($can['manage']);
        $this->assertFalse($can['edit']);
        $this->access()->withCurrentViewer($this->worker, function ($current, $reads, $sites) use ($row): void {
            $this->denied(fn () => $this->access()->assertRecord($row, $current, $sites, true));
        });
    }

    public function test_actual_worker_reads_and_checks_in_without_management_permissions_but_other_worker_cannot(): void
    {
        $row = $this->sessionSource();
        $this->assertSame($row->id, $this->access()->recordForViewer($row, $this->worker)->id);
        $can = $this->access()->commandCan($row, $this->worker);
        $this->assertTrue($can['check_in']);
        $this->assertTrue($can['panic']);
        $this->assertFalse($can['manage']);
        $this->assertFalse($can['end']);
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->staff($this->site)));
    }

    public function test_worker_end_and_picker_require_the_exact_live_owned_workforce_shift(): void
    {
        $shift = $this->shift($this->worker);
        $other = $this->shift($this->staff($this->site));
        $row = $this->sessionSource(['shift_id' => $shift->id, 'client_id' => $shift->client_id]);
        $this->assertTrue($this->access()->commandCan($row, $this->worker)['end']);
        $options = $this->access()->pickerOptions($this->worker);
        $this->assertSame([$shift->id], collect($options['shifts'])->pluck('id')->all());
        $this->assertSame([$this->worker->id], collect($options['workers'])->pluck('id')->all());
        $this->assertArrayNotHasKey('notes', collect($options['shifts'])->first());
        $shift->forceFill(['actual_ends_at' => now()])->saveQuietly();
        $this->assertFalse($this->access()->commandCan($row, $this->worker)['end']);
        $this->assertSame([], collect($this->access()->pickerOptions($this->worker)['shifts'])->all());
    }

    public function test_planned_address_pickers_preserve_scoped_static_sites_without_copying_worker_gps(): void
    {
        $this->site->update(['address_line_1' => '12 Permitted Road', 'address_line_2' => null, 'suburb' => null, 'city' => 'Auckland', 'postcode' => null, 'country' => 'New Zealand', 'latitude' => 0, 'longitude' => 0]);
        $foreign = Site::factory()->create(['address_line_1' => 'Hidden Site address', 'latitude' => -45, 'longitude' => 170]);
        $shift = $this->shift($this->worker, true, ['location' => 'Agreed Shift meeting point']);
        $this->shift($this->staff($foreign), true, ['site_id' => $foreign->id, 'client_id' => Client::factory()->create(['site_id' => $foreign->id])->id, 'location' => 'Hidden Shift plan']);
        ShiftGpsLog::create(['shift_id' => $shift->id, 'user_id' => $this->worker->id, 'event_type' => 'check_in', 'latitude' => -36.85, 'longitude' => 174.76, 'address' => 'Private worker observation', 'captured_at' => now()]);
        $options = $this->access()->pickerOptions($this->worker);
        $this->assertFalse($options['can_geocode']);
        $this->assertSame([$this->site->id], collect($options['sites'])->pluck('id')->all());
        $site = collect($options['sites'])->first();
        $this->assertSame('12 Permitted Road, Auckland, New Zealand', $site['address']);
        $this->assertSame(0.0, $site['latitude']);
        $this->assertSame(0.0, $site['longitude']);
        $this->assertSame([$shift->id], collect($options['shifts'])->pluck('id')->all());
        $plan = collect($options['shifts'])->first();
        $this->assertSame('Agreed Shift meeting point', $plan['address']);
        $this->assertNull($plan['latitude']);
        $this->assertNull($plan['longitude']);
        $this->assertStringNotContainsString('Private worker observation', json_encode($options));
        $this->assertTrue($this->access()->pickerOptions($this->manager)['can_geocode']);
        $this->worker->hrEmployeeProfile->update(['primary_site_id' => $foreign->id]);
        $this->assertSame([$foreign->id], collect($this->access()->pickerOptions($this->worker)['sites'])->pluck('id')->all());
        $this->assertCount(0, $this->access()->pickerOptions($this->worker)['shifts']);
    }

    #[DataProvider('portalIdentities')]
    public function test_portal_identities_with_lingering_active_hr_and_permission_overrides_are_denied(string $identity, bool $rbac): void
    {
        $row = $this->sessionSource();
        if ($rbac) {
            $this->manager->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => $identity], ['label' => $identity, 'type' => 'system', 'level' => 0])->id]);
        } else {
            $this->manager->forceFill(['role' => $identity])->saveQuietly();
        }
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->manager));
        $this->denied(fn () => $this->coverage()->summary($this->manager));
    }

    public static function portalIdentities(): array
    {
        return ['legacy client' => ['client', false], 'legacy kin' => ['next_of_kin', false], 'legacy external' => ['external_clinician', false], 'RBAC client' => ['client', true], 'RBAC kin' => ['next_of_kin', true], 'RBAC external' => ['external_clinician', true]];
    }

    public function test_nz_hr_dates_and_fresh_permission_site_profile_reads_override_cached_viewer(): void
    {
        $row = $this->sessionSource();
        $this->worker->hrEmployeeProfile->update(['start_date' => '2026-10-08']);
        $this->assertSame($row->id, $this->access()->recordForViewer($row, $this->manager)->id);
        $this->manager->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $permission = Permission::where('key', 'hazards.view')->firstOrFail();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->manager));
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->manager->hrEmployeeProfile()->update(['end_date' => '2026-10-07']);
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->manager));
        $this->manager->hrEmployeeProfile()->update(['end_date' => null, 'primary_site_id' => Site::factory()->create()->id]);
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->manager));
    }

    public function test_canonical_secondary_site_string_ids_agree_between_direct_and_register_reads(): void
    {
        $primary = Site::factory()->create();
        $this->worker->hrEmployeeProfile->update(['primary_site_id' => $primary->id, 'secondary_site_ids' => [(string) $this->site->id]]);
        $row = $this->sessionSource();
        $this->assertSame($row->id, $this->access()->recordForViewer($row, $this->manager)->id);
        $this->assertTrue(app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $this->manager)->whereKey($row->id)->exists());
        $this->worker->hrEmployeeProfile->update(['secondary_site_ids' => []]);
        $this->assertFalse(app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $this->manager)->whereKey($row->id)->exists());
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->manager));
    }

    public function test_completed_historical_tuple_remains_readable_after_worker_eligibility_is_lost_but_live_does_not(): void
    {
        $completed = $this->sessionSource(['status' => 'completed', 'ended_at' => now()]);
        $live = $this->sessionSource();
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame($completed->id, $this->access()->recordForViewer($completed, $this->manager)->id);
        $this->denied(fn () => $this->access()->recordForViewer($live, $this->manager));
        $ids = app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $this->manager)->pluck('id')->all();
        $this->assertSame([$completed->id], $ids);
        $can = $this->access()->commandCan($completed, $this->manager);
        $this->assertFalse($can['check_in']);
        $this->assertFalse($can['end']);
        $this->assertFalse($can['remove']);
    }

    public function test_site_bypass_never_repairs_a_poisoned_client_shift_or_worker_tuple(): void
    {
        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $shift = $this->shift($this->staff($this->site));
        $this->grant($this->manager, ['healthSafety.viewAllSites']);
        $this->denied(fn () => $this->access()->recordForViewer($this->sessionSource(['client_id' => $otherClient->id]), $this->manager));
        $this->denied(fn () => $this->access()->recordForViewer($this->sessionSource(['shift_id' => $shift->id, 'client_id' => $shift->client_id]), $this->manager));
        $deletedClient = Client::factory()->create(['site_id' => $this->site->id]);
        $retained = $this->sessionSource(['client_id' => $deletedClient->id]);
        $this->assertTrue(app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $this->manager)->whereKey($retained->id)->exists());
        $deletedClient->deleteQuietly();
        $this->assertFalse(app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $this->manager)->whereKey($retained->id)->exists());
        $this->denied(fn () => $this->access()->recordForViewer($retained, $this->manager));
        $this->assertDatabaseHas('lone_worker_sessions', ['id' => $retained->id, 'client_id' => $deletedClient->id]);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
        $this->denied(fn () => $this->access()->recordForViewer($this->sessionSource(), $this->manager));
    }

    public function test_tracker_locations_require_independent_fresh_permission_and_exact_collection_assignment(): void
    {
        $row = $this->sessionSource();
        $device = Device::factory()->create(['domain' => 'tracking', 'latitude' => -36.8, 'longitude' => 174.7, 'last_seen_at' => now(), 'imei' => 'private-device-identifier']);
        $assignment = DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_STAFF, 'assignable_id' => $this->worker->id, 'custody_site_id' => $this->site->id, 'assigned_at' => now()->subHour(), 'collection_started_at' => now()->subHour()]);
        $this->assertSame('restricted', $this->access()->trackerForViewer($row, $this->manager)['state']);
        $this->assertFalse($this->access()->commandCan($row, $this->manager)['locate']);
        $this->grant($this->manager, ['assets.telemetry.view']);
        $data = $this->access()->trackerForViewer($row, $this->manager);
        $this->assertSame('unknown', $data['state']);
        $this->assertNull($data['tracker']['latitude']);
        $this->assertFalse($this->access()->commandCan($row, $this->manager)['locate']);
        $device->update(['config' => ['management' => ['capabilities' => ['tracking.location_refresh']]]]);
        $this->assertTrue($this->access()->commandCan($row, $this->manager)['locate']);
        $this->assertNull($data['tracker']['observed_at']);
        $this->assertSame('current', $data['tracker']['collection_state']);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $event = FleetTelemetryEvent::create(['asset_id' => $asset->id, 'device_id' => $device->id, 'vendor' => 'synthetic', 'occurred_at' => now()->subHours(2), 'received_at' => now()->subHours(2), 'latitude' => -36.8, 'longitude' => 174.7, 'idempotency_key' => hash('sha256', 'prior-assignment-observation')]);
        $this->assertNull($this->access()->trackerForViewer($row, $this->manager)['tracker']['latitude']);
        $event->update(['occurred_at' => now()->subMinute(), 'received_at' => now()]);
        $data = $this->access()->trackerForViewer($row, $this->manager);
        $this->assertSame('available', $data['state']);
        $this->assertSame(now()->subMinute()->toISOString(), $data['tracker']['observed_at']);
        $this->assertSame(now()->toISOString(), $data['tracker']['received_at']);
        $assignment->update(['access_audience' => ['control_room']]);
        $this->assertSame('restricted', $this->access()->trackerForViewer($row, $this->manager)['state']);
        $this->assertFalse($this->access()->commandCan($row, $this->manager)['locate']);
        $assignment->update(['access_audience' => ['health_and_safety']]);
        $this->assertArrayNotHasKey('imei', $data['tracker']);
        $assignment->update(['retention_days' => 0]);
        $this->assertFalse($this->access()->commandCan($row, $this->manager)['locate']);
        $assignment->update(['retention_days' => 90]);
        $this->manager->load('permissionOverrides', 'roles.permissions');
        $this->manager->permissionOverrides()->updateExistingPivot(Permission::where('key', 'assets.telemetry.view')->firstOrFail()->id, ['allowed' => false]);
        $this->assertNull($this->access()->trackerForViewer($row, $this->manager)['tracker']);
        $this->grant($this->manager, ['assets.telemetry.view']);
        $assignment->update(['collection_stopped_at' => now()]);
        $this->assertSame('collection_stopped', $this->access()->trackerForViewer($row, $this->manager)['state']);
    }

    private function canonicalResponse(LoneWorkerSession $row): ControlRoomAlert
    {
        $type = LoneWorkerSignalService::TYPE_EMERGENCY;
        $context = ['source_module' => 'lone_worker', 'signal_type' => $type, 'lone_worker_session_id' => $row->id, 'worker_user_id' => $row->user_id, 'site_id' => $row->site_id, 'client_id' => $row->client_id];
        $source = SignalSource::firstOrCreate(['slug' => 'lone_worker'], ['name' => 'Lone Worker Safety', 'vendor' => 'internal', 'status' => 'active']);
        $signal = Signal::create(['signal_source_id' => $source->id, 'signal_type_code' => $type, 'site_id' => $row->site_id, 'client_id' => $row->client_id, 'occurred_at' => now(), 'idempotency_key' => hash('sha256', 'synthetic-'.$row->id), 'normalized_data' => $context]);
        $alert = ControlRoomAlert::factory()->create(['source' => 'lone_worker', 'origin_signal_id' => $signal->id, 'site_id' => $row->site_id, 'client_id' => $row->client_id, 'alert_type' => LoneWorkerSignalService::canonicalAlertType($type), 'context' => ['normalized_data' => $context]]);
        $signal->update(['alert_id' => $alert->id, 'status' => 'processed']);

        return $alert;
    }

    public function test_response_origin_and_current_control_room_permission_are_independent_of_source_read(): void
    {
        $row = $this->sessionSource();
        $alert = $this->canonicalResponse($row);
        $this->assertFalse($this->access()->canViewCanonicalAlert($row, $alert, $this->manager));
        $this->grant($this->manager, ['controlRoom.alerts.view']);
        $this->assertTrue($this->access()->canViewCanonicalAlert($row, $alert, $this->manager));
        $this->manager->load('permissionOverrides', 'roles.permissions');
        $this->manager->permissionOverrides()->updateExistingPivot(Permission::where('key', 'controlRoom.alerts.view')->firstOrFail()->id, ['allowed' => false]);
        $this->assertFalse($this->access()->canViewCanonicalAlert($row, $alert, $this->manager));
        $this->grant($this->manager, ['controlRoom.alerts.view']);
        Signal::whereKey($alert->origin_signal_id)->update(['site_id' => Site::factory()->create()->id]);
        $this->assertFalse($this->access()->canViewCanonicalAlert($row, $alert, $this->manager));
        $this->assertDatabaseHas('control_room_alerts', ['id' => $alert->id]);
        $alert->update(['origin_signal_id' => null]);
        $this->assertFalse($this->access()->canViewCanonicalAlert($row, $alert, $this->manager));
    }

    public function test_incident_privacy_is_independent_and_requires_the_exact_source_occurrence_tuple(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $row = $this->sessionSource(['client_id' => $client->id]);
        $alert = $this->canonicalResponse($row);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id, 'control_room_alert_id' => $alert->id, 'is_hr_confidential' => false]);
        $this->assertNull($this->access()->readableIncidentForAlert($row, $alert, $this->manager));
        $this->grant($this->manager, ['incidents.viewAny']);
        $this->assertSame($incident->id, $this->access()->readableIncidentForAlert($row, $alert, $this->manager)->id);
        $incident->forceFill(['is_hr_confidential' => true])->saveQuietly();
        $this->assertTrue((bool) $incident->fresh()->is_hr_confidential);
        $this->assertNull($this->access()->readableIncidentForAlert($row, $alert, $this->manager));
        $this->assertSame($row->id, $this->access()->recordForViewer($row, $this->manager)->id);
    }

    public function test_complete_required_population_over_one_hundred_counts_exact_monitored_sources(): void
    {
        for ($index = 0; $index < 105; $index++) {
            $worker = $this->staff($this->site);
            $shift = $this->shift($worker);
            if ($index < 10) {
                $this->sessionSource(['user_id' => $worker->id, 'shift_id' => $shift->id, 'client_id' => $shift->client_id]);
            }
        }
        $data = $this->coverage()->summary($this->manager);
        $this->assertSame(105, $data['expected_count']);
        $this->assertSame(10, $data['monitored_count']);
        $this->assertSame(95, $data['unmonitored_count']);
        $this->assertSame('unmonitored', $data['state']);
        $this->assertCount(95, $this->coverage()->monitorableShifts($this->manager)['shifts']);
    }

    public function test_explicit_false_solo_on_call_and_no_active_duty_do_not_become_required_or_safe(): void
    {
        $this->assertSame('no_active_duty', $this->coverage()->summary($this->manager)['state']);
        $false = $this->shift($this->worker, false, ['is_on_call' => true]);
        $data = $this->coverage()->summary($this->manager);
        $this->assertSame('configured_zero', $data['state']);
        $this->assertSame(0, $data['expected_count']);
        $this->assertFalse(collect($this->coverage()->monitorableShifts($this->manager)['shifts'])->first()['is_lone']);
        $false->forceFill(['status' => 'cancelled'])->saveQuietly();
        $this->assertSame('no_active_duty', $this->coverage()->summary($this->manager)['state']);
        $upcoming = $this->shift($this->worker, true, ['actual_starts_at' => null, 'starts_at' => now()->addHour()]);
        $data = $this->coverage()->summary($this->manager);
        $this->assertSame(0, $data['expected_count']);
        $this->assertSame(1, $data['upcoming_expected_count']);
        $this->assertSame('available', $data['source_availability']);
    }

    public function test_unestablished_requirement_and_foreign_or_invalid_session_cannot_claim_complete_coverage(): void
    {
        $shift = $this->shift($this->worker);
        $this->sessionSource(['shift_id' => $shift->id, 'client_id' => $shift->client_id, 'user_id' => $this->staff($this->site)->id]);
        $this->assertSame(1, $this->coverage()->summary($this->manager)['unmonitored_count']);
        DB::table('shifts')->where('id', $shift->id)->update(['is_lone_worker' => 2]);
        $data = $this->coverage()->summary($this->manager);
        $this->assertSame('unknown', $data['state']);
        $this->assertNull($data['expected_count']);
        $this->assertSame(1, $data['unknown_count']);
        $otherSite = Site::factory()->create();
        $this->denied(fn () => $this->coverage()->summary($this->manager, $otherSite->id));
    }

    public function test_real_received_check_in_is_distinct_from_startup_and_late_old_observation(): void
    {
        $row = $this->sessionSource(['last_check_in_at' => now()]);
        $data = $this->coverage()->observation($row, $this->manager);
        $this->assertSame('no_check_in', $data['state']);
        $this->assertNull($data['real_check_in_at']);
        $this->assertNull($data['received_at']);
        LoneWorkerCheckIn::create(['lone_worker_session_id' => $row->id, 'checked_in_at' => now()->subHour(), 'received_at' => now(), 'status' => 'ok']);
        $data = $this->coverage()->observation($row, $this->manager);
        $this->assertSame('stale', $data['state']);
        $this->assertSame(now()->toISOString(), $data['received_at']);
        $this->assertSame(now()->subHour()->toISOString(), $data['real_check_in_at']);
        $this->assertSame('recorded_receipt', $data['receipt_source']);
        $this->assertSame(now()->addMinutes(30)->toISOString(), $data['next_due_at']);
        $row->forceFill(['last_check_in_at' => null])->saveQuietly();
        $data = $this->coverage()->observation($row, $this->manager);
        $this->assertSame(now()->subMinutes(30)->toISOString(), $data['next_due_at']);
        $this->assertSame('stale', $data['state']);
        $this->assertTrue($row->fresh()->isCheckInOverdue());
    }

    public function test_manage_only_does_not_bypass_denied_source_read_and_reader_cannot_mutate(): void
    {
        $row = $this->sessionSource();
        $this->manager->permissionOverrides()->updateExistingPivot(Permission::where('key', 'hazards.view')->firstOrFail()->id, ['allowed' => false]);
        $this->denied(fn () => $this->access()->recordForViewer($row, $this->manager));
        $reader = $this->staff($this->site, ['hazards.view']);
        $this->assertSame($row->id, $this->access()->recordForViewer($row, $reader)->id);
        $can = $this->access()->commandCan($row, $reader);
        $this->assertTrue($can['view']);
        foreach (['manage', 'edit', 'end', 'remove', 'check_in', 'panic', 'locate', 'acknowledge_panic'] as $key) {
            $this->assertFalse($can[$key]);
        }
    }

    public function test_monitor_unknown_stale_failure_and_real_check_in_do_not_conflate_delivery_or_safety(): void
    {
        $row = $this->sessionSource();
        $this->assertSame('unknown', $this->coverage()->observation($row, $this->manager)['monitor']['state']);
        $row->forceFill(['last_monitor_checked_at' => now()->subMinutes(11)])->saveQuietly();
        $this->assertSame('stale', $this->coverage()->observation($row, $this->manager)['monitor']['state']);
        $row->forceFill(['monitor_failed_at' => now(), 'monitor_failure_code' => 'signal_delivery_failed'])->saveQuietly();
        $data = $this->coverage()->observation($row, $this->manager);
        $this->assertSame('failed', $data['monitor']['state']);
        $this->assertSame('no_check_in', $data['state']);
        $row->forceFill(['last_monitor_checked_at' => now()->addSecond()])->saveQuietly();
        $this->assertSame('unknown', $this->coverage()->observation($row, $this->manager)['monitor']['state']);
        $this->travel(2)->seconds();
        $this->assertSame('current', $this->coverage()->observation($row, $this->manager)['monitor']['state']);
    }
}
