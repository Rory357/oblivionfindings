<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Attendance-only preservation extracted from the Workforce summary acceptance cases. */
class AttendanceOperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'UTC'));
        Queue::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_attendance_summary_keeps_true_zero_and_no_record_evidence(): void
    {
        $actor = $this->worker($this->site(), ['handovers.viewAny', 'timesheets.viewAny', 'timesheets.manageAny']);
        $attendance = $this->props($actor, '/attendance');
        $this->assertSame(0, $attendance['summary']['sessions_total']);
        $this->assertEquals(0, $attendance['summary']['closed_hours']);
        $this->assertSame('no_records', $attendance['evidence']['state']);
    }

    public function test_attendance_selected_week_totals_are_uncapped_and_today_remains_viewed_person_local_today(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['timesheets.viewAny', 'timesheets.manageAny']);
        $subject = $this->worker($site);
        foreach (range(1, 101) as $index) {
            $start = Carbon::parse('2026-09-28 00:30', 'Pacific/Auckland')->addHours($index - 1);
            $this->attendanceSession($subject, $start->format('Y-m-d H:i'), 1);
        }
        $this->attendanceSession($subject, '2026-10-05 00:15', 2);
        $this->attendanceSession($actor, '2026-10-05 00:15', 8);
        $props = $this->props($actor, '/attendance?user_id='.$subject->id.'&week=2026-09-28');
        $this->assertSame($subject->id, $props['summary']['user_id']);
        $this->assertSame(101, $props['summary']['sessions_total']);
        $this->assertSame(101, $props['summary']['closed_sessions']);
        $this->assertEquals(101, $props['summary']['closed_hours']);
        $this->assertEquals(101, $props['weekHours']);
        $this->assertEquals(2, $props['todayHours']);
        $this->assertSame(102, $props['totalSessions']);
        $this->assertCount(100, $props['sessions']);
        $this->assertTrue($props['lists']['sessions']['truncated']);
        $this->assertSame('2026-09-27T11:00:00+00:00', $props['evidence']['period_start']);
        $this->assertSame('2026-10-04T11:00:00+00:00', $props['evidence']['period_end_exclusive']);
        $this->assertSame('2026-10-05', $props['evidence']['today']);
        $next = $this->props($actor, '/attendance?user_id='.$subject->id.'&week=2026-09-28&page=2');
        $this->assertCount(1, $next['sessions']);
        $this->assertSame(101, $next['summary']['sessions_total']);
        $this->assertEquals(101, $next['summary']['closed_hours']);
        $this->assertSame(2, $next['sessionPagination']['current_page']);
        $this->assertSame(101, $next['sessionPagination']['total']);
        $this->assertStringContainsString('week=2026-09-28', $next['sessionPagination']['links'][0]['url']);
    }

    public function test_attendance_open_board_counts_all_visible_records_before_cap_and_denies_hidden_person(): void
    {
        $site = $this->site();
        $actor = $this->worker($site, ['timesheets.viewAny', 'timesheets.manageAny']);
        foreach (range(1, 51) as $index) {
            $this->attendanceSession($this->worker($site), '2026-10-03 00:15', null);
        }
        $hidden = $this->worker($this->site());
        $this->attendanceSession($hidden, '2026-10-03 00:15', null);
        $props = $this->props($actor, '/attendance');
        $this->assertSame(51, $props['summary']['on_clock_now_total']);
        $this->assertSame(51, $props['summary']['stale_on_clock_total']);
        $this->assertCount(50, $props['onClockNow']);
        $this->assertTrue($props['lists']['onClockNow']['truncated']);
        $this->assertNotContains($hidden->id, collect($props['onClockNow'])->pluck('user_id')->all());
        $this->actingAs($actor)->get('/attendance?user_id='.$hidden->id)->assertNotFound();
    }

    private function props(User $actor, string $url): array
    {
        $response = $this->actingAs($actor)->get($url)->assertOk();

        return $response->viewData('page')['props'];
    }

    private function site(): Site
    {
        return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    private function worker(Site $site, array $permissions = []): User
    {
        $worker = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true]);
        if ($permissions !== []) {
            $role = Role::create(['name' => 'summary-'.str()->uuid(), 'label' => 'Summary regression', 'type' => 'custom', 'level' => 10]);
            $role->permissions()->sync(collect($permissions)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key],
                ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
            $worker->roles()->attach($role);
        }

        return $worker;
    }

    private function attendanceSession(User $worker, string $start, ?int $hours): HrAttendanceSession
    {
        $startsAt = Carbon::parse($start, 'Pacific/Auckland')->utc();

        return HrAttendanceSession::create(['user_id' => $worker->id, 'clock_in_at' => $startsAt,
            'clock_out_at' => $hours === null ? null : $startsAt->copy()->addHours($hours),
            'status' => $hours === null ? 'open' : 'closed', 'break_minutes' => 0, 'source' => 'manual',
            'created_by' => $worker->id]);
    }
}
