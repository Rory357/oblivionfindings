<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Domain\Rostering\RosterPublishingService;
use App\Domain\Rostering\RosterPublishValidator;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Domain\Shifts\Planning\ShiftPlanningCommand;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\SiteStaffRequirement;
use App\Models\StaffCredential;
use App\Models\User;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Eligibility\HouseQualificationCoverageService;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\Sites\Profile\SiteProfilePeoplePresenter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Explicit House qualifications; headcount/roles and actual attendance remain separate. */
class WorkforceHouseQualificationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $actor;

    private User $worker;

    private HrComplianceRequirement $qualification;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 04:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.publish' => true,
            'hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null, 'status' => 'active']);
        $this->actor = $this->person(['sites.create', 'sites.viewAny', 'sites.update', 'staff.viewAny', 'rostering.publish', 'rostering.edit',
            'shifts.create', 'shifts.update', 'shifts.viewAny', 'shifts.manageAny', 'shifts.overrideEligibility'], 'coordinator');
        $this->worker = $this->person();
        $this->qualification = HrComplianceRequirement::factory()->create(['code' => 'HOUSE_'.Str::upper(Str::random(12)),
            'name' => 'Mapped House credential', 'check_type' => 'credential', 'is_active' => true, 'hard_stop' => false, 'validity_months' => null]);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('house_qualification_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function newChoices(): array
    {
        return [
            'per requirement needs choice' => ['per_requirement', [], false, null, null],
            'all workers seeds omission' => ['all_workers', [], true, 'all_workers', null],
            'minimum never guesses one' => ['minimum_staff', [], false, null, null],
            'minimum seeds with explicit count' => ['minimum_staff', ['minimum_qualified_staff' => 2], true, 'minimum_staff', 2],
            'explicit minimum beats default' => ['all_workers', ['applicability_mode' => 'minimum_staff', 'minimum_qualified_staff' => 3], true, 'minimum_staff', 3],
            'explicit all worker beats default' => ['minimum_staff', ['applicability_mode' => 'all_workers'], true, 'all_workers', null],
            'explicit null clone stays unresolved' => ['all_workers', ['applicability_mode' => null], false, null, null],
        ];
    }

    #[DataProvider('newChoices')]
    public function test_new_source_choices_seed_only_omitted_values(string $approach, array $choice, bool $allowed, ?string $mode, ?int $count): void
    {
        $this->policy(['house_qualification_approach' => $approach]);
        $before = $this->state();
        $queue = $this->queueState();
        $response = $this->postJson(route('sites.staff_requirements.store', $this->site), [
            'requirement_name' => 'Explicit House source', 'category' => 'mandatory', 'hr_compliance_requirement_id' => $this->qualification->id, ...$choice]);
        if (! $allowed) {
            $response->assertUnprocessable();
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());

            return;
        }
        $response->assertRedirect();
        $row = SiteStaffRequirement::query()->where('site_id', $this->site->id)->where('requirement_name', 'Explicit House source')->sole();
        $this->assertSame($mode, $row->applicability_mode);
        $this->assertSame($count, $row->minimum_qualified_staff);
        $this->assertSame((int) $this->qualification->id, $row->hr_compliance_requirement_id);
    }

    public static function invalidCounts(): array
    {
        return ['missing' => [null], 'zero' => [0], 'negative' => [-1], 'fractional' => [1.5], 'text' => ['many']];
    }

    #[DataProvider('invalidCounts')]
    public function test_minimum_requires_an_explicit_positive_whole_count(mixed $count): void
    {
        $before = $this->state();
        $queue = $this->queueState();
        $this->postJson(route('sites.staff_requirements.store', $this->site), [
            'requirement_name' => 'Invalid count', 'category' => 'mandatory', 'applicability_mode' => 'minimum_staff',
            'minimum_qualified_staff' => $count, 'hr_compliance_requirement_id' => $this->qualification->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('minimum_qualified_staff');
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_edit_preserves_explicit_and_legacy_choices_after_default_changes(): void
    {
        $chosen = $this->requirement(['minimum_qualified_staff' => 3]);
        $legacy = $this->requirement(['applicability_mode' => null, 'minimum_qualified_staff' => null]);
        $this->policy(['house_qualification_approach' => 'all_workers']);
        foreach ([$chosen, $legacy] as $row) {
            $this->putJson(route('sites.staff_requirements.update', [$this->site, $row]), ['description' => 'Description edited'])->assertRedirect();
            $saved = $row->fresh();
            $this->assertSame($row->applicability_mode, $saved->applicability_mode);
            $this->assertSame($row->minimum_qualified_staff, $saved->minimum_qualified_staff);
            $this->assertSame($row->hr_compliance_requirement_id, $saved->hr_compliance_requirement_id);
        }
        $this->putJson(route('sites.staff_requirements.update', [$this->site, $chosen]), [
            'applicability_mode' => 'all_workers', 'minimum_qualified_staff' => null])->assertRedirect();
        $this->assertSame('all_workers', $chosen->fresh()->applicability_mode);
        $this->assertNull($chosen->fresh()->minimum_qualified_staff);
    }

    public function test_mapping_and_canonical_source_controls_leave_coverage_roles_unchanged(): void
    {
        $row = $this->requirement();
        $outside = Site::factory()->create(['type' => 'house']);
        $inactive = HrComplianceRequirement::factory()->create(['check_type' => 'credential', 'is_active' => false]);
        $coverage = SiteCoverageRequirement::create(['site_id' => $this->site->id, 'name' => 'Independent headcount',
            'coverage_type' => 'daily', 'day_of_week' => 1, 'starts_time' => '08:00', 'ends_time' => '12:00', 'minimum_staff' => 4,
            'role_requirements' => ['driver' => 2], 'allow_overstaffing' => true, 'is_active' => true]);
        $before = $this->state();
        $queue = $this->queueState();
        $this->putJson(route('sites.staff_requirements.update', [$this->site, $row]), ['hr_compliance_requirement_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('hr_compliance_requirement_id');
        $this->putJson(route('sites.staff_requirements.update', [$outside, $row]), ['minimum_qualified_staff' => 6])->assertNotFound();
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
        $this->assertSame(4, $coverage->fresh()->minimum_staff);
        $this->assertSame(['driver' => 2], $coverage->fresh()->role_requirements);
    }

    public function test_profile_and_wizard_expose_explicit_clone_choices_and_unknown_legacy(): void
    {
        $explicit = $this->requirement();
        $legacy = $this->requirement(['applicability_mode' => null, 'minimum_qualified_staff' => null]);
        $outside = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false]);
        $this->policy(['house_qualification_approach' => 'all_workers']);
        $data = app(SiteProfilePeoplePresenter::class)->staffRequirements($this->actor, $this->site);
        $chosen = collect($data['items'])->firstWhere('id', $explicit->id);
        $unknown = collect($data['items'])->firstWhere('id', $legacy->id);
        $this->assertSame('configured', $chosen['mapping']['status']);
        $this->assertSame('minimum_staff', $chosen['applicability_mode']);
        $this->assertSame(2, $chosen['minimum_qualified_staff']);
        $this->assertSame('unresolved', $unknown['applicability_status']);
        $this->assertNull($unknown['applicability_mode']);
        $this->assertNull($unknown['minimum_qualified_staff']);
        $this->assertSame(['applicability_mode' => 'all_workers', 'minimum_qualified_staff' => null], $data['new_requirement_defaults']);
        $this->get(route('sites.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('addSite.qualificationRequirementOptions.house_qualification_approach', 'all_workers')
            ->where('addSite.copyableSites', function ($sites) use ($explicit, $legacy, $outside): bool {
                $sites = collect($sites);
                $this->assertFalse($sites->contains('id', $outside->id));
                $rows = collect($sites->firstWhere('id', $this->site->id)['credentials']);
                $chosen = $rows->firstWhere('name', $explicit->requirement_name);
                $unknown = $rows->firstWhere('name', $legacy->requirement_name);
                $this->assertSame((int) $this->qualification->id, $chosen['hr_compliance_requirement_id']);
                $this->assertSame('minimum_staff', $chosen['applicability_mode']);
                $this->assertSame(2, $chosen['minimum_qualified_staff']);
                $this->assertNull($unknown['applicability_mode']);
                $this->assertSame('unresolved', $unknown['applicability_status']);

                return true;
            }));
    }

    public static function unresolvedPolicies(): array
    {
        return ['default warning' => ['warn', false], 'configured block independent' => ['block', true]];
    }

    #[DataProvider('unresolvedPolicies')]
    public function test_unknown_mode_does_not_hide_the_unmapped_mandatory_policy(string $policy, bool $blocked): void
    {
        $row = $this->requirement(['hr_compliance_requirement_id' => null, 'applicability_mode' => null, 'minimum_qualified_staff' => null]);
        $this->policy(['unmapped_mandatory_qualification' => $policy]);
        $duty = $this->duty();
        $before = $this->state();
        $queue = $this->queueState();
        $checks = $this->checks($duty, $this->worker);
        $this->assertCount(2, $checks);
        $this->assertFalse($checks[0]['passed']);
        $this->assertSame('warning', $checks[0]['severity']);
        $this->assertTrue($checks[0]['overrideable']);
        $this->assertStringContainsString('Coverage is unresolved', $checks[0]['message']);
        $this->assertSame($blocked ? 'block' : 'warning', $checks[1]['severity']);
        $this->assertSame(! $blocked, $checks[1]['overrideable']);
        $this->assertSame($row->id, $checks[1]['site_staff_requirement_id']);
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function overnightQualifications(): array
    {
        return ['covers final duty day' => ['2026-10-13', true], 'misses end day' => ['2026-10-12', false], 'missing' => [null, false]];
    }

    #[DataProvider('overnightQualifications')]
    public function test_every_worker_requires_full_local_overnight_evidence(?string $expiry, bool $passed): void
    {
        $this->requirement(['applicability_mode' => 'all_workers', 'minimum_qualified_staff' => null]);
        if ($expiry) {
            $this->credential($this->worker, $expiry);
        }
        $duty = $this->duty(['starts_at' => $this->instant('2026-10-12 23:00'), 'ends_at' => $this->instant('2026-10-13 02:00')]);
        $before = $this->state();
        $checks = $this->checks($duty, $this->worker);
        $this->assertCount(1, $checks);
        $this->assertSame($passed, $checks[0]['passed']);
        $this->assertSame('block', $checks[0]['severity']);
        $this->assertFalse($checks[0]['overrideable']);
        $this->assertSame($before, $this->state());
    }

    public function test_k_two_planning_builds_one_then_two_with_existing_manager_acknowledgement(): void
    {
        $this->requirement();
        $other = $this->person();
        $this->credential($this->worker);
        $this->credential($other);
        $first = $this->duty();
        $second = $this->duty();
        $lifecycle = app(ShiftLifecycleService::class);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($lifecycle, $first, $second, $other): void {
            $check = $this->checks($first, $this->worker, true)[0];
            $this->assertFalse($check['passed']);
            $this->assertSame('warning', $check['severity']);
            $this->assertTrue($check['overrideable']);
            $lifecycle->assign($first, $this->actor, $this->worker, ['override_acknowledged' => true,
                'override_reason' => 'First qualified worker; coverage will be completed before publication.'], source: ShiftLifecycleSource::Bulk);
            $this->assertSame((int) $this->worker->id, (int) $first->fresh()->user_id);
            $this->assertSame('scheduled', $first->fresh()->status);
            $check = $this->checks($second, $other, true)[0];
            $this->assertTrue($check['passed']);
            $this->assertSame([], $check['coverage_shortages']);
            $lifecycle->assign($second, $this->actor, $other, ['override_acknowledged' => true,
                'override_reason' => 'Both qualified workers provide complete coverage.'], source: ShiftLifecycleSource::Bulk);
            $this->assertSame((int) $other->id, (int) $second->fresh()->user_id);
            $override = DB::table('shift_eligibility_overrides')->where('shift_id', $first->id)->sole();
            $this->assertStringContainsString('house_qualification', $override->rules_overridden);
            $this->assertSame((int) $this->actor->id, (int) $override->overridden_by);
        });
    }

    public function test_k_two_publication_blocks_one_then_publishes_two_without_new_headcount_policy(): void
    {
        $this->requirement();
        $this->credential($this->worker);
        $other = $this->person();
        $this->credential($other);
        $first = $this->duty(['user_id' => $this->worker->id, 'status' => 'scheduled']);
        $period = $this->period();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($first, $period, $other): void {
            $summary = app(RosterPublishingService::class)->review($period, $this->actor);
            $house = collect($summary['blocks'])->where('issue_type', 'house_qualification_coverage')->values();
            $this->assertFalse($summary['can_publish']);
            $this->assertNotEmpty($house);
            $this->assertSame(1, $house[0]['qualified_count']);
            $this->assertSame(2, $house[0]['minimum_qualified_staff']);
            $this->assertNull($first->fresh()->published_at);
            $second = $this->duty(['user_id' => $other->id, 'status' => 'scheduled']);
            $published = app(RosterPublishingService::class)->publish($period, $this->actor);
            $this->assertSame(RosterPeriod::STATUS_PUBLISHED, $published->status);
            $this->assertNotNull($first->fresh()->published_at);
            $this->assertNotNull($second->fresh()->published_at);
            $this->assertSame(2, $published->shift_count);
            $this->assertSame([], $published->validation_summary['blocks']);
            $this->assertSame(0, SiteCoverageRequirement::query()->where('site_id', $this->site->id)->count());
        });
    }

    public static function coverageSlices(): array
    {
        return ['partial end insufficient' => ['partial', false], 'distinct handover covers' => ['handover', true],
            'one worker duplicate insufficient' => ['duplicate', false], 'open not qualified supply' => ['open', false],
            'cancelled no supply' => ['cancelled', false], 'carry in clipped overlap' => ['carry', true]];
    }

    #[DataProvider('coverageSlices')]
    public function test_actual_duty_slices_count_distinct_qualified_staff_only(string $shape, bool $covered): void
    {
        $this->requirement();
        $this->credential($this->worker);
        $other = $this->person();
        $third = $this->person();
        $this->credential($other);
        $this->credential($third);
        $period = $this->period();
        $start = $shape === 'carry' ? '2026-10-12 00:00' : '2026-10-12 08:00';
        $end = $shape === 'carry' ? '2026-10-12 02:00' : '2026-10-12 12:00';
        $this->duty(['user_id' => $this->worker->id, 'status' => 'scheduled', 'starts_at' => $this->instant($start), 'ends_at' => $this->instant($end)]);
        if ($shape === 'handover') {
            $this->duty(['user_id' => $other->id, 'status' => 'scheduled', 'ends_at' => $this->instant('2026-10-12 10:00')]);
            $this->duty(['user_id' => $third->id, 'status' => 'scheduled', 'starts_at' => $this->instant('2026-10-12 10:00')]);
        } else {
            $this->duty(['user_id' => match ($shape) {
                'duplicate' => $this->worker->id, 'open' => null, default => $other->id
            },
                'status' => $shape === 'cancelled' ? 'cancelled' : ($shape === 'open' ? 'draft' : 'scheduled'),
                'starts_at' => $this->instant($shape === 'carry' ? '2026-10-11 23:00' : $start),
                'ends_at' => $this->instant($shape === 'partial' ? '2026-10-12 10:00' : $end)]);
        }
        $before = $this->state();
        $queue = $this->queueState();
        $summary = app(HouseQualificationCoverageService::class)->validatePeriod($period);
        $this->assertSame($covered, $summary['blocks'] === []);
        if (! $covered) {
            $this->assertSame(1, $summary['blocks'][0]['qualified_count']);
            $this->assertSame(2, $summary['blocks'][0]['minimum_qualified_staff']);
            if ($shape === 'partial') {
                $this->assertSame($this->instant('2026-10-12 10:00')->format('Y-m-d\TH:i:s.u\Z'), $summary['blocks'][0]['starts_at']);
            }
        }
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_other_period_and_cancelled_history_do_not_invent_demand_or_change_history(): void
    {
        $this->requirement();
        $period = $this->period();
        $other = $this->period(['week_start' => '2026-10-05', 'week_end' => '2026-10-12']);
        $history = $this->duty(['status' => 'cancelled']);
        $history->forceFill(['roster_period_id' => $period->id, 'published_at' => now()])->save();
        $foreign = $this->duty();
        $foreign->forceFill(['roster_period_id' => $other->id])->save();
        $before = $this->state();
        $this->assertSame(['blocks' => [], 'warnings' => []], app(HouseQualificationCoverageService::class)->validatePeriod($period));
        $this->assertSame($before, $this->state());
    }

    public function test_reassignment_excludes_the_exact_old_worker_duty(): void
    {
        $this->requirement();
        $other = $this->person();
        $this->credential($this->worker);
        $this->credential($other);
        $target = $this->duty(['user_id' => $this->worker->id, 'status' => 'scheduled']);
        $check = $this->checks($target, $other)[0];
        $this->assertFalse($check['passed']);
        $this->assertSame(1, $check['coverage_shortages'][0]['qualified_count']);
        $this->assertSame((int) $this->worker->id, (int) $target->fresh()->user_id);
    }

    public static function currentChanges(): array
    {
        return ['credential expiry' => ['credential'], 'profile Site removed' => ['profile'], 'approval revoked' => ['approval'],
            'minimum increases' => ['count'], 'method unresolved' => ['mode'], 'mapping inactive' => ['mapping'], 'supply cancelled' => ['supply']];
    }

    #[DataProvider('currentChanges')]
    public function test_current_period_uses_independently_committed_inputs_despite_primed_rr(string $change): void
    {
        $this->requirement(['minimum_qualified_staff' => 1]);
        $credential = $this->credential($this->worker);
        $this->duty(['user_id' => $this->worker->id, 'status' => 'scheduled']);
        $period = $this->period();
        $supply = null;
        if ($change === 'supply') {
            $this->requirement(['minimum_qualified_staff' => 2]);
            $other = $this->person();
            $this->credential($other);
            $supply = $this->duty(['user_id' => $other->id, 'status' => 'scheduled']);
        }
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($change, $period, $credential, $writer, $supply): void {
            DB::beginTransaction();
            $old = $this->state();
            $this->assertSame([], app(HouseQualificationCoverageService::class)->validatePeriod($period)['blocks']);
            $writer->transaction(function () use ($change, $credential, $writer, $supply): void {
                match ($change) {
                    'credential' => $writer->table('staff_credentials')->where('id', $credential->id)->update(['expires_at' => '2026-10-11']),
                    'profile' => $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']),
                    'approval' => $writer->table('users')->where('id', $this->worker->id)->update(['approved_at' => null]),
                    'count' => $writer->table('site_staff_requirements')->where('site_id', $this->site->id)->update(['minimum_qualified_staff' => 2]),
                    'mode' => $writer->table('site_staff_requirements')->where('site_id', $this->site->id)->update(['applicability_mode' => null]),
                    'mapping' => $writer->table('hr_compliance_requirements')->where('id', $this->qualification->id)->update(['is_active' => false]),
                    'supply' => $writer->table('shifts')->where('id', $supply->id)->update(['status' => 'cancelled']),
                };
            });
            $this->assertSame($old, $this->state(), 'Ordinary RR evidence must remain the pre-commit snapshot.');
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $queue = $this->queueState();
            if ($change === 'mapping') {
                try {
                    app(HouseQualificationCoverageService::class)->validatePeriod($period, current: true);
                    $this->fail('A configured inactive canonical mapping cannot become an overrideable unmapped warning.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('The configured House qualification evidence is unavailable.', $exception->getMessage());
                }
            } else {
                $summary = app(HouseQualificationCoverageService::class)->validatePeriod($period, current: true);
                $this->assertNotEmpty($change === 'mode' ? $summary['warnings'] : $summary['blocks']);
            }
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($current, $this->state());
        });
    }

    public function test_current_requirement_contention_fails_without_changing_any_store(): void
    {
        $row = $this->requirement();
        $this->credential($this->worker);
        $duty = $this->duty();
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($row, $duty, $writer): void {
            $before = $this->state();
            $queue = $this->queueState();
            $writer->beginTransaction();
            $writer->table('site_staff_requirements')->where('id', $row->id)->lockForUpdate()->first();
            try {
                $this->checks($duty, $this->worker, true);
                $this->fail('Current requirement must fail immediately on contention.');
            } catch (QueryException $exception) {
                $this->assertSame(3572, (int) ($exception->errorInfo[1] ?? 0));
            } finally {
                $writer->rollBack();
            }
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function createWarningModes(): array
    {
        return ['mandatory unresolved requires ack' => ['mandatory', 'unknown', false],
            'recommended unresolved stays generic' => ['recommended', 'unknown', true],
            'mandatory unmapped requires ack' => ['mandatory', 'unmapped', false],
            'minimum shortage can build roster' => ['mandatory', 'minimum', true]];
    }

    #[DataProvider('createWarningModes')]
    public function test_single_create_governs_mandatory_unknowns_but_preserves_minimum_planning_warning(string $category, string $choice, bool $saved): void
    {
        $this->requirement(['category' => $category,
            'applicability_mode' => $choice === 'unknown' ? null : ($choice === 'unmapped' ? 'all_workers' : 'minimum_staff'),
            'minimum_qualified_staff' => $choice === 'minimum' ? 2 : null,
            'hr_compliance_requirement_id' => $choice === 'unmapped' ? null : $this->qualification->id]);
        $this->credential($this->worker);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($saved): void {
            $before = $this->state();
            $queue = $this->queueState();
            $data = ['client_id' => $this->client->id, 'service_context_id' => null, 'user_id' => $this->worker->id,
                'starts_at' => $this->instant()->toIso8601String(), 'ends_at' => $this->instant('2026-10-12 12:00')->toIso8601String(),
                'status' => 'scheduled', 'shift_type' => 'standard', 'coverage_roles' => [], 'tasks' => []];
            $command = app(ShiftPlanningCommand::class);
            $result = $command->save($this->actor, $data);
            if ($saved) {
                $this->assertTrue($result->changed);
                $this->assertNull($result->rejectionReason);
                $this->assertTrue($result->shift->exists);
                $this->assertSame((int) $this->worker->id, (int) $result->shift->fresh()->user_id);

                return;
            }
            $this->assertSame('eligibility_warning', $result->rejectionReason);
            $this->assertFalse($result->changed);
            $this->assertFalse($result->shift->exists);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $accepted = $command->save($this->actor, [...$data, 'override_acknowledged' => true,
                'override_reason' => 'Manager reviewed the unresolved qualification requirement.']);
            $this->assertTrue($accepted->changed);
            $this->assertNull($accepted->rejectionReason);
            $this->assertNotEmpty(DB::table('shift_eligibility_overrides')->where('shift_id', $accepted->shift->id)->get());
        });
    }

    public static function permittedContexts(): array
    {
        return ['global default context' => [false], 'other active context preserves existing planner policy' => [true]];
    }

    public static function optionalUnavailableMappings(): array
    {
        return [
            'recommended inactive' => ['recommended', 'inactive'],
            'recommended unsupported' => ['recommended', 'unsupported'],
            'specialist inactive' => ['specialist', 'inactive'],
            'specialist unsupported' => ['specialist', 'unsupported'],
        ];
    }

    #[DataProvider('optionalUnavailableMappings')]
    public function test_optional_configured_unavailable_evidence_warns_at_real_gateway_and_publication(string $category, string $condition): void
    {
        $requirement = $this->requirement(['category' => $category, 'applicability_mode' => 'all_workers', 'minimum_qualified_staff' => null]);
        $this->qualification->update($condition === 'inactive' ? ['is_active' => false] : ['check_type' => 'unsupported']);
        $period = $this->period();
        $shift = $this->duty(['user_id' => $this->worker->id, 'status' => 'scheduled', 'roster_period_id' => $period->id]);
        $before = $this->state();
        $queue = $this->queueState();
        $expected = $requirement->requirement_name.': the configured qualification evidence is unavailable. Review its recorded mapping.';
        $decision = app(AssignmentEligibilityGateway::class)->decide($shift, $this->worker);
        $this->assertTrue($decision->isWarning(), 'A known optional mapping condition must not make the real gateway unavailable.');
        $this->assertContains($expected, $decision->result->warnings);
        $this->assertSame([], $decision->result->blocking_reasons);
        $summary = DB::transaction(function () use ($period): array {
            app(WorkforceMutationGuard::class)->lock();

            return app(RosterPublishValidator::class)->validate($period, currentHouse: true);
        });
        $this->assertTrue($summary['can_publish']);
        $this->assertSame([], $summary['blocks']);
        $this->assertContains($expected, array_column($summary['warnings'], 'message'));
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    #[DataProvider('permittedContexts')]
    public function test_house_scope_does_not_add_context_site_policy(bool $otherSite): void
    {
        $context = ServiceContext::factory()->create(['site_id' => $otherSite ? Site::factory()->create()->id : null, 'is_active' => true]);
        $duty = $this->duty(['service_context_id' => $context->id, 'user_id' => $this->worker->id, 'status' => 'scheduled']);
        $this->assertSame([], $this->checks($duty, $this->worker));
        $this->requirement(['minimum_qualified_staff' => 1]);
        $this->credential($this->worker);
        $period = $this->period();
        $before = $this->state();
        $queue = $this->queueState();
        $check = $this->checks($duty, $this->worker, true)[0];
        $this->assertTrue($check['passed']);
        $this->assertSame([], $check['coverage_shortages']);
        $summary = DB::transaction(fn () => app(HouseQualificationCoverageService::class)->validatePeriod($period, current: true));
        $this->assertSame([], $summary['blocks']);
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    private function person(array $keys = [], string $roleName = 'support_worker'): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'house-qualification-'.Str::uuid(), 'label' => 'House fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function requirement(array $values = []): SiteStaffRequirement
    {
        return SiteStaffRequirement::create(['site_id' => $this->site->id, 'requirement_name' => 'House '.Str::uuid(),
            'category' => 'mandatory', 'certification_required' => false, 'expiry_period_months' => null, 'is_active' => true,
            'hr_compliance_requirement_id' => $this->qualification->id, 'applicability_mode' => 'minimum_staff', 'minimum_qualified_staff' => 2, ...$values]);
    }

    private function credential(User $user, string $expiry = '2027-01-01'): StaffCredential
    {
        return StaffCredential::create(['user_id' => $user->id, 'type' => $this->qualification->code,
            'issuer' => 'Recorded canonical evidence', 'issued_at' => '2026-01-01', 'expires_at' => $expiry]);
    }

    private function instant(string $value = '2026-10-12 08:00'): Carbon
    {
        return Carbon::parse($value, 'Pacific/Auckland')->utc();
    }

    private function duty(array $values = []): Shift
    {
        return Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $this->client->id, 'service_context_id' => null,
            'user_id' => null, 'status' => 'draft', 'created_by' => $this->actor->id,
            'starts_at' => $this->instant(), 'ends_at' => $this->instant('2026-10-12 12:00'), 'coverage_roles' => [],
            'required_licence_class' => null, 'required_licence_endorsements' => [], 'expected_break_minutes' => null,
            'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, ...$values]);
    }

    private function period(array $values = []): RosterPeriod
    {
        return RosterPeriod::factory()->create(['site_id' => $this->site->id, 'week_start' => '2026-10-12', 'week_end' => '2026-10-19',
            'status' => RosterPeriod::STATUS_DRAFT, 'created_by' => $this->actor->id, ...$values]);
    }

    private function policy(array $values): void
    {
        AppSetting::updateOrCreate(['key' => HrEligibilityRuleSettings::KEY],
            ['value' => ['version' => 1, 'values' => [...HrEligibilityRuleSettings::DEFAULTS, ...$values]]]);
    }

    private function checks(Shift $shift, User $user, bool $current = false): array
    {
        $service = app(HouseQualificationCoverageService::class);

        return $current ? DB::transaction(fn () => $service->evaluateAll($shift, $user, true)) : $service->evaluateAll($shift, $user);
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['site_staff_requirements', 'site_coverage_requirements', 'hr_compliance_requirements', 'staff_credentials', 'hr_employee_profiles',
            'users', 'app_settings', 'shifts', 'roster_periods', 'coverage_reservations', 'shift_eligibility_overrides', 'timeline_events', 'audit_logs',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return array_combine($tables, array_map(fn ($table) => ($connection ?? DB::connection())->table($table)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all(), $tables));
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        $name = 'house_qualification_writer';
        config(['database.connections.'.$name => array_replace(DB::connection()->getConfig(), ['name' => $name])]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());

        return $writer;
    }

    private function withProductionManager(callable $proof): void
    {
        $connection = DB::connection();
        $testing = app('db.transactions');
        $production = new DatabaseTransactionsManager;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        app()->instance('db.transactions', $production);
        $connection->setTransactionManager($production);
        try {
            $proof();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->assertFalse($connection->getPdo()->inTransaction());
            app()->instance('db.transactions', $testing);
            $connection->setTransactionManager($testing);
        }
    }
}
