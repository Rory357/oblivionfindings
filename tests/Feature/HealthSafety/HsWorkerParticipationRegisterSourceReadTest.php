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
use App\Services\HealthSafety\WorkerParticipationAccessService;
use App\Services\HealthSafety\WorkerParticipationRegisterReadService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Whole production-route projections for one organisation; native acceptance is UNRUN. */
final class HsWorkerParticipationRegisterSourceReadTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/health-safety/worker-participation';
    private Site $site;
    private Site $foreign;
    private User $actor;
    private User $historical;
    private array $own;
    private array $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-13T12:00:00+13:00')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['name' => 'Approved participation Site', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreign = Site::factory()->create(['name' => 'Foreign private Site', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->viewer(false, true, $this->site);
        $this->historical = User::factory()->create(['name' => 'Former meeting attendee', 'role' => 'support_worker', 'approved_at' => null, 'external_clinical_account' => false]);
        $this->own = $this->records($this->site, 'Approved');
        $this->other = $this->records($this->foreign, 'Foreign private');
    }

    public static function families(): array
    {
        return [['representatives', 'representative'], ['meetings', 'meeting'], ['consultations', 'consultation']];
    }

    #[DataProvider('families')]
    public function test_actual_http_rows_counts_and_private_details_share_the_same_approved_source(string $kind, string $detail): void
    {
        $response = $this->page(['tab' => $kind, $detail => $this->own[$kind]->id])->assertOk()
            ->assertJsonPath('component', 'health-safety/worker-participation/index')
            ->assertJsonPath('props.participation_viewer_id', $this->actor->id)
            ->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.rows.data.0.id', $this->own[$kind]->id)
            ->assertJsonPath('props.detail.data.id', $this->own[$kind]->id)->assertJsonPath('props.detail.data.site_ownership', 'recorded');
        $this->assertSame(['representatives' => 1, 'meetings' => 1, 'consultations' => 1], $response->json('props.tabCounts'));
        $this->assertSame([$this->site->id], array_column($response->json('props.sites'), 'id'));
        $this->assertStringNotContainsString('Foreign private', $response->getContent());
        if ($kind === 'meetings') {
            $response->assertJsonPath('props.rows.data.0.attendees_count', 1)->assertJsonPath('props.detail.data.minutes', 'Approved private minutes')
                ->assertJsonPath('props.detail.data.attendee_users.0.name', $this->historical->name)
                ->assertJsonPath('props.detail.data.attendee_users.0.pivot.response', 'accepted');
            $this->assertEquals(1, $response->json('props.detail.data.attendee_users.0.pivot.attended'));
            $pivot = $this->own['meetings']->attendeeUsers()->first()->pivot->toArray();
            $this->assertSame($pivot['created_at'], $response->json('props.detail.data.attendee_users.0.pivot.created_at'));
            $this->assertSame($pivot['updated_at'], $response->json('props.detail.data.attendee_users.0.pivot.updated_at'));
        } elseif ($kind === 'representatives') {
            $response->assertJsonPath('props.rows.data.0.user.name', $this->historical->name)
                ->assertJsonPath('props.detail.data.notes', 'Approved private representative notes');
        } else { $response->assertJsonPath('props.detail.data.description', 'Approved private consultation description')->assertJsonPath('props.detail.data.initiated_by_name', $this->actor->name); }
    }

    #[DataProvider('families')]
    public function test_forged_foreign_detail_ids_do_not_hydrate_the_private_foreign_body(string $kind, string $detail): void
    {
        $retrieved = 0; $root = $this->other[$kind];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        $root::retrieved(function ($model) use ($root, &$retrieved): void { if ($model->id === $root->id) { $retrieved++; } });
        try { $this->page(['tab' => $kind, $detail => $root->id])->assertNotFound(); $this->assertSame(0, $retrieved); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_selected_foreign_site_is_denied_before_private_source_or_name_sql(): void
    {
        $selects = []; $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$selects, &$armed): void {
            if ($armed && preg_match('/^select .* from `hs_(representatives|committees|committee_meetings|consultations)`/i', $sql)) { $selects[] = $sql; }
        });
        try { $this->page(['site_id' => $this->foreign->id])->assertNotFound(); $this->assertSame([], $selects); }
        finally { $armed = false; }
        $this->page(['site_id' => $this->site->id])->assertOk()->assertJsonPath('props.rows.total', 1);
    }

    #[DataProvider('families')]
    public function test_original_root_reassociation_before_actual_body_select_hydrates_no_replacement_body(string $kind, string $detail): void
    {
        $root = $this->own[$kind]; $table = $root->getTable();
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        $root::retrieved(function ($model) use ($root, &$fired, &$hydrated): void { if ($fired && $model->id === $root->id) { $hydrated++; } });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $table, $kind, $root): void {
            if ($armed && ! $fired && preg_match('/^select \* from `'.preg_quote($table, '/').'`/i', $sql)) {
                $fired = true;
                DB::table($table)->where('id', $root->id)->update($kind === 'meetings'
                    ? ['hs_committee_id' => $this->other['committees']->id] : ['site_id' => $this->foreign->id]);
            }
        });
        try { $this->page(['tab' => $kind, $detail => $root->id])->assertNotFound(); $this->assertTrue($fired); $this->assertSame(0, $hydrated); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        DB::table($table)->where('id', $root->id)->update($kind === 'meetings'
            ? ['hs_committee_id' => $this->own['committees']->id] : ['site_id' => $this->site->id]);
        $this->page(['tab' => $kind])->assertOk()->assertJsonPath('props.rows.total', 1);
    }

    public function test_approved_wide_reader_without_current_profile_preserves_all_site_and_unconfirmed_null_site_rows(): void
    {
        $this->actor = $this->viewer(true, false);
        $unknown = $this->records(null, 'Unconfirmed');
        $this->assertDatabaseMissing('hr_employee_profiles', ['user_id' => $this->actor->id]);
        foreach (self::families() as [$kind, $detail]) {
            $response = $this->page(['tab' => $kind, $detail => $unknown[$kind]->id])->assertOk()->assertJsonPath('props.rows.total', 3)
                ->assertJsonPath('props.detail.data.site_ownership', 'unconfirmed');
            $byId = array_column($response->json('props.rows.data'), null, 'id');
            $this->assertSame('unconfirmed', $byId[$unknown[$kind]->id]['site_ownership']);
            $this->page(['tab' => $kind, 'site_id' => $this->site->id])->assertOk()->assertJsonPath('props.rows.total', 1);
        }
        $this->page()->assertOk()->assertJsonPath('props.hero.badges.sites_total', 2)->assertJsonPath('props.hero.badges.sites_covered', 2);
        $this->page(['site_id' => $this->site->id, 'meeting' => $unknown['meetings']->id])->assertNotFound();
    }

    public function test_null_site_records_are_not_invented_global_grants_for_scoped_readers(): void
    {
        $unknown = $this->records(null, 'Unconfirmed');
        foreach (self::families() as [$kind, $detail]) {
            $this->page(['tab' => $kind])->assertOk()->assertJsonPath('props.rows.total', 1);
            $this->page([$detail => $unknown[$kind]->id])->assertNotFound();
        }
    }

    public function test_current_capture_choices_are_batched_and_distinct_from_historical_names(): void
    {
        $eligible = $this->viewer(false, true, $this->site);
        $secondary = $this->viewer(false, true, $this->foreign);
        DB::table('hr_employee_profiles')->where('user_id', $secondary->id)->update(['secondary_site_ids' => json_encode([$this->site->id])]);
        $outside = $this->viewer(false, true, $this->foreign);
        $inactive = $this->viewer(false, true, $this->site);
        DB::table('hr_employee_profiles')->where('user_id', $inactive->id)->update(['is_active' => false]);
        $response = $this->page(['representative' => $this->own['representatives']->id])->assertOk();
        $this->assertEqualsCanonicalizing([$this->actor->id, $eligible->id, $secondary->id], array_column($response->json('props.staff'), 'id'));
        $this->assertNotContains($outside->id, array_column($response->json('props.staff'), 'id'));
        $this->assertNotContains($this->historical->id, array_column($response->json('props.staff'), 'id'));
        $response->assertJsonPath('props.detail.data.user.name', $this->historical->name);
        $this->grant($this->actor, 'hazards.manage', false);
        $this->page(['meeting' => $this->own['meetings']->id])->assertOk()->assertJsonPath('props.can.manage', false)
            ->assertJsonPath('props.staff', [])->assertJsonPath('props.detail.data.attendee_users.0.name', $this->historical->name);
    }

    public function test_selected_register_preserves_other_approved_site_scheduling_choices_without_foreign_counts_or_choices(): void
    {
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['secondary_site_ids' => json_encode([$this->foreign->id])]);
        $otherEligible = $this->viewer(false, true, $this->foreign);
        $outsideSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $outside = $this->viewer(false, true, $outsideSite);
        $outsideRows = $this->records($outsideSite, 'Unapproved private');
        $response = $this->page(['tab' => 'meetings', 'site_id' => $this->site->id])->assertOk()->assertJsonPath('props.rows.total', 1)
            ->assertJsonPath('props.tabCounts.meetings', 1)->assertJsonPath('props.hero.clusters.participation.committees', 1);
        $this->assertEqualsCanonicalizing([$this->site->id, $this->foreign->id], array_column($response->json('props.sites'), 'id'));
        $this->assertContains($otherEligible->id, array_column($response->json('props.staff'), 'id'));
        $this->assertNotContains($outside->id, array_column($response->json('props.staff'), 'id'));
        $committees = array_column($response->json('props.committees'), null, 'id');
        $this->assertSame(1, $committees[$this->other['committees']->id]['meetings_count']);
        $this->assertArrayNotHasKey($outsideRows['committees']->id, $committees);
        $this->assertStringNotContainsString('Unapproved private', $response->getContent());
    }

    public function test_active_site_coverage_denominator_and_positive_family_metrics_use_only_canonical_sources(): void
    {
        $empty = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->viewer(true, false);
        $archived = Site::factory()->create(['is_active' => true, 'archived' => true, 'archived_at' => now()]);
        $this->records($archived, 'Archived private');
        $response = $this->page()->assertOk()->assertJsonPath('props.hero.badges.sites_total', 3)->assertJsonPath('props.hero.badges.sites_covered', 2)
            ->assertJsonPath('props.hero.clusters.participation.sites_without_rep', 1)->assertJsonPath('props.hero.clusters.participation.active_reps', 2)
            ->assertJsonPath('props.hero.clusters.participation.committees', 2)->assertJsonPath('props.hero.clusters.consultation.minutes_outstanding', 2)
            ->assertJsonPath('props.hero.clusters.consultation.meetings_with_outstanding_actions', 2)->assertJsonPath('props.hero.clusters.consultation.overdue_actions', 6);
        $this->assertStringNotContainsString('Archived private', $response->getContent());
        $this->page(['site_id' => $empty->id])->assertOk()->assertJsonPath('props.rows.total', 0)->assertJsonPath('props.tabCounts.meetings', 0)
            ->assertJsonPath('props.hero.badges.sites_total', 1)->assertJsonPath('props.hero.clusters.participation.sites_without_rep', 1);
    }

    public function test_focus_search_pagination_detail_precedence_and_civil_dates_preserve_the_real_route_contract(): void
    {
        for ($i = 0; $i < 23; $i++) {
            HsRepresentative::create(['site_id' => $this->site->id, 'user_id' => $this->historical->id, 'status' => 'active', 'training_days_completed' => 1,
                'work_group' => 'Matching work group', 'election_method' => 'elected', 'elected_at' => '2026-09-27', 'created_by' => $this->actor->id]);
        }
        $query = ['tab' => 'representatives', 'site_id' => $this->site->id, 'status' => 'active', 'q' => 'Matching', 'focus' => 'training_below_two', 'page' => 2];
        $response = $this->page($query)->assertOk()->assertJsonPath('props.rows.total', 23)->assertJsonPath('props.rows.current_page', 2)->assertJsonCount(3, 'props.rows.data')
            ->assertJsonPath('props.rows.data.0.elected_at', '2026-09-27');
        $next = $response->json('props.rows.prev_page_url'); parse_str(parse_url($next, PHP_URL_QUERY), $retained);
        foreach (['tab', 'site_id', 'status', 'q', 'focus'] as $key) { $this->assertSame((string) $query[$key], $retained[$key]); }
        $response = $this->page(['representative' => $this->own['representatives']->id, 'meeting' => $this->own['meetings']->id,
            'consultation' => $this->own['consultations']->id])->assertOk()->assertJsonPath('props.detail.kind', 'consultation');
        $this->assertSame('2026-10-11', $response->json('props.detail.data.consultation_date'));
        $this->page(['tab' => 'consultations'])->assertOk()->assertJsonPath('props.rows.data.0.consultation_date', '2026-10-11');
        $this->page(['tab' => 'consultations', 'focus' => 'minutes_missing'])->assertOk()->assertJsonPath('props.filters.focus', null);
    }

    public function test_recent_register_keeps_future_scheduled_meetings_and_unbounded_representatives(): void
    {
        $future = $this->own['committees']->meetings()->create(['scheduled_at' => '2027-01-01 00:00:00', 'status' => 'scheduled', 'created_by' => $this->actor->id]);
        $this->own['committees']->meetings()->create(['scheduled_at' => '2026-09-30 10:59:59', 'status' => 'scheduled', 'created_by' => $this->actor->id]);
        $response = $this->page(['tab' => 'meetings', 'period' => 'quarter'])->assertOk()->assertJsonPath('props.rows.total', 2)
            ->assertJsonPath('props.hero.clusters.participation.meetings_quarter', 1)->assertJsonPath('props.rows.data.0.scheduled_at', $future->scheduled_at->toJSON());
        $this->assertEqualsCanonicalizing([$future->id, $this->own['meetings']->id], array_column($response->json('props.rows.data'), 'id'));
        $this->page(['period' => 'week'])->assertOk()->assertJsonPath('props.rows.total', 1);
    }

    public function test_declared_date_detail_and_row_fields_remain_calendar_dates_and_timestamps_remain_instants(): void
    {
        $rep = $this->own['representatives'];
        $rep->update(['elected_at' => '2026-09-27', 'term_expires_at' => '2027-04-04', 'initial_training_completed_at' => '2026-04-05']);
        $response = $this->page(['representative' => $rep->id])->assertOk();
        foreach (['elected_at' => '2026-09-27', 'term_expires_at' => '2027-04-04', 'initial_training_completed_at' => '2026-04-05'] as $key => $date) {
            $response->assertJsonPath('props.detail.data.'.$key, $date)->assertJsonPath('props.rows.data.0.'.$key, $date);
        }
        $response->assertJsonPath('props.detail.data.created_at', $rep->created_at->toJSON());
        $rep->update(['term_expires_at' => null, 'initial_training_completed_at' => null]);
        $this->page(['representative' => $rep->id])->assertOk()->assertJsonPath('props.detail.data.term_expires_at', null)
            ->assertJsonPath('props.detail.data.initial_training_completed_at', null);
        $this->page(['meeting' => $this->own['meetings']->id])->assertOk()
            ->assertJsonPath('props.detail.data.scheduled_at', $this->own['meetings']->scheduled_at->toJSON());
        $this->page(['consultation' => $this->own['consultations']->id])->assertOk()->assertJsonPath('props.detail.data.consultation_date', '2026-10-11');
    }

    public function test_worker_quarter_boundaries_include_early_nz_instants_and_last_second_but_not_next_quarter_in_meter(): void
    {
        $create = fn (string $instant) => $this->own['committees']->meetings()->create(['scheduled_at' => $instant, 'status' => 'scheduled', 'created_by' => $this->actor->id]);
        $before = $create('2026-09-30 10:59:59');
        $first = $create('2026-09-30 11:00:00');
        $last = $create('2026-12-31 10:59:59');
        $next = $create('2026-12-31 11:00:00');
        $response = $this->page(['tab' => 'meetings', 'period' => 'quarter'])->assertOk()->assertJsonPath('props.rows.total', 4)
            ->assertJsonPath('props.hero.clusters.participation.meetings_quarter', 3);
        $this->assertEqualsCanonicalizing([$first->id, $last->id, $next->id, $this->own['meetings']->id], array_column($response->json('props.rows.data'), 'id'));
        $this->assertNotContains($before->id, array_column($response->json('props.rows.data'), 'id'));
        $this->own['consultations']->update(['consultation_date' => '2026-10-01']);
        HsConsultation::create(['title' => 'Prior calendar quarter', 'consultation_type' => 'procedure_change', 'site_id' => $this->site->id,
            'consultation_date' => '2026-09-30', 'status' => 'open', 'initiated_by' => $this->actor->id, 'created_by' => $this->actor->id]);
        $this->page(['tab' => 'consultations', 'period' => 'quarter'])->assertOk()->assertJsonPath('props.rows.total', 1)
            ->assertJsonPath('props.rows.data.0.consultation_date', '2026-10-01');
    }

    public function test_worker_year_filter_preserves_first_nz_day_and_future_rows_without_date_column_timezone_conversion(): void
    {
        $this->travelTo(Carbon::parse('2026-01-13T12:00:00+13:00')->utc());
        $first = $this->own['committees']->meetings()->create(['scheduled_at' => '2025-12-31 11:00:00', 'status' => 'scheduled', 'created_by' => $this->actor->id]);
        $this->own['committees']->meetings()->create(['scheduled_at' => '2025-12-31 10:59:59', 'status' => 'scheduled', 'created_by' => $this->actor->id]);
        $response = $this->page(['tab' => 'meetings', 'period' => 'year'])->assertOk()->assertJsonPath('props.rows.total', 2)
            ->assertJsonPath('props.hero.clusters.participation.meetings_quarter', 1);
        $this->assertContains($first->id, array_column($response->json('props.rows.data'), 'id'));
        $this->own['consultations']->update(['consultation_date' => '2026-01-01']);
        $this->page(['tab' => 'consultations', 'period' => 'year'])->assertOk()->assertJsonPath('props.rows.total', 1)
            ->assertJsonPath('props.rows.data.0.consultation_date', '2026-01-01');
    }

    public function test_worker_quarter_aggregate_still_refuses_actual_late_source_reassociation_and_restores(): void
    {
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$fired, &$hydrated): void {
            if ($fired && array_key_exists('quarter_meetings', $row->getAttributes())) { $hydrated++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && preg_match('/^select\s+(.+?)\s+from\s+`hs_committee_meetings`/is', ltrim($sql), $match)
                && str_contains($match[1], 'AS quarter_meetings')) {
                $fired = true;
                $this->assertStringContainsString('scheduled_at >= ? AND scheduled_at < ?', $match[1]);
                DB::table('hs_committees')->where('id', $this->own['committees']->id)->update(['site_id' => $this->foreign->id]);
            }
        });
        try { $this->page(['tab' => 'meetings'])->assertNotFound(); $this->assertTrue($fired); $this->assertSame(0, $hydrated); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        DB::table('hs_committees')->where('id', $this->own['committees']->id)->update(['site_id' => $this->site->id]);
        $this->page(['tab' => 'meetings'])->assertOk()->assertJsonPath('props.hero.clusters.participation.meetings_quarter', 1);
    }

    public function test_late_management_withdrawal_at_actual_picker_name_sql_withholds_the_whole_payload_and_fresh_restore_recovers(): void
    {
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        User::retrieved(function ($row) use (&$fired, &$hydrated): void { if ($fired && array_key_exists('name', $row->getAttributes())) { $hydrated++; } });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && $this->namedSelect($sql, ['users']) && str_contains($sql, 'order by `name` asc')) {
                $fired = true; $this->grant($this->actor, 'hazards.manage', false);
            }
        });
        try { $this->page()->assertNotFound(); $this->assertTrue($fired); $this->assertSame(0, $hydrated); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        $this->grant($this->actor, 'hazards.manage', true);
        $this->page()->assertOk()->assertJsonPath('props.can.manage', true)->assertJsonPath('props.rows.total', 1);
    }

    public function test_later_picker_sql_cannot_return_an_earlier_body_that_changed_after_hydration(): void
    {
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && $this->namedSelect($sql, ['hs_committees']) && preg_match('/order by (?:`hs_committees`\.)?`name` asc/i', $sql)) {
                $fired = true; DB::table('hs_representatives')->where('id', $this->own['representatives']->id)->update(['notes' => 'Changed after original body SELECT']);
            }
        });
        try { $this->page()->assertNotFound(); $this->assertTrue($fired); }
        finally { $armed = false; }
        DB::table('hs_representatives')->where('id', $this->own['representatives']->id)->update(['notes' => 'Approved private representative notes']);
        $this->page(['representative' => $this->own['representatives']->id])->assertOk()->assertJsonPath('props.detail.data.notes', 'Approved private representative notes');
    }

    public function test_original_other_site_choice_reassociation_before_name_sql_cannot_be_replaced_by_current_authority(): void
    {
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['secondary_site_ids' => json_encode([$this->foreign->id])]);
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommittee::retrieved(function ($row) use (&$fired, &$hydrated): void { if ($fired && $row->id === $this->other['committees']->id && array_key_exists('name', $row->getAttributes())) { $hydrated++; } });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $outside): void {
            if ($armed && ! $fired && $this->namedSelect($sql, ['hs_committees']) && preg_match('/order by (?:`hs_committees`\.)?`name` asc/i', $sql)) {
                $fired = true; DB::table('hs_committees')->where('id', $this->other['committees']->id)->update(['site_id' => $outside->id]);
            }
        });
        try { $this->page(['site_id' => $this->site->id])->assertNotFound(); $this->assertTrue($fired); $this->assertSame(0, $hydrated); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        DB::table('hs_committees')->where('id', $this->other['committees']->id)->update(['site_id' => $this->foreign->id]);
        $response = $this->page(['site_id' => $this->site->id])->assertOk()->assertJsonPath('props.rows.total', 1);
        $this->assertContains($this->other['committees']->id, array_column($response->json('props.committees'), 'id'));
    }

    public function test_actual_named_sql_is_bounded_by_batches_when_the_page_grows_to_twenty_rows(): void
    {
        $selects = 0; $armed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$selects, &$armed): void {
            if ($armed && $this->namedSelect($sql, ['users', 'sites', 'hs_committees'])) { $selects++; }
        });
        $armed = true; $this->page()->assertOk(); $one = $selects; $armed = false;
        for ($i = 0; $i < 19; $i++) { HsRepresentative::create(['site_id' => $this->site->id, 'user_id' => $this->historical->id, 'status' => 'active', 'election_method' => 'elected', 'elected_at' => '2026-10-01', 'created_by' => $this->actor->id]); }
        $selects = 0; $armed = true;
        try { $this->page()->assertOk()->assertJsonCount(20, 'props.rows.data'); $this->assertSame($one, $selects); }
        finally { $armed = false; }
    }

    public function test_supplied_invalid_ids_are_validated_and_reused_expired_context_cannot_rebaseline(): void
    {
        foreach (['site_id', 'representative', 'meeting', 'consultation'] as $key) {
            $this->actingAs($this->actor)->getJson(self::BASE.'?'.$key.'=0')->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $context = null;
        HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($active) use (&$context): array { $context = $active; return []; });
        $request = \Illuminate\Http\Request::create(self::BASE); $request->setUserResolver(fn () => $this->actor);
        $this->expectException(LogicException::class);
        app(WorkerParticipationRegisterReadService::class)->index($request, $context);
    }

    private function page(array $query = []): TestResponse
    {
        return $this->actingAs($this->actor)->get(self::BASE.'?'.http_build_query(['tab' => 'representatives', 'period' => 'all', ...$query]), ['X-Inertia' => 'true']);
    }

    /** Match the actual outer name projection, never a name predicate buried in a source barrier. */
    private function namedSelect(string $sql, array $tables): bool
    {
        $parts = preg_split('/\sfrom\s/i', $sql, 2);
        if (count($parts) !== 2 || ! str_starts_with(strtolower($parts[0]), 'select ') || ! str_contains($parts[0], '`name`')) { return false; }
        foreach ($tables as $table) { if (preg_match('/^`'.preg_quote($table, '/').'`(?:\s|$)/i', $parts[1])) { return true; } }
        return false;
    }

    private function viewer(bool $wide, bool $profile, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        if ($profile) { HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]); }
        foreach (WorkerParticipationAccessService::PERMISSIONS as $key) { $this->grant($user, $key, $key !== 'healthSafety.viewAllSites' || $wide); }
        return $user;
    }

    private function grant(User $user, string $key, bool $allowed): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function records(?Site $site, string $label): array
    {
        $committee = HsCommittee::create(['name' => $label.' committee', 'site_id' => $site?->id, 'meeting_frequency' => 'monthly',
            'established_at' => '2026-01-01', 'status' => 'active', 'members' => [$this->historical->id], 'created_by' => $this->actor->id]);
        $meeting = $committee->meetings()->create(['scheduled_at' => '2026-10-11 00:00:00', 'status' => 'completed', 'minutes' => $label.' private minutes',
            'agenda_items' => ['Original agenda'], 'action_items' => [['description' => 'Original action', 'status' => 'open']],
            'actions_due_count' => 3, 'minutes_document_path' => null, 'created_by' => $this->actor->id]);
        $meeting->attendeeUsers()->attach($this->historical->id, ['response' => 'accepted', 'attended' => true]);
        return ['committees' => $committee, 'meetings' => $meeting,
            'representatives' => HsRepresentative::create(['user_id' => $this->historical->id, 'site_id' => $site?->id, 'work_group' => $label.' work group',
                'election_method' => 'elected', 'elected_at' => '2026-10-01', 'status' => 'active', 'training_days_completed' => 1,
                'notes' => $label.' private representative notes', 'created_by' => $this->actor->id]),
            'consultations' => HsConsultation::create(['title' => $label.' consultation', 'consultation_type' => 'procedure_change',
                'description' => $label.' private consultation description', 'site_id' => $site?->id, 'consultation_date' => '2026-10-11',
                'initiated_by' => $this->actor->id, 'workers_consulted' => [$this->historical->id], 'status' => 'open', 'created_by' => $this->actor->id])];
    }
}
