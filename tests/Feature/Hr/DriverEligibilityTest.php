<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    // hr.driver.* is granted to provider_manager via RbacSeeder.
    $this->manager = User::factory()->create([
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->manager->id,
        'employee_number' => 'EMP-'.$this->manager->id,
        'work_email' => $this->manager->email,
        'position_title' => 'Provider Manager',
        'position_role' => 'provider_manager',
        'employment_type' => 'full_time',
        'primary_site_id' => $this->site->id,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $this->site->id,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

function makeDriverRecord(int $userId, array $overrides = []): HrDriverEligibility
{
    return HrDriverEligibility::query()->create(array_merge([
        'user_id' => $userId,
        'licence_number' => 'DL12345',
        'licence_class' => 'Class 1',
        'licence_expires_at' => now()->addYear()->toDateString(),
        'status' => 'pending_review',
    ], $overrides));
}

function makeDriverStaffAtSite(Site $site, array $profileOverrides = []): User
{
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create(array_merge([
        'user_id' => $staff->id,
        'employee_number' => 'DRIVER-SITE-'.$staff->id,
        'work_email' => $staff->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ], $profileOverrides));

    return $staff;
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

test('changing approved licence evidence revokes driving until the record is reviewed again', function () {
    $record = makeDriverRecord($this->worker->id, [
        'status' => 'eligible',
        'can_drive_clients' => true,
        'can_drive_clients_approved_by' => $this->manager->id,
        'can_drive_clients_approved_at' => now()->subMonth(),
    ]);
    $this->profile->update(['can_drive_clients' => true]);

    $this->actingAs($this->manager)
        ->put("/hr/compliance/drivers/{$record->id}", [
            'licence_expires_at' => now()->subDay()->toDateString(),
        ])
        ->assertSessionHas('success');

    expect($record->fresh())
        ->status->toBe('expired')
        ->can_drive_clients->toBeFalse()
        ->and($this->profile->fresh()->can_drive_clients)->toBeFalse();

    $record->update([
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);
    $this->profile->update(['can_drive_clients' => true]);

    $this->actingAs($this->manager)
        ->put("/hr/compliance/drivers/{$record->id}", [
            'licence_number' => 'RENEWED-EVIDENCE',
            'licence_expires_at' => now()->addYears(2)->toDateString(),
        ])
        ->assertSessionHas('success');

    expect($record->fresh())
        ->status->toBe('pending_review')
        ->can_drive_clients->toBeFalse()
        ->and($this->profile->fresh()->can_drive_clients)->toBeFalse();
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

test('driver rows summary and staff picker share the current accessible Site boundary', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $secondaryStaff = makeDriverStaffAtSite($otherSite, [
        'secondary_site_ids' => [$this->site->id],
    ]);
    $hiddenStaff = makeDriverStaffAtSite($otherSite);
    $inactiveStaff = makeDriverStaffAtSite($this->site, ['is_active' => false]);

    $visibleRecord = makeDriverRecord($this->worker->id);
    $secondaryRecord = makeDriverRecord($secondaryStaff->id, ['licence_number' => 'SECONDARY']);
    $hiddenRecord = makeDriverRecord($hiddenStaff->id, ['licence_number' => 'HIDDEN']);
    makeDriverRecord($inactiveStaff->id, ['licence_number' => 'INACTIVE']);

    $response = $this->actingAs($this->manager)->get('/hr/compliance/drivers');
    $response->assertOk();

    expect(collect($response->inertiaProps('records.data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$visibleRecord->id, $secondaryRecord->id])
        ->not->toContain($hiddenRecord->id)
        ->and($response->inertiaProps('summary.total'))->toBe(2)
        ->and(collect($response->inertiaProps('employees'))->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->manager->id, $this->worker->id, $secondaryStaff->id]);
});

test('driver direct objects and create selections conceal hidden Site staff', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $hiddenStaff = makeDriverStaffAtSite($otherSite);
    $hiddenRecord = makeDriverRecord($hiddenStaff->id, ['licence_number' => 'HIDDEN-DIRECT']);

    $this->actingAs($this->manager)
        ->get("/hr/compliance/drivers/{$hiddenRecord->id}")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->put("/hr/compliance/drivers/{$hiddenRecord->id}", ['notes' => 'Must not write'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compliance/drivers/{$hiddenRecord->id}/approve")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compliance/drivers/{$hiddenRecord->id}/suspend", [
            'suspension_reason' => 'Must not write',
        ])
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->post('/hr/compliance/drivers', [
            'user_id' => $hiddenStaff->id,
            'licence_number' => 'FORGED-HIDDEN',
            'licence_class' => 'Class 1',
            'licence_expires_at' => now()->addYear()->toDateString(),
        ])
        ->assertSessionHasErrors('user_id');

    expect($hiddenRecord->fresh()->notes)->toBeNull()
        ->and($hiddenRecord->fresh()->status)->toBe('pending_review');
    $this->assertDatabaseMissing('hr_driver_eligibility', ['licence_number' => 'FORGED-HIDDEN']);
});

test('driver creation reauthorises the staff Site boundary after the shared People lock', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $locks = new class extends PeopleMutationLockService
    {
        public ?Closure $beforeLock = null;

        public function lock(
            iterable $userIds,
            iterable $profileIds = [],
            iterable $additionalRoleIds = [],
        ): array {
            if ($this->beforeLock) {
                ($this->beforeLock)();
                $this->beforeLock = null;
            }

            return parent::lock($userIds, $profileIds, $additionalRoleIds);
        }
    };
    $locks->beforeLock = function () use ($otherSite): void {
        $this->profile->update([
            'primary_site_id' => $otherSite->id,
            'secondary_site_ids' => [],
        ]);
    };
    $this->app->instance(PeopleMutationLockService::class, $locks);

    $this->actingAs($this->manager)
        ->post('/hr/compliance/drivers', [
            'user_id' => $this->worker->id,
            'licence_number' => 'RACE-BLOCKED',
            'licence_class' => 'Class 1',
            'licence_expires_at' => now()->addYear()->toDateString(),
        ])
        ->assertSessionHasErrors('user_id');

    $this->assertDatabaseMissing('hr_driver_eligibility', ['licence_number' => 'RACE-BLOCKED']);
});
