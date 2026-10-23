<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\RestraintKpiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Whole native suite. Real source callbacks/queries; execution remains UNRUN. */
final class RestraintMetricSourceAuthorityTest extends RestraintEventCommandTestCase
{
    public function test_current_recorded_sources_keep_real_summary_breakdowns_and_client_names(): void
    {
        $event = $this->event();
        $this->legacyPlan();
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertSame(1, $this->summary()['active_plans']);
        $this->assertSame(0, $this->summary()['clients_no_active_bsp']);
        $this->assertSame([['label' => 'physical', 'count' => 1]], $this->breakdowns()['by_type']);
        $this->assertSame([$event->id], array_column($this->worklist(), 'id'));
        $this->assertSame(trim($this->client->first_name.' '.$this->client->last_name), $this->worklist()[0]['client']);
    }

    public function test_null_booking_location_and_null_request_keep_existing_client_site_fallback(): void
    {
        $graph = $this->stayGraph(false);
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['location_id' => null]);
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertSame([$graph['event']->id], array_column($this->worklist(), 'id'));
        $this->assertNull($graph['booking']->fresh()->booking_request_id);
    }

    public function test_genuine_legacy_null_site_requires_existing_named_wide_bypass_only(): void
    {
        $legacy = $this->legacyEvent(['site_id' => null]);
        $this->assertSame(0, $this->summary()['events_in_period']);
        $this->assertSame([], $this->worklist());
        $this->grant('healthSafety.viewAllSites', true);
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertSame([$legacy->id], array_column($this->worklist(), 'id'));
        $this->grant('healthSafety.viewAllSites', false);
        $this->assertSame(0, $this->summary()['events_in_period']);
    }

    public function test_original_event_site_and_current_client_site_authority_stay_distinct(): void
    {
        $event = $this->event();
        $other = Site::factory()->create(['is_active' => true]);
        DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $other->id]);
        $this->grant('healthSafety.viewAllSites', true);
        $this->assertSame([], $this->worklist());
        $this->grant('clinical.accessAllSites', true);
        $this->assertSame([$event->id], array_column($this->worklist(), 'id'));
        $this->assertSame(1, $this->summary($this->site->id)['events_in_period']);
        $this->assertSame(0, $this->summary($other->id)['events_in_period']);
        $this->grant('clinical.accessAllSites', false);
        $this->assertSame([], $this->worklist());
        DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $this->site->id]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_explicit_referenced_version_nullable_stay_and_archived_plan_keep_historical_reads(): void
    {
        $graph = $this->versionGraph();
        DB::table('behaviour_support_plans')->where('id', $graph['plan']->id)->update(['deleted_at' => now()]);
        $this->assertNull($graph['version']->stay_id);
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertSame([$graph['event']->id], array_column($this->worklist(), 'id'));
        // Exact identity readability does not certify approval/publication.
        $this->assertSame($graph['version']->id, $graph['event']->behaviour_support_plan_version_id);
    }

    public function test_new_unreferenced_version_does_not_replace_retained_explicit_version(): void
    {
        $graph = $this->versionGraph();
        app(BehaviourSupportPlanService::class)->update($graph['plan'], $this->actor,
            ['expected_version' => $graph['plan']->lock_version, 'idempotency_key' => (string) Str::uuid(), 'notes' => 'Genuine new candidate']);
        $this->assertNotSame($graph['version']->id, $graph['plan']->fresh()->current_candidate_version_id);
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertSame($graph['version']->id, $graph['event']->fresh()->behaviour_support_plan_version_id);
    }

    public function test_denied_subject_and_foreign_site_do_not_contribute_private_metric_facts(): void
    {
        $this->event();
        $foreign = Site::factory()->create(['is_active' => true]);
        $person = Client::factory()->create(['site_id' => $foreign->id]);
        $this->legacyEvent(['client_id' => $person->id, 'site_id' => $foreign->id]);
        $this->grant('clients.viewAny', false);
        $this->grant('clients.viewAssigned', true);
        $this->client->supportWorkers()->attach($this->actor->id);
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertSame(1, count($this->worklist()));
        $this->assertSame(0, $this->summary($foreign->id)['events_in_period']);
    }

    public static function supportingTupleChanges(): array
    {
        return [
            'stay client' => ['stay_client'],
            'booking client' => ['booking_client'],
            'booking location' => ['booking_location'],
            'booking request identity' => ['booking_request'],
            'request client' => ['request_client'],
            'referenced plan client' => ['plan_client'],
            'referenced version client' => ['version_client'],
            'referenced version site' => ['version_site'],
            'nullable version stay' => ['version_stay'],
        ];
    }

    #[DataProvider('supportingTupleChanges')]
    public function test_unchanged_root_late_supporting_tuple_change_before_actual_count_withholds_completeness(string $change): void
    {
        $graph = str_starts_with($change, 'version_') || $change === 'plan_client' ? $this->versionGraph() : $this->stayGraph();
        $before = $graph['event']->getRawOriginal();
        [$table, $id, $column, $replacement] = $this->mutation($change, $graph);
        $original = DB::table($table)->where('id', $id)->value($column);
        $result = $this->atMetricSql('count', fn () => DB::table($table)->where('id', $id)->update([$column => $replacement]), fn () => $this->summary());
        $this->assertNull($result['events_in_period']);
        $this->assertContains($result['source_access'], ['restricted', 'unavailable']);
        $this->assertSame($before, $graph['event']->fresh()->getRawOriginal());
        DB::table($table)->where('id', $id)->update([$column => $original]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    #[DataProvider('supportingTupleChanges')]
    public function test_unchanged_root_supporting_tuple_change_at_name_select_hydrates_zero_names(string $change): void
    {
        $graph = str_starts_with($change, 'version_') || $change === 'plan_client' ? $this->versionGraph() : $this->stayGraph();
        [$table, $id, $column, $replacement] = $this->mutation($change, $graph);
        $original = DB::table($table)->where('id', $id)->value($column);
        $result = $this->atMetricSql('name', fn () => DB::table($table)->where('id', $id)->update([$column => $replacement]), fn () => $this->worklist());
        $this->assertNull($result);
        DB::table($table)->where('id', $id)->update([$column => $original]);
        $this->assertSame([$graph['event']->id], array_column($this->worklist(), 'id'));
    }

    public function test_stay_binding_is_retained_for_active_plan_aggregate_and_breakdown(): void
    {
        $graph = $this->stayGraph();
        $plan = $this->legacyPlan(['source_schema_version' => 1, 'source_site_id' => $this->site->id, 'source_stay_id' => $graph['stay']->id]);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $result = $this->atMetricSql('plan_group', fn () => DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['client_id' => $other->id]), fn () => $this->breakdowns());
        $this->assertNull($result['by_plan_status']);
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['client_id' => $this->client->id]);
        $this->assertSame(1, $this->summary()['active_plans']);
        $this->assertSame($graph['stay']->id, $plan->fresh()->source_stay_id);
    }

    public function test_current_assignment_removed_at_group_select_cannot_disclose_breakdown(): void
    {
        $this->event();
        $this->client->supportWorkers()->attach($this->actor->id);
        $this->grant('clients.viewAny', false); $this->grant('clients.viewAssigned', true);
        $result = $this->atMetricSql('event_group', fn () => DB::table('client_user')->where('client_id', $this->client->id)->where('user_id', $this->actor->id)->delete(), fn () => $this->breakdowns());
        $this->assertNull($result['by_type']);
        $this->client->supportWorkers()->attach($this->actor->id);
        $this->assertSame([['label' => 'physical', 'count' => 1]], $this->breakdowns()['by_type']);
    }

    public function test_prepared_permission_override_withdrawn_at_count_cannot_become_healthy_zero(): void
    {
        $this->event();
        $result = $this->atMetricSql('count', fn () => $this->grant('restraints.view', false), fn () => $this->summary());
        $this->assertNull($result['events_in_period']);
        $this->grant('restraints.view', true);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_prepared_role_membership_withdrawn_at_count_requires_fresh_recovery(): void
    {
        $this->event();
        $role = \App\Models\Role::query()->where('name', 'support_worker')->firstOrFail();
        $this->actor->roles()->syncWithoutDetaching([$role->id]);
        $result = $this->atMetricSql('count', fn () => DB::table('role_user')->where('role_id', $role->id)->where('user_id', $this->actor->id)->delete(), fn () => $this->summary());
        $this->assertNull($result['events_in_period']);
        $this->actor->roles()->syncWithoutDetaching([$role->id]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_hr_profile_withdrawn_at_name_select_hydrates_zero_names_then_restore_works(): void
    {
        $this->event();
        $result = $this->atMetricSql('name', fn () => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]), fn () => $this->worklist());
        $this->assertNull($result);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => true]);
        $this->assertCount(1, $this->worklist());
    }

    public function test_hr_withdrawal_between_initial_staff_check_and_profile_snapshot_cannot_become_authority(): void
    {
        $this->event();
        $this->grant('healthSafety.viewAllSites', true);
        $this->grant('clinical.accessAllSites', true);
        $armed = true; $staffChecked = false; $fired = false; $privateQueries = [];
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$armed, &$staffChecked, &$fired, &$privateQueries): void {
            if (! $armed) { return; }
            $sql = strtolower(ltrim($query->sql));
            // QueryExecuted establishes that the canonical initial EXISTS has
            // completed, before mutating its subsequent prepared profile read.
            if (! $fired && str_starts_with($sql, 'select exists(')
                && str_contains($sql, 'from '.chr(96).'users'.chr(96))
                && str_contains($sql, chr(96).'hr_employee_profiles'.chr(96))) { $staffChecked = true; }
            if ($fired && str_starts_with($sql, 'select') &&
                (str_contains($sql, chr(96).'restraint_events'.chr(96)) || str_contains($sql, chr(96).'behaviour_support_plans'.chr(96)))) {
                $privateQueries[] = $sql;
            }
        });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$staffChecked, &$fired): void {
            if ($armed && $staffChecked && ! $fired
                && str_starts_with(strtolower(ltrim($sql)), 'select * from '.chr(96).'hr_employee_profiles'.chr(96))) {
                $fired = true;
                DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]);
            }
        });
        try {
            $result = $this->summary();
            $this->assertTrue($staffChecked);
            $this->assertTrue($fired, 'The actual post-eligibility prepared profile SELECT must be intercepted.');
            $this->assertNull($result['events_in_period']);
            $this->assertSame('unavailable', $result['source_access']);
            $this->assertSame([], $privateQueries, 'No private source discovery/count/body query may follow an inactive prepared snapshot.');
        } finally { $armed = false; }
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => true]);
        $this->assertSame(1, $this->summary()['events_in_period']);
        $this->assertCount(1, $this->worklist());
    }

    public function test_source_site_withdrawn_at_name_select_hydrates_zero_names_then_restore_works(): void
    {
        $this->event();
        $result = $this->atMetricSql('name', fn () => DB::table('sites')->where('id', $this->site->id)->update(['is_active' => false]), fn () => $this->worklist());
        $this->assertNull($result);
        DB::table('sites')->where('id', $this->site->id)->update(['is_active' => true]);
        $this->assertCount(1, $this->worklist());
    }

    public function test_client_move_at_name_select_is_not_rebaseline_under_wide_grants(): void
    {
        $this->event(); $this->grant('healthSafety.viewAllSites', true); $this->grant('clinical.accessAllSites', true);
        $other = Site::factory()->create(['is_active' => true]);
        $result = $this->atMetricSql('name', fn () => DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $other->id]), fn () => $this->worklist());
        $this->assertNull($result);
        DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $this->site->id]);
        $this->assertCount(1, $this->worklist());
    }

    public function test_both_canonical_stay_observations_remain_required_before_metric_sql(): void
    {
        $graph = $this->stayGraph(false);
        $otherBooking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id]);
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $graph, $otherBooking): void {
            // Between assertStay's first booking and the shared resolver's first stay.
            if ($armed && ! $fired && str_starts_with(strtolower(ltrim($sql)), 'select')
                && str_contains($sql, 'from '.chr(96).'respite_stays'.chr(96)) && str_contains($sql, 'exists')) {
                $fired = true;
                DB::table('respite_stays')->where('id', $graph['stay']->id)->update(['booking_id' => $otherBooking->id]);
            }
        });
        try {
            $this->assertNull($this->summary()['events_in_period']);
            $this->assertTrue($fired);
        } finally { $armed = false; }
        DB::table('respite_stays')->where('id', $graph['stay']->id)->update(['booking_id' => $graph['booking']->id]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_final_shared_proof_refuses_supporting_change_after_metric_materialisation(): void
    {
        $graph = $this->stayGraph();
        try {
            $this->within(fn ($context) => HealthSafetyReadMaterialisationProof::within($context, function () use ($context, $graph): array {
                $result = $this->summary(null, $context);
                DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['location_id' => null]);
                return $result;
            }));
            $this->fail('Final barrier must retain the original supporting graph.');
        } catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_empty_population_and_unrelated_supporting_rows_remain_real_zero(): void
    {
        $this->assertSame(0, $this->summary()['events_in_period']);
        $this->assertSame([], $this->worklist());
        $this->assertSame([], $this->breakdowns()['by_type']);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        RespiteBooking::factory()->create(['client_id' => $other->id, 'location_id' => $this->site->id]);
        $this->assertSame(0, $this->summary()['events_in_period']);
        $this->event();
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_initial_malformed_request_tuple_withholds_completeness_and_restore_keeps_healthy_neighbour(): void
    {
        $graph = $this->stayGraph();
        $neighbour = $this->event();
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('respite_booking_requests')->where('id', $graph['request']->id)->update(['client_id' => $other->id]);
        $this->assertNull($this->summary()['events_in_period']);
        $this->assertNull($this->worklist());
        DB::table('respite_booking_requests')->where('id', $graph['request']->id)->update(['client_id' => $this->client->id]);
        $this->assertSame(2, $this->summary()['events_in_period']);
        $this->assertContains($neighbour->id, array_column($this->worklist(), 'id'));
    }

    public function test_original_absent_request_cannot_be_replaced_by_new_same_client_request_at_name_select(): void
    {
        $graph = $this->stayGraph(false);
        $request = RespiteBookingRequest::create(['client_id' => $this->client->id,
            'requested_start' => now()->subDay(), 'requested_end' => now()->addDay(), 'status' => 'approved']);
        $this->assertNull($this->atMetricSql('name',
            fn () => DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['booking_request_id' => $request->id]),
            fn () => $this->worklist()));
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['booking_request_id' => null]);
        $this->assertSame([$graph['event']->id], array_column($this->worklist(), 'id'));
    }

    public function test_same_provided_context_preserves_standalone_operands_and_current_scope(): void
    {
        $this->event(); $this->legacyPlan();
        $expected = $this->summary();
        $actual = $this->within(fn ($context) => $this->summary(null, $context));
        $this->assertSame($expected, $actual);
        $this->assertSame(0, $this->summary([])['events_in_period']);
    }

    public function test_actorless_and_permission_denied_reads_never_query_private_sources(): void
    {
        $this->event(); $this->grant('restraints.view', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->assertNull($this->summary()['events_in_period']);
            $this->assertNull(app(RestraintKpiService::class)->summary(null, now()->subDay(), now())['events_in_period']);
            foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString(chr(96).'restraint_events'.chr(96), $query['query']); }
        } finally { DB::disableQueryLog(); }
    }

    public function test_missing_preparation_and_wrong_viewer_remain_visible_errors(): void
    {
        try {
            HealthSafetyReadContext::within($this->actor, ['restraints.view'], fn ($context) => $this->summary(null, $context));
            $this->fail('Insufficient preparation must not refresh authority.');
        } catch (LogicException) {}
        $other = $this->staff(['restraints.view', 'clients.viewAny']);
        try {
            HealthSafetyReadContext::within($other, RestraintAccessService::PERMISSIONS, fn ($context) => $this->summary(null, $context));
            $this->fail('Wrong supplied actor must not refresh authority.');
        } catch (LogicException) {}
    }

    public function test_parent_direct_and_fresh_viewer_embedded_use_expire_with_original_reads(): void
    {
        $event = $this->event(); $escaped = null;
        $this->within(function ($context) use ($event, &$escaped): array {
            $escaped = app(RestraintAccessService::class)->protectedEventSourceQuery($event, $context);
            return [];
        });
        try { $escaped->count(); $this->fail('Expired parent must not execute.'); } catch (LogicException) {}
        $other = $this->staff(['restraints.view', 'clients.viewAny']);
        try {
            HealthSafetyReadContext::within($other, RestraintAccessService::PERMISSIONS,
                fn ($context) => $context->query(RestraintEvent::query()->whereExists((clone $escaped)->selectRaw('1')->toBase()))->count());
            $this->fail('Embedding expired proof cannot authorise another actor.');
        } catch (LogicException) {}
    }

    private function summary(int|array|null $site = null, ?HealthSafetyReadContext $context = null): array
    {
        return app(RestraintKpiService::class)->summary($site, now()->subDay(), now(), $this->actor, $context);
    }

    private function breakdowns(): array
    {
        return app(RestraintKpiService::class)->breakdowns(null, now()->subDay(), now(), $this->actor);
    }

    private function worklist(): ?array
    {
        return app(RestraintKpiService::class)->unreviewedWorklist(null, 8, $this->actor);
    }

    private function within(callable $consumer): mixed
    {
        return HealthSafetyReadContext::within($this->actor, RestraintAccessService::PERMISSIONS, $consumer);
    }

    private function legacyPlan(array $values = []): BehaviourSupportPlan
    {
        return BehaviourSupportPlan::factory()->createQuietly(['client_id' => $this->client->id, 'source_schema_version' => null,
            'source_site_id' => null, 'source_stay_id' => null, 'status' => 'active', 'review_date' => now()->addMonths(3), ...$values]);
    }

    private function legacyEvent(array $values = []): RestraintEvent
    {
        $row = RestraintEvent::factory()->make(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'stay_id' => null, 'source_schema_version' => null, 'recorded_client_id' => null, 'recorded_site_id' => null,
            'recorded_stay_id' => null, 'behaviour_support_plan_id' => null, 'behaviour_support_plan_version_id' => null,
            'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30), 'duration_minutes' => 30,
            'reviewed_at' => null, 'restraint_type' => 'physical', ...$values]);
        // Factory afterMaking normalises Site from Client; restore the literal
        // historical null/mismatched value deliberately tested by this fixture.
        $row->site_id = array_key_exists('site_id', $values) ? $values['site_id'] : $this->site->id;
        $row->saveQuietly();
        return $row->fresh();
    }

    private function stayGraph(bool $withRequest = true, bool $recordedEvent = true): array
    {
        $request = $withRequest ? RespiteBookingRequest::create(['client_id' => $this->client->id,
            'requested_start' => now()->subDay(), 'requested_end' => now()->addDay(), 'status' => 'approved']) : null;
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id,
            'booking_request_id' => $request?->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
        $stay = RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id,
            'status' => 'active', 'actual_start' => now()->subDay(), 'created_by' => $this->actor->id]);
        $this->grant('respite.stays.manage', true);
        $event = $recordedEvent ? RestraintEvent::findOrFail($this->service()->createForStay($this->actor, $stay, $this->capture())['event_id']) : null;
        return compact('request', 'booking', 'stay', 'event');
    }

    private function versionGraph(): array
    {
        $graph = $this->stayGraph(true, false);
        // Genuine domain-produced candidate. The explicit legacy reference tests
        // the existing exact read tuple, never invents publication/approval.
        $receipt = app(BehaviourSupportPlanService::class)->create($this->actor, ['client_id' => $this->client->id,
            'title' => 'Retained support', 'triggers' => 'Noise', 'de_escalation_strategies' => 'Quiet space',
            'developed_at' => '2026-10-01', 'review_date' => '2026-12-31', 'idempotency_key' => (string) Str::uuid()]);
        $plan = BehaviourSupportPlan::findOrFail($receipt['plan_id']);
        $version = BehaviourSupportPlanVersion::findOrFail($plan->current_candidate_version_id);
        $event = $this->legacyEvent(['stay_id' => $graph['stay']->id, 'behaviour_support_plan_id' => $plan->id,
            'behaviour_support_plan_version_id' => $version->id]);
        return [...$graph, 'event' => $event, 'plan' => $plan, 'version' => $version];
    }

    private function mutation(string $change, array $graph): array
    {
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        return match ($change) {
            'stay_client' => ['respite_stays', $graph['stay']->id, 'client_id', $other->id],
            'booking_client' => ['respite_bookings', $graph['booking']->id, 'client_id', $other->id],
            'booking_location' => ['respite_bookings', $graph['booking']->id, 'location_id', null],
            'booking_request' => ['respite_bookings', $graph['booking']->id, 'booking_request_id', null],
            'request_client' => ['respite_booking_requests', $graph['request']->id, 'client_id', $other->id],
            'plan_client' => ['behaviour_support_plans', $graph['plan']->id, 'client_id', $other->id],
            'version_client' => ['behaviour_support_plan_versions', $graph['version']->id, 'client_id', $other->id],
            'version_site' => ['behaviour_support_plan_versions', $graph['version']->id, 'site_id', Site::factory()->create(['is_active' => true])->id],
            'version_stay' => ['behaviour_support_plan_versions', $graph['version']->id, 'stay_id', $graph['stay']->id],
        };
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    /** Real beforeExecuting mutation; never substitutes canonical policy or query results. */
    private function atMetricSql(string $kind, callable $mutation, callable $read): mixed
    {
        $armed = true; $fired = false; $names = 0;
        $dispatcher = Client::getEventDispatcher(); Client::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function (Client $row) use (&$armed, &$fired, &$names): void {
            if ($armed && $fired && array_key_exists('first_name', $row->getAttributes())) { $names++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($kind, $mutation, &$armed, &$fired): void {
            if (! $armed || $fired) { return; }
            $sql = strtolower(ltrim($sql));
            $matches = match ($kind) {
                'count' => str_starts_with($sql, 'select count(*) as aggregate from '.chr(96).'restraint_events'.chr(96)),
                'name' => str_starts_with($sql, 'select') && str_contains($sql, chr(96).'first_name'.chr(96)) && str_contains($sql, chr(96).'restraint_events'.chr(96)),
                'event_group' => str_starts_with($sql, 'select') && str_contains($sql, 'count(*) as count') && str_contains($sql, 'group by '.chr(96).'restraint_type'.chr(96)),
                'plan_group' => str_starts_with($sql, 'select') && str_contains($sql, 'count(*) as count') && str_contains($sql, 'group by '.chr(96).'status'.chr(96)),
            };
            if ($matches) { $fired = true; $mutation(); }
        });
        try {
            $result = $read();
            $this->assertTrue($fired, 'The actual private metric/name SELECT must be intercepted.');
            if ($kind === 'name') { $this->assertSame(0, $names, 'No private name may hydrate after withdrawal.'); }
            return $result;
        } finally { $armed = false; Client::setEventDispatcher($dispatcher); }
    }
}
