<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MyHrScheduleReadTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;
    private Site $site;
    private ServiceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.publish' => false]);
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => false]);
        Carbon::setTestNow($this->at('2026-10-21 14:00'));
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff($this->site);
        $this->context = ServiceContext::factory()->create(['site_id' => null, 'name' => 'Shared service setting']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_worker_day_selects_the_current_occurrence_whose_start_is_on_the_previous_utc_date(): void
    {
        $shift = $this->shift($this->worker, '2026-10-21 09:00', '2026-10-21 17:00');
        $response = $this->overview();
        $this->assertSame($shift->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($this->at('2026-10-21 09:00')->toIso8601String(), $response->inertiaProps('overview.todayShift.starts_at'));
        $this->assertSame($shift->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertNull($response->inertiaProps('myHr.nextShift'));
    }

    public function test_overnight_current_window_precedes_a_later_start_on_the_worker_day(): void
    {
        Carbon::setTestNow($this->at('2026-10-22 03:00'));
        $overnight = $this->shift($this->worker, '2026-10-21 22:00', '2026-10-22 06:00');
        $next = $this->shift($this->worker, '2026-10-22 09:00', '2026-10-22 17:00');
        $response = $this->overview();
        $this->assertSame($overnight->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($overnight->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame($next->id, $response->inertiaProps('myHr.nextShift.id'));
    }

    public function test_expired_occurrence_and_its_colleagues_are_not_attached_to_the_selected_later_occurrence(): void
    {
        $this->shift($this->worker, '2026-10-21 13:00', '2026-10-21 13:30');
        $chosen = $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        $oldPeer = $this->staff($this->site);
        $peer = $this->staff($this->site);
        $this->shift($oldPeer, '2026-10-21 13:00', '2026-10-21 13:30');
        $this->shift($peer, '2026-10-21 16:00', '2026-10-21 19:00');
        $response = $this->overview();
        $this->assertSame($chosen->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($chosen->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([$peer->id], array_column($response->inertiaProps('overview.shiftColleagues'), 'id'));
    }

    public function test_tomorrow_fallback_does_not_claim_today_colleague_association(): void
    {
        $tomorrow = $this->shift($this->worker, '2026-10-22 09:00', '2026-10-22 17:00');
        $response = $this->overview();
        $this->assertSame($tomorrow->id, $response->inertiaProps('myHr.nextShift.id'));
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertNull($response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
    }

    public function test_worker_timezone_falls_back_to_app_timezone_with_an_exclusive_next_midnight(): void
    {
        config(['app.worker_timezone' => null, 'app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 23:00:00', 'UTC'));
        $next = $this->shift($this->worker, '2026-10-22 13:00', '2026-10-22 14:00');
        // These are 00:00 and 01:00 UTC on 22 Oct: the next UTC day.
        $response = $this->overview();
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertSame($next->id, $response->inertiaProps('myHr.nextShift.id'));
    }

    public function test_canonical_client_site_fallback_remains_readable_for_the_exact_selected_cohort(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $chosen = $this->shift($this->worker, '2026-10-21 13:00', '2026-10-21 17:00', ['site_id' => null, 'client_id' => $client->id]);
        $peer = $this->staff($this->site);
        $this->shift($peer, '2026-10-21 13:00', '2026-10-21 17:00', ['site_id' => null, 'client_id' => $client->id]);
        $response = $this->overview();
        $this->assertSame($chosen->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([$peer->id], array_column($response->inertiaProps('overview.shiftColleagues'), 'id'));
    }

    public function test_location_fallback_still_requires_the_same_canonical_site(): void
    {
        $this->shift($this->worker, '2026-10-21 13:00', '2026-10-21 17:00', ['service_context_id' => null]);
        $peer = $this->staff($this->site);
        $this->shift($peer, '2026-10-21 13:00', '2026-10-21 17:00', ['service_context_id' => null]);
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreign = $this->staff($foreignSite);
        $this->shift($foreign, '2026-10-21 13:00', '2026-10-21 17:00', ['site_id' => $foreignSite->id, 'service_context_id' => null]);
        $this->assertSame([$peer->id], array_column($this->overview()->inertiaProps('overview.shiftColleagues'), 'id'));
    }

    public function test_same_global_context_never_grants_foreign_site_names_and_current_scope_precedes_the_limit(): void
    {
        $chosen = $this->shift($this->worker, '2026-10-21 13:00', '2026-10-21 17:00');
        foreach (range(1, 4) as $_) {
            $former = $this->staff($this->site);
            $this->shift($former, '2026-10-21 13:00', '2026-10-21 17:00');
            HrEmployeeProfile::where('user_id', $former->id)->update(['is_active' => false]);
        }
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreign = $this->staff($foreignSite);
        $this->shift($foreign, '2026-10-21 13:00', '2026-10-21 17:00', ['site_id' => $foreignSite->id]);
        $valid = $this->staff($this->site);
        $this->shift($valid, '2026-10-21 14:00', '2026-10-21 18:00');
        $touching = $this->staff($this->site);
        $this->shift($touching, '2026-10-21 09:00', '2026-10-21 13:00');
        $malformed = $this->staff($this->site);
        $bad = $this->shift($malformed, '2026-10-21 16:00', '2026-10-21 18:00');
        DB::table('shifts')->where('id', $bad->id)->update(['ends_at' => $this->at('2026-10-21 14:00')]);
        $response = $this->overview();
        $this->assertSame($chosen->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([$valid->id], array_column($response->inertiaProps('overview.shiftColleagues'), 'id'));
    }

    #[DataProvider('badIntervals')]
    public function test_missing_or_invalid_intervals_do_not_become_schedule_or_synthetic_colleague_windows(string $shape): void
    {
        $shift = $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        if ($shape === 'missing') {
            // The schema forbids stored NULL ends. Exercise a missing retained
            // projection through a real model retrieval callback instead.
            Shift::retrieved(function ($row) use ($shift) {
                if ($row->id === $shift->id) $row->ends_at = null;
            });
        } else DB::table('shifts')->where('id', $shift->id)->update(['ends_at' =>
            $shape === 'equal' ? $shift->starts_at : $shift->starts_at->copy()->subMinute()]);
        $response = $this->overview();
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertNull($response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
        $this->assertNull($response->inertiaProps('myHr.nextShift'));
    }

    public static function badIntervals(): array { return [['missing'], ['equal'], ['reversed']]; }

    #[DataProvider('unavailableSites')]
    public function test_assignment_alone_does_not_reveal_an_unavailable_or_conflicting_site(string $shape): void
    {
        $shift = $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        if ($shape === 'foreign') $shift->update(['site_id' => $foreign->id]);
        elseif ($shape === 'archived') $this->site->update(['archived' => true, 'archived_at' => now()]);
        else $shift->update(['client_id' => Client::factory()->create(['site_id' => $foreign->id])->id]);
        $response = $this->overview();
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertNull($response->inertiaProps('myHr.nextShift'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
    }

    public static function unavailableSites(): array { return [['foreign'], ['archived'], ['client conflict']]; }

    public function test_publication_rule_is_retained_for_both_own_and_colleague_occurrences(): void
    {
        config(['features.rostering.publish' => true]);
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => true]);
        $hidden = $this->shift($this->worker, '2026-10-21 14:30', '2026-10-21 15:00');
        $shown = $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        DB::table('shifts')->where('id', $shown->id)->update(['published_at' => now()]);
        $peer = $this->staff($this->site);
        $this->shift($peer, '2026-10-21 15:00', '2026-10-21 18:00');
        $response = $this->overview();
        $this->assertNotSame($hidden->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($shown->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($shown->id, $response->inertiaProps('myHr.nextShift.id'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
    }

    public function test_a_foreign_site_service_context_name_is_not_projected_as_the_selected_site_label(): void
    {
        $foreign = Site::factory()->create();
        $context = ServiceContext::factory()->create(['site_id' => $foreign->id, 'name' => 'Foreign private label']);
        $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00', ['service_context_id' => $context->id]);
        $response = $this->overview();
        $this->assertSame('Recorded location', $response->inertiaProps('overview.todayShift.site'));
        $this->assertNull($response->inertiaProps('myHr.nextShift.service_context_name'));
    }

    public function test_current_scope_withdrawn_during_label_read_retires_the_selected_schedule(): void
    {
        $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        $reached = false;
        ServiceContext::retrieved(function ($context) use (&$reached) {
            if (! $reached && $context->id === $this->context->id) {
                $reached = true;
                HrEmployeeProfile::where('user_id', $this->worker->id)->update(['primary_site_id' => null]);
            }
        });
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertNull($response->inertiaProps('myHr.nextShift'));
        $this->assertNull($response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
    }

    #[DataProvider('lateColleagueWithdrawals')]
    public function test_colleague_scope_is_rechecked_after_the_actual_name_model_is_loaded(string $withdrawal): void
    {
        $chosen = $this->shift($this->worker, '2026-10-21 13:00', '2026-10-21 17:00');
        $peer = $this->staff($this->site);
        $peerShift = $this->shift($peer, '2026-10-21 13:00', '2026-10-21 17:00');
        $reached = false;
        User::retrieved(function ($person) use ($peer, $peerShift, $withdrawal, &$reached) {
            if ($reached || $person->id !== $peer->id) return;
            // Target the actual cohort name load, rather than an unrelated
            // earlier directory or celebration read on this same page.
            $inCohort = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
                ->contains(fn ($frame) => ($frame['function'] ?? '') === 'overviewShiftColleagues');
            if (! $inCohort) return;
            $reached = true;
            if ($withdrawal === 'profile') HrEmployeeProfile::where('user_id', $peer->id)->update(['is_active' => false]);
            else DB::table('shifts')->where('id', $peerShift->id)->update(['status' => 'cancelled']);
        });
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertSame($chosen->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($chosen->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
    }

    public static function lateColleagueWithdrawals(): array { return [['profile'], ['occurrence']]; }

    #[DataProvider('calendarWithdrawals')]
    public function test_shell_final_selection_follows_actual_calendar_hydration(string $withdrawal): void
    {
        $chosen = $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        $reached = false;
        Shift::retrieved(function ($row) use ($chosen, $withdrawal, &$reached) {
            if ($reached || $row->id !== $chosen->id || ! $this->inCall('myHrCalendarFeed')) return;
            $reached = true;
            if ($withdrawal === 'site') HrEmployeeProfile::where('user_id', $this->worker->id)->update(['primary_site_id' => null]);
            else DB::table('shifts')->where('id', $chosen->id)->update(['status' => 'cancelled']);
        });
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertNull($response->inertiaProps('myHr.nextShift'));
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertNull($response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
    }

    public static function calendarWithdrawals(): array { return [['site'], ['occurrence']]; }

    #[DataProvider('contextWithdrawals')]
    public function test_context_label_is_not_kept_when_its_actual_hydration_withdraws_that_captured_record(string $withdrawal): void
    {
        $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        $foreign = Site::factory()->create();
        $reached = false;
        ServiceContext::retrieved(function ($context) use ($foreign, $withdrawal, &$reached) {
            if ($reached || $context->id !== $this->context->id || ! $this->inCall('myHrScheduledContext')) return;
            $reached = true;
            DB::table('service_contexts')->where('id', $context->id)->update($withdrawal === 'site'
                ? ['site_id' => $foreign->id] : ['name' => 'Current renamed label']);
        });
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertNull($response->inertiaProps('myHr.nextShift.service_context_name'));
        $this->assertSame($withdrawal === 'site' ? 'Recorded location' : 'Current renamed label',
            $response->inertiaProps('overview.todayShift.site'));
    }

    public static function contextWithdrawals(): array { return [['site'], ['name']]; }

    public function test_peer_expiry_across_midnight_is_rechecked_after_name_hydration_without_database_changes(): void
    {
        Carbon::setTestNow($this->at('2026-10-21 23:59:30'));
        $chosen = $this->shift($this->worker, '2026-10-21 23:30', '2026-10-22 02:00');
        $peer = $this->staff($this->site);
        HrEmployeeProfile::where('user_id', $peer->id)->update(['end_date' => '2026-10-21']);
        $this->shift($peer, '2026-10-21 23:30', '2026-10-22 02:00');
        $reached = false;
        User::retrieved(function ($person) use ($peer, &$reached) {
            if ($reached || $person->id !== $peer->id || ! $this->inCall('overviewShiftColleagues')) return;
            $reached = true;
            Carbon::setTestNow($this->at('2026-10-22 00:00:30'));
        });
        $before = $this->scheduleFacts();
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertSame($chosen->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($chosen->id, $response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
        $this->assertSame($before, $this->scheduleFacts());
    }

    #[DataProvider('hostTimeChanges')]
    public function test_final_overview_host_time_is_checked_after_actual_peer_name_hydration(string $change): void
    {
        if ($change === 'local day') {
            Carbon::setTestNow($this->at('2026-10-22 00:01'));
            $start = '2026-10-22 09:00'; $end = '2026-10-22 17:00'; $after = '2026-10-21 23:59';
        } else {
            $start = '2026-10-21 13:00'; $end = '2026-10-21 14:01'; $after = '2026-10-21 14:02';
        }
        $this->shift($this->worker, $start, $end);
        $peer = $this->staff($this->site);
        $this->shift($peer, $start, $end);
        $reached = false;
        User::retrieved(function ($person) use ($peer, $after, &$reached) {
            if ($reached || $person->id !== $peer->id || ! $this->inCall('overviewShiftColleagues')) return;
            $reached = true;
            Carbon::setTestNow($this->at($after));
        });
        $before = $this->scheduleFacts();
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertNull($response->inertiaProps('overview.todayShift'));
        $this->assertNull($response->inertiaProps('overview.shiftColleaguesShiftId'));
        $this->assertSame([], $response->inertiaProps('overview.shiftColleagues'));
        $this->assertSame($before, $this->scheduleFacts());
    }

    public static function hostTimeChanges(): array { return [['end'], ['local day']]; }

    public function test_shell_future_only_host_is_rechecked_after_its_actual_context_hydration(): void
    {
        $chosen = $this->shift($this->worker, '2026-10-21 15:00', '2026-10-21 18:00');
        $reached = false;
        ServiceContext::retrieved(function ($context) use (&$reached) {
            if ($reached || $context->id !== $this->context->id || ! $this->inCall('myHrScheduledContext')) return;
            $reached = true;
            Carbon::setTestNow($this->at('2026-10-21 15:01'));
        });
        $before = $this->scheduleFacts();
        $response = $this->overview();
        $this->assertTrue($reached);
        $this->assertNull($response->inertiaProps('myHr.nextShift'));
        $this->assertSame($chosen->id, $response->inertiaProps('overview.todayShift.id'));
        $this->assertSame($before, $this->scheduleFacts());
    }

    private function inCall(string $function): bool
    {
        return collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn ($frame) => ($frame['function'] ?? '') === $function);
    }

    private function scheduleFacts(): array
    {
        return collect(['hr_employee_profiles', 'shifts', 'service_contexts'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function at(string $local): Carbon { return Carbon::parse($local, 'Pacific/Auckland')->utc(); }
    private function overview() { return $this->actingAs($this->worker)->get('/hr/my')->assertOk(); }

    private function staff(Site $site): User
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]);
        return $worker;
    }

    private function shift(User $worker, string $start, string $end, array $extra = []): Shift
    {
        return Shift::create(['user_id' => $worker->id, 'site_id' => $this->site->id, 'client_id' => null,
            'service_context_id' => $this->context->id, 'starts_at' => $this->at($start), 'ends_at' => $this->at($end),
            'location' => 'Recorded location', 'shift_type' => 'standard', 'status' => 'scheduled', 'created_by' => $this->worker->id, ...$extra]);
    }
}
