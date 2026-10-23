<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\CalendarSyncMapping;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationAccessService;
use App\Services\Sites\Calendar\Providers\MealPlanObligationProvider;
use App\Services\Sites\Calendar\Providers\WorkerParticipationObligationProvider;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use App\Services\Sites\SiteCalendarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Whole provider and actual HTTP/ICS source candidates; native execution remains UNRUN. */
class WorkerParticipationCalendarSourceReadTest extends RestraintEventCommandTestCase
{
    private HsCommittee $committee;
    private HsCommitteeMeeting $meeting;
    private HsCommittee $cadence;
    private HsCommitteeMeeting $history;
    private User $historical;
    private SiteCalendarAggregator $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland', 'app.timezone' => 'UTC']);
        $this->site->update(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->staff(['hazards.view', 'calendar.view', 'sites.viewAny', 'sites.type.house.view']);
        $this->historical = User::factory()->create(['name' => 'Recorded former representative', 'role' => 'support_worker', 'approved_at' => null]);
        $this->committee = HsCommittee::create(['name' => 'Approved committee', 'site_id' => $this->site->id, 'status' => 'active', 'established_at' => '2026-01-01', 'meeting_frequency' => 'monthly']);
        $this->meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-10-15 01:30:00', 'status' => 'completed', 'location' => 'Recorded meeting room']);
        $this->meeting->attendeeUsers()->attach($this->historical->id, ['response' => 'invited', 'attended' => false]);
        $this->cadence = HsCommittee::create(['name' => 'Cadence committee', 'site_id' => $this->site->id, 'status' => 'active', 'established_at' => '2026-01-01', 'meeting_frequency' => 'monthly']);
        $this->history = $this->cadence->meetings()->create(['scheduled_at' => '2026-09-08 00:00:00', 'status' => 'completed']);
        HsConsultation::create(['title' => 'Recorded consultation topic', 'consultation_type' => 'general', 'description' => 'Private detail not a calendar field',
            'site_id' => $this->site->id, 'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-16', 'status' => 'feedback_received']);
        HsRepresentative::create(['user_id' => $this->historical->id, 'site_id' => $this->site->id, 'election_method' => 'elected', 'elected_at' => '2026-01-01',
            'term_expires_at' => '2026-10-20', 'status' => 'active']);
        SiteCalendarEvent::create(['site_id' => $this->site->id, 'event_type' => 'general', 'title' => 'Existing manual event',
            'start_at' => '2026-10-17 01:00:00', 'created_by_user_id' => $this->actor->id, 'status' => 'approved', 'approval_status' => 'not_required']);
        SiteMealPlanEntry::create(['site_id' => $this->site->id, 'plan_date' => '2026-10-18', 'meal_slot' => 'lunch', 'source_type' => 'ad_hoc', 'ad_hoc_name' => 'Existing meal fact', 'servings' => 2]);
        $this->calendar = new SiteCalendarAggregator(app(SiteCalendarService::class), [new WorkerParticipationObligationProvider, new MealPlanObligationProvider]);
        app()->instance(SiteCalendarAggregator::class, $this->calendar);
    }

    private function start(): Carbon { return Carbon::parse('2026-10-01T00:00:00Z'); }
    private function end(): Carbon { return Carbon::parse('2026-10-31T23:59:59Z'); }
    private function read(?User $viewer = null, ?array $sites = null): array
    {
        return (new WorkerParticipationObligationProvider)->readForViewer($sites ?? [$this->site->id], $this->start(), $this->end(), $viewer ?? $this->actor);
    }

    public function test_actual_source_facts_preserve_status_room_links_attendees_and_historical_owner_without_employment_gate(): void
    {
        $read = $this->read(); $this->assertSame('available', $read['source_access']['status']);
        $rows = collect($read['items']);
        $meeting = $rows->firstWhere('id', 'participation-meeting-'.$this->meeting->id);
        $this->assertSame('completed', $meeting['status']); $this->assertFalse($meeting['allDay']);
        $this->assertSame('Recorded meeting room', $meeting['room']); $this->assertSame([$this->historical->id], $meeting['attendeeIds']);
        $this->assertSame('/health-safety/worker-participation?tab=meetings&meeting='.$this->meeting->id, $meeting['link']);
        $rep = $rows->firstWhere('owner.id', $this->historical->id);
        $this->assertSame($this->historical->name, $rep['owner']['name']); $this->assertFalse($rep['editable']);
        $this->assertStringNotContainsString('Private detail not a calendar field', json_encode($read, JSON_THROW_ON_ERROR));
        $this->assertScalars($read);
    }

    public function test_cadence_retains_actual_all_history_max_outside_visible_month(): void
    {
        $row = collect($this->read()['items'])->firstWhere('id', 'participation-cadence-'.$this->cadence->id);
        $this->assertNotNull($row); $this->assertStringStartsWith('2026-10-08', $row['start']);
        $this->assertSame('overdue', $row['status']); $this->assertSame('high', $row['priority']);
        $this->assertNull(collect($this->read()['items'])->firstWhere('id', 'participation-meeting-'.$this->history->id));
    }

    public function test_raw_site_only_provider_refuses_even_with_authenticated_viewer_and_no_source_sql(): void
    {
        $this->actingAs($this->actor); $state = $this->sourceSql();
        try {
            try { (new WorkerParticipationObligationProvider)->obligations([$this->site->id], $this->start(), $this->end()); $this->fail('Raw source access must refuse.'); }
            catch (LogicException) {}
            $this->assertSame([], $state->sql);
        } finally { $state->armed = false; }
    }

    public function test_explicit_no_viewer_is_restricted_before_source_sql_and_other_calendar_sources_stay_positive(): void
    {
        $state = $this->sourceSql();
        try {
            $items = $this->calendar->arrayForRange([$this->site->id], $this->start(), $this->end(), ['viewer' => null]);
            $this->assertSame('restricted', $this->calendar->participationSourceAccess()['status']); $this->assertSame([], $state->sql);
            $text = json_encode($items, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('Existing manual event', $text); $this->assertStringContainsString('Existing meal fact', $text);
            $this->assertStringNotContainsString('Approved committee', $text);
        } finally { $state->armed = false; }
    }

    public function test_original_requested_site_subset_intersects_approved_sites_before_private_names_and_excludes_null(): void
    {
        $other = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreign = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['secondary_site_ids' => json_encode([$other->id], JSON_THROW_ON_ERROR)]);
        foreach ([[$other->id, 'SECOND APPROVED'], [$foreign->id, 'FOREIGN PRIVATE'], [null, 'NULL PRIVATE']] as [$site, $name]) {
            HsConsultation::create(['title' => $name, 'consultation_type' => 'general', 'description' => 'Recorded', 'site_id' => $site,
                'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-15']);
        }
        $dispatcher = Model::getEventDispatcher(); $names = []; Model::setEventDispatcher(clone $dispatcher);
        HsConsultation::retrieved(function ($row) use (&$names): void { if (array_key_exists('title', $row->getAttributes())) { $names[] = $row->title; } });
        try {
            $read = $this->read(null, [$other->id, $this->site->id, $foreign->id]);
            $this->assertSame('available', $read['source_access']['status']); $this->assertContains('SECOND APPROVED', $names);
            $this->assertNotContains('FOREIGN PRIVATE', $names); $this->assertNotContains('NULL PRIVATE', $names);
            $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'healthSafety.viewAllSites')->sole()->id => ['allowed' => true]]);
            $read = $this->read(null, [$this->site->id, $other->id]);
            $this->assertSame('available', $read['source_access']['status']);
            $this->assertNotContains('FOREIGN PRIVATE', $names); $this->assertNotContains('NULL PRIVATE', $names);
        } finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_existing_explicit_wide_source_access_keeps_positive_facts_without_new_current_employment_requirement(): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'healthSafety.viewAllSites')->sole()->id => ['allowed' => true]]);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]);
        $read = $this->read();
        $this->assertSame('available', $read['source_access']['status']);
        $this->assertStringContainsString('Approved committee', json_encode($read, JSON_THROW_ON_ERROR));
    }

    public static function withdrawals(): array { return [['grant'], ['site'], ['parent'], ['pivot']]; }

    #[DataProvider('withdrawals')]
    public function test_source_withdrawal_at_actual_first_private_select_is_unavailable_and_never_hydrates_private_names(string $change): void
    {
        $armed = true; $fired = false; $hydrated = []; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommittee::retrieved(function ($row) use (&$hydrated, &$fired): void { if ($fired && array_key_exists('name', $row->getAttributes())) { $hydrated[] = $row->id; } });
        DB::connection()->beforeExecuting(function (string $sql) use ($change, &$armed, &$fired): void {
            if (! $armed || $fired || ! str_starts_with(strtolower($sql), 'select `id`, `name`, `site_id`, `meeting_frequency`, `established_at` from `hs_committees`')) { return; }
            $fired = true;
            match ($change) {
                'grant' => $this->revoke($this->actor, 'hazards.view'),
                'site' => DB::table('sites')->where('id', $this->site->id)->update(['archived' => true]),
                'parent' => DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['hs_committee_id' => $this->cadence->id]),
                'pivot' => DB::table('hs_meeting_attendees')->where('meeting_id', $this->meeting->id)->update(['response' => 'declined']),
            };
        });
        try { $read = $this->read(); $this->assertTrue($fired); $this->assertSame('unavailable', $read['source_access']['status']); $this->assertSame([], $read['items']); $this->assertSame([], $hydrated); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        $this->assertSame('available', $this->read()['source_access']['status']);
    }

    public function test_late_all_history_scalar_change_is_withheld_by_final_proof(): void
    {
        $fired = false; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        User::retrieved(function ($row) use (&$fired): void {
            if (! $fired && $row->id === $this->historical->id && array_key_exists('name', $row->getAttributes())) {
                $fired = true; DB::table('hs_committee_meetings')->where('id', $this->history->id)->update(['scheduled_at' => '2026-09-09 00:00:00']);
            }
        });
        try { $read = $this->read(); $this->assertTrue($fired); $this->assertSame('unavailable', $read['source_access']['status']); $this->assertSame([], $read['items']); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_nested_calendar_contributes_names_to_same_original_outer_final_proof(): void
    {
        $this->expectException(HttpException::class);
        HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context): array {
            return HealthSafetyReadMaterialisationProof::within($context, function () use ($context): array {
                $read = (new WorkerParticipationObligationProvider)->readForViewer([$this->site->id], $this->start(), $this->end(), $this->actor, $context);
                $this->assertSame('available', $read['source_access']['status']);
                DB::table('users')->where('id', $this->historical->id)->update(['name' => 'Changed after source read']);
                return $read;
            });
        });
    }

    public function test_aggregator_resets_restricted_state_when_participation_is_not_requested_or_scope_is_empty(): void
    {
        $this->calendar->arrayForRange([$this->site->id], $this->start(), $this->end(), ['viewer' => null]);
        $this->assertSame('restricted', $this->calendar->participationSourceAccess()['status']);
        $this->calendar->arrayForRange([$this->site->id], $this->start(), $this->end(), ['viewer' => null, 'sources' => ['meal']]);
        $this->assertSame(['status' => 'not_requested', 'reason' => null], $this->calendar->participationSourceAccess());
        $this->calendar->arrayForRange([], $this->start(), $this->end(), ['viewer' => null]);
        $this->assertSame('not_requested', $this->calendar->participationSourceAccess()['status']);
    }

    public function test_retained_access_instance_preserves_early_graph_before_an_earlier_provider_mutates_it(): void
    {
        $calendar = new SiteCalendarAggregator(app(SiteCalendarService::class), [new MealPlanObligationProvider, new WorkerParticipationObligationProvider]);
        $fired = false; $armed = true; $names = []; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommittee::retrieved(function ($row) use (&$fired, &$names): void { if ($fired && array_key_exists('name', $row->getAttributes())) { $names[] = $row->id; } });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && str_contains(strtolower($sql), 'from `site_meal_plan_entries`')) {
                $fired = true; DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['hs_committee_id' => $this->cadence->id]);
            }
        });
        try {
            try {
                HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context) use ($calendar): array {
                    $access = app(WorkerParticipationAccessService::class);
                    return $access->materializeSubset($this->actor, [$this->site->id], fn () => $calendar->arrayForRange([$this->site->id], $this->start(), $this->end(),
                        ['viewer' => $this->actor, 'health_safety_context' => $context, 'participation_access' => $access]), $context);
                });
                $this->fail('The original early source graph must not be recaptured.');
            } catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
            $this->assertTrue($fired); $this->assertSame([], $names);
        } finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }

    public function test_supplied_access_cannot_create_a_late_frame_or_escape_original_context(): void
    {
        $provider = new WorkerParticipationObligationProvider;
        $fresh = app(WorkerParticipationAccessService::class);
        HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context) use ($provider, $fresh): array {
            return HealthSafetyReadMaterialisationProof::within($context, function () use ($provider, $fresh, $context): array {
                try { $provider->readForViewer([$this->site->id], $this->start(), $this->end(), $this->actor, $context, $fresh); $this->fail('Unprepared access must refuse.'); }
                catch (LogicException) {}
                return [];
            });
        });
        $this->expectException(LogicException::class);
        $provider->readForViewer([$this->site->id], $this->start(), $this->end(), $this->actor, null, $fresh);
    }

    public function test_actual_native_single_and_global_json_endpoints_report_actor_scope_and_positive_source_status(): void
    {
        foreach (['/sites/'.$this->site->id.'/calendar/events', '/calendar/items'] as $path) {
            $response = $this->actingAs($this->actor)->getJson($path.'?start=2026-10-01&end=2026-10-31&sources[]=participation');
            $response->assertOk()->assertJsonPath('participation_viewer_id', $this->actor->id)->assertJsonPath('availability.participation', 'available')
                ->assertJsonPath('participation_source_access.status', 'available');
            $this->assertStringContainsString('Approved committee', $response->getContent());
        }
    }

    public function test_actual_native_json_source_denial_preserves_manual_schedule_and_explicit_restricted_status(): void
    {
        $this->revoke($this->actor, 'hazards.view'); $state = $this->sourceSql();
        try {
            $response = $this->actingAs($this->actor)->getJson('/sites/'.$this->site->id.'/calendar/events?start=2026-10-01&end=2026-10-31&sources[]=participation&sources[]=event');
            $response->assertOk()->assertJsonPath('participation_viewer_id', null)->assertJsonPath('availability.participation', 'restricted');
            $this->assertSame([], $state->sql); $this->assertStringContainsString('Existing manual event', $response->getContent());
            $this->assertStringNotContainsString('Approved committee', $response->getContent());
        } finally { $state->armed = false; }
    }

    public function test_actual_native_user_ics_token_uses_its_source_viewer_and_reports_positive_then_restricted_status(): void
    {
        $this->actor->update(['calendar_feed_token' => 'participation-native-user-token']);
        $response = $this->get('/calendar/feed/participation-native-user-token.ics');
        $response->assertOk()->assertHeader('X-Calendar-Participation-Source', 'available');
        $this->assertStringContainsString('Approved committee', $response->getContent());
        $this->revoke($this->actor, 'hazards.view');
        $response = $this->get('/calendar/feed/participation-native-user-token.ics');
        $response->assertOk()->assertHeader('X-Calendar-Participation-Source', 'restricted');
        $this->assertStringNotContainsString('Approved committee', $response->getContent());
        $this->assertStringContainsString('Existing manual event', $response->getContent());
    }

    public function test_actual_resource_ics_never_reads_participation_sources_and_keeps_other_provider_bytes(): void
    {
        CalendarSyncMapping::create(['site_id' => $this->site->id, 'provider' => 'google', 'external_calendar_id' => 'synthetic-resource',
            'sync_direction' => 'one_way', 'sources' => ['participation', 'event', 'meal'], 'ical_feed_token' => 'participation-native-resource-token', 'is_active' => true]);
        $state = $this->sourceSql();
        try {
            $response = $this->get('/calendar/site/'.$this->site->id.'/feed/participation-native-resource-token.ics');
            $response->assertOk()->assertHeader('X-Calendar-Participation-Source', 'restricted'); $this->assertSame([], $state->sql);
            foreach (['BEGIN:VCALENDAR', 'Existing manual event', 'Existing meal fact', 'END:VCALENDAR'] as $fact) { $this->assertStringContainsString($fact, $response->getContent()); }
            $this->assertStringNotContainsString('Approved committee', $response->getContent());
        } finally { $state->armed = false; }
    }

    private function sourceSql(): object
    {
        $state = (object) ['armed' => true, 'sql' => []];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void { if ($state->armed && preg_match('/\bfrom\s+`hs_(committees|committee_meetings|representatives|consultations|meeting_attendees)`/i', $sql)) { $state->sql[] = $sql; } });
        return $state;
    }
    private function assertScalars(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { $this->assertScalars($item); } }
        else { $this->assertTrue($value === null || is_scalar($value)); }
    }
}
