<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemUsersSeeder;

test('system users seeder creates hr employee profiles for all seeded staff records', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SystemUsersSeeder::class);

    $staffRecords = Staff::query()->with('user.hrEmployeeProfile')->get();

    expect($staffRecords->count())->toBeGreaterThan(0);

    foreach ($staffRecords as $staff) {
        $user = $staff->user;
        expect($user)->not()->toBeNull();
        expect($user->hasVerifiedEmail())->toBeTrue();

        $profile = $user->hrEmployeeProfile;
        expect($profile)->not()->toBeNull();
        expect((int) $profile->user_id)->toBe((int) $user->id);
        expect((string) $profile->work_email)->toBe((string) $user->email);
        expect((string) $profile->employee_number)->toBe((string) $staff->employee_id);
        expect((string) $profile->position_role)->toBe((string) $user->role);
    }

    $seededSupportWorkers = User::query()
        ->where('email', 'like', 'sw%@demo.test')
        ->with('hrEmployeeProfile')
        ->get();

    expect($seededSupportWorkers->count())->toBeGreaterThanOrEqual(8);
    expect($seededSupportWorkers->every(fn (User $user) => $user->hrEmployeeProfile !== null))->toBeTrue();
});

test('system users seeder assigns a deterministic fallback when a generated employee number is already owned', function () {
    $this->seed(RbacSeeder::class);

    $existingOwner = User::factory()->create(['role' => 'client']);
    User::factory()->create(['role' => 'client']);
    $unprofiledStaff = User::factory()->create(['role' => 'support_worker']);

    $generatedEmployeeNumber = 'EMP'.str_pad((string) $unprofiledStaff->id, 4, '0', STR_PAD_LEFT);
    $fallbackEmployeeNumber = 'EMP-U'.str_pad((string) $unprofiledStaff->id, 4, '0', STR_PAD_LEFT);

    HrEmployeeProfile::factory()->create([
        'user_id' => $existingOwner->id,
        'employee_number' => $generatedEmployeeNumber,
    ]);

    $this->seed(SystemUsersSeeder::class);
    $this->seed(SystemUsersSeeder::class);

    $unprofiledStaff->refresh();

    expect($unprofiledStaff->hrEmployeeProfile)->not()->toBeNull()
        ->and($unprofiledStaff->hrEmployeeProfile->employee_number)->toBe($fallbackEmployeeNumber)
        ->and(HrEmployeeProfile::withTrashed()->where('employee_number', $generatedEmployeeNumber)->count())->toBe(1)
        ->and(HrEmployeeProfile::withTrashed()->where('employee_number', $fallbackEmployeeNumber)->count())->toBe(1);
});
