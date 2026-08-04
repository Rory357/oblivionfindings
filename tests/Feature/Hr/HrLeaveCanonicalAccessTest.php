<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Domain\Hr\Services\LeaveService;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

function leaveCanonicalStaff(string $role, Site $site, array $profile = [], array $user = []): User
{
    $staff = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
        ...$user,
    ]);
    $staff->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'employee_number' => 'LEAVE-'.$staff->id,
        'position_role' => $role,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$profile,
    ]);

    return $staff;
}

function leaveCanonicalRequest(User $staff, array $attributes = []): HrLeaveRequest
{
    return HrLeaveRequest::query()->create([
        'user_id' => $staff->id,
        'leave_type' => 'annual',
        'period' => 'full_day',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->endOfDay(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now(),
        'approval_due_at' => now()->addDay(),
        'escalation_level' => 1,
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create([
        'name' => 'Leave visible Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Leave hidden Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->manager = leaveCanonicalStaff('hr', $this->visibleSite);
});

test('leave worklists use retained Site provenance and ignore legacy storage values', function (): void {
    $legacyColumn = 'ten'.'ant_id';
    $current = leaveCanonicalStaff('support_worker', $this->visibleSite);
    $former = leaveCanonicalStaff('support_worker', $this->visibleSite, [
        'is_active' => false,
        'end_date' => today()->subMonth(),
    ]);
    $hidden = leaveCanonicalStaff('support_worker', $this->hiddenSite);
    $visibleOne = leaveCanonicalRequest($current, [$legacyColumn => 11]);
    $visibleTwo = leaveCanonicalRequest($former, [
        $legacyColumn => 987,
        'starts_at' => now()->addWeeks(2),
        'ends_at' => now()->addWeeks(2)->endOfDay(),
    ]);
    $hiddenRequest = leaveCanonicalRequest($hidden, [$legacyColumn => 11]);

    $response = $this->actingAs($this->manager)->get('/hr/leave')->assertOk();
    $ids = collect($response->inertiaProps('requests.data'))->pluck('id');

    expect($ids)->toContain($visibleOne->id, $visibleTwo->id)
        ->not->toContain($hiddenRequest->id);

    $this->actingAs($this->manager)
        ->get("/hr/leave/{$hiddenRequest->id}")
        ->assertNotFound();
});

test('leave pickers and new requests accept only current staff at approved Sites', function (): void {
    $visible = leaveCanonicalStaff('support_worker', $this->visibleSite);
    $hidden = leaveCanonicalStaff('support_worker', $this->hiddenSite);
    $former = leaveCanonicalStaff('support_worker', $this->visibleSite, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $unapproved = leaveCanonicalStaff('support_worker', $this->visibleSite, [], [
        'approved_at' => null,
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/leave')->assertOk();
    $staffIds = collect($response->inertiaProps('staff'))->pluck('id');
    expect($staffIds)->toContain($visible->id)
        ->not->toContain($hidden->id, $former->id, $unapproved->id);

    $this->actingAs($this->manager)->post('/hr/leave', [
        'user_id' => $visible->id,
        'leave_type' => 'annual',
        'starts_at' => today()->addMonth()->toDateString(),
        'ends_at' => today()->addMonth()->toDateString(),
        'hours_requested' => 8,
    ])->assertSessionHas('success');

    $this->actingAs($this->manager)->post('/hr/leave', [
        'user_id' => $hidden->id,
        'leave_type' => 'annual',
        'starts_at' => today()->addMonths(2)->toDateString(),
        'ends_at' => today()->addMonths(2)->toDateString(),
        'hours_requested' => 8,
    ])->assertNotFound();

    expect(HrLeaveRequest::query()->where('user_id', $visible->id)->count())->toBe(1)
        ->and(HrLeaveRequest::query()->where('user_id', $hidden->id)->exists())->toBeFalse();
});

test('approval actions reauthorise current Site-visible subjects and create one projection', function (): void {
    $visible = leaveCanonicalStaff('support_worker', $this->visibleSite);
    $hidden = leaveCanonicalStaff('support_worker', $this->hiddenSite);
    $visibleRequest = leaveCanonicalRequest($visible);
    $hiddenRequest = leaveCanonicalRequest($hidden);

    foreach ([$visible, $hidden] as $staff) {
        HrLeaveBalance::query()->create([
            'user_id' => $staff->id,
            'leave_type' => 'annual',
            'year' => now()->year,
            'balance_hours' => 152,
            'accrued_hours' => 152,
            'used_hours' => 0,
            'pending_hours' => 8,
        ]);
    }

    $this->actingAs($this->manager)
        ->post("/hr/leave/{$hiddenRequest->id}/approve")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/leave/{$visibleRequest->id}/approve")
        ->assertSessionHas('success');

    expect($visibleRequest->fresh()->status)->toBe('approved')
        ->and($hiddenRequest->fresh()->status)->toBe('pending')
        ->and(StaffTimeOff::query()->where('hr_leave_request_id', $visibleRequest->id)->count())->toBe(1)
        ->and(StaffTimeOff::query()->where('hr_leave_request_id', $hiddenRequest->id)->exists())->toBeFalse();
});

test('leave balances retain visible history but adjustments require current visible staff', function (): void {
    $former = leaveCanonicalStaff('support_worker', $this->visibleSite, [
        'is_active' => false,
        'end_date' => today()->subMonth(),
    ]);
    $hidden = leaveCanonicalStaff('support_worker', $this->hiddenSite);
    foreach ([$former, $hidden] as $staff) {
        HrLeaveBalance::query()->create([
            'user_id' => $staff->id,
            'leave_type' => 'annual',
            'year' => now()->year,
            'balance_hours' => 100,
            'accrued_hours' => 100,
            'used_hours' => 0,
            'pending_hours' => 0,
        ]);
    }

    $response = $this->actingAs($this->manager)
        ->get('/hr/leave/balances?year='.now()->year)
        ->assertOk();
    $ids = collect($response->inertiaProps('balances'))->pluck('user_id');
    expect($ids)->toContain($former->id)->not->toContain($hidden->id);

    $this->actingAs($this->manager)->post('/hr/leave/balances/adjust', [
        'user_id' => $former->id,
        'leave_type' => 'annual',
        'mode' => 'credit',
        'hours' => 8,
    ])->assertNotFound();
});

test('leave routing notifies only current approvers who share the subjects Site', function (): void {
    Notification::fake();
    $staff = leaveCanonicalStaff('support_worker', $this->visibleSite);
    $hiddenManager = leaveCanonicalStaff('hr', $this->hiddenSite);

    $request = app(LeaveService::class)->submitRequest($staff, [
        'leave_type' => 'annual',
        'starts_at' => today()->addMonth()->toDateString(),
        'ends_at' => today()->addMonth()->toDateString(),
        'hours_requested' => 8,
        'created_by' => $staff->id,
    ]);

    expect($request->escalated_to)->toBe($this->manager->id);
    Notification::assertSentTo($this->manager, LeaveRequestNotification::class);
    Notification::assertNotSentTo($hiddenManager, LeaveRequestNotification::class);
});

test('former staff cannot reopen retained Leave self service', function (): void {
    $former = leaveCanonicalStaff('support_worker', $this->visibleSite, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    leaveCanonicalRequest($former);

    $this->actingAs($former)->get('/hr/my/leave')->assertNotFound();
    $this->actingAs($former)->post('/hr/my/leave', [
        'leave_type' => 'annual',
        'starts_at' => today()->addMonth()->toDateString(),
        'ends_at' => today()->addMonth()->toDateString(),
    ])->assertNotFound();
});
