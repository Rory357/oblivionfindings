<?php

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

        $profile = $user->hrEmployeeProfile;
        expect($profile)->not()->toBeNull();
        expect((int) $profile->tenant_id)->toBe(1);
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
