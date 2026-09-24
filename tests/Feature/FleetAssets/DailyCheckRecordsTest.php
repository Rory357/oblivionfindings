<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetChecklistTemplateVersion;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\MaintenanceAttachmentService;
use App\Services\Fleet\MaintenanceCheckService;
use App\Services\Fleet\MaintenanceFingerprint;
use App\Services\Fleet\MaintenanceRestrictionService;
use App\Services\Fleet\MaintenanceTransitionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Daily checks from the Daily checks page are submitted records against the
 * exact daily checklist version. Checking again adds a record and never
 * changes an earlier one; the checks appear with the vehicle's recent checks;
 * and, as decided for PKG-02B, they never block bookings or a maintenance
 * release, whatever they record.
 */
class DailyCheckRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        // 9:30 am in Auckland is 9:30 pm the day before in UTC.
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create([
            'name' => 'Kōwhai House', 'is_active' => true, 'archived' => false, 'archived_at' => null,
        ]);
    }

    public function test_a_daily_check_is_a_submitted_record_against_the_exact_checklist_version(): void
    {
        $worker = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle();
        $url = '/fleet-assets/daily-check';

        $this->actingAs($worker)->post($url, ['asset_id' => $vehicle->id, 'condition' => 'good'])
            ->assertSessionHasErrors('request_key');
        $this->actingAs($worker)->post($url, ['asset_id' => $vehicle->id, 'condition' => 'maybe', 'request_key' => 'daily-bad-condition'])
            ->assertSessionHasErrors('condition');
        $this->assertSame(0, FleetChecklistRun::query()->count());

        $this->actingAs($worker)->post($url, [
            'asset_id' => $vehicle->id, 'condition' => 'issue', 'notes' => ' Rear tyre looks low ', 'request_key' => 'daily-first-check',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $run = FleetChecklistRun::query()->sole();
        $template = FleetChecklistTemplate::query()->where('type', FleetChecklistTemplate::TYPE_DAILY_CHECK)->sole();
        $version = FleetChecklistTemplateVersion::query()->where('template_id', $template->id)->sole();
        $this->assertSame(1, $version->version);
        $this->assertSame(FleetChecklistTemplateVersion::SOURCE_EXISTING, $version->source);
        $this->assertSame((int) $version->id, (int) $run->template_version_id);
        $this->assertSame(FleetChecklistRun::KIND_DAILY, $run->check_kind);
        $this->assertSame(FleetChecklistRun::OUTCOME_ISSUE, $run->outcome);
        $this->assertFalse($run->passed);
        $this->assertSame('2026-09-21 21:30:00', $run->submitted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 21:30:00', $run->observed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('daily-first-check', $run->request_key);
        $this->assertSame(64, strlen((string) $run->request_fingerprint));
        // No approved rule evaluates a daily check.
        $this->assertNull($run->rule_version_id);
        $this->assertNull($run->rule_snapshot_json);
        // The questions as shown, with the answers kept against them.
        $this->assertSame('Visual Condition', $run->presented_template_json['items'][0]['label']);
        $this->assertSame('issue', $run->responses['0']['result']);
        $this->assertSame('Rear tyre looks low', $run->responses['1']['result']);
        $this->assertSame('daily_check', $run->responses['_metadata']['source']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'fleet.daily_check.submit')
            ->where('auditable_id', $run->id)->exists());
    }

    public function test_checking_again_adds_a_record_and_leaves_earlier_checks_unchanged(): void
    {
        $worker = $this->siteUser(['fleet.viewAny']);
        $colleague = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle();
        $check = fn (User $user, array $body) => $this->actingAs($user)
            ->post('/fleet-assets/daily-check', ['asset_id' => $vehicle->id] + $body);

        $check($worker, ['condition' => 'good', 'request_key' => 'daily-morning'])->assertSessionHasNoErrors();
        $first = FleetChecklistRun::query()->sole();
        $original = $first->getRawOriginal();

        $this->travel(2)->hours();
        $afternoon = ['condition' => 'issue', 'notes' => 'Scratch on the sliding door', 'request_key' => 'daily-afternoon'];
        $check($colleague, $afternoon)->assertSessionHasNoErrors();
        // An identical retry returns the saved check; the key can't be reused for other details.
        $check($colleague, $afternoon)->assertSessionHasNoErrors();
        $check($colleague, ['condition' => 'good'] + $afternoon)->assertSessionHasErrors('request_key');

        $this->assertSame(2, FleetChecklistRun::query()->count());
        $this->assertSame($original, FleetChecklistRun::query()->findOrFail($first->id)->getRawOriginal());
        $this->assertSame(1, FleetChecklistTemplateVersion::query()->count(), 'Both checks use the same checklist version.');

        $this->actingAs($worker)->get('/fleet-assets/daily-check')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/daily-check')
                ->has('vehicles', 1)
                ->where('vehicles.0.checked_today', true)
                ->where('vehicles.0.checks_today', 2)
                ->where('vehicles.0.check_result', 'issue')
                ->where('vehicles.0.checked_by', $colleague->name)
                ->where('vehicles.0.check_notes', 'Scratch on the sliding door')
                ->where('summary.checked', 1)
                ->where('can.view_vehicles', true));
    }

    public function test_the_daily_checks_page_counts_the_auckland_day(): void
    {
        $worker = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle();
        $this->actingAs($worker)->post('/fleet-assets/daily-check', [
            'asset_id' => $vehicle->id, 'condition' => 'good', 'request_key' => 'daily-auckland-morning',
        ])->assertSessionHasNoErrors();
        $assertChecked = fn (bool $checked, int $count) => $this->actingAs($worker)->get('/fleet-assets/daily-check')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/daily-check')
                ->where('vehicles.0.checked_today', $checked)
                ->where('vehicles.0.checks_today', $count));

        // 3 pm in Auckland is the next day in UTC; the morning check still counts.
        $this->travelTo(Carbon::parse('2026-09-22 15:00:00', 'Pacific/Auckland')->utc());
        $assertChecked(true, 1);
        $this->travelTo(Carbon::parse('2026-09-23 00:30:00', 'Pacific/Auckland')->utc());
        $assertChecked(false, 0);
    }

    public function test_daily_checks_never_block_bookings_while_vehicle_checks_still_do(): void
    {
        $worker = $this->siteUser(['fleet.viewAny']);
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle();
        $restrictions = app(MaintenanceRestrictionService::class);

        $this->actingAs($worker)->post('/fleet-assets/daily-check', [
            'asset_id' => $vehicle->id, 'condition' => 'issue', 'notes' => 'Warning light on', 'request_key' => 'daily-issue',
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $restrictions->blockers($vehicle->id)['check_run_ids']);
        $this->assertSame([], $restrictions->blockersMany([$vehicle->id])[$vehicle->id]['check_run_ids']);
        $restrictions->assertBookable($vehicle->id);
        // So there is nothing for Maintenance to release as "no issue found".
        $daily = FleetChecklistRun::query()->sole();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks/{$daily->id}/assessments", [
            'decision' => 'no_issue_release', 'reason' => 'Looked at it; all fine.', 'confirmed' => true, 'request_key' => 'assess-daily',
        ])->assertUnprocessable()->assertJsonPath('errors.run.0', 'Daily checks don’t stop the vehicle being used, so there’s nothing to release.');

        // A vehicle check without an approved rule still needs assessment and blocks.
        $template = FleetChecklistTemplate::query()->create([
            'name' => 'Vehicle condition record', 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'exterior', 'label' => 'Exterior condition', 'type' => 'select', 'kind' => 'condition',
                'options' => ['pass', 'fail', 'unable'], 'required' => true]],
        ]);
        $check = $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks", [
            'template_id' => $template->id, 'items_sha256' => MaintenanceFingerprint::of($template->items),
            'observed_local' => '2026-09-22T09:00', 'answers' => ['exterior' => 'pass'], 'request_key' => 'vehicle-check',
        ])->assertOk()->assertJsonPath('run.outcome', 'needs_assessment')->json('run');
        $this->assertSame([$check['id']], $restrictions->blockers($vehicle->id)['check_run_ids']);
        $this->expectException(ValidationException::class);
        $restrictions->assertBookable($vehicle->id);
    }

    public function test_daily_checks_are_listed_with_the_vehicles_recent_checks(): void
    {
        $worker = $this->siteUser(['fleet.viewAny']);
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle();
        $this->actingAs($worker)->post('/fleet-assets/daily-check', [
            'asset_id' => $vehicle->id, 'condition' => 'issue', 'notes' => 'Rear tyre looks low', 'request_key' => 'daily-profile',
        ])->assertSessionHasNoErrors();
        $run = FleetChecklistRun::query()->sole();
        $url = "/fleet-assets/vehicles/{$vehicle->id}/checks";

        $checks = $this->actingAs($worker)->getJson($url)->assertOk()
            ->assertJsonPath('runs.total', 1)
            ->assertJsonPath('runs.data.0.id', $run->id)
            ->assertJsonPath('runs.data.0.reference', 'CHK-'.$run->id)
            ->assertJsonPath('runs.data.0.template', 'Daily Vehicle Check')
            ->assertJsonPath('runs.data.0.version', 1)
            ->assertJsonPath('runs.data.0.check_kind', 'daily')
            ->assertJsonPath('runs.data.0.outcome', 'issue_recorded')
            ->assertJsonPath('runs.data.0.rule_applied', false)
            ->assertJsonPath('runs.data.0.recorded_by', $worker->name)
            ->assertJsonPath('runs.data.0.answers.0.label', 'Visual Condition')
            ->assertJsonPath('runs.data.0.answers.0.value', 'Issue')
            ->assertJsonPath('runs.data.0.answers.1.label', 'Notes')
            ->assertJsonPath('runs.data.0.answers.1.value', 'Rear tyre looks low');
        // The daily checklist isn't offered as a checklist on the vehicle profile.
        $this->assertNotContains('Daily Vehicle Check', collect($checks->json('templates'))->pluck('name')->all());

        // The header keeps the latest daily check apart from the vehicle's check result.
        $this->actingAs($worker)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/vehicles/show')
                ->where('workspace.checks.latest', null)
                ->where('workspace.checks.latest_daily.id', $run->id)
                ->where('workspace.checks.latest_daily.outcome', 'issue_recorded'));
        // Maintenance's check views show it as a daily check, not one waiting on a rule.
        $this->actingAs($manager)->get("/fleet-assets/inspections/{$run->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/inspections/show')
                ->where('inspection.check_kind', 'daily')
                ->where('inspection.outcome', 'issue_recorded'));
        $this->actingAs($manager)->get('/fleet-assets/maintenance/checklists')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/maintenance/checklists/index')
                ->where('recent_runs.0.id', $run->id)
                ->where('recent_runs.0.outcome', 'issue_recorded'));

        // A correction is kept beside the daily check, which stays as recorded.
        $original = $run->fresh()->getRawOriginal();
        $this->actingAs($manager)->postJson("{$url}/{$run->id}/amendments", [
            'note' => 'Tyre pressure checked at the station; it was fine.', 'request_key' => 'daily-amendment',
        ])->assertOk();
        $this->assertSame($original, $run->fresh()->getRawOriginal());
        $this->actingAs($worker)->getJson($url)->assertOk()
            ->assertJsonPath('runs.data.0.amendments.0.note', 'Tyre pressure checked at the station; it was fine.')
            ->assertJsonPath('runs.data.0.amendments.0.recorded_by', $manager->name);
    }

    public function test_the_daily_checklist_is_only_recorded_from_the_daily_checks_page(): void
    {
        $worker = $this->siteUser(['fleet.viewAny']);
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle();
        $this->actingAs($worker)->post('/fleet-assets/daily-check', [
            'asset_id' => $vehicle->id, 'condition' => 'good', 'request_key' => 'daily-only-here',
        ])->assertSessionHasNoErrors();
        $template = FleetChecklistTemplate::query()->where('type', FleetChecklistTemplate::TYPE_DAILY_CHECK)->sole();
        $version = FleetChecklistTemplateVersion::query()->where('template_id', $template->id)->sole();

        // Not as a vehicle check, the vehicle's check requirement or a new library version...
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks", [
            'template_id' => $template->id, 'items_sha256' => $version->items_sha256,
            'observed_local' => '2026-09-22T09:00', 'answers' => ['0' => 'good'], 'request_key' => 'profile-daily',
        ])->assertNotFound();
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}/check-requirement", [
            'template_id' => $template->id, 'due_on' => '2026-09-30', 'owner_user_id' => $worker->id, 'expected_version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/check-templates/{$template->id}/versions", [
            'name' => 'Daily Vehicle Check', 'use' => 'Before vehicle use', 'assignment' => 'all_vehicles',
            'evidence_required' => false, 'confirmed' => true,
            'questions' => [['id' => 'condition', 'label' => 'Condition', 'kind' => 'condition', 'required' => true]],
            'expected_version_id' => $version->id, 'expected_items_sha256' => $version->items_sha256, 'request_key' => 'publish-daily',
        ])->assertNotFound();
        // ...nor a Maintenance checklist run, which offers only Maintenance checklists.
        $maintenance = FleetChecklistTemplate::query()->create([
            'name' => 'Pre-trip van check', 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'tyres', 'label' => 'Tyres', 'type' => 'select', 'options' => ['pass', 'fail']]],
        ]);
        $this->actingAs($manager)->get('/fleet-assets/maintenance/checklists/run')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/maintenance/checklists/run')
                ->where('templates', fn ($templates) => $templates->pluck('id')->all() === [$maintenance->id]));
        $this->actingAs($manager)->post("/fleet-assets/maintenance/checklists/{$template->id}/run", [
            'asset_id' => $vehicle->id, 'results' => ['0' => ['result' => 'good']], 'request_key' => 'maintenance-daily',
        ])->assertSessionHasErrors('template_id');

        $this->assertSame(1, FleetChecklistRun::query()->count());
        $this->assertSame(1, FleetChecklistTemplateVersion::query()->count());
        $this->assertSame($version->items_sha256, MaintenanceFingerprint::of($template->fresh()->items));
    }

    public function test_a_daily_check_after_the_retest_does_not_hold_up_the_release(): void
    {
        Storage::fake('private');
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.maintenance.manage']);
        $reviewer = $this->siteUser(['fleet.maintenance.release']);
        $worker = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle();
        $this->recordReadyEvidence($vehicle);
        $retestTemplate = FleetChecklistTemplate::query()->create([
            'name' => 'Repair retest', 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'condition', 'label' => 'Condition', 'type' => 'select', 'options' => ['pass', 'fail']]],
        ]);
        $this->approveRule($vehicle, $manager, 'hold', ['allowed_kinds' => ['safety']]);
        $this->approveRule($vehicle, $manager, 'repair', ['requires_service_evidence' => true]);
        $this->approveRule($vehicle, $manager, 'release', ['requires_custody' => false]);
        $retestRule = $this->approveRule($vehicle, $manager, 'retest', [
            'template_id' => $retestTemplate->id, 'template_sha256' => MaintenanceFingerprint::of($retestTemplate->items),
            'questions' => [['id' => 'condition', 'pass_values' => ['pass'], 'allow_na' => false]],
        ]);
        DB::table('fleet_maintenance_reviewer_grants')->insert([
            'site_id' => $this->site->id, 'user_id' => $reviewer->id, 'asset_category' => $vehicle->category,
            'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
            'recorded_by_user_id' => $manager->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);
        $transitions = app(MaintenanceTransitionService::class);
        $order = FleetWorkOrder::query()->create([
            'asset_id' => $vehicle->id, 'reported_by_user_id' => $manager->id, 'assigned_to_user_id' => $manager->id,
            'title' => 'Replace the rear tyre', 'category' => 'vehicle', 'priority' => 'high', 'status' => 'open',
        ]);
        $transitions->execute($manager, $order->id, 'place_restriction', 0, 'daily-release-hold', ['restriction_kind' => 'safety']);
        $this->travel(1)->minutes();
        $transitions->execute($manager, $order->id, 'attest_repair', 1, 'daily-release-attest', ['summary' => 'Replaced the rear tyre']);
        $attestation = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->where('action_type', 'attest_repair')->value('id');
        app(MaintenanceAttachmentService::class)->upload($manager, $order->id, 'action', $attestation,
            'daily-release-evidence', UploadedFile::fake()->image('service.png'));
        $transitions->execute($manager, $order->id, 'complete', 2, 'daily-release-complete');
        $retest = function (string $key) use ($manager, $vehicle, $order, $retestTemplate, $retestRule): void {
            $this->travel(1)->minutes();
            $held = DB::table('fleet_maintenance_restrictions')->where('asset_id', $vehicle->id)
                ->where('state', 'active')->orderBy('id')->pluck('id')->all();
            $run = app(MaintenanceCheckService::class)->submit($manager, [
                'asset_id' => $vehicle->id, 'template_id' => $retestTemplate->id, 'check_kind' => 'retest',
                'work_order_id' => $order->id, 'rule_version_id' => $retestRule, 'covered_restriction_ids' => $held,
                'answers' => ['condition' => ['result' => 'pass']], 'request_key' => $key,
            ]);
            $this->assertSame('passed', $run->outcome);
        };
        $retest('daily-release-retest');

        // A vehicle check recorded after the retest still holds up the release...
        $this->travel(1)->minutes();
        $vehicleCheck = FleetChecklistTemplate::query()->create([
            'name' => 'Vehicle condition record', 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'exterior', 'label' => 'Exterior condition', 'type' => 'select', 'options' => ['pass', 'fail']]],
        ]);
        app(MaintenanceCheckService::class)->submit($manager, [
            'asset_id' => $vehicle->id, 'template_id' => $vehicleCheck->id, 'check_kind' => 'check',
            'answers' => ['exterior' => ['result' => 'pass']], 'request_key' => 'daily-release-vehicle-check',
        ]);
        try {
            $transitions->execute($reviewer, $order->id, 'release', 3, 'daily-release-refused');
            $this->fail('A newer unassessed vehicle check should hold up the release.');
        } catch (ValidationException $error) {
            $this->assertSame('A newer unresolved check prevents release.', $error->errors()['status'][0]);
        }

        // ...until a new retest covers it. Daily checks after that don't, whatever they
        // record, and nor does a daily check saved before daily checks were submitted records.
        $retest('daily-release-second-retest');
        $this->travel(1)->minutes();
        $this->actingAs($worker)->post('/fleet-assets/daily-check', [
            'asset_id' => $vehicle->id, 'condition' => 'issue', 'notes' => 'Tyre still looks soft', 'request_key' => 'daily-after-retest',
        ])->assertSessionHasNoErrors();
        DB::table('fleet_checklist_runs')->insert([
            'template_id' => FleetChecklistTemplate::query()->where('type', FleetChecklistTemplate::TYPE_DAILY_CHECK)->value('id'),
            'asset_id' => $vehicle->id, 'user_id' => $worker->id, 'responses' => json_encode(['condition' => 'good']),
            'passed' => true, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $transitions->execute($reviewer, $order->id, 'release', 3, 'daily-release');
        $this->assertSame('released', DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)->value('state'));
        app(MaintenanceRestrictionService::class)->assertBookable($vehicle->id);
    }

    private function siteUser(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    private function vehicle(): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }

    /** @param array<string,mixed> $rules */
    private function approveRule(Asset $vehicle, User $approver, string $kind, array $rules): int
    {
        $id = DB::table('fleet_maintenance_policy_versions')->insertGetId([
            'site_id' => $this->site->id, 'asset_category' => $vehicle->category, 'rule_kind' => $kind, 'version' => 1,
            'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR), 'content_sha256' => MaintenanceFingerprint::of($rules),
            'approved_by_user_id' => $approver->id, 'approved_at' => now(), 'created_at' => now(),
        ]);
        DB::table('fleet_maintenance_policy_assignments')->insert([
            'site_id' => $this->site->id, 'asset_category' => $vehicle->category, 'rule_kind' => $kind,
            'policy_version_id' => $id, 'assigned_by_user_id' => $approver->id,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Current registration and WoF evidence, so release readiness turns on the maintenance rules alone. */
    private function recordReadyEvidence(Asset $vehicle): void
    {
        $evidence = [
            'registration' => ['applicable', 'recorded', 'REG-DAILY', '2027-12-31', null],
            'wof' => ['applicable', 'passed', 'WOF-DAILY', '2027-12-31', null],
            'cof' => ['not_applicable', 'needs_assessment', null, null, 'Light vehicle; a WoF applies instead.'],
            'ruc' => ['not_applicable', 'needs_assessment', null, null, 'Petrol vehicle.'],
        ];
        foreach ($evidence as $kind => [$applicability, $outcome, $reference, $expires, $basis]) {
            $recordId = DB::table('fleet_vehicle_compliance_records')->insertGetId([
                'asset_id' => $vehicle->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $versionId = DB::table('fleet_vehicle_compliance_versions')->insertGetId([
                'record_id' => $recordId, 'version' => 1, 'applicability' => $applicability,
                'applicability_basis' => $basis, 'outcome' => $outcome, 'evidence_reference' => $reference,
                'expires_on' => $expires, 'request_key' => "daily-evidence-{$kind}",
                'request_fingerprint' => str_repeat('e', 64), 'content_sha256' => str_repeat('e', 64),
                'created_at' => now(),
            ]);
            DB::table('fleet_vehicle_compliance_records')->where('id', $recordId)->update(['current_version_id' => $versionId]);
        }
    }
}
