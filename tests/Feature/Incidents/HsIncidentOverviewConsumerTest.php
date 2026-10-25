<?php

namespace Tests\Feature\Incidents;

use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/** Actual Overview HTTP projections; source declarations are not executed acceptance. */
final class HsIncidentOverviewConsumerTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([$this->manager, $this->lead] as $viewer) {
            $this->allow($viewer, 'hazards.view');
            $this->allow($viewer, 'hazards.manage');
        }
    }

    private function bundle(ClientIncident $incident, bool $notifiable = false): void
    {
        $category = $incident->type === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        $event = app(HsEventService::class)->recordEvent([
            'source' => $incident, 'event_category' => $category, 'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $incident->site_id, 'client_id' => $incident->client_id, 'created_by' => $this->manager->id,
            'occurred_at' => $incident->occurred_at, 'worksafe_notifiable' => $notifiable,
        ]);
        $this->assertInstanceOf(HsEvent::class, $event);
        DB::table('client_incidents')->where('id', $incident->id)->update(['hs_event_id' => $event->id]);
    }

    public function test_actual_overview_keeps_readable_counts_trends_categories_and_site_rows_without_involved_source_metrics(): void
    {
        $blocked = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        DB::table('client_incidents')->where('id', $blocked->id)->update([
            'occurred_at' => now()->subDay(), 'type' => 'injury', 'description' => 'Restricted overview source marker.',
        ]);
        $this->bundle($blocked->fresh(), true);
        $readable = $this->incident(['client_id' => $this->client->id, 'type' => 'near_miss', 'occurred_at' => now()->subHours(2)]);
        $this->bundle($readable);

        $this->actingAs($this->manager)->get('/health-safety?site='.$this->site->id)
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->component('health-safety/dashboard')
                ->where('kpis.incidents_30d', 2)->where('kpis.near_misses_30d', 1)
                // Lagging incidents leave out near misses (main's rule since June, kept): near misses are a leading measure.
                ->where('leading_lagging.lagging.incidents', 1)
                ->where('incident_trends.0.count', 2)
                ->where('incidents_by_category.0.label', 'injury')->where('incidents_by_category.0.count', 1)
                ->where('site_league.0.incidents', 2));

        $this->actingAs($this->lead)->get('/health-safety?site='.$this->site->id)
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->component('health-safety/dashboard')
                ->where('source_scope', 'readable_subset')->where('source_completeness', 'unknown')
                ->where('kpis.incidents_30d', 1)->where('kpis.near_misses_30d', 1)
                ->where('kpis.days_since_notifiable', null)
                // The lead's only readable incident is the near miss, which lagging incidents leave out.
                ->where('leading_lagging.lagging.incidents', 0)
                ->where('incident_trends.0.count', 1)->has('incidents_by_category', 0)
                ->where('site_league.0.incidents', 1)->has('worklists.notifiable_events', 0));
    }

    public function test_actual_category_sql_withdrawal_withholds_earlier_metrics_then_a_fresh_request_recovers(): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id, 'occurred_at' => now()->subHour()]));
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_contains($sql, 'from `client_incidents`')
                || ! str_contains($sql, 'group by `type`') || ! str_contains($sql, 'as count')) {
                return;
            }
            $state->armed = false;
            $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false);
            $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $this->actingAs($this->manager)->get('/health-safety?site='.$this->site->id)->assertNotFound();
        } finally {
            $state->armed = false;
        }
        $this->assertTrue($state->fired, 'The mutation must occur at the actual later category aggregation.');
        $this->allow($this->manager, 'incidents.viewAny');
        $this->actingAs($this->manager)->get('/health-safety?site='.$this->site->id)
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('kpis.incidents_30d', 1));
    }

    public function test_actual_late_site_league_read_cannot_return_an_earlier_incident_snapshot_after_reassociation(): void
    {
        $incident = $this->incident(['client_id' => $this->client->id, 'occurred_at' => now()->subHour()]);
        $this->bundle($incident);
        $other = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $incident, $other): void {
            if (! $state->armed || ! str_contains($sql, 'from `hs_events`')
                || ! str_contains($sql, 'group by `site_id`')) {
                return;
            }
            $state->armed = false;
            $state->fired = true;
            DB::table('client_incidents')->where('id', $incident->id)->update(['client_id' => $other->id]);
        });
        try {
            $this->actingAs($this->manager)->get('/health-safety?site='.$this->site->id)->assertNotFound();
        } finally {
            $state->armed = false;
        }
        $this->assertTrue($state->fired, 'The mutation must occur at the actual later Site league read.');
    }
}
