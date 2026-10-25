<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsWorklistHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function reader(Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        $permission = Permission::firstOrCreate(['key' => 'hazards.view'], ['description' => 'Read safety reviews', 'module' => 'Test', 'group' => 'test']);
        $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);

        return $user;
    }

    private function event(Site $site, array $attributes = []): HsEvent
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        // A submitted incident and its canonical H&S event (key, category and direct link as the incident journey
        // records them), so the event shows to H&S at the Site without incident read (decision 9).
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create(['client_id' => $client->id, 'site_id' => $site->id, 'type' => 'injury']));

        $event = HsEvent::factory()->create([
            'site_id' => $site->id, 'client_id' => $client->id, 'source_type' => ClientIncident::class, 'source_id' => $incident->id,
            'severity' => 'high', 'event_category' => 'incident', 'status' => 'open', 'investigation_required' => true,
            'occurred_at' => '2026-10-06 12:00:00',
            'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $incident->id, HsEvent::CATEGORY_INCIDENT), ...$attributes,
        ]);
        DB::table('client_incidents')->where('id', $incident->id)->update(['hs_event_id' => $event->id]);

        return $event;
    }

    public function test_review_attention_worklists_match_filtered_counts_and_preserve_site_authority(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->reader($site);
        $needed = $this->event($site, ['reference_number' => 'HS-MATCH-NEEDED', 'worksafe_notifiable' => true, 'worksafe_status' => 'pending']);
        $verifiedInvestigation = $this->event($site, ['reference_number' => 'HS-MATCH-DONE', 'status' => 'corrective_action']);
        HsInvestigation::factory()->create(['hs_event_id' => $verifiedInvestigation->id, 'status' => 'completed']);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $verifiedInvestigation->id, 'status' => 'completed']);
        $this->event($site, ['reference_number' => 'HS-MATCH-LOW', 'severity' => 'low']);
        $this->event($site, ['reference_number' => 'HS-OTHER']);
        $this->event(Site::factory()->create(), ['reference_number' => 'HS-MATCH-FOREIGN']);
        $filters = ['q' => 'HS-MATCH', 'severity' => 'high', 'source' => 'incidents', 'category' => 'incident', 'site_id' => $site->id, 'from' => '2026-10-01', 'to' => '2026-10-08'];

        foreach (['investigation_due' => $needed->id, 'awaiting_verification' => $verifiedInvestigation->id, 'worksafe_pending' => $needed->id] as $tab => $id) {
            $this->actingAs($viewer)->get('/health-safety/events?'.http_build_query([...$filters, 'tab' => $tab]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('tabCounts.all', 2)->where('tabCounts.'.$tab, 1)->where('events.total', 1)
                ->where('events.data.0.id', $id)->where('filters.q', 'HS-MATCH')
                ->where('hero.attention.investigation_due', 1)->where('hero.attention.awaiting_verification', 1)
                ->where('hero.attention.worksafe_due', 1));
        }
    }

    public function test_action_attention_counts_and_distinct_monitoring_drill_use_the_same_action_filters(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->reader($site);
        $first = $this->event($site, ['status' => 'monitoring', 'occurred_at' => '2020-01-01 12:00:00']);
        $second = $this->event($site, ['status' => 'monitoring']);
        $open = $this->event($site);
        $foreign = $this->event(Site::factory()->create(), ['status' => 'monitoring']);
        foreach ([$first, $first, $second, $open, $foreign] as $event) {
            HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'title' => 'Needle matching action', 'priority' => 'high', 'status' => 'open', 'assigned_to_user_id' => null, 'due_date' => '2026-10-05']);
        }
        HsCorrectiveAction::factory()->create(['hs_event_id' => $first->id, 'title' => 'Needle already verified', 'priority' => 'high', 'status' => 'verified', 'assigned_to_user_id' => null, 'due_date' => '2026-10-05']);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $first->id, 'title' => 'Other action', 'priority' => 'low', 'status' => 'open', 'due_date' => '2026-10-05']);
        $filters = ['q' => 'Needle', 'priority' => 'high', 'unassigned' => 1, 'site_id' => $site->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'tab' => 'critical_open'];
        $response = $this->actingAs($viewer)->get('/health-safety/corrective-actions?'.http_build_query($filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.total', 4)->where('tabCounts.critical_open', 4)->where('tabCounts.unassigned', 4)
            ->where('hero.attention.monitoring_events', 2)->where('hero.attention.critical_open', 4));
        $url = $response->viewData('page')['props']['monitoringReviewUrl'];
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.total', 2)->where('tabCounts.monitoring', 2)
            ->where('events.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all())
            ->where('filters.from', null)->where('filters.action_context.from', '2026-10-01')
            ->where('actionReturnUrl', '/health-safety/corrective-actions?'.http_build_query([
                'q' => 'Needle', 'priority' => 'high', 'site_id' => $site->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'tab' => 'critical_open', 'unassigned' => '1',
            ], '', '&', PHP_QUERY_RFC3986)));
        $this->get('/health-safety/events?'.http_build_query(['tab' => 'monitoring', 'action_context' => ['site_id' => $foreign->site_id]]))->assertForbidden();
    }

    public function test_occurrence_days_and_action_due_dates_use_nz_calendar_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:15:00', 'UTC')); // 8 October NZ, still 7 October UTC.
        $site = Site::factory()->create();
        $viewer = $this->reader($site);
        $inside = $this->event($site, ['occurred_at' => '2026-10-07 11:00:00']);
        $this->event($site, ['occurred_at' => '2026-10-07 10:59:59']);
        $this->event($site, ['occurred_at' => '2026-10-08 11:00:00']);
        $overdue = HsCorrectiveAction::factory()->create(['hs_event_id' => $inside->id, 'status' => 'open', 'due_date' => '2026-10-07']);
        $today = HsCorrectiveAction::factory()->create(['hs_event_id' => $inside->id, 'status' => 'open', 'due_date' => '2026-10-08']);
        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($today->isOverdue());
        $this->actingAs($viewer)->get('/health-safety/events?from=2026-10-08&to=2026-10-08')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('events.total', 1)->where('events.data.0.id', $inside->id)->where('tabCounts.all', 1));
        $this->get('/health-safety/corrective-actions?tab=overdue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('actions.total', 1)->where('actions.data.0.id', $overdue->id)->where('actions.data.0.is_overdue', true)->where('hero.attention.overdue', 1));
        foreach (['/health-safety/events', '/health-safety/corrective-actions'] as $path) {
            $this->getJson($path.'?from=2026-10-08&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        }
        $this->getJson('/health-safety/events?'.http_build_query(['action_context' => ['from' => '2026-10-08', 'to' => '2026-10-01']]))
            ->assertUnprocessable()->assertJsonValidationErrors('action_context.to');
    }
}
