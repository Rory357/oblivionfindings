<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrFatiguePolicySettings;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\Rules\FatigueRule;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\Operations\ShiftReportingService;
use App\Services\Operations\WorkforcePreferences;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkforceStaffingRulesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland', 'hr.fatigue' => [
            'max_hours_per_day' => 12, 'max_hours_per_week' => 50, 'warning_threshold_weekly' => 40,
            'min_rest_between_shifts_hours' => 10, 'max_consecutive_days' => 7,
        ]]);
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defaults_are_read_only_for_roster_managers_without_the_exact_hr_permission(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'shifts.manageAny', 'settings.access.manage']);
        $this->actingAs($actor)->get(route('operations.workforce.settings'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('staffingRules.values', fn ($values) => $values->all() == $this->policy()->defaults())
                ->where('staffingRules.source', 'deployment_defaults')->where('staffingRules.can_edit', false)
                ->where('staffingRules.can_view_history', false)->where('staffingRules.urls.update', null)
                ->where('staffingRules.urls.history', null)->where('staffingRules.scope', 'organisation'));
        $this->patchJson($this->saveUrl(), $this->body())->assertForbidden();
        $this->getJson($this->historyUrl())->assertForbidden();
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
        $this->assertSame(0, $this->policyAudits()->count());
    }

    public function test_hr_permission_does_not_bypass_the_existing_roster_workspace_permission(): void
    {
        $actor = $this->actor(['hr.settings.manage']);
        $this->actingAs($actor)->patchJson($this->saveUrl(), $this->body())->assertForbidden();
        $this->getJson($this->historyUrl())->assertForbidden();
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
    }

    public function test_authorized_save_changes_the_canonical_rule_and_records_reason_and_durable_recheck(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $before = $this->policy()->snapshot();
        $values = [...$before['values'], 'max_hours_per_day' => 3.0];
        $this->clearRefresh();
        $this->actingAs($actor)->patchJson($this->saveUrl(), $this->body($values, $before['revision']))
            ->assertRedirect(route('operations.workforce.settings'));
        $after = $this->policy()->snapshot();
        $this->assertSame($values, $after['values']);
        $this->assertSame(1, $after['version']);
        $this->assertNotSame($before['revision'], $after['revision']);
        $audit = $this->policyAudits()->sole();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('Reviewed staffing plan for the next period.', $audit->meta['reason']);
        $this->assertEquals($before['values'], $audit->meta['before']);
        $this->assertEquals($values, $audit->meta['after']);
        $request = $this->policyRecheck();
        $this->assertTrue($request->all_assigned);
        $this->assertSame('pending', $request->status);
        $this->assertSame($after['revision'], $request->source_fingerprint);
        Queue::assertPushed(RefreshWorkforceEligibility::class, fn ($job) => $job->recheckId === $request->id && $job->sourceVersion === 1);

        $candidate = new Shift(['user_id' => $actor->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(4)]);
        $checks = collect(app(FatigueRule::class)->evaluateAll($candidate, $actor))->keyBy('rule');
        $this->assertFalse($checks['fatigue_daily']['passed']);
        $this->assertSame('block', $checks['fatigue_daily']['severity']);
        $this->assertFalse($checks['fatigue_daily']['overrideable']);
        $this->get(route('operations.workforce.settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('staffingRules.values.max_hours_per_day', 3)->where('staffingRules.source', 'saved_override')
            ->where('staffingRules.can_edit', true)->where('staffingRules.urls.history', $this->historyUrl()));
    }

    public function test_stale_revision_and_same_second_restore_do_not_overwrite_another_save(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $initial = $this->policy()->snapshot();
        $first = $this->save($actor, [...$initial['values'], 'max_hours_per_day' => 8.0]);
        $this->actingAs($actor)->patchJson($this->saveUrl(), $this->body([...$initial['values'], 'max_hours_per_day' => 4], $initial['revision']))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->assertSame($first['revision'], $this->policy()->snapshot()['revision']);
        $restored = $this->save($actor, $initial['values']);
        $this->assertSame($initial['values'], $restored['values']);
        $this->assertSame(2, $restored['version']);
        $this->assertNotSame($initial['revision'], $restored['revision']);
        $this->assertSame(2, $this->policyRecheck()->source_version);
        $this->assertSame(2, $this->policyAudits()->count());
        $this->withHeaders(['X-Inertia' => 'true'])->patch($this->saveUrl(), $this->body($first['values'], $first['revision']))
            ->assertRedirect()->assertSessionHasErrors('expected_revision');
    }

    public function test_an_identical_save_does_not_create_an_override_audit_or_recheck(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $this->clearRefresh();
        $before = $this->policy()->snapshot();
        $outcome = $this->save($actor, $before['values']);
        $this->assertFalse($outcome['changed']);
        $this->assertSame($before['revision'], $outcome['revision']);
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
        $this->assertSame(0, $this->policyAudits()->count());
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        $saved = $this->save($actor, [...$before['values'], 'max_hours_per_day' => 8.0]);
        $auditCount = $this->policyAudits()->count();
        Queue::fake();
        $outcome = $this->save($actor, $saved['values']);
        $this->assertFalse($outcome['changed']);
        $this->assertSame($saved['revision'], $outcome['revision']);
        $this->assertSame($auditCount, $this->policyAudits()->count());
        $this->assertSame(1, $this->policyRecheck()->source_version);
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
    }

    #[DataProvider('invalidPolicyInputs')]
    public function test_invalid_values_or_missing_reason_cannot_change_policy_or_queue_work(array $changes, string $field, ?string $reason = null): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $body = $this->body([...$this->policy()->defaults(), ...$changes]);
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        $this->clearRefresh();
        $this->actingAs($actor)->patchJson($this->saveUrl(), $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
        $this->assertSame(0, $this->policyAudits()->count());
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
    }

    public static function invalidPolicyInputs(): array
    {
        return [
            'zero daily' => [['max_hours_per_day' => 0], 'values.max_hours_per_day'],
            'negative weekly' => [['max_hours_per_week' => -1], 'values.max_hours_per_week'],
            'nonfinite numeric' => [['max_hours_per_day' => '1e309'], 'values.max_hours_per_day'],
            'warning exceeds maximum' => [['warning_threshold_weekly' => 51], 'values.warning_threshold_weekly'],
            'negative rest' => [['min_rest_between_shifts_hours' => -1], 'values.min_rest_between_shifts_hours'],
            'rest processing bound' => [['min_rest_between_shifts_hours' => 8785], 'values.min_rest_between_shifts_hours'],
            'consecutive processing bound' => [['max_consecutive_days' => 367], 'values.max_consecutive_days'],
            'fractional consecutive days' => [['max_consecutive_days' => 1.5], 'values.max_consecutive_days'],
            'unknown safety field' => [['unreviewed_policy' => true], 'values'],
            'blank reason' => [['max_hours_per_day' => 8], 'reason', '   '],
            'reason length' => [['max_hours_per_day' => 8], 'reason', str_repeat('x', 2001)],
        ];
    }

    public function test_processing_boundaries_accept_valid_values_without_changing_default_policies(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $values = ['max_hours_per_day' => 120.5, 'max_hours_per_week' => 900.0, 'warning_threshold_weekly' => 0.0,
            'min_rest_between_shifts_hours' => 8784.0, 'max_consecutive_days' => 366];
        $this->actingAs($actor)->patchJson($this->saveUrl(), $this->body($values))->assertRedirect();
        $this->assertSame($values, $this->policy()->values());
        $this->assertSame(12.0, $this->policy()->defaults()['max_hours_per_day']);
        $this->assertSame(7, $this->policy()->defaults()['max_consecutive_days']);
    }

    public function test_policy_audit_and_recheck_roll_back_and_delivery_waits_for_outer_commit(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $this->clearRefresh();
        try {
            DB::transaction(function () use ($actor): void {
                $this->save($actor, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
                $this->assertSame(1, $this->policyAudits()->count());
                $this->assertSame(1, WorkforceEligibilityRecheck::count());
                Queue::assertNotPushed(RefreshWorkforceEligibility::class);
                throw new \RuntimeException('Rollback outer command.');
            });
            $this->fail('The outer command must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback outer command.', $exception->getMessage());
        }
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
        $this->assertSame(0, $this->policyAudits()->count());
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        DB::transaction(function () use ($actor): void {
            $this->save($actor, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
            Queue::assertNotPushed(RefreshWorkforceEligibility::class);
        });
        Queue::assertPushed(RefreshWorkforceEligibility::class, 1);
    }

    public function test_audit_failure_rolls_back_the_policy_and_emits_no_refresh(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $this->clearRefresh();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === HrFatiguePolicySettings::AUDIT_ACTION) {
                throw new \RuntimeException('Audit unavailable.');
            }
        });
        try {
            $this->save($actor, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
            $this->fail('The save must surface the audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable.', $exception->getMessage());
        }
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
        $this->assertSame(0, $this->policyAudits()->count());
        $this->assertSame(0, WorkforceEligibilityRecheck::count());
        Queue::assertNotPushed(RefreshWorkforceEligibility::class);
    }

    public function test_a_cached_permission_or_approval_cannot_authorize_a_new_policy_save(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $this->assertTrue($actor->canDo('hr.settings.manage'));
        DB::table('permission_user')->where('user_id', $actor->id)
            ->where('permission_id', Permission::where('key', 'hr.settings.manage')->value('id'))->update(['allowed' => false]);
        try {
            $this->save($actor, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
            $this->fail('Current locked permissions must deny a revoked grant.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]);
        try {
            $this->save($actor, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
            $this->fail('Current locked approval must deny a revoked account.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
    }

    public function test_recheck_supersedes_old_versions_and_covers_current_published_and_distant_unpublished_assignments(): void
    {
        $owner = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $worker = $this->actor([]);
        $duties = collect([
            $this->shift($worker, ['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'status' => 'in_progress']),
            $this->shift($worker, ['starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(4), 'published_at' => now()]),
            $this->shift($worker, ['starts_at' => now()->addDays(400), 'ends_at' => now()->addDays(400)->addHours(4), 'published_at' => null]),
        ]);
        $cancelled = $this->shift($worker, ['status' => 'cancelled']);
        $this->clearRefresh();
        $this->save($owner, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
        $first = $this->policyRecheck();
        $this->save($owner, [...$this->policy()->values(), 'max_hours_per_day' => 2.0]);
        $refresh = app(WorkforceEligibilityRefresh::class);
        $refresh->process($first->id, $first->source_version);
        $this->assertSame(0, WorkforceEligibilityObservation::count());
        $current = $this->policyRecheck();
        $refresh->process($current->id, $current->source_version);
        $this->assertSame(3, $current->fresh()->scanned_count);
        foreach ($duties as $duty) {
            $observation = WorkforceEligibilityObservation::where('shift_id', $duty->id)->sole();
            $this->assertSame('blocked', $observation->posture);
            $this->assertContains('fatigue_daily', array_column($observation->failed_rules, 'rule'));
            $this->assertSame($worker->id, $duty->fresh()->user_id);
        }
        $this->assertFalse(WorkforceEligibilityObservation::where('shift_id', $cancelled->id)->exists());
    }

    public function test_fatigue_rule_reads_one_current_policy_snapshot_per_evaluation_and_preserves_warning_override(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $this->save($actor, [...$this->policy()->values(), 'warning_threshold_weekly' => 3.0]);
        $candidate = new Shift(['user_id' => $actor->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(4)]);
        $rule = app(FatigueRule::class);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $checks = collect($rule->evaluateAll($candidate, $actor))->keyBy('rule');
            $this->assertSame(1, collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `app_settings`'))->count());
            $this->assertFalse($checks['fatigue_weekly']['passed']);
            $this->assertSame('warning', $checks['fatigue_weekly']['severity']);
            $this->assertTrue($checks['fatigue_weekly']['overrideable']);

            $this->save($actor, [...$this->policy()->values(), 'warning_threshold_weekly' => 10.0]);
            DB::flushQueryLog();
            $freshChecks = collect($rule->evaluateAll($candidate, $actor))->keyBy('rule');
            $this->assertSame(1, collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `app_settings`'))->count());
            $this->assertTrue($freshChecks['fatigue_weekly']['passed']);
            $this->assertTrue($freshChecks['fatigue_daily']['passed']);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_history_exposes_only_authorized_policy_differences_and_private_pagination(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage']);
        $this->save($actor, [...$this->policy()->values(), 'max_hours_per_day' => 8.0]);
        AuditLog::create(['user_id' => $actor->id, 'action' => HrFatiguePolicySettings::AUDIT_ACTION,
            'auditable_type' => (new AppSetting)->getMorphClass(), 'auditable_id' => 999,
            'meta' => ['source_key' => 'private.unrelated', 'reason' => 'Hidden unrelated source.']]);
        $response = $this->actingAs($actor)->getJson($this->historyUrl())->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('current_page', 1)->assertJsonPath('per_page', 20)
            ->assertJsonPath('data.0.actor.id', $actor->id)->assertJsonPath('data.0.reason', 'Reviewed staffing plan for the next period.')
            ->assertJsonPath('data.0.changes.0.key', 'max_hours_per_day')->assertJsonPath('data.0.changes.0.before', 12)
            ->assertJsonPath('data.0.changes.0.after', 8);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['id', 'at', 'actor', 'reason', 'changes'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('Hidden unrelated source', $response->getContent());
        $this->assertStringNotContainsString('revision_before', $response->getContent());
        $this->getJson($this->historyUrl().'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_personal_preferences_have_a_separate_minimal_audit_and_do_not_modify_global_rules(): void
    {
        $actor = $this->actor(['rostering.viewAny']);
        $other = $this->actor(['rostering.viewAny']);
        $preferences = app(WorkforcePreferences::class);
        $otherBefore = $preferences->for($other);
        $this->actingAs($actor)->patchJson(route('operations.workforce.settings.update'), ['default_tab' => 'calendar',
            'roster_view' => 'list', 'expected_revision' => $preferences->for($actor)['revision']])->assertRedirect();
        $audit = AuditLog::where('action', 'workforce.preferences.updated')->sole();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame(['default_tab' => 'calendar', 'roster_view' => 'list'], $audit->meta['after']);
        $this->assertSame($otherBefore, $preferences->for($other));
        $this->assertNull(AppSetting::where('key', HrFatiguePolicySettings::KEY)->first());
    }

    public function test_malformed_saved_policy_fails_closed_instead_of_silently_using_defaults(): void
    {
        AppSetting::create(['key' => HrFatiguePolicySettings::KEY, 'value' => ['version' => 1, 'values' => ['max_hours_per_day' => -1]]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The saved staffing rules could not be loaded.');
        $this->policy()->values();
    }

    public function test_roster_capacity_and_staff_utilisation_use_the_same_saved_thresholds(): void
    {
        $actor = $this->actor(['rostering.viewAny', 'hr.settings.manage', 'shifts.manageAny']);
        $worker = $this->actor([]);
        $shift = $this->shift($worker, ['actual_starts_at' => now()->addDays(2), 'actual_ends_at' => now()->addDays(2)->addHours(4)]);
        // Utilisation intentionally reports recorded worked hours, not raw
        // planned/actual Shift clocks. Supply its canonical timesheet evidence.
        Timesheet::factory()->create(['shift_id' => $shift->id, 'user_id' => $worker->id, 'client_id' => $shift->client_id,
            'work_date' => $shift->starts_at->toDateString(), 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at,
            'break_minutes' => 0, 'status' => 'draft']);
        $this->save($actor, [...$this->policy()->values(), 'warning_threshold_weekly' => 3.0]);
        $this->actingAs($actor)->get(route('operations.rostering.index', ['week' => '2026-10-05']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('capacityPlanningReferenceHours', 3)
                ->where('capacity', fn ($rows) => $rows->firstWhere('user_id', $worker->id)['warn'] === 'medium'));
        $start = Carbon::parse('2026-10-05', 'Pacific/Auckland')->utc();
        $end = Carbon::parse('2026-10-12', 'Pacific/Auckland')->utc();
        $reporter = app(ShiftReportingService::class);
        $method = new \ReflectionMethod($reporter, 'buildStaffUtilisation');
        $report = $method->invoke($reporter, ['start' => $start, 'end' => $end,
            'site_id' => $this->site->id, 'staff_id' => $worker->id, 'allowed_site_ids' => [$this->site->id]]);
        $this->assertSame('warning', $report['rows'][0]['overtime_flag']);
        $this->assertSame(4.0, $report['rows'][0]['worked_hours']);
        $this->assertSame($worker->id, $shift->fresh()->user_id);
    }

    private function actor(array $permissions): User
    {
        $actor = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $actor->roles()->sync([Role::create(['name' => 'staffing-policy-'.str()->uuid(), 'label' => 'Staffing policy test',
            'type' => 'custom', 'level' => 10])->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'manager_user_id' => null]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'Workforce', 'module' => 'operations']);
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $actor;
    }

    private function shift(User $worker, array $changes = []): Shift
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);

        return Shift::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id, 'service_context_id' => null,
            'user_id' => $worker->id, 'status' => 'scheduled', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(4), ...$changes]);
    }

    private function policy(): HrFatiguePolicySettings
    {
        return app(HrFatiguePolicySettings::class);
    }

    private function body(?array $values = null, ?string $revision = null): array
    {
        return ['expected_revision' => $revision ?? $this->policy()->snapshot()['revision'],
            'values' => $values ?? [...$this->policy()->values(), 'max_hours_per_day' => 8.0],
            'reason' => 'Reviewed staffing plan for the next period.'];
    }

    private function save(User $actor, array $values): array
    {
        $request = Request::create($this->saveUrl(), 'PATCH');
        $request->setUserResolver(fn () => $actor);

        return $this->policy()->save($actor, $values, $this->policy()->snapshot()['revision'], 'Reviewed staffing plan for the next period.', $request);
    }

    private function policyAudits()
    {
        return AuditLog::where('action', HrFatiguePolicySettings::AUDIT_ACTION);
    }

    private function policyRecheck(): WorkforceEligibilityRecheck
    {
        return WorkforceEligibilityRecheck::where('source_type', 'hr_fatigue_policy')->sole();
    }

    private function clearRefresh(): void
    {
        WorkforceEligibilityRecheck::query()->delete();
        WorkforceEligibilityObservation::query()->delete();
        Queue::fake();
    }

    private function saveUrl(): string
    {
        return route('operations.workforce.settings.staffing-rules.update');
    }

    private function historyUrl(): string
    {
        return route('operations.workforce.settings.history');
    }
}
