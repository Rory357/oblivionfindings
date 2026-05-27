<?php

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(Tests\TestCase::class, RefreshDatabase::class);

function userWithRosteringLeavePermissions(): User
{
    $manager = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $role = Role::query()->create([
        'name' => 'rostering-index-leave-test',
        'label' => 'Rostering index leave test',
        'level' => 10,
        'type' => 'custom',
    ]);

    $permissions = collect(['rostering.viewAny', 'shifts.manageAny'])->map(
        fn (string $key) => Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'Rostering',
                'module' => 'operations',
            ],
        ),
    );

    $role->permissions()->sync($permissions->pluck('id'));
    $manager->roles()->attach($role);

    return $manager;
}

it('surfaces pending HR leave overlapping the rostering week', function () {
    $manager = userWithRosteringLeavePermissions();
    $staff = User::factory()->create([
        'organization_id' => 1,
        'name' => 'Ari Kauri',
        'approved_at' => now(),
    ]);

    HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'leave_type' => 'annual',
        'starts_at' => '2026-05-05 09:00:00',
        'ends_at' => '2026-05-06 17:00:00',
        'hours_requested' => 16,
        'reason' => 'Family leave',
        'status' => 'pending',
        'submitted_at' => now(),
        'created_by' => $staff->id,
    ]);

    HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'leave_type' => 'annual',
        'starts_at' => '2026-05-20 09:00:00',
        'ends_at' => '2026-05-21 17:00:00',
        'hours_requested' => 16,
        'reason' => 'Outside week',
        'status' => 'pending',
        'submitted_at' => now(),
        'created_by' => $staff->id,
    ]);

    HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'leave_type' => 'sick',
        'starts_at' => '2026-05-06 09:00:00',
        'ends_at' => '2026-05-06 17:00:00',
        'hours_requested' => 8,
        'reason' => 'Already declined',
        'status' => 'declined',
        'submitted_at' => now(),
        'created_by' => $staff->id,
    ]);

    $this->actingAs($manager)
        ->get(route('operations.rostering.index', ['week' => '2026-05-04']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/rostering/index')
            ->has('pendingLeave', 1)
            ->where('pendingLeave.0.user_id', $staff->id)
            ->where('pendingLeave.0.user', 'Ari Kauri')
            ->where('pendingLeave.0.leave_type', 'annual')
            ->where('pendingLeave.0.reason', 'Family leave')
            ->where('pendingLeave.0.status', 'pending'));
});
