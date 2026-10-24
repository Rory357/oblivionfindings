<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Site;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Incidents\IncidentRoutingTestCase;

/** Filter/serialization populations only; separate approved-Site/private-source acceptance remains open. */
final class HsWorkerParticipationMetricFocusTest extends IncidentRoutingTestCase
{
    private const BASE = '/health-safety/worker-participation';
    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->allow($this->manager, 'hazards.view');
        $this->other = Site::factory()->create(['is_active' => true]);
    }

    public function test_training_focus_rows_match_active_under_two_metric_and_exclude_other_states_sites_and_trained_reps(): void
    {
        $zero = $this->rep(['training_days_completed' => 0]);
        $one = $this->rep(['training_days_completed' => 1]);
        $this->rep(['training_days_completed' => 2]);
        $this->rep(['status' => 'inactive', 'training_days_completed' => 0]);
        $this->rep(['status' => 'resigned', 'training_days_completed' => 1]);
        $this->rep(['site_id' => $this->other->id, 'training_days_completed' => 0]);
        $response = $this->page(['tab' => 'representatives', 'site_id' => $this->site->id, 'period' => 'all',
            'status' => 'active', 'focus' => 'training_below_two'])->assertOk();
        $this->assertPopulation($response, [$zero->id, $one->id], 'hero.badges.training_below_minimum');
        $response->assertJsonPath('props.filters.focus', 'training_below_two')->assertJsonPath('props.filters.status', 'active');
    }

    public function test_minutes_focus_matches_completed_missing_document_metric_including_older_and_empty_paths(): void
    {
        $old = $this->meeting(['scheduled_at' => '2025-01-01 00:00:00', 'status' => 'completed', 'minutes_document_path' => null]);
        $recent = $this->meeting(['status' => 'completed', 'minutes_document_path' => null]);
        $this->meeting(['status' => 'scheduled', 'minutes_document_path' => null]);
        $this->meeting(['status' => 'cancelled', 'minutes_document_path' => null]);
        $this->meeting(['status' => 'completed', 'minutes_document_path' => 'minutes/recorded.pdf']);
        $empty = $this->meeting(['status' => 'completed', 'minutes_document_path' => '']);
        $this->meeting(['hs_committee_id' => $this->committee($this->other)->id, 'status' => 'completed', 'minutes_document_path' => null]);
        $response = $this->page(['tab' => 'meetings', 'site_id' => $this->site->id, 'period' => 'all', 'focus' => 'minutes_missing'])->assertOk();
        $this->assertPopulation($response, [$old->id, $recent->id, $empty->id], 'hero.clusters.consultation.minutes_outstanding');
        $response->assertJsonPath('props.hero.badges.minutes_overdue', 3)->assertJsonPath('props.filters.focus', 'minutes_missing');
    }

    public function test_outstanding_meeting_count_matches_rows_while_preserving_the_distinct_unfinished_item_sum(): void
    {
        $scheduled = $this->meeting(['actions_due_count' => 3]);
        $completed = $this->meeting(['status' => 'completed', 'actions_due_count' => 2]);
        $this->meeting(['status' => 'cancelled', 'actions_due_count' => 9]);
        $this->meeting(['status' => 'scheduled', 'actions_due_count' => 0]);
        $this->meeting(['hs_committee_id' => $this->committee($this->other)->id, 'actions_due_count' => 7]);
        $response = $this->page(['tab' => 'meetings', 'site_id' => $this->site->id, 'period' => 'all', 'focus' => 'actions_outstanding'])->assertOk();
        $this->assertPopulation($response, [$scheduled->id, $completed->id], 'hero.clusters.consultation.meetings_with_outstanding_actions');
        $response->assertJsonPath('props.hero.clusters.consultation.overdue_actions', 5)->assertJsonPath('props.filters.focus', 'actions_outstanding');
    }

    public function test_all_meetings_destination_keeps_all_statuses_and_matches_site_tab_count_without_committee_count_substitution(): void
    {
        $expected = [];
        foreach (['scheduled', 'completed', 'cancelled'] as $status) { $expected[] = $this->meeting(['status' => $status])->id; }
        $inactive = $this->committee($this->site, ['status' => 'inactive']);
        $expected[] = $this->meeting(['hs_committee_id' => $inactive->id, 'scheduled_at' => '2025-01-01 00:00:00'])->id;
        $this->meeting(['hs_committee_id' => $this->committee($this->other)->id]);
        $response = $this->page(['tab' => 'meetings', 'site_id' => $this->site->id, 'period' => 'all'])->assertOk();
        $this->assertPopulation($response, $expected, 'tabCounts.meetings');
        $this->assertNotSame($response->json('props.hero.clusters.participation.committees'), $response->json('props.rows.total'));
    }

    #[DataProvider('focuses')]
    public function test_selected_site_zero_remains_zero_despite_matching_neighbours_in_another_site(string $tab, string $focus, string $metric): void
    {
        $this->rep(['site_id' => $this->other->id, 'training_days_completed' => 0]);
        $this->meeting(['hs_committee_id' => $this->committee($this->other)->id, 'status' => 'completed', 'minutes_document_path' => null, 'actions_due_count' => 3]);
        $response = $this->page(['tab' => $tab, 'focus' => $focus, 'site_id' => $this->site->id, 'period' => 'all'])->assertOk();
        $this->assertPopulation($response, [], $metric);
    }

    public static function focuses(): array
    {
        return ['training' => ['representatives', 'training_below_two', 'hero.badges.training_below_minimum'],
            'minutes' => ['meetings', 'minutes_missing', 'hero.clusters.consultation.minutes_outstanding'],
            'actions' => ['meetings', 'actions_outstanding', 'hero.clusters.consultation.meetings_with_outstanding_actions']];
    }

    public function test_stale_cross_tab_focus_is_ignored_but_unknown_or_array_focus_is_validated(): void
    {
        $ordinary = $this->rep(['training_days_completed' => 4]);
        $response = $this->page(['tab' => 'representatives', 'focus' => 'minutes_missing', 'period' => 'all'])->assertOk();
        $response->assertJsonPath('props.filters.focus', null)->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.rows.data.0.id', $ordinary->id);
        $consultation = $this->consultation();
        $this->page(['tab' => 'consultations', 'focus' => 'training_below_two', 'period' => 'all'])->assertOk()
            ->assertJsonPath('props.filters.focus', null)->assertJsonPath('props.rows.data.0.id', $consultation->id);
        foreach (['unrecognized', ['actions_outstanding']] as $focus) {
            $this->getJson(self::BASE.'?'.http_build_query(['focus' => $focus]))->assertUnprocessable()->assertJsonValidationErrors('focus');
        }
    }

    public function test_user_search_and_status_filters_still_narrow_the_focused_register_without_redefining_the_standing_metric(): void
    {
        $night = $this->rep(['training_days_completed' => 1, 'work_group' => 'Night']);
        $this->rep(['training_days_completed' => 1, 'work_group' => 'Day']);
        $this->rep(['training_days_completed' => 1, 'work_group' => 'Night', 'status' => 'resigned']);
        $query = ['tab' => 'representatives', 'focus' => 'training_below_two', 'site_id' => $this->site->id,
            'period' => 'all', 'q' => 'Night', 'status' => 'active'];
        $this->page($query)->assertOk()->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.rows.data.0.id', $night->id)
            ->assertJsonPath('props.hero.badges.training_below_minimum', 2)->assertJsonPath('props.filters.q', 'Night');
        $this->page([...$query, 'status' => 'resigned'])->assertOk()->assertJsonPath('props.rows.total', 0)
            ->assertJsonPath('props.filters.focus', 'training_below_two')->assertJsonPath('props.filters.status', 'resigned');
    }

    public function test_focus_pagination_detail_and_refresh_preserve_the_full_selected_filter_contract(): void
    {
        $expected = [];
        for ($i = 0; $i < 23; $i++) { $expected[] = $this->rep(['training_days_completed' => 1, 'work_group' => 'Night'])->id; }
        $this->rep(['training_days_completed' => 3, 'work_group' => 'Night']);
        $query = ['tab' => 'representatives', 'focus' => 'training_below_two', 'site_id' => $this->site->id,
            'status' => 'active', 'period' => 'all', 'q' => 'Night'];
        $first = $this->page($query)->assertOk()->assertJsonPath('props.rows.total', 23)->assertJsonCount(20, 'props.rows.data');
        $next = $first->json('props.rows.next_page_url');
        parse_str((string) parse_url($next, PHP_URL_QUERY), $preserved);
        foreach ($query as $key => $value) { $this->assertSame((string) $value, (string) $preserved[$key]); }
        $second = $this->get($next, $this->headers())->assertOk()->assertJsonPath('props.rows.total', 23)->assertJsonCount(3, 'props.rows.data');
        $this->assertEqualsCanonicalizing($expected, [...array_column($first->json('props.rows.data'), 'id'), ...array_column($second->json('props.rows.data'), 'id')]);
        $detail = $this->page([...$query, 'page' => 2, 'representative' => $expected[0]])->assertOk()
            ->assertJsonPath('props.detail.kind', 'representative')->assertJsonPath('props.detail.data.id', $expected[0]);
        foreach ($query as $key => $value) { $detail->assertJsonPath('props.filters.'.$key, $value); }
        $this->page([...$query, 'page' => 2])->assertOk()->assertJsonPath('props.filters.focus', 'training_below_two')->assertJsonPath('props.detail', null);
    }

    public function test_civil_list_dates_are_explicit_date_strings_and_other_row_relationship_fields_and_detail_dates_survive(): void
    {
        $rep = $this->rep(['elected_at' => '2026-10-01', 'work_group' => 'Night', 'training_days_completed' => 1]);
        $response = $this->page(['period' => 'all', 'representative' => $rep->id])->assertOk()
            ->assertJsonPath('props.rows.data.0.elected_at', '2026-10-01')->assertJsonPath('props.rows.data.0.work_group', 'Night')
            ->assertJsonPath('props.rows.data.0.user.name', $rep->user->name)->assertJsonPath('props.rows.data.0.site.name', $this->site->name)
            ->assertJsonPath('props.rows.data.0.created_by', $this->manager->id);
        $this->assertSame($rep->toArray()['elected_at'], $response->json('props.detail.data.elected_at'));
        $consultation = $this->consultation(['consultation_date' => '2026-09-27', 'outcome' => 'Recorded outcome']);
        $response = $this->page(['tab' => 'consultations', 'period' => 'all', 'consultation' => $consultation->id])->assertOk()
            ->assertJsonPath('props.rows.data.0.consultation_date', '2026-09-27')->assertJsonPath('props.rows.data.0.site.name', $this->site->name)
            ->assertJsonPath('props.rows.data.0.outcome', 'Recorded outcome')->assertJsonPath('props.rows.data.0.created_by', $this->manager->id);
        $this->assertSame($consultation->toArray()['consultation_date'], $response->json('props.detail.data.consultation_date'));
    }

    public function test_default_quarter_keeps_first_day_and_all_future_scheduling_without_redefining_bounded_hero_quarter(): void
    {
        $this->meeting(['scheduled_at' => '2026-09-30 23:59:59']);
        $first = $this->meeting(['scheduled_at' => '2026-10-01 00:00:00']);
        $current = $this->meeting(['scheduled_at' => '2026-10-12 03:00:00']);
        $future = $this->meeting(['scheduled_at' => '2027-01-01 00:00:00']);
        $response = $this->page(['tab' => 'meetings', 'site_id' => $this->site->id])->assertOk()
            ->assertJsonPath('props.filters.period', 'quarter')->assertJsonPath('props.rows.total', 3)
            ->assertJsonPath('props.hero.clusters.participation.meetings_quarter', 2);
        $this->assertEqualsCanonicalizing([$first->id, $current->id, $future->id], array_column($response->json('props.rows.data'), 'id'));
        $this->assertSame($future->scheduled_at->toJSON(), $response->json('props.rows.data.0.scheduled_at'));
        $this->consultation(['consultation_date' => '2026-09-30']);
        $firstConsultation = $this->consultation(['consultation_date' => '2026-10-01']);
        $futureConsultation = $this->consultation(['consultation_date' => '2027-01-01']);
        $response = $this->page(['tab' => 'consultations', 'site_id' => $this->site->id])->assertOk()->assertJsonPath('props.rows.total', 2);
        $this->assertEqualsCanonicalizing([$firstConsultation->id, $futureConsultation->id], array_column($response->json('props.rows.data'), 'id'));
        $response->assertJsonPath('props.rows.data.0.consultation_date', '2027-01-01');
    }

    private function assertPopulation(TestResponse $response, array $expected, string $metric): void
    {
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('props.rows.data'), 'id'));
        $this->assertSame(count($expected), $response->json('props.rows.total'));
        $this->assertSame($response->json('props.rows.total'), $response->json('props.'.$metric));
    }

    private function page(array $query): TestResponse
    {
        return $this->actingAs($this->manager)->get(self::BASE.'?'.http_build_query($query), $this->headers());
    }

    private function headers(): array { return ['X-Inertia' => 'true']; }

    private function rep(array $values = []): HsRepresentative
    {
        return HsRepresentative::create(['user_id' => User::factory()->create()->id, 'site_id' => $this->site->id,
            'election_method' => 'elected', 'elected_at' => '2026-10-01', 'status' => 'active', 'training_days_completed' => 2,
            'created_by' => $this->manager->id, ...$values]);
    }

    private function committee(Site $site, array $values = []): HsCommittee
    {
        return HsCommittee::create(['name' => 'Recorded committee', 'site_id' => $site->id, 'status' => 'active',
            'meeting_frequency' => 'monthly', 'created_by' => $this->manager->id, ...$values, 'established_at' => '2026-01-01']);
    }

    private function meeting(array $values = []): HsCommitteeMeeting
    {
        return HsCommitteeMeeting::create(['hs_committee_id' => $values['hs_committee_id'] ?? $this->committee($this->site)->id,
            'scheduled_at' => '2026-10-11 00:00:00', 'status' => 'scheduled', 'actions_due_count' => 0,
            'minutes_document_path' => null, 'created_by' => $this->manager->id, ...$values]);
    }

    private function consultation(array $values = []): HsConsultation
    {
        return HsConsultation::create(['title' => 'Recorded consultation', 'site_id' => $this->site->id,
            'consultation_type' => 'procedure_change', 'consultation_date' => '2026-10-11', 'status' => 'open',
            'initiated_by' => $this->manager->id, 'created_by' => $this->manager->id, ...$values]);
    }
}
