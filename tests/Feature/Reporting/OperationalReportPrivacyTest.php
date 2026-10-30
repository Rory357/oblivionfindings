<?php

namespace Tests\Feature\Reporting;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\LoneWorkerSession;
use App\Models\OperationalReportRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\Reporting\ReportAccess;
use App\Services\Reporting\ReportRuns;
use App\Services\Reporting\ReportSourceReader;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class OperationalReportPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'admin')->value('id'));

        return $actor;
    }

    private function definition(string $source = 'journeys'): array
    {
        return ['version' => 1, 'name' => 'Privacy regression', 'source' => $source,
            'columns' => array_slice(array_keys(config('operational-reports.sources.'.$source.'.fields')), 0, 8),
            'date_from' => now('Pacific/Auckland')->subDay()->toDateString(), 'date_to' => now('Pacific/Auckland')->toDateString(),
            'site_ids' => [], 'resource_ids' => [], 'subject_id' => null, 'match' => 'all', 'filters' => [], 'groups' => ['date'],
            'measures' => [['id' => 'm1', 'label' => 'Rows', 'operation' => 'count', 'field' => null, 'formula' => null, 'decimals' => 0, 'unit' => 'count']],
            'layout' => 'table', 'sort' => 'group', 'direction' => 'asc', 'limit' => 20, 'precision' => 'redacted', 'comparison' => false];
    }

    private function generate(User $actor, array $definition): OperationalReportRun
    {
        $id = $this->actingAs($actor)->postJson('/report-builder/runs', ['definition' => $definition, 'reason' => 'Synthetic privacy verification'])
            ->assertAccepted()->json('id');
        $run = OperationalReportRun::findOrFail($id);
        app(ReportRuns::class)->execute($run);
        $run->refresh();
        $this->assertSame('ready', $run->status, (string) $run->failure_code);
        $this->getJson('/report-builder/runs/'.$id)->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonMissingPath('payload.source.evidence')->assertJsonMissingPath('payload.source.comparison_evidence');

        return $run;
    }

    private function trip(?Site $site = null, ?string $at = null): FleetTrip
    {
        $site ??= Site::factory()->create();
        $asset = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id, 'home_site_id' => $site->id, 'client_id' => null]);

        return FleetTrip::create(['asset_id' => $asset->id, 'started_at' => $at ?? now()->subHours(2), 'ended_at' => now()->subHour(),
            'distance_km' => 25, 'duration_s' => 3600, 'status' => 'closed', 'is_personal' => false, 'consent_blocked' => false]);
    }

    private function denied(OperationalReportRun $run): void
    {
        $this->getJson('/report-builder/runs/'.$run->id)->assertForbidden()->assertJsonMissingPath('payload.result');
        foreach (['csv', 'json', 'xlsx', 'pdf'] as $format) {
            $this->postJson('/report-builder/runs/'.$run->id.'/export', ['format' => $format, 'reason' => 'Synthetic privacy verification'])
                ->assertForbidden()->assertJsonMissingPath('result')->assertHeaderMissing('Content-Disposition');
        }
    }

    public static function privateSources(): array
    {
        return [
            'journey personal' => ['journeys', 'is_personal'],
            'journey consent' => ['journeys', 'consent_blocked'],
            'cost aggregate personal' => ['resource_costs', 'is_personal'],
            'cost aggregate consent' => ['resource_costs', 'consent_blocked'],
            'resource last-trip personal' => ['resources', 'is_personal'],
            'resource last-trip consent' => ['resources', 'consent_blocked'],
        ];
    }

    #[DataProvider('privateSources')]
    public function test_current_privacy_blocks_cached_detail_derived_results_and_every_export(string $source, string $flag): void
    {
        $actor = $this->admin();
        $trip = $this->trip();
        FleetVehicleStateSnapshot::create(['asset_id' => $trip->asset_id, 'last_trip_id' => $trip->id, 'last_seen_at' => now()->subHour(), 'consent_blocked' => false]);
        $d = $this->definition($source);
        $d['columns'] = ['resource']; // Privacy evidence must survive hidden identifiers/metrics.
        $d['measures'][] = ['id' => 'm2', 'label' => 'Distance', 'operation' => 'sum', 'field' => 'distance', 'formula' => null, 'decimals' => 2, 'unit' => 'km'];
        $d['measures'][] = ['id' => 'm3', 'label' => 'Calculated', 'operation' => 'formula', 'field' => null, 'formula' => 'm2 / m1', 'decimals' => 2, 'unit' => 'number'];
        $d['layout'] = 'bar';
        $run = $this->generate($actor, $d);
        $this->assertSame(1, $run->payload['result']['row_count']);
        $this->postJson('/report-builder/runs/'.$run->id.'/export', ['format' => 'json', 'reason' => 'Permitted control'])
            ->assertOk()->assertJsonMissingPath('provenance.evidence');
        // Deliberately bypass timestamps and observers: privacy is a current-record property.
        DB::table('fleet_trips')->where('id', $trip->id)->update([$flag => true]);
        $this->denied($run);
        $fresh = app(ReportSourceReader::class)->read($actor, $d)['rows'];
        if ($source === 'journeys') {
            $this->assertCount(0, $fresh);
        } elseif ($source === 'resource_costs') {
            $this->assertNull($fresh[0]['distance']);
        } else {
            $this->assertNull($fresh[0]['last_contact']);
        }
    }

    public function test_comparison_only_trip_invalidates_whole_result(): void
    {
        $actor = $this->admin();
        $trip = $this->trip(at: now('Pacific/Auckland')->subDays(3)->utc()->toDateTimeString());
        $d = $this->definition();
        $d['comparison'] = true;
        $run = $this->generate($actor, $d);
        $this->assertSame(0, $run->payload['result']['row_count']);
        $this->assertEquals(1, $run->payload['comparison']['totals']['m1']);
        DB::table('fleet_trips')->where('id', $trip->id)->update(['is_personal' => true]);
        $this->denied($run);
    }

    public function test_state_consent_change_and_legacy_payloads_fail_closed(): void
    {
        $actor = $this->admin();
        $trip = $this->trip();
        $state = FleetVehicleStateSnapshot::create(['asset_id' => $trip->asset_id, 'last_trip_id' => $trip->id, 'last_seen_at' => now(), 'consent_blocked' => false]);
        $run = $this->generate($actor, $this->definition('resources'));
        DB::table($state->getTable())->where('asset_id', $trip->asset_id)->update(['consent_blocked' => true]);
        $this->denied($run);
        $legacy = $this->generate($actor, $this->definition());
        $payload = $legacy->payload;
        unset($payload['source']['evidence']);
        $legacy->update(['payload' => $payload]);
        $this->denied($legacy);
    }

    public function test_changes_during_source_read_prevent_ready_result(): void
    {
        $actor = $this->admin();
        $trip = $this->trip();
        $changed = false;
        FleetTrip::retrieved(function ($record) use (&$changed, $trip) {
            if (! $changed && $record->id === $trip->id) {
                $changed = true;
                DB::table('fleet_trips')->where('id', $trip->id)->update(['consent_blocked' => true]);
            }
        });
        try {
            $id = $this->actingAs($actor)->postJson('/report-builder/runs', ['definition' => $this->definition(), 'reason' => 'Read-race verification'])->assertAccepted()->json('id');
            $run = OperationalReportRun::findOrFail($id);
            app(ReportRuns::class)->execute($run);
            $this->assertTrue($changed);
            $this->getJson('/report-builder/runs/'.$id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('failure_code', 'access_changed')->assertJsonMissingPath('payload.result');
        } finally {
            FleetTrip::getEventDispatcher()->forget('eloquent.retrieved: '.FleetTrip::class);
        }
    }

    public function test_privacy_change_during_pdf_render_sends_no_download(): void
    {
        $actor = $this->admin();
        $trip = $this->trip();
        $run = $this->generate($actor, $this->definition());
        $rendered = false;
        View::composer('pdf.operational-report', function () use ($trip, &$rendered) {
            $rendered = true;
            DB::table('fleet_trips')->where('id', $trip->id)->update(['is_personal' => true]);
        });
        $this->postJson('/report-builder/runs/'.$run->id.'/export', ['format' => 'pdf', 'reason' => 'Render-race verification'])->assertForbidden()->assertHeaderMissing('Content-Disposition');
        $this->assertTrue($rendered);
    }

    private function reporter(Site $site, string $capability, bool $controlled = false): array
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $role = Role::create(['name' => 'scoped-report-'.Str::uuid(), 'label' => 'Scoped reporter', 'level' => 10, 'type' => 'custom']);
        foreach (array_filter(['assets.telemetry.view', 'assets.telemetry.export', 'assets.viewAny', 'clients.viewAny', 'hazards.view', $capability, $controlled ? 'medications.controlled.view' : null]) as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'reports', 'module' => 'Reports']);
            $role->permissions()->attach($permission->id);
        }
        $actor->roles()->attach($role->id);

        return [$actor, $role];
    }

    private function personalFixture(User $admin, string $domain, Site $site, bool $controlled = false): array
    {
        $at = now()->subHours(2);
        $event = null;
        if ($domain === 'client') {
            $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            $type = ConsentType::factory()->create(['name' => 'Personal Tracker (Wandering Risk)', 'purpose' => 'Client personal safety tracking', 'active' => true]);
            $consent = AuthoritativeConsentFixture::manualSelf($client, $type, $admin, ['status' => 'given', 'given_at' => now()->subDay(), 'expires_at' => now()->addMonth()]);
            $device = Device::factory()->tracking()->create();
            $assignment = app(DeviceAssignmentService::class)->assign($device, 'client', $client->id, $admin->id, consentId: $consent->id);
            $assignment->update(['assigned_at' => now()->subDay(), 'collection_started_at' => now()->subDay()]);
            $asset = Asset::factory()->create(['site_id' => $site->id, 'home_site_id' => $site->id]);
            $event = FleetTelemetryEvent::create(['device_id' => $device->id, 'asset_id' => $asset->id, 'vendor' => 'test', 'occurred_at' => $at, 'received_at' => $at,
                'latitude' => -36.8, 'longitude' => 174.7, 'consent_blocked' => false, 'event_type' => 'fall_detected', 'idempotency_key' => (string) Str::uuid()]);
            $fleet = FleetSignal::create(['asset_id' => $asset->id, 'device_id' => $device->id, 'source_event_id' => $event->id, 'signal_type' => 'resident.fall_detected', 'occurred_at' => $at, 'severity_hint' => 'critical', 'idempotency_key' => (string) Str::uuid(), 'payload' => ['event_id' => $event->id, 'assignment_id' => $assignment->id]]);
            $normalized = ['fleet_signal_id' => $fleet->id, 'client_id' => $client->id, 'controlled_drug' => $controlled];
            $typeCode = 'fall_detected';
            $subject = $client->id;
        } else {
            $client = null;
            $worker = User::factory()->create(['approved_at' => now()]);
            HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
            $session = LoneWorkerSession::create(['user_id' => $worker->id, 'site_id' => $site->id, 'started_at' => now()->subHours(3), 'expected_end_at' => now()->subHour(), 'ended_at' => now()->subHour(), 'status' => 'completed', 'check_in_interval_minutes' => 30, 'created_by' => $admin->id]);
            $typeCode = LoneWorkerSignalService::TYPE_EMERGENCY;
            $normalized = ['source_module' => 'lone_worker', 'signal_type' => $typeCode, 'lone_worker_session_id' => $session->id, 'worker_user_id' => $worker->id, 'site_id' => $site->id, 'client_id' => null, 'controlled_drug' => $controlled];
            $subject = $session->id;
        }
        $origin = Signal::create(['signal_type_code' => $typeCode, 'site_id' => $site->id, 'client_id' => $client?->id, 'occurred_at' => $at, 'idempotency_key' => (string) Str::uuid(), 'normalized_data' => $normalized]);
        $alert = ControlRoomAlert::factory()->create(['source' => $domain === 'client' ? 'personal_tracker' : 'lone_worker',
            'alert_type' => $domain === 'client' ? 'Fall detected' : 'Lone Worker Emergency', 'origin_signal_id' => $origin->id,
            'site_id' => $site->id, 'client_id' => $client?->id, 'triggered_at' => $at, 'context' => ['signal_id' => $origin->id, 'normalized_data' => $normalized]]);
        $d = $this->definition($domain.'_alerts');
        $d['subject_id'] = $subject;

        return [$d, $alert, $event];
    }

    public static function alertCapabilities(): array
    {
        $cases = [];
        foreach (['client', 'staff'] as $domain) {
            foreach (['controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage'] as $capability) {
                $cases[$domain.' '.$capability] = [$domain, $capability];
            }
        }

        return $cases;
    }

    #[DataProvider('alertCapabilities')]
    public function test_alert_reports_use_canonical_read_permission_and_recheck_withdrawal(string $domain, string $capability): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        [$d] = $this->personalFixture($admin, $domain, $site);
        [$actor, $role] = $this->reporter($site, $capability);
        $this->actingAs($actor);
        if ($capability === 'controlRoom.viewAny') {
            $this->postJson('/report-builder/validate', ['definition' => $d])->assertForbidden();
            $this->postJson('/report-builder/runs', ['definition' => $d, 'reason' => 'Dashboard-only denial'])->assertForbidden();

            return;
        }
        $this->assertFalse($actor->canDo('controlRoom.viewAny'));
        $run = $this->generate($actor, $d);
        $this->assertSame(1, $run->payload['result']['row_count']);
        $this->postJson('/report-builder/runs/'.$run->id.'/export', ['format' => 'json', 'reason' => 'Permitted alert control'])->assertOk();
        $role->permissions()->detach(Permission::where('key', $capability)->value('id'));
        $this->denied($run);
    }

    public static function domains(): array
    {
        return [['client'], ['staff']];
    }

    #[DataProvider('domains')]
    public function test_alerts_exclude_wrong_site_conflicting_person_and_controlled_content(string $domain): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        [$d, $alert] = $this->personalFixture($admin, $domain, $site);
        [$controlled] = $this->personalFixture($admin, $domain, $site, true);
        [$foreign] = $this->personalFixture($admin, $domain, Site::factory()->create());
        [$actor] = $this->reporter($site, 'controlRoom.alerts.view');
        $run = $this->generate($actor, $d);
        $this->assertSame(1, $run->payload['result']['row_count']);
        $hidden = $this->generate($actor, $controlled);
        $this->assertSame(0, $hidden->payload['result']['row_count']);
        $response = $this->postJson('/report-builder/runs', ['definition' => $foreign, 'reason' => 'Other site denial']);
        $this->assertContains($response->status(), [403, 404]);
        $bad = $alert->context;
        $bad['normalized_data'][$domain === 'client' ? 'fleet_signal_id' : 'worker_user_id'] = 999999;
        $alert->update(['context' => $bad]);
        $this->denied($run);
        $fresh = $this->generate($actor, $d);
        $this->assertSame(0, $fresh->payload['result']['row_count']);
    }

    #[DataProvider('domains')]
    public function test_controlled_content_withdrawal_invalidates_cached_alert_without_removing_source(string $domain): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        [$d] = $this->personalFixture($admin, $domain, $site, true);
        [$actor, $role] = $this->reporter($site, 'controlRoom.alerts.view', true);
        $run = $this->generate($actor, $d);
        $this->assertSame(1, $run->payload['result']['row_count']);
        $role->permissions()->detach(Permission::where('key', 'medications.controlled.view')->value('id'));
        $this->assertArrayHasKey($domain.'_alerts', app(ReportAccess::class)->sources($actor));
        $this->denied($run);
        $fresh = $this->generate($actor, $d);
        $this->assertSame(0, $fresh->payload['result']['row_count']);
    }

    public function test_client_telemetry_privacy_invalidates_alert_and_location_contributors(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        [$d, , $event] = $this->personalFixture($admin, 'client', $site);
        [$actor] = $this->reporter($site, 'controlRoom.alerts.view');
        $alertRun = $this->generate($actor, $d);
        $locations = $this->definition('client_locations');
        $locations['subject_id'] = $d['subject_id'];
        $locationRun = $this->generate($actor, $locations);
        $this->assertSame(1, $locationRun->payload['result']['row_count']);
        DB::table('fleet_telemetry_events')->where('id', $event->id)->update(['consent_blocked' => true]);
        $this->denied($alertRun);
        $this->denied($locationRun);
    }

    public function test_legacy_tracker_identity_is_rechecked_when_a_conflicting_device_appears(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        [$d, , $telemetry] = $this->personalFixture($admin, 'client', $site);
        [$actor] = $this->reporter($site, 'controlRoom.alerts.view');
        $hardware = LocationHardware::create(['site_id' => $site->id, 'provider' => 'legacy_tracker',
            'category' => LocationHardware::CATEGORY_TRACKER, 'name' => 'Synthetic legacy tracker',
            'status' => LocationHardware::STATUS_ONLINE, 'last_seen_at' => now()]);
        Device::whereKey($telemetry->device_id)->update(['legacy_location_hardware_id' => $hardware->id]);
        IntegrationEvent::factory()->create(['canonical_device_id' => null, 'hardware_id' => $hardware->id,
            'site_id' => $site->id, 'occurred_at' => now()->subHours(2), 'received_at' => now()->subHours(2),
            'raw_payload' => ['lat' => -36.8, 'lng' => 174.7], 'normalized_payload' => null]);
        $d['source'] = 'client_locations';
        $d['columns'] = ['reference', 'observed_at'];
        $run = $this->generate($actor, $d);
        $this->assertSame(2, $run->payload['result']['row_count']);
        Device::factory()->tracking()->create(['legacy_location_hardware_id' => $hardware->id]);
        $this->denied($run);
        $this->assertCount(1, app(ReportSourceReader::class)->read($actor, $d)['rows']);
    }

    public function test_canonical_integration_payload_remains_part_of_current_evidence(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        [$d, , $telemetry] = $this->personalFixture($admin, 'client', $site);
        [$actor] = $this->reporter($site, 'controlRoom.alerts.view');
        $event = IntegrationEvent::factory()->create(['canonical_device_id' => $telemetry->device_id, 'hardware_id' => null,
            'site_id' => $site->id, 'occurred_at' => now()->subHours(2), 'received_at' => now()->subHours(2),
            'raw_payload' => ['lat' => -36.8, 'lng' => 174.7], 'normalized_payload' => null]);
        DB::table('integration_events')->where('id', $event->id)->update(['raw_payload' => json_encode(['lat' => -37, 'lng' => 175])]);
        $d['source'] = 'client_locations';
        $d['columns'] = ['reference', 'observed_at'];
        $run = $this->generate($actor, $d);
        $this->assertSame(2, $run->payload['result']['row_count']);
        DB::table('integration_events')->where('id', $event->id)->update(['raw_payload' => json_encode(['lat' => -38, 'lng' => 176])]);
        $this->denied($run);
    }
}
