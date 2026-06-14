<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

function photoProfile(User $user): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-PHOTO-' . $user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ]);
}

test('a manager can upload a profile photo', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertRedirect();

    $path = $profile->fresh()->profile_photo_path;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);
});

test('a non-manager cannot upload a photo for someone else', function () {
    Storage::fake('public');

    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($other);

    $this->actingAs($worker)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('x.jpg'),
        ])
        ->assertForbidden();
});
