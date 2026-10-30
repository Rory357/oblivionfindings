<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComplianceAlertsQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create();
    }

    private function vehicle(array $attributes = []): Asset
    {
        return Asset::factory()->vehicle()->create([...[
            'name' => 'Queue vehicle', 'status' => 'active', 'site_id' => $this->site->id, 'home_site_id' => $this->site->id,
            'created_by_user_id' => $this->admin->id, 'updated_by_user_id' => $this->admin->id,
            'insurance_expires_at' => null,
        ], ...$attributes]);
    }

    private function evidence(Asset $vehicle, string $kind, array $content = []): int
    {
        $record = DB::table('fleet_vehicle_compliance_records')->insertGetId(['asset_id' => $vehicle->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('fleet_vehicle_compliance_versions')->insertGetId([
            'record_id' => $record, 'version' => 1, 'applicability' => 'applicable', 'outcome' => 'passed',
            'evidence_reference' => 'QUEUE-CERT', 'expires_on' => now('Pacific/Auckland')->toDateString(),
            'recorded_by_user_id' => $this->admin->id, 'request_key' => 'queue-'.$record,
            'request_fingerprint' => hash('sha256', 'request-'.$record), 'content_sha256' => hash('sha256', 'content-'.$record), 'created_at' => now(), ...$content,
        ]);
        DB::table('fleet_vehicle_compliance_records')->where('id', $record)->update(['current_version_id' => $version]);

        return $version;
    }

    public function test_queue_uses_versions_unique_vehicle_counts_and_honest_unknowns(): void
    {
        $vehicle = $this->vehicle(['wof_expires_at' => now()->subYear()]);
        $this->evidence($vehicle, 'wof');
        $this->evidence($vehicle, 'cof', ['applicability' => 'not_applicable', 'applicability_basis' => 'Recorded source decision', 'outcome' => 'recorded', 'expires_on' => null]);
        $this->actingAs($this->admin)->get('/fleet-assets/compliance?view=all')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.vehicles', 1)->where('summary.attention', 1)
                ->where('summary.not_recorded', 1)->where('summary.due_soon', 1)->where('queue.total', 5)
                ->where('queue.data.0.state', 'due_soon')->where('queue.data.2.state', 'not_applicable')
                ->where('queue.data.4.state', 'not_recorded'));
        $this->actingAs($this->admin)->get('/fleet-assets/compliance?view=not_applicable')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('queue.total', 1)->where('summary.vehicles', 1)->where('queue.data.0.basis', 'Recorded source decision'));
    }

    public function test_compliance_scope_precedes_search_counts_and_context_access(): void
    {
        $foreign = Site::factory()->create();
        $visible = $this->vehicle();
        $hidden = $this->vehicle(['name' => 'Hidden vehicle', 'site_id' => $foreign->id, 'home_site_id' => $foreign->id]);
        $viewer = $this->viewer(['fleet.viewAny', 'assets.viewAny']);
        $this->actingAs($viewer)->get('/fleet-assets/compliance?view=all&search=Hidden')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.vehicles', 1)->where('queue.total', 0)->has('sites', 1));
        $this->actingAs($viewer)->getJson('/fleet-assets/compliance/vehicles/'.$visible->id)->assertOk()->assertJsonPath('can.manage', false);
        $this->actingAs($viewer)->getJson('/fleet-assets/compliance/vehicles/'.$hidden->id)->assertNotFound();
        $this->actingAs($viewer)->get('/fleet-assets/compliance?site_id='.$foreign->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.vehicles', 0)->where('queue.total', 0));
    }

    public function test_invalid_version_pointer_never_exposes_another_vehicle_evidence(): void
    {
        $vehicle = $this->vehicle();
        $other = $this->vehicle(['name' => 'Other vehicle']);
        $this->evidence($vehicle, 'wof');
        $foreignVersion = $this->evidence($other, 'wof', ['evidence_reference' => 'OTHER-CERT']);
        DB::table('fleet_vehicle_compliance_records')->where('asset_id', $vehicle->id)->where('kind', 'wof')->update(['current_version_id' => $foreignVersion]);
        $this->actingAs($this->admin)->get('/fleet-assets/compliance?view=all&search=Queue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('queue.data.0.state', 'not_recorded')->where('queue.data.0.reference', null));
    }

    public function test_alert_instruments_cover_all_pages_before_severity_and_share_entry_scope(): void
    {
        $vehicle = $this->vehicle();
        $base = ['site_id' => $this->site->id, 'asset_id' => $vehicle->id, 'alert_type' => 'queue_probe'];
        ControlRoomAlert::factory()->fromFleet()->open()->high()->count(27)->create($base);
        ControlRoomAlert::factory()->fromFleet()->open()->critical()->count(3)->create($base);
        ControlRoomAlert::factory()->fromCompliance()->open()->critical()->create($base);
        ControlRoomAlert::factory()->fromFleet()->resolved()->create($base);
        $this->actingAs($this->admin)->get('/fleet-assets/alerts?search=queue_probe&severity=high&cr_page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hero.unresolved', 30)->where('severity_total', 30)
                ->where('severity_counts.high', 27)->where('severity_counts.critical', 3)
                ->where('control_room_alerts.meta.total', 27)->has('control_room_alerts.data', 2)
                ->where('control_room_alerts.data.0.source', 'fleet')
                ->where('control_room_alerts.data.0.asset.category', 'vehicle'));
        $this->actingAs($this->admin)->get('/fleet-assets/vehicles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('compliance.open_alerts', 30));
        $this->actingAs($this->admin)->get('/fleet-assets/alerts?status=all&search=queue_probe')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('severity_total', 31)->where('control_room_alerts.meta.total', 31));
    }

    public function test_alert_search_empty_and_foreign_site_never_widen_counts(): void
    {
        $viewer = $this->viewer(['assets.alerts.view']);
        ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $this->site->id]);
        $other = Site::factory()->create();
        ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $other->id]);
        $this->actingAs($viewer)->get('/fleet-assets/alerts?search=no-such-alert')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hero.unresolved', 1)->where('severity_total', 0)->where('control_room_alerts.meta.total', 0)->where('can.manage', false));
        $this->actingAs($viewer)->get('/fleet-assets/alerts?site_id='.$other->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hero.unresolved', 0)->where('severity_total', 0)->where('control_room_alerts.meta.total', 0));
    }

    public function test_response_checks_current_status_and_retains_canonical_authority(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $this->site->id]);
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/'.$alert->id.'/acknowledge', ['expected_status' => 'open', 'notes' => 'Checking source'])->assertOk()->assertJsonPath('status', 'ack');
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/'.$alert->id.'/acknowledge', ['expected_status' => 'open'])->assertConflict();
        $this->assertSame('ack', $alert->fresh()->status);
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/'.$alert->id.'/resolve', ['expected_status' => 'ack', 'resolution_notes' => 'Cannot skip triage'])->assertConflict();
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/'.$alert->id.'/triage', ['expected_status' => 'ack'])->assertOk();
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/'.$alert->id.'/resolve', ['expected_status' => 'triaging', 'resolution_notes' => ''])->assertUnprocessable();
        $this->actingAs($this->viewer(['assets.alerts.view']))->postJson('/fleet-assets/alerts/'.$alert->id.'/resolve', ['expected_status' => 'triaging', 'resolution_notes' => 'Not allowed'])->assertForbidden();
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/'.$alert->id.'/resolve', ['expected_status' => 'triaging', 'resolution_notes' => 'Source checked and outcome recorded'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'controlRoom.alert.resolve', 'auditable_id' => $alert->id]);
    }

    public function test_bulk_json_response_is_atomic_retains_notes_and_snapshots_keep_scope(): void
    {
        $first = ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $this->site->id]);
        $second = ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $this->site->id]);
        $payload = ['action' => 'acknowledge', 'ids' => [$first->id, $second->id], 'notes' => 'Checking both vehicles', 'expected_statuses' => [$first->id => 'open', $second->id => 'ack']];
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/bulk-action', $payload)->assertConflict();
        $this->assertSame('open', $first->fresh()->status);
        $payload['expected_statuses'][$second->id] = 'open';
        $this->actingAs($this->admin)->postJson('/fleet-assets/alerts/bulk-action', $payload)->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame('Checking both vehicles', data_get($first->fresh()->context, 'activity_log.0.content'));
        $this->assertSame('Checking both vehicles', data_get($second->fresh()->context, 'activity_log.0.content'));
        $this->actingAs($this->admin)->getJson('/fleet-assets/alerts/'.$first->id.'/snapshot')->assertOk()->assertJsonPath('status', 'ack')->assertJsonPath('can_respond', true);
        $viewer = $this->viewer(['assets.alerts.view']);
        $this->actingAs($viewer)->getJson('/fleet-assets/alerts/'.$first->id.'/snapshot')->assertOk()->assertJsonPath('can_respond', false)->assertJsonPath('can_open_control_room', false);
        $foreign = ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => Site::factory()->create()->id]);
        $this->actingAs($viewer)->getJson('/fleet-assets/alerts/'.$foreign->id.'/snapshot')->assertNotFound();
        $this->actingAs($viewer)->postJson('/fleet-assets/alerts/bulk-action', $payload)->assertForbidden();
    }

    private function viewer(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $user->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => []]);

        return $user;
    }
}
