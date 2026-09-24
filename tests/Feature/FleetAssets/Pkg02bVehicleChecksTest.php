<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistRunAmendment;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetChecklistTemplateVersion;
use App\Models\FleetVehicleCheckRequirement;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Fleet\MaintenanceFingerprint;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PKG-02B vehicle Checks & inspections: the checks read model, recording a
 * check against its exact checklist version through the Maintenance check
 * rules, evidence kept with a check, controlled checklist versions, the
 * vehicle's check requirement, amendments, reporting a problem from a check,
 * and the daily-check site scope.
 */
class Pkg02bVehicleChecksTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
        Storage::fake('private');
        // No scanning binary runs in tests; treat uploads as clean.
        $this->app->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                return new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner', null);
            }
        });
    }

    public function test_the_checks_read_model_follows_vehicle_access(): void
    {
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $template = $this->template();
        $url = "/fleet-assets/vehicles/{$vehicle->id}/checks";

        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($reader)->getJson('/fleet-assets/vehicles/999999/checks')->assertNotFound();
        $read = $this->actingAs($reader)->getJson($url)->assertOk();
        // An existing checklist shows the version its content will be recorded as.
        $read->assertJsonPath('templates.0.id', $template->id)
            ->assertJsonPath('templates.0.version', 1)
            ->assertJsonPath('templates.0.version_id', null)
            ->assertJsonPath('templates.0.items_sha256', MaintenanceFingerprint::of($template->items))
            ->assertJsonPath('templates.0.questions.0.kind', 'condition')
            ->assertJsonPath('templates.0.questions.0.options.1.label', 'Issue recorded')
            ->assertJsonPath('templates.0.rule_version_id', null)
            ->assertJsonPath('requirement.template_id', $template->id)
            ->assertJsonPath('requirement.source', 'library')
            ->assertJsonPath('runs.total', 0)
            ->assertJsonPath('route', null)
            ->assertJsonPath('can.start', false)
            ->assertJsonPath('can.report', false);
        $this->actingAs($manager)->getJson($url)->assertOk()
            ->assertJsonPath('can.start', true)
            ->assertJsonPath('can.manage_templates', true)
            ->assertJsonPath('can.manage_requirement', false);
        $this->assertSame(0, FleetChecklistTemplateVersion::query()->count(), 'Reading never records a version.');
    }

    public function test_a_check_is_recorded_through_the_maintenance_rules_against_its_exact_version(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $template = $this->template();
        $url = "/fleet-assets/vehicles/{$vehicle->id}/checks";
        $body = [
            'template_id' => $template->id, 'items_sha256' => MaintenanceFingerprint::of($template->items),
            'observed_local' => '2026-09-22T09:00', 'answers' => ['exterior' => 'fail'],
            'notes' => 'Scratch on the rear door.', 'request_key' => 'check-first',
        ];

        $this->actingAs($reader)->postJson($url, $body)->assertForbidden();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$foreign->id}/checks", $body)->assertNotFound();
        $this->actingAs($manager)->postJson($url, ['answers' => [], 'request_key' => 'check-missing'] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('answers.exterior');
        $this->actingAs($manager)->postJson($url, ['observed_local' => '2026-09-23T09:00', 'request_key' => 'check-future'] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('observed_local');
        $this->assertSame(0, FleetChecklistRun::query()->count());

        // Without an approved rule the check is recorded for assessment.
        $first = $this->actingAs($manager)->postJson($url, $body)->assertOk()
            ->assertJsonPath('run.outcome', 'needs_assessment')->json('run');
        $run = FleetChecklistRun::query()->findOrFail($first['id']);
        $version = FleetChecklistTemplateVersion::query()->where('template_id', $template->id)->sole();
        $this->assertSame(1, $version->version);
        $this->assertSame(FleetChecklistTemplateVersion::SOURCE_EXISTING, $version->source);
        $this->assertSame((int) $version->id, (int) $run->template_version_id);
        $this->assertSame('Exterior condition', $run->presented_template_json['items'][0]['label']);
        $this->assertSame('2026-09-21 21:00:00', $run->observed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('check', $run->check_kind);
        $this->assertSame('vehicle_profile', $run->responses['_metadata']['source']);

        // A retry returns the same check; the key can't be reused for different answers.
        $this->actingAs($manager)->postJson($url, $body)->assertOk()->assertJsonPath('run.id', $first['id']);
        $this->actingAs($manager)->postJson($url, ['answers' => ['exterior' => 'pass']] + $body)->assertStatus(409);
        $this->assertSame(1, FleetChecklistRun::query()->count());

        // A checklist changed since it was opened can't take the stale answers.
        $template->update(['items' => [$this->conditionItem('exterior', 'Exterior paint and panels')]]);
        $this->actingAs($manager)->postJson($url, ['request_key' => 'check-stale'] + $body)->assertStatus(409);

        // An approved rule covering the current version decides Passed or Failed.
        $template->refresh();
        $ruleId = $this->approveCheckRule($vehicle, $template, $manager);
        $current = ['items_sha256' => MaintenanceFingerprint::of($template->items), 'rule_version_id' => $ruleId];
        $this->actingAs($manager)->getJson($url)->assertOk()->assertJsonPath('templates.0.rule_version_id', $ruleId)
            ->assertJsonPath('templates.0.version', 2);
        $passed = $this->actingAs($manager)->postJson($url, ['answers' => ['exterior' => 'pass'], 'request_key' => 'check-pass'] + $current + $body)
            ->assertOk()->assertJsonPath('run.outcome', 'passed')->json('run');
        $this->actingAs($manager)->postJson($url, ['request_key' => 'check-fail'] + $current + $body)
            ->assertOk()->assertJsonPath('run.outcome', 'failed');
        $second = FleetChecklistTemplateVersion::query()->where('template_id', $template->id)->where('version', 2)->sole();
        $this->assertSame((int) $second->id, (int) FleetChecklistRun::query()->findOrFail($passed['id'])->template_version_id);
        // The earlier check keeps its original version and wording.
        $this->assertSame((int) $version->id, (int) $run->fresh()->template_version_id);
        $this->assertSame('Exterior condition', $run->fresh()->presented_template_json['items'][0]['label']);

        $runs = $this->actingAs($reader)->getJson($url)->assertOk()->json('runs');
        $this->assertSame(3, $runs['total']);
        $original = collect($runs['data'])->firstWhere('id', $run->id);
        $this->assertSame('Issue recorded', $original['answers'][0]['value']);
        $this->assertSame(1, $original['version']);
        $this->assertSame('CHK-'.$run->id, $original['reference']);
    }

    public function test_required_evidence_is_kept_with_the_check_as_private_vehicle_documents(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage', 'fleet.settings.manage', 'assets.viewAny', 'assets.documents.manage']);
        $vehicle = $this->vehicle($this->site);
        $otherVehicle = $this->vehicle($this->site);
        $published = $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/check-templates", [
            'name' => 'Hoist condition record', 'use' => 'Accessibility equipment', 'assignment' => 'all_vehicles',
            'evidence_required' => true, 'confirmed' => true, 'request_key' => 'publish-hoist',
            'questions' => [['id' => 'hoist', 'label' => 'Hoist condition', 'kind' => 'condition', 'required' => true]],
        ])->assertOk()->assertJsonPath('template.version', 1)->json('template');
        $template = FleetChecklistTemplate::query()->findOrFail($published['id']);
        $listed = collect($this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->json('templates'))
            ->firstWhere('id', $template->id);
        $this->assertTrue($listed['evidence_required']);
        $this->assertSame($published['version_id'], $listed['version_id']);

        $url = "/fleet-assets/vehicles/{$vehicle->id}/checks";
        $body = [
            'template_id' => $template->id, 'template_version_id' => $published['version_id'],
            'items_sha256' => $listed['items_sha256'], 'observed_local' => '2026-09-22T08:45',
            'answers' => ['hoist' => 'pass'],
        ];
        $this->actingAs($manager)->postJson($url, $body + ['request_key' => 'hoist-no-file'])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
        $pdf = UploadedFile::fake()->createWithContent('hoist.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $run = $this->actingAs($manager)->post($url, $body + ['files' => [$pdf], 'request_key' => 'hoist-with-file'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('files.0.state', 'available')->json('run');
        $document = AssetDocument::query()->where('source_type', 'checklist_run')->where('source_id', $run['id'])->sole();
        $this->assertSame($vehicle->id, (int) $document->asset_id);
        $this->assertSame('hoist.pdf', $document->original_name);

        // More files can be added to the check later; never to another vehicle's check.
        $foreignRun = FleetChecklistRun::query()->create([
            'template_id' => $template->id, 'asset_id' => $otherVehicle->id, 'user_id' => $manager->id, 'responses' => [],
            'passed' => false, 'completed_at' => now(), 'outcome' => 'needs_assessment', 'check_kind' => 'check', 'submitted_at' => now(),
        ]);
        $meta = ['category' => 'Check evidence', 'document_date' => '2026-09-22', 'reason' => 'Photo of the hoist', 'source_type' => 'checklist_run'];
        $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", $meta + ['source_id' => $foreignRun->id,
            'files' => [UploadedFile::fake()->image('hoist-other.png', 20, 20)], 'request_key' => 'hoist-foreign'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", $meta + ['source_id' => $run['id'],
            'files' => [UploadedFile::fake()->image('hoist.png', 20, 20)], 'request_key' => 'hoist-later'], ['Accept' => 'application/json'])
            ->assertOk();

        $row = collect($this->actingAs($manager)->getJson($url)->json('runs.data'))->firstWhere('id', $run['id']);
        $this->assertSame(2, $row['evidence_count']);
        $this->assertNotNull($row['files'][0]['url']);
    }

    public function test_changing_a_checklist_publishes_a_new_version_and_keeps_earlier_checks(): void
    {
        // Checklists used beyond one vehicle are fleet-wide settings.
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage', 'fleet.settings.manage']);
        $siteManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $otherVehicle = $this->vehicle($this->site);
        $template = $this->template();
        $checks = "/fleet-assets/vehicles/{$vehicle->id}/checks";
        $first = $this->actingAs($manager)->postJson($checks, [
            'template_id' => $template->id, 'items_sha256' => MaintenanceFingerprint::of($template->items),
            'observed_local' => '2026-09-22T09:00', 'answers' => ['exterior' => 'pass'], 'request_key' => 'version-check-1',
        ])->assertOk()->json('run');
        $one = FleetChecklistTemplateVersion::query()->where('template_id', $template->id)->sole();

        $url = "/fleet-assets/vehicles/{$vehicle->id}/check-templates/{$template->id}/versions";
        $change = [
            'name' => 'Vehicle condition record', 'use' => 'Before vehicle use', 'assignment' => 'all_vehicles',
            'evidence_required' => false, 'confirmed' => true,
            'questions' => [
                ['id' => 'exterior', 'label' => 'Exterior condition', 'kind' => 'condition', 'required' => true],
                ['id' => 'cabin', 'label' => 'Cabin notes', 'kind' => 'text', 'required' => false],
            ],
            'expected_version_id' => $one->id, 'expected_items_sha256' => $one->items_sha256, 'request_key' => 'customise-1',
        ];
        $this->actingAs($reader)->postJson($url, $change)->assertForbidden();
        $this->actingAs($siteManager)->getJson($checks)->assertOk()
            ->assertJsonPath('can.manage_templates', true)->assertJsonPath('can.manage_shared_templates', false);
        $this->actingAs($siteManager)->postJson($url, ['request_key' => 'customise-site'] + $change)->assertForbidden()
            ->assertJsonPath('message', 'Checklists used beyond this vehicle are fleet-wide settings. Ask a Fleet Manager to change them, or publish a checklist for this vehicle only.');
        $this->actingAs($manager)->getJson($checks)->assertOk()->assertJsonPath('can.manage_shared_templates', true);
        $this->actingAs($manager)->postJson($url, ['confirmed' => false] + $change)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $published = $this->actingAs($manager)->postJson($url, $change)->assertOk()
            ->assertJsonPath('template.version', 2)->json('template');
        $this->actingAs($manager)->postJson($url, $change)->assertOk()->assertJsonPath('template.version_id', $published['version_id']);

        // New checks use version 2; version 1 and the check answered against it are unchanged.
        $this->assertCount(2, $template->fresh()->items);
        $this->assertCount(1, $one->fresh()->items);
        $run = FleetChecklistRun::query()->findOrFail($first['id']);
        $this->assertSame((int) $one->id, (int) $run->template_version_id);
        $this->assertCount(1, $run->presented_template_json['items']);
        $this->actingAs($manager)->getJson($checks)->assertOk()->assertJsonPath('templates.0.version', 2)
            ->assertJsonPath('templates.0.version_id', $published['version_id']);

        // A stale editor can't overwrite version 2, and nothing-changed is not a version.
        $this->actingAs($manager)->postJson($url, ['request_key' => 'customise-stale'] + $change)->assertStatus(409);
        $two = FleetChecklistTemplateVersion::query()->findOrFail($published['version_id']);
        $fromTwo = ['expected_version_id' => $two->id, 'expected_items_sha256' => $two->items_sha256] + $change;
        $this->actingAs($manager)->postJson($url, ['request_key' => 'customise-same'] + $fromTwo)
            ->assertUnprocessable()->assertJsonValidationErrors('questions');

        // Renaming keeps the approved content: the items and their fingerprint don't move.
        $renamed = $this->actingAs($manager)->postJson($url, ['name' => 'Vehicle condition record (before use)',
            'request_key' => 'customise-rename'] + $fromTwo)->assertOk()->assertJsonPath('template.version', 3)->json('template');
        $this->assertSame($two->items_sha256, FleetChecklistTemplateVersion::query()->findOrFail($renamed['version_id'])->items_sha256);
        $this->assertSame('Vehicle condition record (before use)', $template->fresh()->name);

        // Names stay distinct, and a checklist for this vehicle only isn't offered elsewhere.
        $create = "/fleet-assets/vehicles/{$vehicle->id}/check-templates";
        $own = ['use' => 'After vehicle use', 'evidence_required' => false, 'confirmed' => true,
            'questions' => [['id' => 'return', 'label' => 'Return condition', 'kind' => 'condition', 'required' => true]]];
        $this->actingAs($manager)->postJson($create, ['name' => 'vehicle condition record (before use)', 'assignment' => 'all_vehicles',
            'request_key' => 'create-duplicate'] + $own)->assertUnprocessable()->assertJsonValidationErrors('name');
        // A Site manager publishes checklists for their own vehicle, not for the fleet.
        $this->actingAs($siteManager)->postJson($create, ['name' => 'Fleet return record', 'assignment' => 'all_vehicles',
            'request_key' => 'create-shared-site'] + $own)->assertForbidden();
        $this->actingAs($siteManager)->postJson($create, ['name' => 'Return condition record', 'assignment' => 'vehicle',
            'request_key' => 'create-own'] + $own)->assertOk();
        $this->assertContains('Return condition record', collect($this->actingAs($manager)->getJson($checks)->json('templates'))->pluck('name')->all());
        $this->assertNotContains('Return condition record', collect($this->actingAs($manager)
            ->getJson("/fleet-assets/vehicles/{$otherVehicle->id}/checks")->json('templates'))->pluck('name')->all());
        // Versions 2 and 3 were published once each; the retry added nothing.
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'fleet.checklist_template.publish')
            ->where('auditable_id', $template->id)->where('auditable_type', (new FleetChecklistTemplate)->getMorphClass())->count());
    }

    public function test_the_check_requirement_sets_the_checklist_owner_and_due_date(): void
    {
        $fleetManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $maintenance = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $owner = $this->siteUser([$this->site], ['fleet.viewAny']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $template = $this->template();
        $url = "/fleet-assets/vehicles/{$vehicle->id}/check-requirement";
        $body = ['template_id' => $template->id, 'due_on' => '2026-09-30', 'owner_user_id' => $owner->id, 'expected_version' => 0];
        $profileVersion = (int) $vehicle->fresh()->vehicle_profile_version;

        $this->actingAs($maintenance)->putJson($url, $body)->assertForbidden();
        $this->actingAs($fleetManager)->putJson($url, ['due_on' => '2026-09-21'] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->actingAs($fleetManager)->putJson($url, ['owner_user_id' => $outsider->id] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->actingAs($fleetManager)->putJson($url, $body)->assertOk()->assertJsonPath('requirement.lock_version', 1);
        // A retried save that already landed reports success; a stale edit is refused.
        $this->actingAs($fleetManager)->putJson($url, $body)->assertOk()->assertJsonPath('requirement.lock_version', 1);
        $this->actingAs($fleetManager)->putJson($url, ['due_on' => '2026-10-02'] + $body)->assertStatus(409);

        $vehicle->refresh();
        $this->assertSame('2026-09-30', $vehicle->inspection_due_at->toDateString());
        $this->assertTrue($vehicle->requires_inspection);
        $this->assertSame($profileVersion + 1, (int) $vehicle->vehicle_profile_version);
        $this->assertSame($owner->id, (int) FleetVehicleCheckRequirement::query()->where('asset_id', $vehicle->id)->sole()->owner_user_id);
        $this->actingAs($fleetManager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('requirement.source', 'vehicle')
            ->assertJsonPath('requirement.due_on', '2026-09-30')
            ->assertJsonPath('requirement.owner.name', $owner->name)
            ->assertJsonPath('requirement.lock_version', 1);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'fleet.vehicle.check_requirement.update')->exists());
    }

    public function test_amendments_are_attributed_and_keep_the_original_check(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle($this->site);
        $otherVehicle = $this->vehicle($this->site);
        $template = $this->template();
        $run = $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks", [
            'template_id' => $template->id, 'items_sha256' => MaintenanceFingerprint::of($template->items),
            'observed_local' => '2026-09-22T09:00', 'answers' => ['exterior' => 'fail'], 'request_key' => 'amend-check',
        ])->assertOk()->json('run');
        $before = FleetChecklistRun::query()->findOrFail($run['id'])->responses;
        $url = "/fleet-assets/vehicles/{$vehicle->id}/checks/{$run['id']}/amendments";

        $this->actingAs($manager)->postJson($url, ['note' => ' ', 'request_key' => 'amend-blank'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$otherVehicle->id}/checks/{$run['id']}/amendments",
            ['note' => 'Wrong vehicle.', 'request_key' => 'amend-foreign'])->assertNotFound();
        $amendment = $this->actingAs($manager)->postJson($url, ['note' => 'The scratch was already reported last week.',
            'request_key' => 'amend-first'])->assertOk()->json('amendment');
        $this->actingAs($manager)->postJson($url, ['note' => 'The scratch was already reported last week.',
            'request_key' => 'amend-first'])->assertOk()->assertJsonPath('amendment.id', $amendment['id']);
        $this->actingAs($manager)->postJson($url, ['note' => 'Something else.', 'request_key' => 'amend-first'])->assertStatus(409);

        $this->assertSame(1, FleetChecklistRunAmendment::query()->count());
        $this->assertSame($manager->id, (int) FleetChecklistRunAmendment::query()->sole()->recorded_by_user_id);
        $this->assertSame($before, FleetChecklistRun::query()->findOrFail($run['id'])->responses);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'fleet.maintenance.check.amend')->exists());
        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('runs.data.0.amendments.0.note', 'The scratch was already reported last week.')
            ->assertJsonPath('runs.data.0.amendments.0.recorded_by', $manager->name);
    }

    public function test_a_report_from_a_check_keeps_it_as_the_source_and_needs_approved_routing(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $backup = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $reporter = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.report']);
        $vehicle = $this->vehicle($this->site);
        $otherVehicle = $this->vehicle($this->site);
        $template = $this->template();
        $run = $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks", [
            'template_id' => $template->id, 'items_sha256' => MaintenanceFingerprint::of($template->items),
            'observed_local' => '2026-09-22T09:00', 'answers' => ['exterior' => 'fail'], 'request_key' => 'report-check',
        ])->assertOk()->json('run');
        $url = "/fleet-assets/vehicles/{$vehicle->id}/maintenance-reports";
        $body = [
            'title' => 'Condition concern from vehicle check', 'description' => 'Please review the original check.',
            'source_run_id' => $run['id'], 'estimated_start_date' => '2026-09-24', 'estimated_end_date' => '2026-09-25',
            'request_key' => 'report-1',
        ];

        // No approved Coordinator and backup: the report is refused and nothing is created.
        $this->actingAs($reporter)->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->assertSame(0, FleetWorkOrder::query()->count());
        DB::table('fleet_maintenance_site_routes')->insert([
            'site_id' => $this->site->id, 'coordinator_user_id' => $manager->id, 'backup_user_id' => $backup->id,
            'approved_by_user_id' => $manager->id, 'approved_at' => now(), 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($reporter)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('route.approved', true)->assertJsonPath('route.coordinator', $manager->name)
            ->assertJsonPath('can.report', true)->assertJsonPath('can.link_work', false);

        $this->actingAs($reporter)->postJson($url, ['source_run_id' => $run['id'] + 1000, 'request_key' => 'report-bad'] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->actingAs($reporter)->postJson($url, ['estimated_end_date' => '2026-09-23', 'request_key' => 'report-range'] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_end_date');
        $order = $this->actingAs($reporter)->postJson($url, $body)->assertOk()->assertJsonPath('linked', false)->json('work_order');
        $this->actingAs($reporter)->postJson($url, $body)->assertOk()->assertJsonPath('work_order.id', $order['id']);
        $this->assertSame($manager->id, (int) FleetWorkOrder::query()->findOrFail($order['id'])->assigned_to_user_id);
        $report = DB::table('fleet_maintenance_reports')->where('work_order_id', $order['id'])->sole();
        $this->assertSame('fleet_checklist_run', $report->source_type);
        $this->assertSame($run['id'], (int) $report->source_id);
        $this->assertSame('2026-09-24', substr((string) $report->estimated_start_date, 0, 10));

        // Linking to existing work needs Maintenance manager access.
        $this->actingAs($reporter)->postJson($url, ['existing_work_order_id' => $order['id'], 'request_key' => 'report-link'] + $body)
            ->assertForbidden();
        $this->actingAs($manager)->postJson($url, ['existing_work_order_id' => $order['id'],
            'source_run_id' => null, 'request_key' => 'report-link-manager'] + $body)->assertOk()
            ->assertJsonPath('linked', true)->assertJsonPath('work_order.id', $order['id']);
        $this->assertSame(2, DB::table('fleet_maintenance_reports')->where('work_order_id', $order['id'])->count());
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$otherVehicle->id}/maintenance-reports",
            ['request_key' => 'report-other-vehicle'] + $body)->assertUnprocessable()->assertJsonValidationErrors('source_id');

        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('runs.data.0.linked', true)
            ->assertJsonPath('runs.data.0.linked_work.id', $order['id']);
    }

    public function test_daily_checks_stay_within_the_persons_fleet_sites(): void
    {
        $user = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);

        $this->actingAs($user)->post('/fleet-assets/daily-check', ['asset_id' => $foreign->id, 'condition' => 'issue', 'request_key' => 'daily-foreign'])
            ->assertNotFound();
        $this->actingAs($user)->post('/fleet-assets/daily-check', ['asset_id' => 999999, 'condition' => 'good', 'request_key' => 'daily-missing'])
            ->assertNotFound();
        $this->assertSame(0, FleetChecklistRun::query()->count());

        $this->actingAs($user)->post('/fleet-assets/daily-check', ['asset_id' => $vehicle->id, 'condition' => 'good', 'request_key' => 'daily-own-site'])
            ->assertRedirect();
        $this->assertSame([$vehicle->id], FleetChecklistRun::query()->pluck('asset_id')->map(fn ($id): int => (int) $id)->all());

        $this->actingAs($user)->get('/fleet-assets/daily-check')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/daily-check')
                ->has('vehicles', 1)
                ->where('vehicles.0.id', $vehicle->id)
                ->where('vehicles.0.checked_today', true));
    }

    /** @return array<string,mixed> */
    private function conditionItem(string $id, string $label): array
    {
        return ['id' => $id, 'label' => $label, 'type' => 'select', 'kind' => 'condition',
            'options' => ['pass', 'fail', 'unable'], 'required' => true];
    }

    private function template(): FleetChecklistTemplate
    {
        return FleetChecklistTemplate::query()->create([
            'name' => 'Vehicle condition record', 'type' => 'custom', 'is_active' => true,
            'items' => [$this->conditionItem('exterior', 'Exterior condition')],
        ]);
    }

    private function approveCheckRule(Asset $vehicle, FleetChecklistTemplate $template, User $approver): int
    {
        $rules = [
            'template_id' => $template->id,
            'template_sha256' => MaintenanceFingerprint::of($template->items),
            'questions' => [['id' => 'exterior', 'pass_values' => ['pass'], 'allow_na' => false]],
        ];
        $id = DB::table('fleet_maintenance_policy_versions')->insertGetId([
            'site_id' => $this->site->id, 'asset_category' => $vehicle->category, 'rule_kind' => 'check', 'version' => 1,
            'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR), 'content_sha256' => MaintenanceFingerprint::of($rules),
            'approved_by_user_id' => $approver->id, 'approved_at' => now(), 'created_at' => now(),
        ]);
        DB::table('fleet_maintenance_policy_assignments')->insert([
            'site_id' => $this->site->id, 'asset_category' => $vehicle->category, 'rule_kind' => 'check',
            'policy_version_id' => $id, 'assigned_by_user_id' => $approver->id,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function siteUser(array $sites, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
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

    private function vehicle(Site $site): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }
}
