<?php

namespace Tests\Feature\Reporting;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\PersonalTrackingLocationExportService;
use App\Jobs\GenerateOperationalReport;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\LoneWorkerSession;
use App\Models\OperationalReport;
use App\Models\OperationalReportRun;
use App\Models\OperationalReportSubscription;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Reporting\FleetSupplementalReports;
use App\Services\Reporting\ReportAccess;
use App\Services\Reporting\ReportEngine;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportFormula;
use App\Services\Reporting\ReportRuns;
use App\Services\Reporting\ReportSourceReader;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class OperationalReportsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $user = User::factory()->create(['approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'admin')->value('id'));

        return $user;
    }

    private function definition(string $source = 'journeys'): array
    {
        return ['version' => 1, 'name' => 'Reporting test', 'source' => $source,
            'columns' => array_slice(array_keys(config('operational-reports.sources.'.$source.'.fields')), 0, 8),
            'date_from' => '2026-09-20', 'date_to' => '2026-09-27', 'site_ids' => [], 'resource_ids' => [], 'subject_id' => null,
            'match' => 'all', 'filters' => [], 'groups' => ['date'], 'measures' => [['id' => 'm1', 'label' => 'Rows', 'operation' => 'count', 'field' => null, 'formula' => null, 'decimals' => 0, 'unit' => 'count']],
            'layout' => 'table', 'sort' => 'group', 'direction' => 'asc', 'limit' => 20, 'precision' => 'redacted', 'comparison' => false];
    }

    public function test_schema_rejects_malformed_imports_before_mutating_saved_definitions(): void
    {
        $actor = $this->actor();
        $d = $this->definition();
        $this->actingAs($actor)->postJson('/report-builder/reports', ['definition' => $d])->assertOk();
        foreach ([-1, 7, 1.5, 'bad'] as $decimals) {
            $bad = $d;
            $bad['measures'][0]['decimals'] = $decimals;
            $this->postJson('/report-builder/validate', ['definition' => $bad])->assertUnprocessable();
        }
        $bad = $d;
        $bad['columns'] = ['password'];
        $this->postJson('/report-builder/validate', ['definition' => $bad])->assertUnprocessable();
        $this->assertSame(1, OperationalReport::count());
        $this->assertEquals($d['measures'], OperationalReport::first()->definition['measures']);
    }

    public function test_time_series_is_chronological_has_calendar_gaps_and_does_not_use_top_n_dates(): void
    {
        $d = $this->definition();
        $d['layout'] = 'line';
        $d['sort'] = 'value';
        $d['direction'] = 'desc';
        $d['limit'] = 1;
        $result = app(ReportEngine::class)->calculate($d, [
            ['date' => '2026-09-20'], ['date' => '2026-09-25'], ['date' => '2026-09-25'], ['date' => '2026-09-25'],
        ]);
        $this->assertSame('2026-09-25', $result['groups'][0]['dimensions'][0]);
        $this->assertCount(8, $result['chart']);
        $this->assertSame('2026-09-20', $result['chart'][0]['dimensions'][0]);
        $this->assertNull($result['chart'][1]['values']['m1']);
        $this->assertLessThan($result['chart'][7]['timestamp'], $result['chart'][0]['timestamp']);
    }

    public function test_unknowns_negative_values_and_formula_division_are_preserved(): void
    {
        $this->assertSame('-42.5', ReportExporter::csvCell(-42.5));
        $this->assertSame("'=2+3", ReportExporter::csvCell('=2+3'));
        $this->assertSame("' -42.5", ReportExporter::csvCell(' -42.5'));
        $this->assertSame('0', ReportExporter::csvCell(0));
        $this->assertSame('', ReportExporter::csvCell(null));
        $this->assertNull(app(ReportFormula::class)->calculate('m1 / m2', ['m1' => 10, 'm2' => 0]));
        $this->assertSame(-2.0, app(ReportFormula::class)->calculate('(m1 - 12) / 2', ['m1' => 8]));
    }

    public function test_formula_rejects_code_and_cyclic_dependencies(): void
    {
        $actor = $this->actor();
        $d = $this->definition();
        $d['measures'][0]['operation'] = 'formula';
        $d['measures'][0]['formula'] = 'system(1)';
        $this->actingAs($actor)->postJson('/report-builder/validate', ['definition' => $d])->assertUnprocessable();
        $d['measures'][0]['formula'] = 'm2';
        $d['measures'][] = ['id' => 'm2', 'label' => 'Cycle', 'operation' => 'formula', 'field' => null, 'formula' => 'm1', 'decimals' => 0, 'unit' => 'number'];
        $this->postJson('/report-builder/validate', ['definition' => $d])->assertUnprocessable();
    }

    public function test_report_reader_exceeds_map_limit_and_freezes_source_watermark(): void
    {
        $device = Device::factory()->tracking()->create();
        $asset = Asset::factory()->create();
        for ($i = 0; $i < 601; $i++) {
            FleetTelemetryEvent::create(['device_id' => $device->id, 'asset_id' => $asset->id, 'vendor' => 'test', 'occurred_at' => now()->subMinutes(10), 'received_at' => now(),
                'latitude' => -36.8, 'longitude' => 174.7, 'consent_blocked' => false, 'event_type' => 'position', 'idempotency_key' => Str::uuid()->toString()]);
        }
        $history = app(IntegrationEventHistoryService::class);
        $watermark = $history->reportWatermark();
        FleetTelemetryEvent::create(['device_id' => $device->id, 'asset_id' => $asset->id, 'vendor' => 'test', 'occurred_at' => now()->subMinute(), 'received_at' => now(),
            'latitude' => -36.8, 'longitude' => 174.7, 'consent_blocked' => false, 'event_type' => 'position', 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertCount(500, $history->forDevice($device));
        $all = $history->reportForDevice($device, [], 90, $watermark)->collect();
        $this->assertCount(601, $all);
        $this->assertCount(601, $all->unique('source_id'));
    }

    public function test_saved_versions_conflicts_archive_and_other_users_are_denied(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $response = $this->postJson('/report-builder/reports', ['definition' => $this->definition()])->assertOk();
        $id = $response->json('report.id');
        $this->putJson('/report-builder/reports/'.$id, ['definition' => $this->definition(), 'version' => 1])->assertOk()->assertJsonPath('report.version', 2);
        $this->putJson('/report-builder/reports/'.$id, ['definition' => $this->definition(), 'version' => 1])->assertConflict();
        $this->getJson('/report-builder/reports/'.$id.'/versions')->assertOk()->assertJsonCount(2, 'versions');
        $other = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($other)->getJson('/report-builder/reports/'.$id.'/versions')->assertNotFound();
        $this->actingAs($actor)->postJson('/report-builder/reports/'.$id.'/archive', ['archived' => true])->assertOk();
        $this->postJson('/report-builder/reports/'.$id.'/archive', ['archived' => false])->assertOk()->assertJsonPath('report.archived_at', null);
    }

    public function test_fleet_permission_does_not_grant_personal_sources(): void
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        $role = Role::create(['name' => 'report-only', 'label' => 'Report only', 'level' => 10, 'type' => 'custom']);
        $permission = Permission::firstOrCreate(['key' => 'fleet.reports.view'], ['description' => 'Fleet reports', 'group' => 'fleet', 'module' => 'Fleet']);
        $role->permissions()->attach($permission->id);
        $actor->roles()->attach($role->id);
        $sources = app(ReportAccess::class)->sources($actor);
        $this->assertArrayHasKey('journeys', $sources);
        $this->assertArrayNotHasKey('client_locations', $sources);
        $this->assertArrayNotHasKey('staff_locations', $sources);
        $this->actingAs($actor)->postJson('/report-builder/validate', ['definition' => $this->definition('client_locations')])->assertForbidden();
    }

    public function test_private_run_reconciles_and_exports_actual_workbook_and_pdf(): void
    {
        $actor = $this->actor();
        $site = Site::factory()->create();
        $asset = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id, 'home_site_id' => $site->id, 'client_id' => null]);
        FleetTrip::create(['asset_id' => $asset->id, 'started_at' => '2026-09-25 10:00:00', 'ended_at' => '2026-09-25 11:00:00', 'distance_km' => -42.5, 'duration_s' => 3600, 'status' => 'closed', 'is_personal' => false, 'consent_blocked' => false]);
        $response = $this->actingAs($actor)->postJson('/report-builder/runs', ['definition' => $this->definition(), 'reason' => 'Review planning'])->assertAccepted();
        $id = $response->json('id');
        $this->getJson('/report-builder/runs/'.$id)->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('payload.result.row_count', 1);
        $csv = $this->postJson('/report-builder/runs/'.$id.'/export', ['format' => 'csv', 'reason' => 'Review planning'])->assertOk();
        $this->assertStringContainsString('-42.5', $csv->getContent());
        $this->assertStringNotContainsString("'-42.5", $csv->getContent());
        $calculated = $this->definition();
        $calculated['measures'][] = ['id' => 'm2', 'label' => 'Adjustment', 'operation' => 'formula', 'field' => null, 'formula' => 'm1 - 43.5', 'decimals' => 1, 'unit' => 'number'];
        $calculatedId = $this->postJson('/report-builder/runs', ['definition' => $calculated, 'reason' => 'Review calculated measure'])->assertAccepted()->json('id');
        $calculatedCsv = $this->postJson('/report-builder/runs/'.$calculatedId.'/export', ['format' => 'csv', 'section' => 'summary', 'reason' => 'Review calculated measure'])->assertOk()->getContent();
        $this->assertStringContainsString('Adjustment (number)', $calculatedCsv);
        $this->assertStringContainsString('-42.5', $calculatedCsv);
        $this->assertStringNotContainsString("'-42.5", $calculatedCsv);
        $xlsx = $this->postJson('/report-builder/runs/'.$id.'/export', ['format' => 'xlsx', 'reason' => 'Review planning'])->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $xlsxPath = tempnam(sys_get_temp_dir(), 'report-xlsx-test-');
        file_put_contents($xlsxPath, $xlsx->getContent());
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($xlsxPath));
            $xml = $zip->getFromName('xl/worksheets/sheet3.xml');
            $this->assertStringContainsString('<v>-42.5</v>', $xml);
            $zip->close();
        } finally {
            unlink($xlsxPath);
        }
        $pdf = $this->postJson('/report-builder/runs/'.$id.'/export', ['format' => 'pdf', 'reason' => 'Review planning'])->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        if (getenv('REPORT_QA_DIRECTORY')) {
            file_put_contents(getenv('REPORT_QA_DIRECTORY').'/synthetic-report.pdf', $pdf->getContent());
            file_put_contents(getenv('REPORT_QA_DIRECTORY').'/synthetic-report.xlsx', $xlsx->getContent());
        }
        $this->assertStringNotContainsString('Reporting test', DB::table('operational_report_runs')->where('id', $id)->value('payload'));
        $other = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($other)->getJson('/report-builder/runs/'.$id)->assertNotFound();
    }

    public function test_client_export_is_complete_and_clips_to_consent_then_rechecks_before_download(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $type = ConsentType::factory()->create(['name' => 'Personal Tracker (Wandering Risk)', 'purpose' => 'Client personal safety tracking', 'active' => true]);
        $consent = AuthoritativeConsentFixture::manualSelf($client, $type, $actor, [
            'status' => 'given', 'given_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);
        $device = Device::factory()->tracking()->create();
        $assignment = app(DeviceAssignmentService::class)->assign($device, 'client', $client->id, $actor->id, consentId: $consent->id);
        $assignment->update(['assigned_at' => now()->subDays(3), 'collection_started_at' => now()->subDays(3)]);
        $asset = Asset::factory()->create();
        for ($i = 0; $i < 603; $i++) {
            FleetTelemetryEvent::create(['device_id' => $device->id, 'asset_id' => $asset->id, 'vendor' => 'test',
                'occurred_at' => $i < 2 ? now()->subDays(2) : now()->subMinutes(10), 'received_at' => now(),
                'latitude' => -36.8, 'longitude' => 174.7, 'consent_blocked' => false, 'event_type' => 'position', 'idempotency_key' => Str::uuid()->toString()]);
        }
        $data = ['reason' => 'Review authorised observations', 'date_from' => now('Pacific/Auckland')->subDays(3)->toDateString(), 'date_to' => now('Pacific/Auckland')->toDateString()];
        $response = app(PersonalTrackingLocationExportService::class)->export($client, $actor, $data);
        $this->assertSame('601', $response->headers->get('X-Report-Row-Count'));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertCount(603, explode("\n", $csv)); // header + 601 observations + final newline

        $event = FleetTelemetryEvent::where('device_id', $device->id)->latest('id')->first();
        $event->update(['event_type' => 'fall_detected', 'battery_pct' => 50, 'raw_payload' => ['charging_status' => 'charging']]);
        $fleet = FleetSignal::create(['asset_id' => $asset->id, 'device_id' => $device->id, 'source_event_id' => $event->id, 'signal_type' => 'resident.fall_detected', 'occurred_at' => $event->occurred_at, 'severity_hint' => 'critical', 'idempotency_key' => Str::uuid()->toString(), 'payload' => ['event_id' => $event->id, 'assignment_id' => $assignment->id]]);
        $origin = Signal::create(['signal_type_code' => 'fall_detected', 'site_id' => $site->id, 'client_id' => $client->id, 'asset_id' => $asset->id, 'occurred_at' => $event->occurred_at, 'idempotency_key' => Str::uuid()->toString(), 'normalized_data' => ['fleet_signal_id' => $fleet->id, 'client_id' => $client->id]]);
        ControlRoomAlert::factory()->create(['source' => 'personal_tracker', 'alert_type' => 'Fall detected', 'origin_signal_id' => $origin->id, 'site_id' => $site->id, 'client_id' => $client->id, 'asset_id' => $asset->id, 'triggered_at' => $event->occurred_at, 'context' => ['signal_id' => $origin->id, 'normalized_data' => $origin->normalized_data]]);
        foreach (['client_locations', 'client_signals', 'client_zones', 'client_alerts', 'client_authority'] as $sourceKey) {
            $definition = $this->definition($sourceKey);
            $definition['subject_id'] = $client->id;
            $definition['date_from'] = $data['date_from'];
            $definition['date_to'] = $data['date_to'];
            $result = app(ReportSourceReader::class)->read($actor, $definition);
            if ($sourceKey === 'client_signals') {
                $this->assertSame('charging', collect($result['rows'])->firstWhere('battery', 50)['charging']);
            }
            if ($sourceKey === 'client_alerts') {
                $this->assertCount(1, $result['rows']);
            }
        }
        $blocked = app(PersonalTrackingLocationExportService::class)->export($client, $actor, $data);
        $assignment->update(['retention_days' => 30]);
        try {
            ob_start();
            $blocked->sendContent();
            $this->fail('Changed authority must block a previously generated download.');
        } catch (StreamedResponseException $exception) {
            $this->assertSame(403, $exception->getInnerException()->getStatusCode());
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_staff_history_is_session_bound_and_never_restarts_collection(): void
    {
        $actor = $this->actor();
        $site = Site::factory()->create();
        $this->assertArrayNotHasKey('staff_locations', app(ReportAccess::class)->sources($actor));
        $permission = Permission::firstOrCreate(['key' => 'assets.telemetry.history'], ['description' => 'History', 'group' => 'assets', 'module' => 'Fleet']);
        Role::where('name', 'admin')->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $worker = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $session = LoneWorkerSession::create(['user_id' => $worker->id, 'site_id' => $site->id, 'started_at' => now()->subHours(3), 'expected_end_at' => now()->subHour(), 'ended_at' => now()->subHour(), 'status' => 'completed', 'check_in_interval_minutes' => 30, 'created_by' => $actor->id]);
        $device = Device::factory()->tracking()->create();
        $assignment = app(DeviceAssignmentService::class)->assign($device, 'staff', $worker->id, $actor->id);
        $assignment->update(['assigned_at' => now()->subDay(), 'collection_started_at' => now()->subDay(), 'collection_stopped_at' => now()->subHour(), 'retention_days' => 1]);
        $asset = Asset::factory()->create();
        foreach ([4, 2, 0] as $hours) {
            FleetTelemetryEvent::create(['device_id' => $device->id, 'asset_id' => $asset->id, 'vendor' => 'test', 'occurred_at' => now()->subHours($hours), 'received_at' => now(), 'latitude' => -36.8, 'longitude' => 174.7, 'consent_blocked' => false, 'event_type' => 'position', 'idempotency_key' => Str::uuid()->toString()]);
        }
        $normalized = ['source_module' => 'lone_worker', 'signal_type' => LoneWorkerSignalService::TYPE_EMERGENCY, 'lone_worker_session_id' => $session->id, 'worker_user_id' => $worker->id, 'site_id' => $site->id, 'client_id' => null];
        $signal = Signal::create(['signal_type_code' => $normalized['signal_type'], 'site_id' => $site->id, 'client_id' => null, 'occurred_at' => now()->subHours(2), 'idempotency_key' => Str::uuid()->toString(), 'normalized_data' => $normalized]);
        $alert = ControlRoomAlert::factory()->create(['source' => 'lone_worker', 'alert_type' => 'Lone Worker Emergency', 'origin_signal_id' => $signal->id, 'site_id' => $site->id, 'client_id' => null, 'triggered_at' => now()->subHours(2), 'context' => ['signal_id' => $signal->id, 'normalized_data' => $normalized]]);
        $alertDefinition = $this->definition('staff_alerts');
        $alertDefinition['subject_id'] = $session->id;
        $alertDefinition['date_from'] = now('Pacific/Auckland')->subDay()->toDateString();
        $alertDefinition['date_to'] = now('Pacific/Auckland')->toDateString();
        $this->assertCount(1, app(ReportSourceReader::class)->read($actor, $alertDefinition)['rows']);
        $bad = $alert->context;
        $bad['normalized_data']['worker_user_id'] = $actor->id;
        $alert->update(['context' => $bad]);
        $this->assertCount(0, app(ReportSourceReader::class)->read($actor, $alertDefinition)['rows']);
        $d = $this->definition('staff_locations');
        $d['subject_id'] = $session->id;
        $d['date_to'] = now('Pacific/Auckland')->toDateString();
        $d['date_from'] = now('Pacific/Auckland')->subDay()->toDateString();
        $d['precision'] = 'exact';
        $result = app(ReportSourceReader::class)->read($actor, $d);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(-36.8, $result['rows'][0]['latitude']);
        $this->assertNotNull($assignment->fresh()->collection_stopped_at);
        $run = app(ReportRuns::class)->queue($actor, $d, 'Review safety session');
        $this->actingAs($actor)->getJson('/report-builder/runs/'.$run->id)->assertOk()->assertJsonPath('status', 'ready');
        $this->travel(23)->hours();
        $this->getJson('/report-builder/runs/'.$run->id)->assertStatus(410);
        $this->travelBack();
        Role::where('name', 'admin')->first()->permissions()->detach($permission->id);
        $this->actingAs($actor)->postJson('/report-builder/validate', ['definition' => $d])->assertForbidden();
    }

    public function test_report_only_viewer_receives_only_their_site_vehicles(): void
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        $local = Site::factory()->create();
        $other = Site::factory()->create();
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $local->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $role = Role::create(['name' => 'scoped-reporter', 'label' => 'Scoped reporter', 'level' => 10, 'type' => 'custom']);
        $p = Permission::firstOrCreate(['key' => 'fleet.reports.view'], ['description' => 'Fleet reports', 'group' => 'fleet', 'module' => 'Fleet']);
        $role->permissions()->attach($p->id);
        $actor->roles()->attach($role->id);
        $allowed = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $local->id, 'home_site_id' => $local->id, 'client_id' => null]);
        $denied = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $other->id, 'home_site_id' => $other->id, 'client_id' => null]);
        foreach ([$allowed, $denied] as $asset) {
            FleetTrip::create(['asset_id' => $asset->id, 'started_at' => '2026-09-25 10:00:00', 'ended_at' => '2026-09-25 11:00:00', 'distance_km' => 10, 'duration_s' => 3600, 'status' => 'closed', 'is_personal' => false, 'consent_blocked' => false]);
        }
        $rows = app(ReportSourceReader::class)->read($actor, $this->definition())['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame($allowed->name, $rows[0]['resource']);
        $this->actingAs($actor)->getJson('/report-builder/targets?source=journeys')->assertOk()->assertJsonCount(1, 'targets')->assertJsonPath('targets.0.id', $allowed->id)->assertJsonCount(1, 'sites');
        Asset::factory()->create(['category' => 'equipment', 'site_id' => $local->id, 'home_site_id' => $local->id, 'client_id' => null]);
        FleetVehicleStateSnapshot::create(['asset_id' => $allowed->id, 'last_seen_at' => now(), 'consent_blocked' => true]);
        $resources = app(ReportSourceReader::class)->read($actor, $this->definition('resources'))['rows'];
        $this->assertCount(1, $resources);
        $this->assertNull($resources[0]['last_contact']);
        $this->actingAs($actor)->getJson('/report-builder/targets?source=resources')->assertOk()->assertJsonCount(1, 'targets');
        $d = $this->definition();
        $d['resource_ids'] = [$denied->id];
        $this->actingAs($actor)->postJson('/report-builder/runs', ['definition' => $d, 'reason' => 'Review planning'])->assertForbidden();
    }

    public function test_catalogue_queries_execute_with_empty_sources_and_overlapping_intervals_are_unioned(): void
    {
        $actor = $this->actor();
        foreach (app(ReportAccess::class)->sources($actor, 'fleet') as $key => $source) {
            $result = app(ReportSourceReader::class)->read($actor, $this->definition($key));
            $this->assertIsArray($result['rows'], $key);
        }
        $this->assertSame(96, FleetSupplementalReports::unionSeconds([[0, 60], [36, 96], [40, 50]]));
    }

    public function test_finance_costs_require_canonical_asset_site_and_exclude_unposted_invoices(): void
    {
        $actor = $this->actor();
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $asset = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id, 'home_site_id' => $site->id, 'client_id' => null]);
        $journal = FinJournal::factory()->create(['status' => 'posted', 'reversed_by_journal_id' => null]);
        foreach ([[$site->id, $journal->id, 120], [$other->id, $journal->id, 900], [$site->id, null, 700]] as [$siteId,$journalId,$amount]) {
            FinBill::factory()->create(['asset_id' => $asset->id, 'site_id' => $siteId, 'journal_id' => $journalId, 'bill_date' => '2026-09-25', 'total_amount' => $amount]);
        }
        foreach ([60, 0] as $distance) {
            FleetTrip::create(['asset_id' => $asset->id, 'started_at' => '2026-09-25 10:00:00', 'distance_km' => $distance, 'status' => 'closed', 'is_personal' => false, 'consent_blocked' => false]);
        }
        $rows = app(ReportSourceReader::class)->read($actor, $this->definition('resource_costs'))['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(120.0, $rows[0]['posted_cost']);
        $this->assertSame(60.0, $rows[0]['distance']);
        $this->assertSame(1, $rows[0]['missing_distance']);
        $this->assertSame(2.0, $rows[0]['cost_per_km']);
    }

    public function test_conditional_measures_keep_unknowns_and_numeric_equality_matches_decimal_input(): void
    {
        $d = $this->definition();
        $d['filters'] = [['field' => 'distance', 'operator' => 'eq', 'value' => '2.0']];
        $d['measures'][0]['where'] = ['field' => 'status', 'operator' => 'eq', 'value' => 'closed'];
        $r = app(ReportEngine::class)->calculate($d, [['distance' => 2, 'status' => 'closed'], ['distance' => 2, 'status' => 'open'], ['distance' => null, 'status' => 'closed']]);
        $this->assertSame(2, $r['row_count']);
        $this->assertSame(1, $r['totals']['m1']);
        $actor = $this->actor();
        $this->actingAs($actor)->postJson('/report-builder/validate', ['definition' => $d])->assertOk();
        $d['measures'][0]['where']['field'] = 'password';
        $this->postJson('/report-builder/validate', ['definition' => $d])->assertUnprocessable();
    }

    public function test_sharing_copies_only_definition_and_schedules_reauthorise_the_owner(): void
    {
        $actor = $this->actor();
        $recipient = User::factory()->create(['approved_at' => now()]);
        $recipient->roles()->attach(Role::where('name', 'admin')->value('id'));
        $definition = $this->definition();
        $definition['resource_ids'] = [999];
        $definition['filters'] = [['field' => 'resource', 'operator' => 'eq', 'value' => 'Private resource name']];
        $report = $this->actingAs($actor)->postJson('/report-builder/reports', ['definition' => $definition])->assertOk()->json('report');
        $this->postJson('/report-builder/reports/'.$report['id'].'/share', ['email' => $recipient->email])->assertOk();
        $this->actingAs($recipient)->get('/fleet-assets/reports/builder')->assertOk()
            ->assertInertia(fn ($page) => $page->where('shared.0.definition.resource_ids', [])->where('shared.0.definition.filters', []));
        $this->getJson('/report-builder/reports/'.$report['id'].'/versions')->assertNotFound();
        $this->actingAs($actor)->putJson('/report-builder/reports/'.$report['id'], ['definition' => $this->definition(), 'version' => 1])->assertOk();
        $this->postJson('/report-builder/reports/'.$report['id'].'/subscription', ['frequency' => 'daily', 'active' => true, 'reason' => 'Private planning'])->assertOk();
        Queue::fake();
        $subscription = OperationalReportSubscription::first();
        $subscription->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('reports:run-schedules')->assertSuccessful();
        $this->artisan('reports:run-schedules')->assertSuccessful();
        $this->assertSame(1, OperationalReportRun::count());
        Queue::assertPushed(GenerateOperationalReport::class, 1);
        $subscription->refresh()->update(['next_run_at' => now()->subMinute()]);
        $actor->update(['approved_at' => null]);
        $this->assertNull($actor->fresh()->approved_at);
        $this->artisan('reports:run-schedules')->assertSuccessful();
        $this->assertFalse($subscription->fresh()->active);
        $this->assertSame(1, OperationalReportRun::count());
    }

    public function test_grouped_filters_preserve_and_or_unknowns_and_calendar_comparisons(): void
    {
        $d = $this->definition();
        $d['filter_groups'] = [
            ['match' => 'any', 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'open'], ['field' => 'status', 'operator' => 'eq', 'value' => 'closed']]],
            ['match' => 'all', 'filters' => [['field' => 'distance', 'operator' => 'gt', 'value' => '10'], ['field' => 'date', 'operator' => 'gte', 'value' => '2026-09-22']]],
        ];
        $rows = [
            ['date' => '2026-09-22', 'status' => 'open', 'distance' => 20],
            ['date' => '2026-09-23', 'status' => 'closed', 'distance' => 30],
            ['date' => '2026-09-23', 'status' => 'open', 'distance' => null],
            ['date' => '2026-09-21', 'status' => 'closed', 'distance' => 50],
            ['date' => '2026-09-22', 'status' => 'cancelled', 'distance' => 40],
        ];
        $result = app(ReportEngine::class)->calculate($d, $rows);
        $this->assertSame(2, $result['row_count']);
        $d['match'] = 'any';
        $this->assertSame(5, app(ReportEngine::class)->calculate($d, $rows)['row_count']);
        $actor = $this->actor();
        $this->actingAs($actor)->postJson('/report-builder/validate', ['definition' => $d])->assertOk();
        $d['filter_groups'][1]['filters'][1]['value'] = 'not-a-date';
        $this->postJson('/report-builder/validate', ['definition' => $d])->assertUnprocessable();
        $d['filter_groups'][1]['filters'][1]['value'] = '2026-09-22';
        $d['filters'] = [['field' => 'status', 'operator' => 'known', 'value' => null]];
        $this->postJson('/report-builder/validate', ['definition' => $d])->assertUnprocessable();
    }

    public function test_calendar_buckets_keep_chronology_and_recompute_pivot_margins(): void
    {
        $d = $this->definition();
        $d['layout'] = 'line';
        $d['date_bucket'] = 'week';
        $d['sort'] = 'value';
        $d['direction'] = 'desc';
        $d['limit'] = 1;
        $rows = [['date' => '2026-09-20', 'site' => 'A', 'distance' => 10], ['date' => '2026-09-21', 'site' => 'A', 'distance' => 20], ['date' => '2026-09-27', 'site' => 'B', 'distance' => 90]];
        $result = app(ReportEngine::class)->calculate($d, $rows);
        $this->assertSame(['2026-09-14', '2026-09-21'], array_column(array_column($result['chart'], 'dimensions'), 0));
        $this->assertSame([1, 2], array_column($result['chart'], 'row_count'));
        $this->assertLessThan($result['chart'][1]['timestamp'], $result['chart'][0]['timestamp']);
        $d['date_bucket'] = 'month';
        $d['date_to'] = '2026-11-20';
        $this->assertSame([3, 0, 0], array_column(app(ReportEngine::class)->calculate($d, $rows)['chart'], 'row_count'));
        $d['layout'] = 'pivot';
        $d['groups'] = ['site', 'date'];
        $d['measures'] = [['id' => 'm1', 'label' => 'Average distance', 'operation' => 'avg', 'field' => 'distance', 'formula' => null, 'decimals' => 2, 'unit' => 'km']];
        $result = app(ReportEngine::class)->calculate($d, $rows);
        $this->assertEqualsWithDelta(40, $result['totals']['m1'], .0001);
        $this->assertEqualsWithDelta(40, $result['pivot_totals']['columns'][0]['values']['m1'], .0001);
        $this->assertEquals(15, $result['pivot_totals']['rows'][0]['values']['m1']);
        $this->assertEquals(90, $result['pivot_totals']['rows'][1]['values']['m1']);
    }

    public function test_distinct_counts_ignore_unknown_and_detail_sort_keeps_unknown_last(): void
    {
        $d = $this->definition();
        $d['groups'] = [];
        $d['detail_sort'] = 'distance';
        $d['detail_direction'] = 'desc';
        $d['measures'][0]['operation'] = 'distinct';
        $d['measures'][0]['field'] = 'site';
        $result = app(ReportEngine::class)->calculate($d, [['site' => 'A', 'distance' => 0], ['site' => null, 'distance' => null], ['site' => 'A', 'distance' => -5], ['site' => 'B', 'distance' => 10]]);
        $this->assertSame(2, $result['totals']['m1']);
        $this->assertSame([10, 0, -5, null], array_column($result['rows'], 'distance'));
        $this->assertSame(1, $result['missing']['site']);
    }

    public function test_new_builder_options_validate_round_trip_and_shared_filters_are_removed(): void
    {
        $actor = $this->actor();
        $d = $this->definition();
        $d['date_bucket'] = 'week';
        $d['detail_sort'] = 'distance';
        $d['detail_direction'] = 'desc';
        $d['filter_groups'] = [['match' => 'any', 'filters' => [['field' => 'site', 'operator' => 'contains', 'value' => 'Private site']]]];
        $d['highlight'] = ['measure' => 'm1', 'operator' => 'gt', 'value' => 2];
        $saved = $this->actingAs($actor)->postJson('/report-builder/reports', ['definition' => $d])->assertOk()->json('report');
        $this->assertSame($d['filter_groups'], $saved['definition']['filter_groups']);
        $this->assertSame('week', $saved['definition']['date_bucket']);
        $this->assertSame($d['highlight'], $saved['definition']['highlight']);
        $recipient = $this->actor();
        $this->postJson('/report-builder/reports/'.$saved['id'].'/share', ['email' => $recipient->email])->assertOk();
        $this->actingAs($recipient)->get('/fleet-assets/reports/builder')->assertOk()->assertInertia(fn ($page) => $page->has('shared', 1)->where('shared.0.definition.filter_groups', []));
        $bad = $d;
        $bad['highlight']['measure'] = 'm8';
        $this->postJson('/report-builder/validate', ['definition' => $bad])->assertUnprocessable();
        $bad = $d;
        $bad['date_bucket'] = 'year';
        $this->postJson('/report-builder/validate', ['definition' => $bad])->assertUnprocessable();
        $bad = $d;
        $bad['filter_groups'][0]['filters'] = array_fill(0, 26, $d['filter_groups'][0]['filters'][0]);
        $this->postJson('/report-builder/validate', ['definition' => $bad])->assertUnprocessable();
    }
}
