<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MyHrAttendanceReadContractTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 02:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff($this->site);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_canonical_session_identity_is_distinct_from_the_retained_time_entry_projection(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $client->id,
            'site_id' => $this->site->id, 'status' => 'scheduled']);
        $session = $this->session(['shift_id' => $shift->id]);
        $entry = $this->entry($session, (int) $session->id + 1000000);
        $before = $this->recordedRows();
        $response = $this->actingAs($this->worker)->get('/hr/my/profile')->assertOk();
        $state = $response->inertiaProps('myHr.attendance');

        $this->assertNotSame($entry->id, $session->id);
        $this->assertSame(['id' => $entry->id, 'clock_in' => $entry->clock_in->toIso8601String(),
            'notes' => 'Existing projection notes'], $response->inertiaProps('myHr.activeClock'));
        $this->assertSame($this->frame($session), $state);
        $this->assertSame($shift->id, $state['session']['shift_id']);
        $this->assertSame($before, $this->recordedRows());
    }

    public function test_a_canonical_session_is_available_without_a_time_entry_projection(): void
    {
        $session = $this->session();
        $response = $this->actingAs($this->worker)->get('/hr/my/profile')->assertOk();

        $this->assertNull($response->inertiaProps('myHr.activeClock'));
        $this->assertSame($this->frame($session), $response->inertiaProps('myHr.attendance'));
        $this->assertFalse(HrTimeEntry::where('user_id', $this->worker->id)->exists());
    }

    public function test_a_readable_legacy_shift_without_client_cannot_advertise_break_commands(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $client->id,
            'site_id' => $this->site->id, 'status' => 'scheduled']);
        $session = $this->session(['shift_id' => $shift->id]);
        // Historical record state; do not disable the production model guards.
        DB::table('shifts')->where('id', $shift->id)->update(['client_id' => null]);
        $before = $this->recordedRows();

        $this->assertTrue($this->worker->canDo('timesheets.create'));
        $this->assertSame($this->frame($session, false), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
        $this->postJson('/attendance/break/start', ['session_id' => $session->id])->assertNotFound();
        $this->assertSame($before, $this->recordedRows());
    }

    #[DataProvider('selfClockGrants')]
    public function test_each_existing_workforce_self_clock_grant_allows_break_hint(string $grant): void
    {
        $this->worker = $this->staff($this->site, [$grant]);
        $session = $this->session();
        $response = $this->actingAs($this->worker)->get('/hr/my/profile')->assertOk();

        $this->assertSame($this->frame($session), $response->inertiaProps('myHr.attendance'));
    }

    public static function selfClockGrants(): array
    {
        return array_map(fn (string $grant) => [$grant], [
            'timesheets.create', 'shifts.viewAssigned', 'shifts.update', 'shifts.manageAny',
        ]);
    }

    public function test_hr_management_permissions_do_not_grant_workforce_break_commands(): void
    {
        $this->worker = $this->staff($this->site, ['hr.time.manage', 'hr.employees.viewAny']);
        $session = $this->session();
        $state = $this->actingAs($this->worker)->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance');

        $this->assertSame($this->frame($session, false), $state);
    }

    public function test_an_explicit_self_clock_denial_withdraws_the_hint(): void
    {
        $session = $this->session();
        $permission = Permission::where('key', 'timesheets.create')->sole();
        $this->worker->permissionOverrides()->attach($permission, ['allowed' => false]);

        $this->assertSame($this->frame($session, false), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
    }

    public function test_a_valid_ongoing_break_keeps_explicit_state_and_legacy_zero_count(): void
    {
        $session = $this->session(['break_started_at' => now()->subMinutes(5), 'break_count' => 0]);
        $this->event($session);

        $this->assertSame($this->frame($session), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
    }

    public function test_a_future_break_is_readable_but_cannot_yet_be_ended(): void
    {
        $session = $this->session(['break_started_at' => now()->addMinutes(5), 'break_count' => 1]);
        $this->event($session);

        $this->assertSame($this->frame($session, false, false), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
    }

    public function test_a_break_starting_at_the_proposed_end_keeps_both_action_hints(): void
    {
        $session = $this->session(['break_started_at' => now(), 'break_count' => 1]);
        $this->event($session);

        $this->assertSame($this->frame($session), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
    }

    public function test_a_just_started_session_keeps_break_authority_while_clock_out_must_wait(): void
    {
        $session = $this->session(['clock_in_at' => now(), 'break_minutes' => 0]);

        $this->assertSame($this->frame($session, true, false), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
    }

    public function test_recorded_breaks_filling_the_interval_withhold_clock_out_independently(): void
    {
        $session = $this->session(['break_minutes' => 120]);

        $this->assertSame($this->frame($session, true, false), $this->actingAs($this->worker)
            ->get('/hr/my/profile')->assertOk()->inertiaProps('myHr.attendance'));
    }

    #[DataProvider('inconsistentBreaks')]
    public function test_conflicting_break_evidence_is_unavailable(string $scenario): void
    {
        $session = $this->session(['break_started_at' => $scenario === 'stray' ? null : now()->subMinutes(5)]);
        if ($scenario !== 'missing') {
            $this->event($session, ['started_at' => $scenario === 'mismatch' ? now()->subMinutes(10) : now()->subMinutes(5)]);
        }
        if ($scenario === 'multiple') {
            $this->event($session);
        }
        $this->entry($session);

        $this->assertState('unavailable');
    }

    public static function inconsistentBreaks(): array
    {
        return [['missing'], ['mismatch'], ['multiple'], ['stray']];
    }

    public function test_a_foreign_site_session_is_unavailable_instead_of_becoming_entry_only(): void
    {
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $session = $this->session(['site_id' => $other->id]);
        $this->entry($session);

        $this->assertState('unavailable');
    }

    public function test_an_archived_captured_site_is_unavailable(): void
    {
        $this->session();
        $this->site->update(['archived' => true, 'archived_at' => now()]);

        $this->assertState('unavailable');
    }

    public function test_conflicting_current_sessions_are_unavailable_without_choosing_the_first(): void
    {
        $this->session();
        $other = $this->staff($this->site);
        $second = $this->session(['user_id' => $other->id, 'created_by' => $other->id]);
        // Construct historical invalid rows without disabling the model guard.
        DB::table('hr_attendance_sessions')->where('id', $second->id)->update(['user_id' => $this->worker->id]);

        $this->assertState('unavailable');
    }

    public function test_a_closed_row_missing_its_end_is_unavailable_instead_of_absent(): void
    {
        $session = $this->session(['status' => 'closed', 'clock_out_at' => now()->subMinutes(30)]);
        DB::table('hr_attendance_sessions')->where('id', $session->id)->update(['clock_out_at' => null]);

        $this->assertState('unavailable');
    }

    public function test_a_later_shift_reassignment_withholds_the_self_session(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $client->id,
            'site_id' => $this->site->id, 'status' => 'scheduled']);
        $session = $this->session(['shift_id' => $shift->id]);
        $this->entry($session);
        // Historical/current fixture drift: exercise the real read guard.
        DB::table('shifts')->where('id', $shift->id)->update(['user_id' => $this->staff($this->site)->id]);

        $this->assertState('unavailable');
    }

    public function test_a_genuine_unlinked_time_entry_is_entry_only_with_its_original_clock_id(): void
    {
        $entry = $this->entry();
        $response = $this->assertState('entry_only', entry: $entry);

        $this->assertSame($entry->id, $response->inertiaProps('myHr.activeClock.id'));
        $this->assertSame(['id' => $entry->id, 'shift_id' => null,
            'clock_in_at' => $entry->clock_in->toIso8601String(), 'break_minutes' => 7],
            $response->inertiaProps('myHr.attendance.time_entry'));
        $this->assertFalse(HrAttendanceSession::where('user_id', $this->worker->id)->exists());
    }

    public function test_an_active_projection_of_a_closed_session_is_unavailable(): void
    {
        $session = $this->session(['status' => 'closed', 'clock_out_at' => now()->subMinutes(30)]);
        $this->entry($session);

        $this->assertState('unavailable');
    }

    public function test_attendance_source_without_a_canonical_link_is_not_certified_as_entry_only(): void
    {
        $entry = $this->entry();
        $entry->update(['source_type' => 'attendance']);

        $this->assertState('unavailable');
    }

    public function test_multiple_unlinked_entries_are_unavailable_instead_of_arbitrarily_selected(): void
    {
        $this->entry();
        $this->entry();

        $this->assertState('unavailable');
    }

    #[DataProvider('unavailableEntrySites')]
    public function test_an_actual_entry_with_unavailable_site_scope_does_not_become_entry_only(string $scenario): void
    {
        $entry = $this->entry();
        if ($scenario === 'foreign') {
            $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
            $entry->update(['site_id' => $site->id]);
        } elseif ($scenario === 'missing') {
            $entry->update(['site_id' => null]);
        } else {
            $this->site->update(['archived' => true, 'archived_at' => now()]);
        }

        $this->assertState('unavailable');
    }

    public static function unavailableEntrySites(): array
    {
        return [['foreign'], ['missing'], ['archived']];
    }

    public function test_an_unlinked_entry_at_an_assigned_secondary_site_is_still_entry_only(): void
    {
        $secondary = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        HrEmployeeProfile::where('user_id', $this->worker->id)->firstOrFail()->update(['secondary_site_ids' => [$secondary->id]]);
        $entry = $this->entry();
        $entry->update(['site_id' => $secondary->id]);

        $this->assertState('entry_only', entry: $entry);
    }

    public function test_a_genuine_empty_state_does_not_require_a_primary_site(): void
    {
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['primary_site_id' => null]);

        $this->assertState('none');
    }

    public function test_an_unlinked_entry_with_foreign_client_provenance_is_unavailable(): void
    {
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $client = Client::factory()->create(['site_id' => $other->id]);
        $this->entry()->update(['client_id' => $client->id]);

        $this->assertState('unavailable');
    }

    public function test_an_unlinked_entry_with_a_reassigned_shift_is_unavailable(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->staff($this->site)->id, 'client_id' => $client->id,
            'site_id' => $this->site->id, 'status' => 'scheduled']);
        $this->entry()->update(['client_id' => $client->id, 'shift_id' => $shift->id]);

        $this->assertState('unavailable');
    }

    public function test_a_scoped_unlinked_entry_preserves_its_exact_nullable_shift_identity(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $client->id,
            'site_id' => $this->site->id, 'status' => 'scheduled']);
        $entry = $this->entry();
        $entry->update(['client_id' => $client->id, 'shift_id' => $shift->id]);
        $response = $this->assertState('entry_only', entry: $entry);

        $this->assertSame($shift->id, $response->inertiaProps('myHr.attendance.time_entry.shift_id'));
        $this->assertFalse($response->inertiaProps('myHr.attendance.can_break'));
        $this->assertNull($response->inertiaProps('myHr.attendance.session'));
    }

    public function test_another_workers_open_session_is_not_disclosed_or_substituted(): void
    {
        $other = $this->staff($this->site);
        $this->session(['user_id' => $other->id, 'created_by' => $other->id]);

        $this->assertState('none');
    }

    public function test_the_work_timezone_uses_the_existing_app_fallback(): void
    {
        config(['app.worker_timezone' => null, 'app.timezone' => 'UTC']);

        $this->assertState('none', 'UTC');
    }

    public function test_the_existing_current_staff_host_still_denies_an_inactive_employee(): void
    {
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false]);
        $this->actingAs($this->worker)->get('/hr/my/profile')->assertNotFound();
    }

    private function staff(Site $site, array $grants = ['timesheets.create']): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'my-hr-attendance-'.Str::uuid(), 'label' => 'My HR attendance fixture', 'type' => 'custom', 'level' => 10]);
        foreach ($grants as $grant) {
            $permission = Permission::firstOrCreate(['key' => $grant], ['description' => $grant, 'group' => 'attendance', 'module' => 'HR']);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function session(array $attributes = []): HrAttendanceSession
    {
        return HrAttendanceSession::create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'clock_in_at' => now()->subHours(2), 'clock_out_at' => null, 'status' => 'open', 'source' => 'manual',
            'break_minutes' => 7, 'break_count' => 0, 'break_started_at' => null,
            'created_by' => $this->worker->id, ...$attributes])->fresh();
    }

    private function event(HrAttendanceSession $session, array $attributes = []): void
    {
        HrAttendanceBreakEvent::create(['session_id' => $session->id, 'started_at' => $session->break_started_at,
            'ended_at' => null, 'minutes' => 0, 'created_by' => $session->user_id, ...$attributes]);
    }

    private function entry(?HrAttendanceSession $session = null, ?int $id = null): HrTimeEntry
    {
        $entry = new HrTimeEntry(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'shift_id' => $session?->shift_id, 'attendance_session_id' => $session?->id,
            'entry_date' => now()->toDateString(), 'clock_in' => now()->subHours(2), 'clock_out' => null,
            'entry_type' => 'clock', 'status' => 'active', 'notes' => 'Existing projection notes', 'break_minutes' => 7,
            'source_type' => $session ? 'attendance' : 'manual', 'source_id' => $session?->id,
            'created_by' => $this->worker->id]);
        if ($id !== null) {
            $entry->id = $id;
        }
        $entry->saveOrFail();

        return $entry->fresh();
    }

    private function frame(HrAttendanceSession $session, bool $canBreak = true, ?bool $canClockOut = null): array
    {
        return ['schema_version' => 1, 'scope' => 'my_hr_self_attendance', 'state' => 'open',
            'actor_id' => $this->worker->id, 'timezone' => 'Pacific/Auckland', 'can_break' => $canBreak,
            'can_clock_out' => $canClockOut ?? $canBreak,
            'session' => ['id' => $session->id, 'shift_id' => $session->shift_id,
                'clock_in_at' => $session->clock_in_at->toIso8601String(),
                'is_on_break' => $session->break_started_at !== null,
                'break_started_at' => $session->break_started_at?->toIso8601String(),
                'break_minutes' => $session->break_minutes, 'break_count' => $session->break_count], 'time_entry' => null];
    }

    private function assertState(string $state, string $timezone = 'Pacific/Auckland', ?HrTimeEntry $entry = null)
    {
        $response = $this->actingAs($this->worker)->get('/hr/my/profile')->assertOk();
        $this->assertSame(['schema_version' => 1, 'scope' => 'my_hr_self_attendance', 'state' => $state,
            'actor_id' => $this->worker->id, 'timezone' => $timezone, 'can_break' => false, 'can_clock_out' => false, 'session' => null,
            'time_entry' => $entry ? ['id' => $entry->id, 'shift_id' => $entry->shift_id,
                'clock_in_at' => $entry->clock_in->toIso8601String(), 'break_minutes' => $entry->break_minutes] : null],
            $response->inertiaProps('myHr.attendance'));

        return $response;
    }

    private function recordedRows(): array
    {
        return [DB::table('hr_attendance_sessions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('hr_attendance_break_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('hr_time_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
