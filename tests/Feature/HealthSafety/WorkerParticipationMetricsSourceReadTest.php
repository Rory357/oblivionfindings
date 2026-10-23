<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationAccessService;
use App\Services\HealthSafety\WorkerParticipationMetricsReadService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Real source/aggregate/HTTP consumers; native execution remains UNRUN. */
final class WorkerParticipationMetricsSourceReadTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Site $site;
    private Site $other;
    private WorkerParticipationAccessService $access;
    private WorkerParticipationMetricsReadService $metrics;
    private array $graph;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-13T12:00:00+13:00')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        foreach (WorkerParticipationAccessService::PERMISSIONS as $key) { $this->grant($key, $key !== 'hazards.manage'); }
        $this->access = app(WorkerParticipationAccessService::class);
        $this->metrics = new WorkerParticipationMetricsReadService($this->access);
        $this->graph = $this->records($this->site);
    }

    public function test_overview_preserves_all_representative_and_committee_status_definitions(): void
    {
        $this->graph['representative']->update(['status' => 'inactive']);
        $this->graph['committee']->update(['status' => 'inactive']);
        $this->assertSame(['pct' => 100, 'committees' => 1, 'source_access' => 'readable', 'scope' => 'current_readable_sources', 'viewer_id' => $this->actor->id], $this->metrics->overview($this->actor, $this->site->id));
        $this->assertSame(50, $this->metrics->overview($this->actor, [$this->site->id, $this->other->id])['pct']);
    }

    public function test_monthly_keeps_cancelled_meetings_in_denominator_and_actioned_closed_consultations(): void
    {
        $this->graph['committee']->meetings()->create(['scheduled_at' => '2026-10-02 12:00:00', 'status' => 'cancelled', 'created_by' => $this->actor->id]);
        $this->consultation($this->site, 'actioned'); $this->consultation($this->site, 'closed');
        $result = $this->monthly($this->site->id);
        $this->assertSame(['2026-10' => 50], $result['engagement']);
        $this->assertSame(['2026-10' => 67], $result['consultation']);
        $this->assertSame('readable', $result['source_access']);
    }

    public function test_period_filters_keep_empty_months_absent_and_empty_approved_scope_truthful(): void
    {
        $result = $this->metrics->monthly($this->actor, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30 23:59:59'), $this->site->id);
        $this->assertSame([], $result['engagement']); $this->assertSame([], $result['consultation']);
        $this->assertSame(['pct' => 0, 'committees' => 0, 'source_access' => 'readable', 'scope' => 'current_readable_sources', 'viewer_id' => $this->actor->id], $this->metrics->overview($this->actor, $this->other->id));
    }

    public function test_absolute_window_buckets_early_nz_month_instants_without_reinterpreting_consultation_dates(): void
    {
        $this->graph['meeting']->update(['scheduled_at' => '2026-09-30 11:30:00']);
        $this->graph['committee']->meetings()->create(['scheduled_at' => '2026-09-30 10:30:00', 'status' => 'cancelled', 'created_by' => $this->actor->id]);
        $this->graph['consultation']->update(['consultation_date' => '2026-09-30', 'status' => 'actioned']);
        $this->consultation($this->site, 'open')->update(['consultation_date' => '2026-10-01']);
        $from = Carbon::parse('2026-09-30T10:00:00Z'); $to = Carbon::parse('2026-10-01T00:00:00Z');
        $result = $this->metrics->monthly($this->actor, $from, $to, $this->site->id);
        $this->assertEquals(['2026-09' => 0, '2026-10' => 100], $result['engagement']);
        $this->assertEquals(['2026-09' => 100, '2026-10' => 0], $result['consultation']);
        $this->assertSame('2026-09-30T10:00:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-10-01T00:00:00+00:00', $to->toIso8601String());
    }

    public function test_civil_filter_opt_in_includes_last_worker_second_and_preserves_fractional_bound_parameters(): void
    {
        $this->graph['meeting']->update(['scheduled_at' => '2026-09-30 11:00:00', 'status' => 'cancelled']);
        $this->graph['committee']->meetings()->create(['scheduled_at' => '2026-10-01 10:59:59', 'status' => 'completed', 'created_by' => $this->actor->id]);
        $this->graph['committee']->meetings()->create(['scheduled_at' => '2026-10-01 11:00:00', 'status' => 'cancelled', 'created_by' => $this->actor->id]);
        $armed = true; $meetingBindings = []; $dateBindings = [];
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$armed, &$meetingBindings, &$dateBindings): void {
            if (! $armed || ! preg_match('/^select\s+(.+?)\s+from\s+`([^`]+)`/is', ltrim($sql), $match) || ! str_contains($match[1], ' as m')) { return; }
            if ($match[2] === 'hs_committee_meetings') { $meetingBindings = $bindings; $this->assertStringContainsString('CASE WHEN scheduled_at >= ? AND scheduled_at < ?', $sql); }
            if ($match[2] === 'hs_consultations') { $dateBindings = $bindings; }
        });
        try {
            $result = $this->metrics->monthly($this->actor, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-01'), $this->site->id, null, true);
            $this->assertSame(['2026-10' => 50], $result['engagement']);
            $this->assertContains('2026-09-30 11:00:00.000000', $meetingBindings);
            $this->assertContains('2026-10-01 10:59:59.999999', $meetingBindings);
            $this->assertContains('2026-10-01', $dateBindings);
        } finally { $armed = false; }
        $absolute = $this->metrics->monthly($this->actor, Carbon::parse('2026-10-01T00:00:00Z'), Carbon::parse('2026-10-01T23:59:59.999999Z'), $this->site->id);
        $this->assertSame(['2026-10' => 50], $absolute['engagement']);
        $exclusiveLower = $this->metrics->monthly($this->actor, Carbon::parse('2026-09-30T11:00:00.000001Z'), Carbon::parse('2026-10-01T10:59:59.999999Z'), $this->site->id);
        $this->assertSame(['2026-10' => 100], $exclusiveLower['engagement']);
    }

    public static function absoluteDstWindows(): array
    {
        return [
            'first fold occurrence' => ['2026-04-05T02:29:00+13:00', '2026-04-05T02:31:00+13:00', '2026-04-04 13:30:00', '2026-04-04 14:30:00', '2026-04', 100],
            'second fold occurrence' => ['2026-04-05T02:29:00+12:00', '2026-04-05T02:31:00+12:00', '2026-04-04 13:30:00', '2026-04-04 14:30:00', '2026-04', 0],
            'gap crossed by real instants' => ['2026-09-27T01:59:00+12:00', '2026-09-27T03:01:00+13:00', '2026-09-26 13:59:30', '2026-09-26 14:00:30', '2026-09', 50],
        ];
    }

    #[DataProvider('absoluteDstWindows')]
    public function test_absolute_dst_windows_keep_offset_instants_distinct(string $from, string $to, string $completed, string $cancelled, string $month, int $ratio): void
    {
        $this->graph['meeting']->update(['scheduled_at' => $completed]);
        $this->graph['committee']->meetings()->create(['scheduled_at' => $cancelled, 'status' => 'cancelled', 'created_by' => $this->actor->id]);
        $result = $this->metrics->monthly($this->actor, Carbon::parse($from), Carbon::parse($to), $this->site->id);
        $this->assertSame([$month => $ratio], $result['engagement']);
        $this->assertSame([], $result['consultation']);
    }

    public function test_actual_analytics_http_opts_into_worker_civil_month_window(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $this->graph['meeting']->update(['scheduled_at' => '2026-09-30 11:30:00']);
        $this->graph['consultation']->update(['consultation_date' => '2026-10-01', 'status' => 'closed']);
        $response = $this->actingAs($this->actor)->getJson('/health-safety/analytics?period=custom&from=2026-10-01&to=2026-10-31&site_id='.$this->site->id, ['X-Inertia' => 'true'])->assertOk();
        $point = collect($response->json('props.trends'))->sole('month', '2026-10');
        $this->assertSame(100, $point['worker_engagement']);
        $this->assertSame(100, $point['worker_consultation']);
        $this->assertSame('readable', $point['worker_participation_source_access']);
        $this->assertSame($this->actor->id, $point['worker_participation_viewer_id']);
    }

    public function test_wide_approved_reader_without_or_with_inactive_profile_remains_authorised(): void
    {
        $this->assertSame(100, $this->metrics->overview($this->actor, $this->site->id)['pct']);
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->other->id, 'is_active' => false]);
        $this->assertSame(['2026-10' => 100], $this->monthly($this->site->id)['engagement']);
    }

    public function test_null_viewer_has_no_private_source_sql_or_false_zero_operands(): void
    {
        $armed = true; $queries = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$queries): void { if ($armed && str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'hs_')) { $queries++; } });
        try {
            $this->assertNull($this->metrics->overview(null)['committees']);
            $this->assertSame('restricted', $this->metrics->overview(null)['source_access']);
            $this->assertNull($this->metrics->overview(null)['viewer_id']);
            $this->assertNull($this->metrics->monthly(null, $this->from(), $this->to())['engagement']);
            $this->assertSame(0, $queries);
        } finally { $armed = false; }
    }

    public function test_warmed_permission_denial_then_current_restore_uses_fresh_source_authority(): void
    {
        $this->grant('hazards.view', false); $this->actor->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame('restricted', $this->monthly($this->site->id)['source_access']);
        $this->grant('hazards.view', true);
        $this->assertSame(['2026-10' => 100], $this->monthly($this->site->id)['engagement']);
    }

    public function test_scoped_foreign_parent_and_unconfirmed_null_sources_cannot_inflate_counts(): void
    {
        $this->records($this->other); $this->records(null);
        $this->grant('healthSafety.viewAllSites', false);
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]);
        $this->assertSame(1, $this->metrics->overview($this->actor)['committees']);
        $this->assertSame('unavailable', $this->metrics->overview($this->actor, $this->other->id)['source_access']);
        $this->assertNull($this->metrics->overview($this->actor, $this->other->id)['pct']);
    }

    public function test_wide_null_ownership_is_readable_but_explicit_site_subsets_exclude_it(): void
    {
        $unknown = $this->records(null); $unknown['meeting']->update(['status' => 'cancelled']);
        $this->assertSame(2, $this->metrics->overview($this->actor)['committees']);
        $this->assertSame(['2026-10' => 50], $this->monthly(null)['engagement']);
        $this->assertSame(['2026-10' => 100], $this->monthly([$this->site->id])['engagement']);
        $this->assertSame(1, $this->metrics->overview($this->actor, [$this->site->id])['committees']);
    }

    public function test_explicit_empty_subset_keeps_valid_empty_distinct_from_denied_actor(): void
    {
        $this->assertSame([], $this->monthly([])['engagement']);
        $this->assertSame(['pct' => null, 'committees' => 0, 'source_access' => 'readable', 'scope' => 'current_readable_sources', 'viewer_id' => $this->actor->id], $this->metrics->overview($this->actor, []));
        $this->grant('hazards.view', false);
        $this->assertNull($this->metrics->overview($this->actor, [])['committees']);
        $this->assertSame('restricted', $this->monthly([])['source_access']);
    }

    public function test_subset_operand_order_is_deterministic_and_foreign_sites_are_intersected_before_metadata(): void
    {
        $stamp = fn (array $sites): string => $this->access->materializeSubset($this->actor, $sites,
            fn ($current, $reads, $context): array => [$this->access->sourceStamp($context)])[0];
        $this->assertSame($stamp([$this->other->id, $this->site->id, $this->site->id]), $stamp([$this->site->id, $this->other->id]));
        $this->grant('healthSafety.viewAllSites', false);
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        $this->assertSame(1, $this->metrics->overview($this->actor, [$this->site->id, $this->other->id])['committees']);
    }

    public static function invalidSubsets(): array { return [[['1']], [[0]], [[-1]], [[null]], [[1.5]]]; }

    #[DataProvider('invalidSubsets')]
    public function test_subset_rejects_nonpositive_or_noninteger_operands_before_source_sql(array $sites): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->access->materializeSubset($this->actor, $sites, fn () => []);
    }

    public function test_original_subset_cannot_be_replaced_inside_same_live_context(): void
    {
        $this->expectException(LogicException::class);
        $this->access->materializeSubset($this->actor, [$this->site->id], fn ($current, $reads, $context): array =>
            $this->access->materializeSubset($current, [$this->other->id], fn () => [], $context));
    }

    public function test_supporting_committee_reassociation_at_actual_monthly_aggregate_is_unavailable_and_restores(): void
    {
        $this->atAggregate('hs_committee_meetings', ' as m', fn () => DB::table('hs_committees')->where('id', $this->graph['committee']->id)->update(['site_id' => $this->other->id]),
            function (): void { $result = $this->monthly($this->site->id); $this->assertSame('unavailable', $result['source_access']); $this->assertNull($result['engagement']); });
        DB::table('hs_committees')->where('id', $this->graph['committee']->id)->update(['site_id' => $this->site->id]);
        $this->assertSame(['2026-10' => 100], $this->monthly($this->site->id)['engagement']);
    }

    public function test_late_new_source_before_committee_count_is_not_certified_as_complete(): void
    {
        $this->atAggregate('hs_committees', 'count(', fn () => $this->records($this->site),
            function (): void { $result = $this->metrics->overview($this->actor, $this->site->id); $this->assertSame('unavailable', $result['source_access']); $this->assertNull($result['committees']); });
        $this->assertSame(2, $this->metrics->overview($this->actor, $this->site->id)['committees']);
    }

    public function test_current_grant_withdrawal_at_consultation_aggregate_prevents_positive_result_and_fresh_restore(): void
    {
        $this->atAggregate('hs_consultations', ' as m', fn () => $this->grant('hazards.view', false),
            function (): void { $this->assertSame('unavailable', $this->monthly($this->site->id)['source_access']); });
        $this->assertSame('restricted', $this->monthly($this->site->id)['source_access']);
        $this->grant('hazards.view', true); $this->assertSame('readable', $this->monthly($this->site->id)['source_access']);
    }

    public function test_site_denominator_is_guarded_without_management_picker_authority(): void
    {
        $this->atAggregate('sites', 'count(', fn () => DB::table('sites')->where('id', $this->site->id)->update(['archived_at' => now()]),
            function (): void { $result = $this->metrics->overview($this->actor, $this->site->id); $this->assertNull($result['pct']); $this->assertNull($result['committees']); });
        DB::table('sites')->where('id', $this->site->id)->update(['archived_at' => null]);
        $this->assertSame(100, $this->metrics->overview($this->actor, $this->site->id)['pct']);
    }

    public function test_scoped_profile_withdrawal_at_actual_monthly_sql_is_unavailable_then_freshly_restored(): void
    {
        $this->grant('healthSafety.viewAllSites', false);
        $profile = HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]);
        $this->atAggregate('hs_committee_meetings', ' as m', fn () => DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['is_active' => false]),
            function (): void { $this->assertSame('unavailable', $this->monthly($this->site->id)['source_access']); });
        DB::table('hr_employee_profiles')->where('id', $profile->id)->update(['is_active' => true]);
        $this->assertSame(['2026-10' => 100], $this->monthly($this->site->id)['engagement']);
    }

    public function test_unrelated_outside_subset_source_change_does_not_invalidate_requested_metrics(): void
    {
        $other = $this->records($this->other);
        $this->atAggregate('hs_committee_meetings', ' as m', fn () => DB::table('hs_committees')->where('id', $other['committee']->id)->update(['members' => json_encode([])]),
            function (): void { $this->assertSame(['2026-10' => 100], $this->monthly([$this->site->id])['engagement']); }, false);
    }

    public function test_same_outer_context_and_collector_are_reused_through_final_barrier(): void
    {
        $result = HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context): array {
            return HealthSafetyReadMaterialisationProof::within($context, function ($proof) use ($context): array {
                $result = $this->monthly($this->site->id, $context);
                $this->assertSame($proof, HealthSafetyReadMaterialisationProof::current($context));
                return $result;
            });
        });
        $this->assertSame('readable', $result['source_access']);
    }

    public function test_late_fact_change_after_metrics_return_invalidates_outer_response(): void
    {
        $this->refused(fn () => HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, fn ($context): array =>
            HealthSafetyReadMaterialisationProof::within($context, function () use ($context): array {
                $result = $this->monthly($this->site->id, $context);
                DB::table('hs_committee_meetings')->where('id', $this->graph['meeting']->id)->update(['status' => 'cancelled']);
                return $result;
            })), 404);
    }

    public function test_expired_or_foreign_viewer_context_is_not_replaced_with_fresh_admission(): void
    {
        $escaped = null;
        HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context) use (&$escaped): array { $escaped = $context; return []; });
        try { $this->monthly($this->site->id, $escaped); $this->fail('Expired context must refuse.'); } catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        $foreign = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        try {
            HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS,
                fn ($context): array => $this->metrics->overview($foreign, $this->site->id, $context));
            $this->fail('Another viewer must not use the original context.');
        } catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }

    public function test_metrics_do_not_hydrate_private_notes_minutes_names_or_consultation_narratives(): void
    {
        $subject = User::factory()->create(['name' => 'Private represented worker']);
        $this->graph['representative']->update(['user_id' => $subject->id]);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher); $privateBodies = 0;
        foreach ([HsCommittee::class => 'name', HsCommitteeMeeting::class => 'minutes', HsConsultation::class => 'description', HsRepresentative::class => 'notes', User::class => 'name'] as $class => $field) {
            $class::retrieved(function (Model $model) use (&$privateBodies, $field): void {
                // The current actor's name is part of canonical authentication;
                // other User names are private source bodies, even outside hs_*.
                if ($model instanceof User && $model->getKey() === $this->actor->id) { return; }
                if (array_key_exists($field, $model->getAttributes())) { $privateBodies++; }
            });
        }
        try { $this->monthly($this->site->id); $this->metrics->overview($this->actor, $this->site->id); $this->assertSame(0, $privateBodies); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_actual_overview_http_uses_guarded_current_participation_operands(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $this->records($this->other);
        $response = $this->actingAs($this->actor)->getJson('/health-safety?site='.$this->site->id, ['X-Inertia' => 'true']);
        $response->assertOk()->assertJsonPath('props.worker_participation.pct', 100)->assertJsonPath('props.worker_participation.committees', 1)
            ->assertJsonPath('props.worker_participation.source_access', 'readable')->assertJsonPath('props.worker_participation.viewer_id', $this->actor->id);
    }

    public function test_actual_analytics_http_carries_monthly_state_and_scorecard_without_changing_ratios(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $response = $this->actingAs($this->actor)->getJson('/health-safety/analytics?period=custom&from=2026-10-01&to=2026-10-31&site_id='.$this->site->id, ['X-Inertia' => 'true']);
        $response->assertOk();
        $point = collect($response->json('props.trends'))->firstWhere('month', '2026-10');
        $this->assertSame(100, $point['worker_engagement']); $this->assertSame(0, $point['worker_consultation']);
        $this->assertSame('readable', $point['worker_participation_source_access']);
        $this->assertSame($this->actor->id, $point['worker_participation_viewer_id']);
        $card = collect($response->json('props.scorecard.leading'))->firstWhere('key', 'worker_engagement');
        $this->assertSame('readable', $card['source_access']);
        $this->assertSame($this->actor->id, $card['viewer_id']);
    }

    public function test_actual_overview_partial_response_binds_the_requested_descriptor_to_current_account(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/dashboard', 'X-Inertia-Partial-Data' => 'auth,worker_participation'];
        $this->actingAs($this->actor)->getJson('/health-safety?site='.$this->site->id, $headers)->assertOk()
            ->assertJsonPath('props.worker_participation.viewer_id', $this->actor->id);
        $other = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now(), 'external_clinical_account' => false]);
        $other->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $this->actingAs($other)->getJson('/health-safety?site='.$this->site->id, $headers)->assertOk()
            ->assertJsonPath('props.worker_participation.viewer_id', $other->id);
    }

    public function test_actual_analytics_partial_response_uses_same_actor_in_trends_and_both_participation_cards(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $response = $this->actingAs($this->actor)->getJson('/health-safety/analytics?period=custom&from=2026-10-01&to=2026-10-31&site_id='.$this->site->id,
            ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/analytics', 'X-Inertia-Partial-Data' => 'auth,trends,scorecard']);
        $response->assertOk();
        foreach ($response->json('props.trends') as $point) { $this->assertSame($this->actor->id, $point['worker_participation_viewer_id']); }
        $cards = collect($response->json('props.scorecard.leading'))->whereIn('key', ['worker_engagement', 'worker_consultation']);
        $this->assertCount(2, $cards);
        foreach ($cards as $card) { $this->assertSame($this->actor->id, $card['viewer_id']); }
    }

    public function test_actual_overview_partial_omission_or_exclusion_still_refreshes_participation_and_scope(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $url = '/health-safety?site='.$this->site->id;
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/dashboard'];
        $this->actingAs($this->actor)->getJson($url, $headers)->assertOk()->assertJsonPath('props.worker_participation.committees', 1);
        $this->graph['committee']->delete();
        foreach ([['X-Inertia-Partial-Data' => 'auth'], ['X-Inertia-Partial-Except' => 'worker_participation,filters,sites']] as $partial) {
            $response = $this->actingAs($this->actor)->getJson($url, [...$headers, ...$partial])->assertOk();
            $response->assertJsonPath('props.worker_participation.viewer_id', $this->actor->id)
                ->assertJsonPath('props.worker_participation.source_access', 'readable')
                ->assertJsonPath('props.worker_participation.committees', 0);
            $this->assertArrayHasKey('filters', $response->json('props'));
            $this->assertArrayHasKey('sites', $response->json('props'));
        }
    }

    public function test_actual_analytics_partial_omission_or_exclusion_still_refreshes_values_deltas_and_scope(): void
    {
        $this->actor->roles()->attach(\App\Models\Role::where('name', 'health_safety_officer')->sole());
        $url = '/health-safety/analytics?period=custom&from=2026-10-01&to=2026-10-31&site_id='.$this->site->id;
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/analytics'];
        $initial = $this->actingAs($this->actor)->getJson($url, $headers)->assertOk();
        $this->assertSame(100, collect($initial->json('props.trends'))->sole('month', '2026-10')['worker_engagement']);
        $this->graph['meeting']->update(['status' => 'cancelled']);
        foreach ([['X-Inertia-Partial-Data' => 'auth'], ['X-Inertia-Partial-Except' => 'trends,scorecard,filters,sites,active_site,site_brand_colour']] as $partial) {
            $response = $this->actingAs($this->actor)->getJson($url, [...$headers, ...$partial])->assertOk();
            $point = collect($response->json('props.trends'))->sole('month', '2026-10');
            $this->assertSame(0, $point['worker_engagement']);
            $this->assertSame($this->actor->id, $point['worker_participation_viewer_id']);
            $card = collect($response->json('props.scorecard.leading'))->sole('key', 'worker_engagement');
            $this->assertSame(0, $card['value']);
            $this->assertSame($this->actor->id, $card['viewer_id']);
            foreach (['filters', 'sites', 'active_site', 'site_brand_colour'] as $key) { $this->assertArrayHasKey($key, $response->json('props')); }
        }
    }

    private function monthly(int|array|null $sites, ?HealthSafetyReadContext $context = null): array { return $this->metrics->monthly($this->actor, $this->from(), $this->to(), $sites, $context); }
    private function from(): Carbon { return Carbon::parse('2026-10-01 00:00:00'); }
    private function to(): Carbon { return Carbon::parse('2026-10-31 23:59:59'); }
    private function grant(string $key, bool $allowed): void { $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]); }

    private function consultation(?Site $site, string $status): HsConsultation
    {
        return HsConsultation::create(['title' => 'Private source consultation', 'description' => 'Retained private narrative', 'consultation_type' => 'procedure_change',
            'site_id' => $site?->id, 'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-02', 'workers_consulted' => [$this->actor->id], 'status' => $status, 'created_by' => $this->actor->id]);
    }

    private function records(?Site $site): array
    {
        $committee = HsCommittee::create(['name' => 'Private committee', 'site_id' => $site?->id, 'status' => 'active', 'meeting_frequency' => 'monthly', 'established_at' => '2026-01-01', 'members' => [$this->actor->id], 'created_by' => $this->actor->id]);
        return ['committee' => $committee, 'meeting' => $committee->meetings()->create(['scheduled_at' => '2026-10-02 12:00:00', 'status' => 'completed', 'minutes' => 'Private minutes', 'created_by' => $this->actor->id]),
            'consultation' => $this->consultation($site, 'open'),
            'representative' => HsRepresentative::create(['user_id' => $this->actor->id, 'site_id' => $site?->id, 'election_method' => 'elected', 'elected_at' => '2026-01-01', 'status' => 'active', 'notes' => 'Private notes', 'created_by' => $this->actor->id])];
    }

    /** Actual SQL interleaving, including zero aggregate-model hydration after a source refusal. */
    private function atAggregate(string $table, string $projection, callable $mutation, callable $operation, bool $expectNoHydration = true): void
    {
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        foreach ([HsCommittee::class, HsCommitteeMeeting::class, HsConsultation::class, HsRepresentative::class] as $class) {
            $class::retrieved(function (Model $row) use (&$armed, &$fired, &$hydrated): void { if ($armed && $fired && array_key_exists('m', $row->getAttributes())) { $hydrated++; } });
        }
        DB::connection()->beforeExecuting(function (string $sql) use ($table, $projection, $mutation, &$armed, &$fired): void {
            if ($armed && ! $fired && preg_match('/^select\s+(.+?)\s+from\s+`([^`]+)`/is', ltrim($sql), $match)
                && $match[2] === $table && str_contains(strtolower($match[1]), $projection)) { $fired = true; $mutation(); }
        });
        try { $operation(); $this->assertTrue($fired, 'The actual aggregate SELECT must fire.'); if ($expectNoHydration) { $this->assertSame(0, $hydrated, 'Refused monthly aggregate facts must not hydrate into source models.'); } }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }

    private function refused(callable $operation, int $status): void
    {
        try { $operation(); $this->fail('Source refusal was required.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }
}
