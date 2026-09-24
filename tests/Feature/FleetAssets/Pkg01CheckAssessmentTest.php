<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetVehicleBooking;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessAssessment;
use App\Services\Fleet\MaintenanceAttachmentService;
use App\Services\Fleet\MaintenanceCheckService;
use App\Services\Fleet\MaintenanceFingerprint;
use App\Services\Fleet\MaintenanceRestrictionService;
use App\Services\Fleet\MaintenanceTransitionService;
use App\Services\Fleet\VehicleReadinessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/**
 * Decision 3 (Stephan): a check that holds a vehicle can be released with a
 * valid reason. PKG-01 records Maintenance's "No issue found — release for
 * use" decision (MaintenanceTransitionService::assessCheck): only that check
 * stops counting, under the vehicle lock and the same readiness gate as a
 * release; holds keep repair, retest and independent release; the check's
 * own answers and outcome never change.
 */
class Pkg01CheckAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Checked the vehicle myself; nothing stops safe use.';

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        Storage::fake('private');
    }

    public function test_an_independent_manager_releases_a_check_that_no_approved_rule_covers(): void
    {
        $recorder = $this->manager();
        $assessor = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-with-issue');
        $before = FleetChecklistRun::query()->findOrFail($runId);
        $this->assertSame('needs_assessment', $before->outcome);
        $this->assertNull($before->rule_version_id);

        $blocked = $this->readiness($vehicle);
        $this->assertFalse($blocked->canProceed);
        $this->assertSame([$runId], $blocked->checkRunIds);
        $this->assertSame($runId, collect($blocked->reasons)->firstWhere('code', 'maintenance.unresolved_check')?->sourceId);
        $this->assertNotBookable($vehicle);

        $this->assess($assessor, $vehicle, $runId, ['reason' => '  Checked the rear door myself; the scratch is cosmetic.  '])
            ->assertOk()
            ->assertJsonPath('assessment.run_id', $runId)
            ->assertJsonPath('assessment.decision', 'no_issue_release')
            ->assertJsonPath('assessment.reason', 'Checked the rear door myself; the scratch is cosmetic.')
            ->assertJsonPath('vehicle_ready', true);

        $row = DB::table('fleet_maintenance_check_assessments')->sole();
        $this->assertSame($runId, (int) $row->check_run_id);
        $this->assertSame((int) $vehicle->id, (int) $row->asset_id);
        $this->assertSame($assessor->id, (int) $row->assessed_by_user_id);
        $this->assertSame('Checked the rear door myself; the scratch is cosmetic.', $row->reason);
        $this->assertSame('needs_assessment', $row->run_outcome);
        $this->assertNull($row->run_rule_version_id);
        $this->assertNull($row->work_order_id);
        // MySQL keeps JSON object keys in its own order, so compare by key.
        $this->assertEquals([['question_id' => 'exterior', 'answer' => 'fail']], json_decode((string) $row->acknowledged_issues_json, true));
        $evidence = json_decode((string) $row->readiness_json, true);
        $this->assertSame([], $evidence['check_run_ids']);
        $this->assertSame([], $evidence['restriction_ids']);
        $this->assertSame(64, strlen((string) $evidence['input_fingerprint']));

        // The check itself is exactly as recorded.
        $after = FleetChecklistRun::query()->findOrFail($runId);
        $this->assertSame('needs_assessment', $after->outcome);
        $this->assertFalse($after->passed);
        $this->assertSame($before->responses, $after->responses);

        // Only that check stopped counting: the vehicle is ready and bookable.
        $ready = $this->readiness($vehicle);
        $this->assertTrue($ready->canProceed);
        $this->assertSame([], $ready->checkRunIds);
        $this->assertNull(collect($ready->reasons)->firstWhere('code', 'maintenance.unresolved_check'));
        app(MaintenanceRestrictionService::class)->assertBookable((int) $vehicle->id);
        $this->assertSame('ready', app(VehicleReadinessService::class)->projections([$vehicle->fresh()])[$vehicle->id]->status);
    }

    public function test_the_recorder_may_release_their_own_check_only_when_it_recorded_no_issue_and_no_rule_applied(): void
    {
        $recorder = $this->manager();
        $other = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $template = $this->template();

        // Case A: no approved rule and nothing recorded as an issue.
        $clean = $this->recordCheck($recorder, $vehicle, 'pass', 'clean-check', $template);
        $this->assess($recorder, $vehicle, $clean)->assertOk();
        $this->assertSame($recorder->id, (int) DB::table('fleet_maintenance_check_assessments')->where('check_run_id', $clean)->value('assessed_by_user_id'));

        // Case B: an item couldn't be assessed, so someone else assesses it.
        $unable = $this->recordCheck($recorder, $vehicle, 'unable', 'unable-check', $template);
        $own = $this->actingAs($recorder)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk();
        $row = collect($own->json('runs.data'))->firstWhere('id', $unable);
        $this->assertFalse($row['assess']['available']);
        $this->assertTrue($row['assess']['needs_independent']);
        $this->assertSame([['id' => 'exterior', 'label' => 'Exterior condition', 'value' => 'Unable to assess']], $row['assess']['issues']);
        $this->assess($recorder, $vehicle, $unable)->assertUnprocessable()
            ->assertJsonPath('errors.run.0', 'This check recorded an issue or couldn’t be fully assessed, so another maintenance manager must assess it.');
        $this->assertFalse(DB::table('fleet_maintenance_check_assessments')->where('check_run_id', $unable)->exists());
        $theirs = collect($this->actingAs($other)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->json('runs.data'))->firstWhere('id', $unable);
        $this->assertTrue($theirs['assess']['available']);
        $this->assess($other, $vehicle, $unable)->assertOk();

        // Case C: an approved rule applied but couldn't decide (evidence missing).
        $ruleId = $this->checkRule($vehicle, $template, $other, ['questions' => [
            ['id' => 'exterior', 'pass_values' => ['pass'], 'allow_na' => false, 'evidence_required' => true],
        ]]);
        $uncertain = $this->recordCheck($recorder, $vehicle, 'pass', 'ruled-uncertain', $template, $ruleId);
        $run = FleetChecklistRun::query()->findOrFail($uncertain);
        $this->assertSame('needs_assessment', $run->outcome);
        $this->assertSame($ruleId, (int) $run->rule_version_id);
        $this->assess($recorder, $vehicle, $uncertain)->assertUnprocessable()->assertJsonValidationErrors('run');
        $this->assess($other, $vehicle, $uncertain)->assertOk();
        $this->assertSame($ruleId, (int) DB::table('fleet_maintenance_check_assessments')->where('check_run_id', $uncertain)->value('run_rule_version_id'));
    }

    public function test_a_reason_and_confirmation_are_required_and_nothing_is_kept_without_them(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-needs-reason');

        $this->assess($assessor, $vehicle, $runId, ['reason' => '   ', 'request_key' => 'assess-blank-reason'])
            ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Record why the vehicle is safe to use.');
        $this->assess($assessor, $vehicle, $runId, ['reason' => str_repeat('a', 2001), 'request_key' => 'assess-long-reason'])
            ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Keep the reason under 2,000 characters.');
        $this->assess($assessor, $vehicle, $runId, ['confirmed' => false, 'request_key' => 'assess-unconfirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->actingAs($assessor)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks/{$runId}/assessments", [
            'decision' => 'no_issue_release', 'reason' => self::REASON, 'request_key' => 'assess-no-confirmation',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assess($assessor, $vehicle, $runId, ['decision' => 'passed', 'request_key' => 'assess-bad-decision'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assess($assessor, $vehicle, $runId, ['request_key' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');

        // The Maintenance command applies the same rules to any caller.
        foreach ([['  ', true], [self::REASON, false]] as [$reason, $confirmed]) {
            try {
                app(MaintenanceTransitionService::class)->assessCheck($assessor, (int) $vehicle->id, $runId, $reason, $confirmed, 'direct-'.Str::random(8));
                $this->fail('The assessment should have been refused.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($confirmed ? 'reason' : 'confirmed', $error->errors());
            }
        }

        $this->assertSame(0, DB::table('fleet_maintenance_check_assessments')->count());
        $this->assertFalse(DB::table('audit_logs')->where('action', 'fleet.maintenance.check.assess')->exists());
        $this->assertSame([$runId], $this->readiness($vehicle)->checkRunIds);
    }

    public function test_only_maintenance_managers_at_the_vehicles_site_can_assess(): void
    {
        $recorder = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $otherVehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-for-access');
        $otherRun = $this->recordCheck($recorder, $otherVehicle, 'fail', 'check-other-vehicle');

        $this->assess($this->siteUser([$this->site], ['fleet.viewAny']), $vehicle, $runId)->assertForbidden();
        $this->assess($this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.report']), $vehicle, $runId)->assertForbidden();
        $this->assess($this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.release']), $vehicle, $runId)->assertForbidden();
        $this->assess($this->siteUser([$this->site], ['fleet.maintenance.manage']), $vehicle, $runId)->assertForbidden();

        $manager = $this->manager();
        $this->assess($this->siteUser([$this->foreignSite], ['fleet.viewAny', 'fleet.maintenance.manage']), $vehicle, $runId)->assertNotFound();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/999999/checks/{$runId}/assessments", $this->body('assess-missing-vehicle'))->assertNotFound();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks/{$otherRun}/assessments", $this->body('assess-other-vehicle'))->assertNotFound();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks/999999/assessments", $this->body('assess-missing-run'))->assertNotFound();

        // Central fleet oversight shows the check but never opens this decision
        // outside the person's own Sites.
        $overseer = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'fleet.manage', 'fleet.maintenance.manage', 'fleet.vehicles.viewAllSites']);
        $this->actingAs($overseer)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('can.assess', false)->assertJsonPath('runs.data.0.assess', null);
        $this->assess($overseer, $vehicle, $runId)->assertNotFound();

        // Authority withdrawn after the page loaded is rechecked by the command.
        $withdrawn = $this->manager();
        $stale = User::query()->findOrFail($withdrawn->id);
        $withdrawn->permissionOverrides()->detach();
        try {
            app(MaintenanceTransitionService::class)->assessCheck($stale, (int) $vehicle->id, $runId, self::REASON, true, 'withdrawn-authority');
            $this->fail('A withdrawn manager should be refused.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $this->assertSame(0, DB::table('fleet_maintenance_check_assessments')->count());
        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('can.assess', true)->assertJsonPath('runs.data.0.assess.available', true);
        $this->actingAs($this->siteUser([$this->site], ['fleet.viewAny']))->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")
            ->assertOk()->assertJsonPath('can.assess', false)->assertJsonPath('runs.data.0.assess', null);
    }

    public function test_a_retry_returns_the_same_decision_and_a_changed_retry_or_second_decision_conflicts(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $other = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-for-retry');

        $first = $this->assess($assessor, $vehicle, $runId, ['request_key' => 'assess-retry-one'])->assertOk()->json('assessment');
        $this->assess($assessor, $vehicle, $runId, ['request_key' => 'assess-retry-one'])->assertOk()
            ->assertJsonPath('assessment.id', $first['id']);
        $this->actingAs($assessor)->withHeader('Idempotency-Key', 'assess-retry-one')
            ->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks/{$runId}/assessments", [
                'decision' => 'no_issue_release', 'reason' => self::REASON, 'confirmed' => true,
            ])->assertOk()->assertJsonPath('assessment.id', $first['id']);
        $this->assess($assessor, $vehicle, $runId, ['request_key' => 'assess-retry-one', 'reason' => 'A different reason entirely.'])
            ->assertStatus(409)->assertJsonPath('message', 'This request was already used for a different assessment. Reload and try again.');
        $this->assess($other, $vehicle, $runId, ['request_key' => 'assess-second-person'])
            ->assertStatus(409)->assertJsonPath('message', 'This check has already been assessed. Reload to see the decision.');

        $this->assertSame(1, DB::table('fleet_maintenance_check_assessments')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fleet.maintenance.check.assess')->count());
    }

    public function test_a_check_failed_by_an_approved_rule_keeps_the_repair_retest_and_release_path(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $template = $this->template();
        $ruleId = $this->checkRule($vehicle, $template, $assessor);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'rule-failed-check', $template, $ruleId);
        $this->assertSame('failed', FleetChecklistRun::query()->findOrFail($runId)->outcome);

        $message = 'An approved check rule recorded a failure. Report it to Maintenance for repair, a retest and an independent release.';
        $row = collect($this->actingAs($assessor)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->json('runs.data'))->firstWhere('id', $runId);
        $this->assertTrue($row['blocking']);
        $this->assertFalse($row['assess']['available']);
        $this->assertSame($message, $row['assess']['reason']);
        $this->assess($assessor, $vehicle, $runId)->assertUnprocessable()->assertJsonPath('errors.run.0', $message);

        $this->assertSame(0, DB::table('fleet_maintenance_check_assessments')->count());
        $this->assertSame([$runId], $this->readiness($vehicle)->checkRunIds);
    }

    public function test_a_check_maintenance_placed_a_hold_for_is_released_only_by_independent_release(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $template = $this->template();
        $this->policy($vehicle, $assessor, 'hold', ['allowed_kinds' => ['safety']]);
        $message = 'Maintenance has placed a hold for this check. The hold is released through repair, a retest and an independent release.';

        // The hold's own source check.
        $order = $this->workOrder($vehicle, $recorder, 'Brake concern');
        $source = app(MaintenanceCheckService::class)->submit($recorder, [
            'asset_id' => $vehicle->id, 'template_id' => $template->id, 'check_kind' => 'check',
            'work_order_id' => $order->id, 'answers' => ['exterior' => ['result' => 'fail']], 'request_key' => 'held-source-check',
        ]);
        app(MaintenanceTransitionService::class)->execute($recorder, $order->id, 'place_restriction', 0,
            'held-source-hold', ['restriction_kind' => 'safety', 'source_run_id' => $source->id]);
        $this->assess($assessor, $vehicle, (int) $source->id)->assertUnprocessable()->assertJsonPath('errors.run.0', $message);

        // A check reported to work Maintenance then held.
        $this->route($recorder, $assessor);
        $reported = $this->recordCheck($recorder, $vehicle, 'fail', 'held-reported-check', $template);
        $work = $this->actingAs($recorder)->postJson("/fleet-assets/vehicles/{$vehicle->id}/maintenance-reports", [
            'title' => 'Condition concern', 'source_run_id' => $reported, 'request_key' => 'held-report',
        ])->assertOk()->json('work_order.id');
        app(MaintenanceTransitionService::class)->execute($recorder, (int) $work, 'place_restriction', 0,
            'held-reported-hold', ['restriction_kind' => 'safety']);
        $this->assess($assessor, $vehicle, $reported)->assertUnprocessable()->assertJsonPath('errors.run.0', $message);

        $this->assertSame(0, DB::table('fleet_maintenance_check_assessments')->count());
        $this->assertSame(2, DB::table('fleet_maintenance_restrictions')->where('asset_id', $vehicle->id)->where('state', 'active')->count());
    }

    public function test_retests_passed_advisory_and_already_released_checks_have_nothing_to_assess(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $template = $this->template();
        $nothing = 'This check doesn’t stop the vehicle being used, so there’s nothing to release.';

        // A retest is resolved by releasing its work.
        $order = $this->workOrder($vehicle, $recorder, 'Retest work');
        $retest = app(MaintenanceCheckService::class)->submit($recorder, [
            'asset_id' => $vehicle->id, 'template_id' => $template->id, 'check_kind' => 'retest',
            'work_order_id' => $order->id, 'answers' => ['exterior' => ['result' => 'pass']], 'request_key' => 'retest-run',
        ]);
        $this->assess($assessor, $vehicle, (int) $retest->id)->assertUnprocessable()
            ->assertJsonPath('errors.run.0', 'A retest is resolved when its Maintenance work is released.');

        // Under an approved advisory rule a pass and a failure don't hold the vehicle.
        $ruleId = $this->checkRule($vehicle, $template, $assessor, ['availability_impact' => 'advisory']);
        $passed = $this->recordCheck($recorder, $vehicle, 'pass', 'advisory-pass', $template, $ruleId);
        $advisory = $this->recordCheck($recorder, $vehicle, 'fail', 'advisory-fail', $template, $ruleId);
        $this->assertSame('failed', FleetChecklistRun::query()->findOrFail($advisory)->outcome);
        $this->assess($assessor, $vehicle, $passed)->assertUnprocessable()->assertJsonPath('errors.run.0', $nothing);
        $this->assess($assessor, $vehicle, $advisory)->assertUnprocessable()->assertJsonPath('errors.run.0', $nothing);

        // A later authorised release already resolved an earlier check.
        $other = $this->vehicle($this->site);
        $this->readyEvidence($other);
        $earlier = $this->recordCheck($recorder, $other, 'fail', 'released-earlier');
        $released = $this->workOrder($other, $recorder, 'Released work');
        DB::table('fleet_maintenance_actions')->insert([
            'work_order_id' => $released->id, 'action_type' => 'release', 'actor_user_id' => $assessor->id,
            'expected_version' => 0, 'resulting_version' => 1, 'idempotency_key' => 'fixture-release',
            'payload_sha256' => str_repeat('0', 64), 'payload_json' => '{}',
            'occurred_at' => now()->addSecond()->format('Y-m-d H:i:s.u'), 'created_at' => now(),
        ]);
        $this->assess($assessor, $other, $earlier)->assertUnprocessable()->assertJsonPath('errors.run.0', $nothing);

        $this->assertSame(0, DB::table('fleet_maintenance_check_assessments')->count());
    }

    public function test_the_release_readiness_gate_applies_before_a_check_is_released(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-with-wof-problem');

        // The same compliance gate as any Maintenance release.
        $wof = DB::table('fleet_vehicle_compliance_records')->where('asset_id', $vehicle->id)->where('kind', 'wof')->first();
        $unresolved = DB::table('fleet_vehicle_compliance_versions')->insertGetId([
            'record_id' => $wof->id, 'version' => 2, 'supersedes_version_id' => $wof->current_version_id,
            'applicability' => 'applicable', 'outcome' => 'needs_assessment', 'request_key' => 'gate-wof-unresolved',
            'request_fingerprint' => str_repeat('f', 64), 'content_sha256' => str_repeat('f', 64), 'created_at' => now(),
        ]);
        DB::table('fleet_vehicle_compliance_records')->where('id', $wof->id)->update(['current_version_id' => $unresolved]);
        $this->assess($assessor, $vehicle, $runId, ['request_key' => 'gate-refused'])
            ->assertUnprocessable()->assertJsonPath('errors.run.0', 'WoF: assessment is unresolved.');
        $this->assertSame(0, DB::table('fleet_maintenance_check_assessments')->count());

        DB::table('fleet_vehicle_compliance_records')->where('id', $wof->id)->update(['current_version_id' => $wof->current_version_id]);
        $this->assess($assessor, $vehicle, $runId, ['request_key' => 'gate-passed'])->assertOk();
        $evidence = json_decode((string) DB::table('fleet_maintenance_check_assessments')->value('readiness_json'), true);
        $this->assertSame((int) $wof->current_version_id, (int) $evidence['compliance_version_ids']['wof']);
    }

    public function test_other_maintenance_holds_keep_the_vehicle_unavailable(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $this->policy($vehicle, $assessor, 'hold', ['allowed_kinds' => ['safety']]);
        $order = $this->workOrder($vehicle, $recorder, 'Unrelated tyre work');
        app(MaintenanceTransitionService::class)->execute($recorder, $order->id, 'place_restriction', 0,
            'other-hold', ['restriction_kind' => 'safety']);
        $holdId = (int) DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)->value('id');
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-beside-hold');

        $this->assess($assessor, $vehicle, $runId)->assertOk()
            ->assertJsonPath('vehicle_ready', false)
            ->assertJsonPath('message', 'CHK-'.$runId.' released for use: no issue found. Other readiness items still stop the vehicle being used.');

        $readiness = $this->readiness($vehicle);
        $this->assertFalse($readiness->canProceed);
        $this->assertSame([], $readiness->checkRunIds);
        $this->assertSame([$holdId], $readiness->restrictionIds);
        $this->assertSame('active', DB::table('fleet_maintenance_restrictions')->where('id', $holdId)->value('state'));
        try {
            app(MaintenanceRestrictionService::class)->assertBookable((int) $vehicle->id);
            $this->fail('The hold should still stop bookings.');
        } catch (ValidationException $error) {
            $this->assertSame('This asset has an active maintenance restriction. It must be released before booking.', $error->errors()['asset_id'][0]);
        }
    }

    public function test_a_booking_the_check_stopped_can_be_approved_once_it_is_released(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $driver = $this->siteUser([$this->site], ['fleet.viewAny'], true);
        $approver = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.bookings.approve']);
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'pass', 'check-before-booking');
        $booking = FleetVehicleBooking::query()->create([
            'asset_id' => $vehicle->id, 'user_id' => $driver->id, 'purpose' => 'Hospital appointment',
            'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(5),
            'pickup_site_id' => $vehicle->site_id, 'return_site_id' => $vehicle->site_id, 'status' => 'pending',
        ]);

        $this->actingAs($approver)->post("/fleet-assets/bookings/{$booking->id}/approve")
            ->assertRedirect()->assertSessionHasErrors(['asset_id' => 'A vehicle check needs assessment or repair.']);
        $this->assertSame('pending', $booking->fresh()->status);

        $this->assess($assessor, $vehicle, $runId)->assertOk()->assertJsonPath('vehicle_ready', true);

        $this->actingAs($approver)->post("/fleet-assets/bookings/{$booking->id}/approve")
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $booking->fresh()->status);
    }

    public function test_an_assessed_check_recorded_after_a_retest_no_longer_prevents_release(): void
    {
        [$vehicle, $order, $manager, $reviewer] = $this->releasableWork();
        $assessor = $this->manager();
        $runId = $this->recordCheck($manager, $vehicle, 'pass', 'check-after-retest');

        try {
            app(MaintenanceTransitionService::class)->execute($reviewer, $order->id, 'release', 3, 'release-blocked-by-check');
            $this->fail('A newer unresolved check should stop the release.');
        } catch (ValidationException $error) {
            $this->assertSame('A newer unresolved check prevents release.', $error->errors()['status'][0]);
        }

        $this->assess($assessor, $vehicle, $runId)->assertOk();
        app(MaintenanceTransitionService::class)->execute($reviewer, $order->id, 'release', 3, 'release-after-assessment');

        $this->assertSame('released', DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)->value('state'));
        app(MaintenanceRestrictionService::class)->assertBookable((int) $vehicle->id);
    }

    public function test_a_legacy_daily_check_without_a_submission_time_never_blocks_release(): void
    {
        [$vehicle, $order, $manager, $reviewer] = $this->releasableWork();
        // The old daily check form stores no submission time or outcome; it has
        // never been a readiness blocker, so it can't hold a release either.
        $legacy = FleetChecklistRun::query()->create([
            'template_id' => $this->template()->id, 'asset_id' => $vehicle->id, 'user_id' => $manager->id,
            'responses' => ['condition' => 'issue'], 'passed' => false, 'completed_at' => now(),
        ]);
        $this->assertNull($legacy->fresh()->submitted_at);

        app(MaintenanceTransitionService::class)->execute($reviewer, $order->id, 'release', 3, 'release-despite-legacy');

        $this->assertSame('released', DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)->value('state'));
        $this->assertSame(['condition' => 'issue'], $legacy->fresh()->responses);
    }

    public function test_the_decision_is_visible_on_the_check_the_vehicle_and_its_linked_work(): void
    {
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $this->route($recorder, $assessor);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-to-show');
        $workId = (int) $this->actingAs($recorder)->postJson("/fleet-assets/vehicles/{$vehicle->id}/maintenance-reports", [
            'title' => 'Condition concern from vehicle check', 'source_run_id' => $runId, 'request_key' => 'report-to-show',
        ])->assertOk()->json('work_order.id');

        $this->assess($assessor, $vehicle, $runId, ['reason' => 'Scratch is cosmetic; doors open and close normally.'])->assertOk();
        $this->assertSame($workId, (int) DB::table('fleet_maintenance_check_assessments')->value('work_order_id'));
        // Linked work stays open for the Coordinator.
        $this->assertSame('open', FleetWorkOrder::query()->findOrFail($workId)->status);

        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $this->actingAs($reader)->getJson("/fleet-assets/vehicles/{$vehicle->id}/checks")->assertOk()
            ->assertJsonPath('runs.data.0.id', $runId)
            ->assertJsonPath('runs.data.0.outcome', 'needs_assessment')
            ->assertJsonPath('runs.data.0.blocking', false)
            ->assertJsonPath('runs.data.0.assessment.decision', 'no_issue_release')
            ->assertJsonPath('runs.data.0.assessment.label', 'No issue found — released for use')
            ->assertJsonPath('runs.data.0.assessment.reason', 'Scratch is cosmetic; doors open and close normally.')
            ->assertJsonPath('runs.data.0.assessment.assessed_by', $assessor->name)
            ->assertJsonPath('runs.data.0.assessment.self_assessed', false)
            ->assertJsonPath('runs.data.0.assessment.issues', ['Exterior condition'])
            ->assertJsonPath('runs.data.0.assess', null);

        $this->actingAs($assessor)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/vehicles/show')
                ->where('workspace.checks.latest.id', $runId)
                ->where('workspace.checks.latest.assessed', true)
                ->where('workspace.readiness.check_run_ids', [])
                // Its work no longer carries the check's warning.
                ->where('workspace.work.open.0.id', $workId)
                ->where('workspace.work.open.0.source.failed_check', false)
                ->etc());

        $this->actingAs($assessor)->get("/fleet-assets/maintenance/work-orders/{$workId}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/maintenance/work-orders/show')
                ->where('checks.0.id', $runId)
                ->where('checks.0.outcome', 'needs_assessment')
                ->where('checks.0.assessment.decision', 'no_issue_release')
                ->where('checks.0.assessment.reason', 'Scratch is cosmetic; doors open and close normally.')
                ->where('checks.0.assessment.assessed_by', $assessor->name)
                ->etc());

        $audit = DB::table('audit_logs')->where('action', 'fleet.maintenance.check.assess')->sole();
        $this->assertSame((new FleetChecklistRun)->getMorphClass(), $audit->auditable_type);
        $this->assertSame($runId, (int) $audit->auditable_id);
        $this->assertSame($assessor->id, (int) $audit->user_id);
        $meta = json_decode((string) $audit->meta, true);
        $this->assertSame('no_issue_release', $meta['decision']);
        $this->assertSame('Scratch is cosmetic; doors open and close normally.', $meta['reason']);
        $this->assertSame((int) $vehicle->id, (int) $meta['asset_id']);
        $this->assertSame($recorder->id, (int) $meta['recorded_by_user_id']);
        $this->assertSame($workId, (int) $meta['work_order_id']);
    }

    public function test_check_assessments_roll_back_only_while_empty(): void
    {
        $migration = require database_path('migrations/2026_09_24_000300_pkg01_check_assessments.php');
        $recorder = $this->manager();
        $assessor = $this->manager();
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $runId = $this->recordCheck($recorder, $vehicle, 'fail', 'check-before-rollback');
        $this->assess($assessor, $vehicle, $runId)->assertOk();

        try {
            $migration->down();
            $this->fail('Rollback should refuse while decisions exist.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('check assessments exist', $error->getMessage());
        }
        try {
            \App\Services\Fleet\MaintenanceRollbackGuard::assertEmpty();
            $this->fail('The PKG-01 rollback guard should refuse while decisions exist.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('rollback must not remove its provenance', $error->getMessage());
        }
        $this->assertSame(1, DB::table('fleet_maintenance_check_assessments')->count());
    }

    /**
     * Both scenarios commit their fixtures so a second PHP process can see
     * them. CommittedFixtureCleanup removes those rows afterwards, so the next
     * test neither sees them nor has to rebuild the schema.
     */
    public function test_the_vehicle_lock_orders_a_release_against_a_new_hold_and_a_waiting_booking_decision(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $recorder = $this->manager();
        $assessor = $this->manager();
        $template = $this->template();
        // A check recorded on work, which Maintenance is about to hold.
        $held = $this->vehicle($this->site);
        $this->readyEvidence($held);
        $this->policy($held, $recorder, 'hold', ['allowed_kinds' => ['safety']]);
        $order = $this->workOrder($held, $recorder, 'Concurrent hold');
        $heldRun = app(MaintenanceCheckService::class)->submit($recorder, [
            'asset_id' => $held->id, 'template_id' => $template->id, 'check_kind' => 'check',
            'work_order_id' => $order->id, 'answers' => ['exterior' => ['result' => 'fail']], 'request_key' => 'concurrent-hold-check',
        ]);
        // A clean check no rule covers, which Maintenance is about to release.
        $bookable = $this->vehicle($this->site);
        $this->readyEvidence($bookable);
        $cleanRun = app(MaintenanceCheckService::class)->submit($recorder, [
            'asset_id' => $bookable->id, 'template_id' => $template->id, 'check_kind' => 'check',
            'answers' => ['exterior' => ['result' => 'pass']], 'request_key' => 'concurrent-booking-check',
        ]);

        DB::connection()->commit(); // Publish the fixtures outside RefreshDatabase's transaction.
        try {
            // 1. An assessment waiting for the vehicle lock sees the hold committed first.
            $output = $this->whileVehicleLocked($held, self::ASSESS_WORKER,
                [(string) $assessor->id, (string) $held->id, (string) $heldRun->id],
                fn () => app(MaintenanceTransitionService::class)->execute($recorder, $order->id, 'place_restriction', 0,
                    'concurrent-hold', ['restriction_kind' => 'safety', 'source_run_id' => $heldRun->id]));
            $this->assertSame(
                'refused:Maintenance has placed a hold for this check. The hold is released through repair, a retest and an independent release.',
                $output,
            );
            $this->assertFalse(DB::table('fleet_maintenance_check_assessments')->where('check_run_id', $heldRun->id)->exists());

            // 2. A booking decision waiting for the vehicle lock sees the committed release,
            // even though it read the database before taking the lock.
            $output = $this->whileVehicleLocked($bookable, self::BOOKING_WORKER,
                [(string) $bookable->id, (string) $recorder->id],
                fn () => app(MaintenanceTransitionService::class)->assessCheck(
                    $assessor, (int) $bookable->id, (int) $cleanRun->id, self::REASON, true, 'concurrent-release'));
            $this->assertSame('bookable', $output);
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            $this->forgetCommitted($held, [$order->id], []);
            $this->forgetCommitted($bookable, [], [$template->id]);
        }
    }

    /**
     * Holds the vehicle lock while a second process waits for it, makes the
     * write inside that lock, commits, and returns what the process printed.
     * The worker receives its ready, attempt and barrier files after $arguments.
     *
     * @param  list<string>  $arguments
     */
    private function whileVehicleLocked(Asset $vehicle, string $code, array $arguments, callable $write): string
    {
        [$ready, $attempt, $barrier] = $this->barrierFiles((string) $vehicle->id);
        $process = null;
        try {
            DB::connection()->beginTransaction();
            Asset::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $process = $this->worker($code, [...$arguments, $ready, $attempt, $barrier]);
            $this->waitForFiles([$ready], 'The second process did not start.');
            touch($barrier);
            $this->waitForFiles([$attempt], 'The second process did not reach the vehicle lock.');
            usleep(250000);
            $this->assertTrue($process->isRunning(), 'The second process should be waiting for the vehicle lock.');
            $write();
            DB::connection()->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return trim($process->getOutput());
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            $this->removeFiles([$ready, $attempt, $barrier]);
        }
    }

    /** Arguments: actor, vehicle, check, then the ready, attempt and barrier files. */
    private const ASSESS_WORKER = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        file_put_contents($argv[5], 'ready');
        $deadline = microtime(true) + 60;
        while (! is_file($argv[7])) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Barrier timed out');
            usleep(10000);
        }
        file_put_contents($argv[6], 'attempt');
        try {
            $actor = App\Models\User::query()->findOrFail((int) $argv[2]);
            app(App\Services\Fleet\MaintenanceTransitionService::class)->assessCheck(
                $actor, (int) $argv[3], (int) $argv[4], 'Checked the vehicle; nothing stops safe use.', true, 'concurrent-assessment');
            echo 'released';
        } catch (Illuminate\Validation\ValidationException $error) {
            echo 'refused:'.collect($error->errors())->flatten()->first();
        }
        PHP;

    /** Arguments: vehicle, any user, then the ready, attempt and barrier files. */
    private const BOOKING_WORKER = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        file_put_contents($argv[4], 'ready');
        $deadline = microtime(true) + 60;
        while (! is_file($argv[6])) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Barrier timed out');
            usleep(10000);
        }
        file_put_contents($argv[5], 'attempt');
        try {
            Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
                // A plain read first fixes this transaction's snapshot before the lock.
                App\Models\User::query()->findOrFail((int) $argv[3]);
                App\Models\Asset::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
                app(App\Services\Fleet\MaintenanceRestrictionService::class)->assertBookable((int) $argv[2]);
            });
            echo 'bookable';
        } catch (Illuminate\Validation\ValidationException $error) {
            echo 'blocked';
        }
        PHP;

    /** @param array<string,mixed> $overrides */
    private function assess(User $actor, Asset $vehicle, int $runId, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->postJson(
            "/fleet-assets/vehicles/{$vehicle->id}/checks/{$runId}/assessments",
            [...$this->body('assess-'.$runId.'-'.$actor->id), ...$overrides],
        );
    }

    /** @return array<string,mixed> */
    private function body(string $key): array
    {
        return ['decision' => 'no_issue_release', 'reason' => self::REASON, 'confirmed' => true, 'request_key' => $key];
    }

    private function readiness(Asset $vehicle): VehicleReadinessAssessment
    {
        return app(VehicleReadinessService::class)->assess($vehicle->fresh());
    }

    private function assertNotBookable(Asset $vehicle): void
    {
        try {
            app(MaintenanceRestrictionService::class)->assertBookable((int) $vehicle->id);
            $this->fail('The vehicle should not be bookable.');
        } catch (ValidationException $error) {
            $this->assertSame('A maintenance check needs assessment or repair before this asset can be booked.', $error->errors()['asset_id'][0]);
        }
    }

    /** Records a check from the vehicle profile and returns its id. */
    private function recordCheck(User $actor, Asset $vehicle, string $answer, string $key, ?FleetChecklistTemplate $template = null, ?int $ruleId = null): int
    {
        $template ??= $this->template();
        $observed = now('Pacific/Auckland')->subMinutes(30);

        return (int) $this->actingAs($actor)->postJson("/fleet-assets/vehicles/{$vehicle->id}/checks", [
            'template_id' => $template->id,
            'items_sha256' => MaintenanceFingerprint::of($template->fresh()->items),
            'observed_local' => $observed->format('Y-m-d\TH:i'),
            'observed_offset' => $observed->format('P'),
            'answers' => ['exterior' => $answer],
            'request_key' => $key,
            ...($ruleId !== null ? ['rule_version_id' => $ruleId] : []),
        ])->assertOk()->json('run.id');
    }

    private function template(): FleetChecklistTemplate
    {
        return FleetChecklistTemplate::query()->create([
            'name' => 'Vehicle condition record '.Str::random(6), 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'exterior', 'label' => 'Exterior condition', 'type' => 'select', 'kind' => 'condition',
                'options' => ['pass', 'fail', 'unable'], 'required' => true]],
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function checkRule(Asset $vehicle, FleetChecklistTemplate $template, User $approver, array $extra = []): int
    {
        return $this->policy($vehicle, $approver, 'check', [
            'template_id' => $template->id,
            'template_sha256' => MaintenanceFingerprint::of($template->fresh()->items),
            'questions' => [['id' => 'exterior', 'pass_values' => ['pass'], 'allow_na' => false]],
            ...$extra,
        ]);
    }

    /** @param array<string,mixed> $rules */
    private function policy(Asset $vehicle, User $approver, string $kind, array $rules): int
    {
        $current = DB::table('fleet_maintenance_policy_assignments')->where('site_id', $vehicle->site_id)
            ->where('asset_category', $vehicle->category)->where('rule_kind', $kind)->first();
        $version = (int) DB::table('fleet_maintenance_policy_versions')->where('site_id', $vehicle->site_id)
            ->where('asset_category', $vehicle->category)->where('rule_kind', $kind)->max('version') + 1;
        $id = DB::table('fleet_maintenance_policy_versions')->insertGetId([
            'site_id' => $vehicle->site_id, 'asset_category' => $vehicle->category, 'rule_kind' => $kind,
            'version' => $version, 'rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
            'content_sha256' => MaintenanceFingerprint::of($rules),
            'approved_by_user_id' => $approver->id, 'approved_at' => now(), 'created_at' => now(),
        ]);
        if ($current) {
            DB::table('fleet_maintenance_policy_assignments')->where('id', $current->id)
                ->update(['policy_version_id' => $id, 'updated_at' => now()]);
        } else {
            DB::table('fleet_maintenance_policy_assignments')->insert([
                'site_id' => $vehicle->site_id, 'asset_category' => $vehicle->category, 'rule_kind' => $kind,
                'policy_version_id' => $id, 'assigned_by_user_id' => $approver->id,
                'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** The Site's approved Maintenance Coordinator and backup, so reports can be made. */
    private function route(User $coordinator, User $backup): void
    {
        DB::table('fleet_maintenance_site_routes')->insert([
            'site_id' => $this->site->id, 'coordinator_user_id' => $coordinator->id, 'backup_user_id' => $backup->id,
            'approved_by_user_id' => $coordinator->id, 'approved_at' => now(), 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function workOrder(Asset $vehicle, User $manager, string $title): FleetWorkOrder
    {
        return FleetWorkOrder::create([
            'asset_id' => $vehicle->id, 'reported_by_user_id' => $manager->id, 'assigned_to_user_id' => $manager->id,
            'title' => $title, 'category' => $vehicle->category, 'priority' => 'high', 'status' => 'open',
        ]);
    }

    /**
     * Completed, retested work holding the vehicle, ready for an independent
     * release at version 3.
     *
     * @return array{0: Asset, 1: FleetWorkOrder, 2: User, 3: User}
     */
    private function releasableWork(): array
    {
        $manager = $this->manager();
        $reviewer = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.release']);
        $vehicle = $this->vehicle($this->site);
        $this->readyEvidence($vehicle);
        $retestTemplate = FleetChecklistTemplate::query()->create([
            'name' => 'Brake retest '.Str::random(6), 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'brakes', 'label' => 'Brakes', 'type' => 'select', 'options' => ['pass', 'fail'], 'required' => true]],
        ]);
        $this->policy($vehicle, $manager, 'hold', ['allowed_kinds' => ['safety']]);
        $this->policy($vehicle, $manager, 'repair', ['requires_service_evidence' => true]);
        $this->policy($vehicle, $manager, 'release', ['requires_custody' => false]);
        $retestRule = $this->policy($vehicle, $manager, 'retest', [
            'template_id' => $retestTemplate->id, 'template_sha256' => MaintenanceFingerprint::of($retestTemplate->items),
            'questions' => [['id' => 'brakes', 'pass_values' => ['pass'], 'allow_na' => false]],
        ]);
        DB::table('fleet_maintenance_reviewer_grants')->insert([
            'site_id' => $this->site->id, 'user_id' => $reviewer->id, 'asset_category' => $vehicle->category,
            'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
            'recorded_by_user_id' => $manager->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);
        $service = app(MaintenanceTransitionService::class);
        $order = $this->workOrder($vehicle, $manager, 'Brake repair');
        $service->execute($manager, $order->id, 'place_restriction', 0, 'release-fixture-hold', ['restriction_kind' => 'safety']);
        $service->execute($manager, $order->id, 'attest_repair', 1, 'release-fixture-attest', ['summary' => 'Brake line repaired']);
        $attestation = (int) DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->where('action_type', 'attest_repair')->value('id');
        app(MaintenanceAttachmentService::class)->upload($manager, $order->id, 'action', $attestation,
            'release-fixture-evidence', UploadedFile::fake()->image('service.png'));
        $service->execute($manager, $order->id, 'complete', 2, 'release-fixture-complete');
        $holds = DB::table('fleet_maintenance_restrictions')->where('asset_id', $vehicle->id)->where('state', 'active')
            ->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $retest = app(MaintenanceCheckService::class)->submit($manager, [
            'asset_id' => $vehicle->id, 'template_id' => $retestTemplate->id, 'check_kind' => 'retest',
            'work_order_id' => $order->id, 'rule_version_id' => $retestRule, 'covered_restriction_ids' => $holds,
            'answers' => ['brakes' => ['result' => 'pass']], 'request_key' => 'release-fixture-retest',
        ]);
        $this->assertSame('passed', $retest->outcome);

        return [$vehicle, $order->fresh(), $manager, $reviewer];
    }

    /** Current registration, WoF, CoF and RUC evidence, so readiness turns on Maintenance alone. */
    private function readyEvidence(Asset $vehicle): void
    {
        $evidence = [
            'registration' => ['applicable', 'recorded', 'REG-CHECK', '2027-12-31', null],
            'wof' => ['applicable', 'passed', 'WOF-CHECK', '2027-12-31', null],
            'cof' => ['not_applicable', 'needs_assessment', null, null, 'Light vehicle; a WoF applies instead.'],
            'ruc' => ['not_applicable', 'needs_assessment', null, null, 'Recorded by the fleet owner.'],
        ];
        foreach ($evidence as $kind => [$applicability, $outcome, $reference, $expires, $basis]) {
            $recordId = DB::table('fleet_vehicle_compliance_records')->insertGetId([
                'asset_id' => $vehicle->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $versionId = DB::table('fleet_vehicle_compliance_versions')->insertGetId([
                'record_id' => $recordId, 'version' => 1, 'applicability' => $applicability,
                'applicability_basis' => $basis, 'outcome' => $outcome, 'evidence_reference' => $reference,
                'expires_on' => $expires, 'request_key' => "check-evidence-{$kind}-{$vehicle->id}",
                'request_fingerprint' => str_repeat('e', 64), 'content_sha256' => str_repeat('e', 64),
                'created_at' => now(),
            ]);
            DB::table('fleet_vehicle_compliance_records')->where('id', $recordId)->update(['current_version_id' => $versionId]);
        }
    }

    private function manager(): User
    {
        return $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions, bool $driverEligible = false): User
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
        if ($driverEligible) {
            HrDriverEligibility::query()->create([
                'tenant_id' => 1, 'user_id' => $user->id, 'licence_number' => 'DL-'.$user->id,
                'licence_class' => '1', 'licence_expires_at' => today()->addYear(), 'status' => 'eligible',
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
        }

        return $user;
    }

    private function vehicle(Site $site): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function barrierFiles(string $name): array
    {
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkg01-check-'.$name.'-'.Str::uuid();

        return [$prefix.'-ready', $prefix.'-attempt', $prefix.'-barrier'];
    }

    /** @param list<string> $arguments */
    private function worker(string $code, array $arguments): Process
    {
        $process = new Process([PHP_BINARY, '-r', $code, base_path(), ...$arguments], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'QUEUE_CONNECTION' => 'null',
        ]);
        $process->setTimeout(120);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }
            usleep(10000);
        }
        throw new RuntimeException($message);
    }

    /** @param list<string> $paths */
    private function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The concurrency tests commit their fixtures so a second process can see
     * them. Remove the committed checks and work again, so tests that count
     * them in the same process start clean.
     *
     * @param  list<int>  $workIds
     * @param  list<int>  $templateIds
     */
    private function forgetCommitted(Asset $vehicle, array $workIds, array $templateIds): void
    {
        DB::table('fleet_maintenance_check_assessments')->where('asset_id', $vehicle->id)->delete();
        DB::table('fleet_maintenance_booking_impacts')->where('asset_id', $vehicle->id)->delete();
        DB::table('fleet_maintenance_restrictions')->where('asset_id', $vehicle->id)->delete();
        DB::table('fleet_maintenance_actions')->whereIn('work_order_id', $workIds)->delete();
        DB::table('fleet_checklist_runs')->where('asset_id', $vehicle->id)->delete();
        FleetWorkOrder::query()->whereIn('id', $workIds)->delete();
        FleetChecklistTemplate::query()->whereIn('id', $templateIds)->delete();
    }
}
