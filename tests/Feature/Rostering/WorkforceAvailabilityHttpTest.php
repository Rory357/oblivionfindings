<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkforceAvailabilityHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-04 11:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_availability_only_self_service_has_a_working_entry_and_denies_other_workers(): void
    {
        [$actor, $site] = $this->actor(['staff.availability.updateSelf']);
        [$other] = $this->actor([], $site);
        $this->actingAs($actor)->get(route('operations.availability.index', ['week' => '2026-10-12']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/availability')->where('user.id', $actor->id)
                ->where('canManage', true)->where('week', '2026-10-12')->where('workerTimezone', 'Pacific/Auckland')->has('staffOptions', 0));
        $this->get(route('staff.availability.index', $other))->assertForbidden();
        $this->postJson(route('staff.availability.store', $other), $this->window())->assertForbidden();
    }

    public function test_any_availability_authority_is_site_scoped_and_can_manage_self_without_self_permission(): void
    {
        [$actor, $site] = $this->actor(['staff.availability.updateAny']);
        [$other] = $this->actor([], $site);
        [$outside] = $this->actor([]);
        $this->actingAs($actor)->get(route('operations.availability.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('staffOptions', 2));
        $this->get(route('operations.availability.index', ['staff_id' => $other->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('user.id', $other->id)->where('canManage', true));
        $this->get(route('staff.availability.index', $outside))->assertForbidden();
        $this->postJson(route('staff.availability.store', $outside), $this->window())->assertForbidden();
        $slot = StaffAvailability::query()->create(['user_id' => $outside->id, ...$this->window()]);
        $this->deleteJson(route('staff.availability.destroy', [$outside, $slot]))->assertForbidden();
        $this->deleteJson(route('staff.availability.destroy', [$other, $slot]))->assertNotFound();
        $this->assertDatabaseHas('staff_availabilities', ['id' => $slot->id]);
    }

    public function test_staff_read_permission_provides_read_only_availability_at_approved_sites(): void
    {
        [$actor, $site] = $this->actor(['staff.viewAny']);
        [$other] = $this->actor([], $site);
        $this->actingAs($actor)->get(route('staff.availability.index', $other))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('staffOptions', 2));
        $this->postJson(route('staff.availability.store', $other), $this->window())->assertForbidden();
    }

    public function test_overnight_window_is_saved_read_and_removed_as_one_record(): void
    {
        [$actor] = $this->actor(['staff.availability.updateSelf']);
        $this->actingAs($actor)->post(route('staff.availability.store', $actor), $this->window())->assertRedirect();
        $slot = StaffAvailability::query()->sole();
        $this->assertSame(1, $slot->day_of_week);
        $this->assertTrue($slot->ends_next_day);
        $this->assertSame('21:00:00', $slot->starts_at);
        $this->assertSame('07:00:00', $slot->ends_at);
        $this->get(route('staff.availability.index', $actor))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('availability', 1)->where('availability.0.ends_next_day', true)->where('availability.0.starts_at', '21:00')->where('availability.0.ends_at', '07:00'));
        $this->delete(route('staff.availability.destroy', [$actor, $slot]))->assertRedirect();
        $this->assertDatabaseCount('staff_availabilities', 0);
    }

    public function test_explicit_next_day_validation_rejects_zero_length_and_more_than_one_day(): void
    {
        [$actor] = $this->actor(['staff.availability.updateSelf']);
        $url = route('staff.availability.store', $actor);
        $this->actingAs($actor)->postJson($url, [...$this->window(), 'ends_next_day' => false])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson($url, [...$this->window(), 'starts_at' => '09:00', 'ends_at' => '17:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson($url, [...$this->window(), 'ends_at' => '21:00', 'ends_next_day' => false])->assertUnprocessable();
        $this->post($url, [...$this->window(), 'ends_at' => '21:00'])->assertRedirect();
        $this->assertDatabaseCount('staff_availabilities', 1);
    }

    public function test_roster_self_availability_uses_exact_capabilities_without_shift_management(): void
    {
        [$actor, $site] = $this->actor(['rostering.viewAny', 'staff.availability.updateSelf']);
        $this->actor([], $site);
        $this->actingAs($actor)->get(route('operations.rostering.index', ['tab' => 'availability']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('availabilityCapabilities', ['view_any' => false, 'update_any' => false, 'update_self' => true])
                ->has('staffAvailabilitySummary.staff', 1)->where('staffAvailabilitySummary.staff.0.id', $actor->id)
                ->where('staffAvailabilitySummary.staff.0.can_manage', true)->has('staff', 0));
    }

    public function test_roster_availability_coordinator_filters_site_and_masks_sensitive_leave_type(): void
    {
        [$actor, $site] = $this->actor(['rostering.viewAny', 'staff.availability.updateAny']);
        [$worker] = $this->actor([], $site);
        [, $otherSite] = $this->actor([]);
        $actor->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $leave = HrLeaveRequest::factory()->create(['user_id' => $worker->id, 'leave_type' => 'family_violence', 'reason' => 'Private details',
            'status' => 'approved', 'starts_at' => '2026-10-05 00:00:00', 'ends_at' => '2026-10-06 00:00:00']);
        $off = StaffTimeOff::create(['user_id' => $worker->id, 'hr_leave_request_id' => $leave->id,
            'starts_at' => $leave->starts_at, 'ends_at' => $leave->ends_at, 'type' => 'family_violence',
            'label' => 'Family violence', 'notes' => 'Private review notes', 'created_by' => $actor->id]);
        $leave->update(['time_off_id' => $off->id]);
        StaffAvailability::query()->create(['user_id' => $worker->id, ...$this->window()]);
        $this->actingAs($actor)->get(route('operations.rostering.index', ['tab' => 'availability', 'site_id' => $site->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('availabilityCapabilities.view_any', true)
                ->has('staffAvailabilitySummary.staff', 2)->where('staffAvailabilitySummary.upcomingLeave.'.$worker->id.'.0.leave_type', 'leave')
                ->where('staffAvailabilitySummary.staff', fn ($rows) => collect($rows)->firstWhere('id', $worker->id)['staff_availability'][0]['ends_next_day'] === true)
                ->where('staffAvailabilitySummary.staff', fn ($rows) => collect($rows)->firstWhere('id', $worker->id)['staff_time_off'][0]['reason'] === 'Leave')
                ->where('weekStart', '2026-10-05')->where('weekEnd', '2026-10-12')->where('workerTimezone', 'Pacific/Auckland')
                ->missing('staffAvailabilitySummary.upcomingLeave.'.$worker->id.'.0.reason'));
        $this->assertSame('Family violence', $off->fresh()->label);
        $this->assertSame('Private review notes', $off->fresh()->notes);
    }

    public function test_scheduler_availability_redirect_retains_worker_and_week_context(): void
    {
        [$actor, $site] = $this->actor(['rostering.viewAny', 'staff.availability.updateAny']);
        [$worker] = $this->actor([], $site);
        $this->actingAs($actor)->get(route('staff.availability.index', ['user' => $worker->id, 'week' => '2026-10-12']))
            ->assertRedirect(route('operations.rostering.index', ['tab' => 'availability', 'staff_id' => $worker->id, 'week' => '2026-10-12']));
    }

    #[DataProvider('sensitiveReaders')]
    public function test_sensitive_linked_time_off_labels_preserve_the_exact_owner_and_hr_read_exemptions(string $reader): void
    {
        [$actor, $site] = $this->actor(['rostering.viewAny', 'staff.availability.updateAny',
            ...($reader === 'hr' ? ['hr.leave.manage'] : [])]);
        [$worker] = $this->actor([], $site);
        $subject = $reader === 'owner' ? $actor : $worker;
        foreach (['sick', 'family_violence'] as $type) {
            $leave = HrLeaveRequest::factory()->create(['user_id' => $subject->id, 'leave_type' => $type,
                'reason' => 'Private reason must not appear', 'status' => 'approved',
                'starts_at' => '2026-10-05 00:00:00', 'ends_at' => '2026-10-06 00:00:00']);
            $off = StaffTimeOff::create(['user_id' => $subject->id, 'hr_leave_request_id' => $leave->id,
                'starts_at' => $leave->starts_at, 'ends_at' => $leave->ends_at, 'type' => $type,
                'label' => ucfirst(str_replace('_', ' ', $type)), 'notes' => 'Private notes must not appear',
                'created_by' => $actor->id]);
            $leave->update(['time_off_id' => $off->id]);
        }

        $this->actingAs($actor)->get(route('operations.rostering.index', [
            'tab' => 'availability', 'staff_id' => $subject->id, 'week' => '2026-10-05',
        ]))->assertInertia(fn (Assert $page) => $page->has('staffAvailabilitySummary.staff', 1)
            ->where('staffAvailabilitySummary.staff.0.id', $subject->id)
            ->where('staffAvailabilitySummary.staff.0.staff_time_off', function ($rows) use ($reader): bool {
                $reasons = collect($rows)->pluck('reason')->sort()->values()->all();

                return $reasons === ($reader === 'coordinator' ? ['Leave', 'Leave'] : ['Family violence', 'Sick']);
            })
            ->where('staffAvailabilitySummary.upcomingLeave.'.$subject->id, function ($rows) use ($reader): bool {
                $types = collect($rows)->pluck('leave_type')->sort()->values()->all();

                return $types === ($reader === 'coordinator' ? ['leave', 'leave'] : ['family_violence', 'sick']);
            })
            ->where('staffAvailabilitySummary', fn ($summary) => ! str_contains(json_encode($summary), 'Private')));
    }

    public static function sensitiveReaders(): array
    {
        return ['own record' => ['owner'], 'HR manager' => ['hr'], 'site coordinator' => ['coordinator']];
    }

    public function test_availability_week_filters_use_configured_local_half_open_bounds_without_exposing_other_workers(): void
    {
        config(['app.worker_timezone' => 'America/New_York']);
        [$actor, $site] = $this->actor(['rostering.viewAny', 'staff.availability.updateAny']);
        [$worker] = $this->actor([], $site);
        [$outside] = $this->actor([]);
        $inside = [];
        foreach ([
            ['2026-10-05 03:59:00', '2026-10-05 04:01:00', true],
            ['2026-10-04 04:00:00', '2026-10-05 04:00:00', false],
            ['2026-10-12 04:00:00', '2026-10-12 05:00:00', false],
            ['2026-10-12 03:59:00', '2026-10-12 04:01:00', true],
        ] as [$start, $end, $included]) {
            $off = StaffTimeOff::create(['user_id' => $worker->id, 'starts_at' => $start, 'ends_at' => $end,
                'type' => 'unavailable', 'label' => 'Boundary evidence', 'created_by' => $actor->id]);
            if ($included) {
                $inside[] = $off->id;
            }
        }
        StaffTimeOff::create(['user_id' => $outside->id, 'starts_at' => '2026-10-06 04:00:00',
            'ends_at' => '2026-10-06 05:00:00', 'type' => 'unavailable', 'label' => 'Hidden worker',
            'created_by' => $outside->id]);
        $this->actingAs($actor)->get(route('operations.rostering.index', [
            'tab' => 'availability', 'staff_id' => $worker->id, 'site_id' => $site->id, 'week' => '2026-10-07',
        ]))->assertInertia(fn (Assert $page) => $page->where('workerTimezone', 'America/New_York')
            ->where('weekStart', '2026-10-05')->where('weekEnd', '2026-10-12')->where('filters.week', '2026-10-05')
            ->has('staffAvailabilitySummary.staff', 1)->where('staffAvailabilitySummary.staff.0.id', $worker->id)
            ->where('staffAvailabilitySummary.staff.0.staff_time_off', fn ($rows) => collect($rows)->pluck('id')->all() === $inside));
    }

    private function window(): array
    {
        return ['day_of_week' => 1, 'starts_at' => '21:00', 'ends_at' => '07:00', 'ends_next_day' => true];
    }

    private function actor(array $permissions, ?Site $site = null): array
    {
        $site ??= Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true]);
        $role = Role::query()->create(['name' => 'availability-test-'.uniqid(), 'label' => 'Availability test', 'level' => 10, 'type' => 'custom']);
        $role->permissions()->sync(collect($permissions)->map(fn ($key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $user->roles()->attach($role);

        return [$user, $site];
    }
}
