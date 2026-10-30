<?php

namespace Tests\Feature\Incidents;

use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HsKpiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual HTTP parity for one organisation's approved Sites; native execution remains UNRUN. */
final class HsDashboardIncidentPeriodParityTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        foreach ([$this->manager, $this->lead] as $viewer) {
            $this->allow($viewer, 'hazards.view');
        }
    }

    #[DataProvider('civilBoundaries')]
    public function test_actual_dashboard_and_register_include_both_edges_of_the_worker_day_across_dst(string $day, string $before, string $start, string $end, string $after): void
    {
        $this->incident(['occurred_at' => $before]);
        $first = $this->incident(['occurred_at' => $start]);
        $last = $this->incident(['occurred_at' => $end]);
        $this->incident(['occurred_at' => $after]);
        $this->incident(['occurred_at' => $start, 'type' => 'near_miss']);
        $this->assertHttpParity($this->manager, ['from' => $day, 'to' => $day, 'site' => $this->site->id], [$first->id, $last->id]);
    }

    public static function civilBoundaries(): array
    {
        return [
            'spring twenty-three-hour day' => ['2026-09-27', '2026-09-26 11:59:59', '2026-09-26 12:00:00', '2026-09-27 10:59:59', '2026-09-27 11:00:00'],
            'autumn twenty-five-hour day' => ['2026-04-05', '2026-04-04 10:59:59', '2026-04-04 11:00:00', '2026-04-05 11:59:59', '2026-04-05 12:00:00'],
            'ordinary winter day' => ['2026-07-08', '2026-07-07 11:59:59', '2026-07-07 12:00:00', '2026-07-08 11:59:59', '2026-07-08 12:00:00'],
        ];
    }

    public function test_default_dates_follow_worker_today_and_include_whole_first_and_last_days_without_changing_rolling_snapshot(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12T12:30:00Z'));
        $this->incident(['occurred_at' => '2026-09-12 11:59:59']);
        $first = $this->incident(['occurred_at' => '2026-09-12 12:00:00']);
        $last = $this->incident(['occurred_at' => '2026-10-13 10:59:59']);
        $this->incident(['occurred_at' => '2026-10-13 11:00:00']);
        $dashboard = $this->assertHttpParity($this->manager, [], [$first->id, $last->id]);
        $dashboard->assertJsonPath('props.filters.from', '2026-09-13')->assertJsonPath('props.filters.to', '2026-10-13');
        $snapshot = $this->dashboard($this->manager, [], ['kpis'])->assertOk();
        $snapshot->assertJsonPath('props.kpis.incidents_30d', 2);
    }

    #[DataProvider('fallbackCalendars')]
    public function test_null_or_empty_worker_timezone_uses_the_application_calendar_for_actual_default_labels_and_register_boundaries(?string $workerTimezone, string $appTimezone, string $expectedFrom, string $expectedTo, string $before, string $start, string $end, string $after): void
    {
        config(['app.worker_timezone' => $workerTimezone, 'app.timezone' => $appTimezone]);
        $this->travelTo(Carbon::parse('2026-10-12T12:30:00Z'));
        $this->incident(['occurred_at' => $before]);
        $first = $this->incident(['occurred_at' => $start]);
        $last = $this->incident(['occurred_at' => $end]);
        $this->incident(['occurred_at' => $after]);
        $this->assertHttpParity($this->manager, [], [$first->id, $last->id])
            ->assertJsonPath('props.filters.from', $expectedFrom)->assertJsonPath('props.filters.to', $expectedTo);
    }

    public static function fallbackCalendars(): array
    {
        $utc = ['UTC', '2026-09-12', '2026-10-12', '2026-09-11 23:59:59', '2026-09-12 00:00:00', '2026-10-12 23:59:59', '2026-10-13 00:00:00'];
        $nz = ['Pacific/Auckland', '2026-09-13', '2026-10-13', '2026-09-12 11:59:59', '2026-09-12 12:00:00', '2026-10-13 10:59:59', '2026-10-13 11:00:00'];
        return ['null worker uses UTC' => [null, ...$utc], 'empty worker uses UTC' => ['', ...$utc],
            'null worker uses NZ' => [null, ...$nz], 'empty worker uses NZ' => ['', ...$nz]];
    }

    public function test_selected_site_keeps_canonical_review_shift_and_client_fallbacks_and_excludes_conflicting_origins(): void
    {
        $other = Site::factory()->create(['is_active' => true]);
        $placedElsewhere = Client::factory()->create(['site_id' => $other->id]);
        $clientFallback = $this->incident(['site_id' => null, 'client_id' => $this->client->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $shift = Shift::factory()->create(['client_id' => $placedElsewhere->id, 'site_id' => $this->site->id]);
        $shiftFallback = $this->incident(['site_id' => null, 'client_id' => $placedElsewhere->id, 'shift_id' => $shift->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $reviewFallback = $this->incident(['site_id' => null, 'client_id' => $placedElsewhere->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $this->canonicalReview($reviewFallback, $this->site);
        $this->incident(['site_id' => $other->id, 'client_id' => $this->client->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $conflictingReview = $this->incident(['site_id' => null, 'client_id' => $this->client->id, 'shift_id' => $shift->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $this->canonicalReview($conflictingReview, $other);
        $this->incident(['site_id' => null, 'client_id' => $this->client->id, 'shift_id' => $shift->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $this->assertHttpParity($this->manager, ['from' => '2026-10-11', 'to' => '2026-10-11', 'site' => $this->site->id],
            [$clientFallback->id, $shiftFallback->id, $reviewFallback->id]);
    }

    public function test_single_bound_requests_emit_a_valid_complete_period_for_the_register_link(): void
    {
        $first = $this->incident(['occurred_at' => '2026-03-05 00:00:00']);
        $this->incident(['occurred_at' => '2026-04-10 00:00:00']);
        $this->assertHttpParity($this->manager, ['to' => '2026-03-31'], [$first->id])
            ->assertJsonPath('props.filters.from', '2026-03-01')->assertJsonPath('props.filters.to', '2026-03-31');
        $last = $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        $this->assertHttpParity($this->manager, ['from' => '2026-10-10'], [$last->id])
            ->assertJsonPath('props.filters.from', '2026-10-10')->assertJsonPath('props.filters.to', '2026-10-12');
    }

    public function test_selected_incident_period_does_not_change_the_positive_annual_ratio_or_its_display_operands(): void
    {
        $this->allow($this->manager, 'hr.wellbeing.view');
        $ordinary = $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        foreach (['2026-01-15 00:00:00', '2026-03-15 00:00:00', '2024-09-15 00:00:00'] as $date) {
            $this->incident(['type' => 'near_miss', 'occurred_at' => $date]);
        }
        foreach (['2026-02-15 00:00:00', '2024-09-15 00:00:00'] as $date) {
            WorkplaceInjury::factory()->createQuietly(['user_id' => $this->manager->id, 'site_id' => $this->site->id,
                'injury_date' => $date, 'lost_time_days' => 1, 'medical_treatment_type' => 'hospital',
                'source_schema_version' => 0, 'source_revision' => 0, 'related_incident_id' => null, 'created_by' => $this->manager->id]);
        }
        BillingEntry::create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'staff_id' => $this->manager->id,
            'service_date' => '2026-02-15', 'hours' => 2000, 'rate' => 0, 'amount' => 0, 'site_name_snapshot' => $this->site->name]);
        $filters = ['from' => '2026-10-11', 'to' => '2026-10-11', 'site' => $this->site->id];
        $this->assertHttpParity($this->manager, $filters, [$ordinary->id]);
        $dashboard = $this->dashboard($this->manager, $filters, ['leading_lagging', 'frequency_operands'])->assertOk();
        $this->assertEquals(2.0, $dashboard->json('props.leading_lagging.leading.near_miss_ratio'));
        $dashboard->assertJsonPath('props.frequency_operands.near_misses', 2)
            ->assertJsonPath('props.frequency_operands.recordable', 1)
            ->assertJsonPath('props.frequency_operands.recordable_factual', 1)
            ->assertJsonPath('props.frequency_operands.medical_recordability', 'readable');
        $this->assertEquals(500.0, $dashboard->json('props.leading_lagging.lagging.ltifr'));
        $this->assertEquals(500.0, $dashboard->json('props.leading_lagging.lagging.trifr'));
    }

    public function test_existing_service_intraday_bounds_remain_precise_and_only_explicit_calendar_mode_expands_them(): void
    {
        foreach (['2026-10-10 23:59:59', '2026-10-11 00:00:00', '2026-10-11 01:00:00', '2026-10-11 01:00:01'] as $date) {
            $this->incident(['occurred_at' => $date]);
        }
        $from = Carbon::parse('2026-10-11T00:00:00Z');
        $to = Carbon::parse('2026-10-11T01:00:00Z');
        $service = app(HsKpiService::class);
        $this->assertSame(2, $service->incidentsInPeriod($from, $to, $this->site->id, $this->manager));
        $this->assertSame(4, $service->incidentsInPeriod($from, $to, $this->site->id, $this->manager, null, true));
        $this->assertSame('2026-10-11 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 01:00:00', $to->format('Y-m-d H:i:s'));
    }

    public function test_all_sites_preserves_explicit_global_reader_unknown_occurrences_and_selected_site_still_narrows(): void
    {
        $this->allow($this->manager, 'healthSafety.viewAllSites');
        $unknownClient = Client::factory()->create(['site_id' => null]);
        $unknown = $this->incident(['site_id' => null, 'client_id' => $unknownClient->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $ordinary = $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        $filters = ['from' => '2026-10-11', 'to' => '2026-10-11'];
        $this->assertHttpParity($this->manager, $filters, [$unknown->id, $ordinary->id])->assertJsonPath('props.filters.site', null);
        $this->assertHttpParity($this->manager, [...$filters, 'site' => $this->site->id], [$ordinary->id]);
    }

    public function test_involved_reader_receives_positive_readable_neighbour_but_neither_private_narrative_nor_hr_count(): void
    {
        $blocked = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        DB::table('client_incidents')->where('id', $blocked->id)->update(['occurred_at' => '2026-10-11 00:00:00', 'type' => 'injury']);
        $ordinary = $this->incident(['occurred_at' => '2026-10-11 00:00:00', 'description' => 'readable positive neighbour']);
        $this->incident(['occurred_at' => '2026-10-11 00:00:00', 'is_hr_confidential' => true, 'description' => 'private HR narrative']);
        $filters = ['from' => '2026-10-11', 'to' => '2026-10-11', 'site' => $this->site->id];
        $this->assertHttpParity($this->manager, $filters, [$blocked->id, $ordinary->id]);
        $this->assertHttpParity($this->lead, $filters, [$ordinary->id]);
    }

    public function test_assignment_reader_keeps_own_client_and_cannot_count_unassigned_client(): void
    {
        $assigned = $this->staff(['hazards.view', 'incidents.viewAssigned', 'clients.viewAssigned']);
        $this->allow($assigned, 'incidents.viewAny', false);
        $this->client->supportWorkers()->attach($assigned->id);
        $own = $this->incident(['client_id' => $this->client->id, 'occurred_at' => '2026-10-11 00:00:00']);
        $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        $this->assertHttpParity($assigned, ['from' => '2026-10-11', 'to' => '2026-10-11', 'site' => $this->site->id], [$own->id]);
    }

    public function test_invalid_and_reversed_selected_dates_are_refused_by_both_http_entries(): void
    {
        foreach ([['from' => '2026-02-30'], ['from' => '2026-10-12', 'to' => '2026-10-11']] as $filters) {
            $this->actingAs($this->manager)->getJson('/health-safety?'.http_build_query($filters))->assertUnprocessable();
            $this->getJson('/incidents?'.http_build_query([...$filters, 'kind' => 'incidents', 'tab' => 'all']))->assertUnprocessable();
        }
    }

    public function test_late_category_sql_permission_withdrawal_withholds_the_already_positive_http_count(): void
    {
        $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select `type`, COUNT(*) as count from `client_incidents`')) { return; }
            $state->armed = false;
            $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false);
            $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $this->dashboard($this->manager, ['from' => '2026-10-11', 'to' => '2026-10-11', 'site' => $this->site->id])->assertNotFound();
            $this->assertTrue($state->fired, 'Withdraw after the incident KPI aggregate, at the later actual category SQL.');
        } finally {
            $state->armed = false;
            $this->allow($this->manager, 'incidents.viewAny');
        }
        $this->dashboard($this->manager, ['from' => '2026-10-11', 'to' => '2026-10-11', 'site' => $this->site->id])
            ->assertOk()->assertJsonPath('props.leading_lagging.lagging.incidents', 1);
    }

    private function assertHttpParity(User $viewer, array $filters, array $expectedIds): TestResponse
    {
        $dashboard = $this->dashboard($viewer, $filters)->assertOk()->assertJsonPath('component', 'health-safety/dashboard');
        $selected = $dashboard->json('props.filters');
        $query = ['kind' => 'incidents', 'tab' => 'all', 'from' => $selected['from'], 'to' => $selected['to']];
        if ($selected['site'] !== null) { $query['site_id'] = $selected['site']; }
        $register = $this->get('/incidents?'.http_build_query($query), ['X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'incidents/index', 'X-Inertia-Partial-Data' => 'rows,tabCounts,filters'])
            ->assertOk()->assertJsonPath('component', 'incidents/index')->assertJsonPath('props.filters.kind', 'incidents');
        $this->assertEqualsCanonicalizing($expectedIds, array_column($register->json('props.rows.data'), 'id'));
        $this->assertSame(count($expectedIds), $register->json('props.rows.total'));
        $this->assertSame($register->json('props.rows.total'), $register->json('props.tabCounts.all'));
        $this->assertSame($register->json('props.rows.total'), $dashboard->json('props.leading_lagging.lagging.incidents'));
        $this->assertSame($register->json('props.rows.total'), array_sum(array_column($dashboard->json('props.incidents_by_category'), 'count')));
        if ($viewer->id === $this->lead->id) {
            $this->assertStringNotContainsString('Synthetic private source text', $register->getContent());
        }
        $this->assertStringNotContainsString('private HR narrative', $register->getContent());
        return $dashboard;
    }

    private function dashboard(User $viewer, array $filters, array $props = ['filters', 'leading_lagging', 'incidents_by_category']): TestResponse
    {
        return $this->actingAs($viewer)->get('/health-safety?'.http_build_query($filters), ['X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'health-safety/dashboard', 'X-Inertia-Partial-Data' => implode(',', $props)]);
    }

    private function canonicalReview(ClientIncident $incident, Site $site): void
    {
        $event = HsEvent::factory()->createQuietly(['source_type' => ClientIncident::class, 'source_id' => $incident->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT, 'site_id' => $site->id, 'client_id' => $incident->client_id,
            'occurred_at' => $incident->occurred_at, 'created_by' => $this->manager->id,
            'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $incident->id, HsEvent::CATEGORY_INCIDENT)]);
        DB::table('client_incidents')->where('id', $incident->id)->update(['hs_event_id' => $event->id]);
    }
}
