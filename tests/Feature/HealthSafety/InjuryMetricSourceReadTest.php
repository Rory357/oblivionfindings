<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\HsKpiService;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\InjurySourceProjectionService;
use App\Services\HealthSafety\RestraintAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Complete focused native suite UNRUN. Source syntax does not certify privacy or query budget. */
final class InjuryMetricSourceReadTest extends WorkplaceInjurySourceTestCase
{
    private function kpi(): HsKpiService { return app(HsKpiService::class); }

    private function hours(float $hours = 10000): void
    {
        // billing_entries.hours is decimal(6,2): record the total as entries of at most 5,000 hours.
        $client = Client::factory()->create(['site_id' => $this->site->id])->id;
        for ($left = $hours; $left > 0; $left -= 5000) {
            BillingEntry::create(['client_id' => $client,
                'site_id' => $this->site->id, 'staff_id' => $this->worker->id, 'service_date' => '2026-10-10',
                'hours' => min(5000, $left), 'rate' => 0, 'amount' => 0, 'site_name_snapshot' => $this->site->name]);
        }
    }

    private function inReads($viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
        ]), $operation);
    }

    public function test_recorded_positive_factual_operands_and_lost_time_rates_remain_readable_without_medical_permission(): void
    {
        $this->injury(['lost_time_days' => 5, 'medical_treatment_type' => 'hospital', 'injury_date' => '2026-10-10T12:00:00Z']); $this->hours();
        $values = $this->kpi()->workplaceInjuryOperands(now()->subDays(7), now(), $this->site->id, $this->factual);
        $this->assertSame(1, $values['injuries']); $this->assertSame(5, $values['lost_time_days']);
        $this->assertSame('readable', $values['access']); $this->assertSame('recorded', $values['attribution_state']);
        $this->assertSame('unknown', $values['source_completeness']);
        $this->assertSame(100.0, $this->kpi()->ltifr(siteId: $this->site->id, viewer: $this->factual));
        $this->assertSame(500.0, $this->kpi()->injurySeverityRate(siteId: $this->site->id, viewer: $this->factual));
        // 2026-10-10 12:00Z to the frozen 2026-10-12 01:00Z is 37 hours: one whole day (main's whole-day count, UTC app clock).
        $this->assertSame(1, $this->kpi()->daysSinceLostTimeInjury($this->site->id, $this->factual));
        $this->assertStringNotContainsString('Private', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function test_factual_reader_never_queries_private_treatment_recordability_and_does_not_get_a_healthy_zero_denominator(): void
    {
        $this->injury(['lost_time_days' => 3, 'medical_treatment_type' => 'hospital']); $this->hours();
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->assertNull($this->kpi()->trifr(siteId: $this->site->id, viewer: $this->factual));
            $operands = $this->kpi()->nearMissOperands(siteId: $this->site->id, viewer: $this->factual);
            $this->assertNull($operands['recordable']); $this->assertSame(1, $operands['recordable_factual']);
            $this->assertSame('restricted', $operands['medical_recordability']);
            foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString('medical_treatment_type', $query['query']); }
        } finally { DB::disableQueryLog(); }
    }

    public function test_medical_reader_keeps_the_exact_recordability_rule_and_billing_hours_denominator(): void
    {
        $this->injury(['lost_time_days' => 4, 'medical_treatment_type' => 'first_aid']);
        $this->injury(['lost_time_days' => 0, 'medical_treatment_type' => 'hospital']);
        $this->injury(['lost_time_days' => 0, 'medical_treatment_type' => 'none', 'worksafe_notifiable' => true]);
        $this->injury(['lost_time_days' => 0, 'medical_treatment_type' => 'first_aid']); $this->hours();
        $this->assertSame(300.0, $this->kpi()->trifr(siteId: $this->site->id, viewer: $this->manager));
        $values = $this->kpi()->nearMissOperands(siteId: $this->site->id, viewer: $this->manager);
        $this->assertSame(3, $values['recordable']); $this->assertSame(2, $values['recordable_factual']);
    }

    public function test_no_actor_and_denied_current_reader_return_nullable_restricted_operands(): void
    {
        $this->injury(['lost_time_days' => 5]);
        $noActor = $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id);
        $this->assertNull($noActor['injuries']); $this->assertNull($noActor['lost_time_days']);
        $this->assertSame('restricted', $noActor['access']);
        $this->revoke($this->factual, 'hazards.view');
        $denied = $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual);
        $this->assertNull($denied['injuries']); $this->assertSame('restricted', $denied['access']);
    }

    public function test_annual_rates_keep_the_existing_minimum_hours_floor(): void
    {
        $this->injury(['lost_time_days' => 5, 'medical_treatment_type' => 'hospital']); $this->hours(999);
        $this->assertNull($this->kpi()->ltifr(siteId: $this->site->id, viewer: $this->manager));
        $this->assertNull($this->kpi()->trifr(siteId: $this->site->id, viewer: $this->manager));
        $this->assertNull($this->kpi()->injurySeverityRate(siteId: $this->site->id, viewer: $this->manager));
    }

    public function test_requested_range_and_site_filter_precede_original_source_discovery(): void
    {
        $this->injury(['lost_time_days' => 5]);
        $this->injury(['injury_date' => '2025-01-01T03:00:00+13:00', 'lost_time_days' => 9]);
        WorkplaceInjury::factory()->createQuietly(['user_id' => $this->worker->id, 'site_id' => Site::factory()->create()->id,
            'injury_date' => '2026-10-10', 'lost_time_days' => 20]);
        $values = $this->kpi()->workplaceInjuryOperands(now()->subDays(7), now(), $this->site->id, $this->factual);
        $this->assertSame(1, $values['injuries']); $this->assertSame(5, $values['lost_time_days']);
    }

    public function test_established_unversioned_legacy_facts_are_not_fabricated_as_recorded_attribution(): void
    {
        WorkplaceInjury::factory()->createQuietly(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'injury_date' => '2026-10-10', 'source_schema_version' => 0, 'source_revision' => 0, 'lost_time_days' => 4]);
        $values = $this->kpi()->workplaceInjuryOperands(now()->subDays(7), now(), $this->site->id, $this->factual);
        $this->assertSame(1, $values['injuries']); $this->assertSame(4, $values['lost_time_days']);
        $this->assertSame('legacy_unknown', $values['attribution_state']);
    }

    public function test_damaged_typed_proof_keeps_recorded_positive_neighbours_and_never_certifies_an_empty_healthy_total(): void
    {
        $valid = $this->injury(['lost_time_days' => 5]); $damaged = $this->injury(['lost_time_days' => 8]);
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $damaged->id)->update(['factual_digest' => str_repeat('0', 64)]);
        $values = $this->kpi()->workplaceInjuryOperands(now()->subDays(7), now(), $this->site->id, $this->factual);
        $this->assertSame(1, $values['injuries']); $this->assertSame(5, $values['lost_time_days']);
        $this->assertSame('repair_required', $values['access']);
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $valid->id)->update(['factual_digest' => str_repeat('0', 64)]);
        $empty = $this->kpi()->workplaceInjuryOperands(now()->subDays(7), now(), $this->site->id, $this->factual);
        $this->assertNull($empty['injuries']); $this->assertSame('repair_required', $empty['access']);
    }

    public function test_actual_aggregate_select_rejects_late_original_factual_version_tamper(): void
    {
        $injury = $this->injury(['lost_time_days' => 5]); $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($injury, $state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `workplace_injuries`')) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->update(['factual_digest' => str_repeat('0', 64)]);
        });
        try {
            $this->denial(fn () => $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual), 404);
            $this->assertTrue($state->fired);
        } finally { $state->armed = false; }
    }

    public function test_same_original_context_does_not_recapture_a_repaired_version_as_new_metric_admission(): void
    {
        $injury = $this->injury(['lost_time_days' => 5]);
        $digest = DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->value('factual_digest');
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->update(['factual_digest' => str_repeat('0', 64)]);
        $service = $this->kpi();
        $this->inReads($this->factual, function ($context) use ($injury, $digest, $service): array {
            $first = $service->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual, context: $context);
            $this->assertNull($first['injuries']); $this->assertSame('repair_required', $first['access']);
            DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->update(['factual_digest' => $digest]);
            $second = $service->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual, context: $context);
            $this->assertNull($second['injuries']); $this->assertSame('repair_required', $second['access']);
            return [];
        });
        $this->assertSame(1, $service->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual)['injuries']);
    }

    public function test_actual_sum_select_rejects_late_permission_withdrawal_and_a_fresh_read_recovers(): void
    {
        $this->injury(['lost_time_days' => 5]); $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select sum(`lost_time_days`) as aggregate from `workplace_injuries`')) { return; }
            $state->armed = false; $state->fired = true; $this->revoke($this->factual, 'hazards.view');
        });
        try {
            $this->denial(fn () => $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual), 404);
            $this->assertTrue($state->fired);
            $this->factual->permissionOverrides()->syncWithoutDetaching([\App\Models\Permission::where('key', 'hazards.view')->sole()->id => ['allowed' => true]]);
            $this->assertSame(5, $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual)['lost_time_days']);
        } finally { $state->armed = false; }
    }

    public function test_original_metric_builder_cannot_escape_and_compile_in_a_later_read(): void
    {
        $this->injury(['lost_time_days' => 5]); $escaped = null;
        $this->inReads($this->factual, function ($context) use (&$escaped): array {
            return app(InjurySourceProjectionService::class)->consumeMetricQueryInContext($context, WorkplaceInjury::query()->where('site_id', $this->site->id), 'factual',
                function (Builder $source) use (&$escaped): array { $escaped = clone $source; return []; });
        });
        $this->expectException(LogicException::class);
        WorkplaceInjury::query()->whereExists($escaped->selectRaw('1')->toBase())->toSql();
    }

    public function test_actual_metric_compilation_reconsumes_original_resolved_timezone_after_early_subquery_compilation(): void
    {
        $this->injury(['lost_time_days' => 5]);
        $worker = config('app.worker_timezone'); $fallback = config('app.timezone');
        config(['app.worker_timezone' => null, 'app.timezone' => 'Pacific/Auckland']);
        try {
            try {
                $this->inReads($this->factual, function ($context): array {
                    return app(InjurySourceProjectionService::class)->consumeMetricQueryInContext($context, WorkplaceInjury::query()->where('site_id', $this->site->id), 'factual',
                        function (Builder $source): array {
                            $source->getQuery()->beforeQuery(fn () => config(['app.timezone' => 'UTC']));
                            return ['days' => $source->sum('lost_time_days')];
                        });
                });
                $this->fail('Actual aggregate must consume the original date guard.');
            } catch (LogicException $failure) { $this->assertStringContainsString('metric date evidence changed', $failure->getMessage()); }
        } finally { config(['app.worker_timezone' => $worker, 'app.timezone' => $fallback]); }
    }

    public function test_actual_metric_select_rejects_late_original_site_withdrawal(): void
    {
        $this->injury(['lost_time_days' => 5]); $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `workplace_injuries`')) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('sites')->where('id', $this->site->id)->update(['archived' => true]);
        });
        try {
            $this->denial(fn () => $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual), 404);
            $this->assertTrue($state->fired);
        } finally { $state->armed = false; }
    }

    public function test_outer_response_barrier_withholds_an_earlier_positive_metric_after_later_source_change(): void
    {
        $injury = $this->injury(['lost_time_days' => 5]);
        $this->denial(fn () => $this->inReads($this->factual, function ($context) use ($injury): array {
            return HealthSafetyReadMaterialisationProof::within($context, function () use ($context, $injury): array {
                $values = $this->kpi()->workplaceInjuryOperands(siteId: $this->site->id, viewer: $this->factual, context: $context);
                $this->assertSame(5, $values['lost_time_days']);
                DB::table('workplace_injuries')->where('id', $injury->id)->update(['lost_time_days' => 40]);
                return ['earlier' => $values];
            });
        }), 404);
    }

    public function test_monthly_series_preserves_factual_positive_rates_and_nullable_private_recordability(): void
    {
        $this->injury(['lost_time_days' => 5]); $this->hours();
        $rows = $this->kpi()->monthlyFrequencyRates(1, $this->site->id, $this->factual);
        $this->assertCount(1, $rows); $this->assertSame('2026-10', $rows[0]['month']);
        $this->assertSame(100.0, $rows[0]['ltifr']); $this->assertNull($rows[0]['trifr']);
    }

    public function test_actual_injury_analytics_export_preserves_factual_records_without_private_medical_body(): void
    {
        $injury = $this->injury(['lost_time_days' => 5, 'medical_treatment_type' => 'hospital']);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $data = app(HsAnalyticsService::class)->exportRows('injuries', $this->site->id, now()->subDays(7), now(), viewer: $this->factual);
            $this->assertSame('readable', $data['access']); $this->assertCount(1, $data['rows']);
            $this->assertSame($injury->id, $data['rows'][0][0]); $this->assertSame(5, $data['rows'][0][5]);
            $this->assertStringNotContainsString('Private', json_encode($data, JSON_THROW_ON_ERROR));
            foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString('medical_treatment_type', $query['query']); }
        } finally { DB::disableQueryLog(); }
    }

    public function test_actual_injury_export_body_select_rejects_late_original_source_reassociation(): void
    {
        $injury = $this->injury(['lost_time_days' => 5]); $foreign = Site::factory()->create();
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($injury, $foreign, $state): void {
            if (! $state->armed || ! preg_match('/^select (.*?) from `workplace_injuries` /s', $sql, $selection)
                || ! str_contains($selection[1], '`description`') || ! str_contains($sql, 'workplace_injury_source_versions')) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('workplace_injuries')->where('id', $injury->id)->update(['site_id' => $foreign->id]);
        });
        try {
            $this->denial(fn () => app(HsAnalyticsService::class)->exportRows('injuries', $this->site->id, now()->subDays(7), now(), viewer: $this->factual), 404);
            $this->assertTrue($state->fired);
        } finally { $state->armed = false; }
    }
}
