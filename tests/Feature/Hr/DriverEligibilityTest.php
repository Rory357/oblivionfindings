<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    // hr.driver.* is granted to provider_manager via RbacSeeder.
    $this->manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

function makeDriverRecord(int $userId, array $overrides = []): HrDriverEligibility
{
    return HrDriverEligibility::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $userId,
        'licence_number' => 'DL12345',
        'licence_class' => 'Class 1',
        'licence_expires_at' => now()->addYear()->toDateString(),
        'status' => 'pending_review',
    ], $overrides));
}

test('the drivers index ships employees and the manage flag', function () {
    $response = $this->actingAs($this->manager)->get('/hr/compliance/drivers');
    $response->assertOk();

    $userIds = collect($response->inertiaProps('employees'))->pluck('user_id')->all();
    expect($userIds)->toContain($this->worker->id);
    expect($response->inertiaProps('can.manage'))->toBeTrue();
});

test('a driver eligibility record can be created from the UI endpoint', function () {
    $response = $this->actingAs($this->manager)->post('/hr/compliance/drivers', [
        'user_id' => $this->worker->id,
        'licence_number' => 'DL98765',
        'licence_class' => 'Class 2',
        'licence_endorsements' => ['P'],
        'licence_expires_at' => now()->addYear()->toDateString(),
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_driver_eligibility', [
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'licence_number' => 'DL98765',
        'status' => 'pending_review',
    ]);
});

test('approving a driver marks them eligible and updates the profile flag', function () {
    $record = makeDriverRecord($this->worker->id);

    $this->actingAs($this->manager)
        ->post("/hr/compliance/drivers/{$record->id}/approve")
        ->assertSessionHas('success');

    expect($record->fresh()->status)->toBe('eligible');
    expect((bool) $record->fresh()->can_drive_clients)->toBeTrue();
    expect((bool) $this->profile->fresh()->can_drive_clients)->toBeTrue();
});

test('suspending a driver requires a reason and clears eligibility', function () {
    $record = makeDriverRecord($this->worker->id, [
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    // Missing reason → validation error.
    $this->actingAs($this->manager)
        ->post("/hr/compliance/drivers/{$record->id}/suspend", [])
        ->assertSessionHasErrors('suspension_reason');

    $this->actingAs($this->manager)
        ->post("/hr/compliance/drivers/{$record->id}/suspend", [
            'suspension_reason' => 'Licence under review',
        ])
        ->assertSessionHas('success');

    expect($record->fresh()->status)->toBe('suspended');
    expect((bool) $record->fresh()->can_drive_clients)->toBeFalse();
});

test('a user without hr.driver.manage cannot create a driver record', function () {
    $this->actingAs($this->worker)->post('/hr/compliance/drivers', [
        'user_id' => $this->worker->id,
        'licence_number' => 'DL00000',
        'licence_class' => 'Class 1',
        'licence_expires_at' => now()->addYear()->toDateString(),
    ])->assertForbidden();

    $this->assertDatabaseMissing('hr_driver_eligibility', [
        'licence_number' => 'DL00000',
    ]);
});
