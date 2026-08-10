<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('public');
});

function migrationPhotoProfile(): HrEmployeeProfile
{
    $user = User::factory()->create(['approved_at' => now()]);

    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-PHOTO-MIG-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'profile_photo_path' => null,
    ]);
}

function setCanonicalMigrationPhotoPath(HrEmployeeProfile $profile, string $filename = 'legacy.jpg'): string
{
    $path = "hr/photos/{$profile->id}/{$filename}";
    $profile->update(['profile_photo_path' => $path]);

    return $path;
}

test('the command migrates a canonical legacy public photo to private storage idempotently', function () {
    $profile = migrationPhotoProfile();
    $path = setCanonicalMigrationPhotoPath($profile);
    Storage::disk('public')->put($path, 'legacy profile photo');

    $this->artisan('hr:profile-photos:migrate-private')->assertSuccessful();

    expect($profile->fresh()->profile_photo_path)->toBe($path)
        ->and(Storage::disk('private')->get($path))->toBe('legacy profile photo');
    Storage::disk('public')->assertMissing($path);

    $this->artisan('hr:profile-photos:migrate-private')->assertSuccessful();
    Storage::disk('private')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

test('the command leaves conflicting public and private objects untouched and fails closed', function () {
    $profile = migrationPhotoProfile();
    $path = setCanonicalMigrationPhotoPath($profile, 'conflict.jpg');
    Storage::disk('public')->put($path, 'legacy public bytes');
    Storage::disk('private')->put($path, 'different private bytes');

    $this->artisan('hr:profile-photos:migrate-private')->assertFailed();

    expect(Storage::disk('public')->get($path))->toBe('legacy public bytes')
        ->and(Storage::disk('private')->get($path))->toBe('different private bytes')
        ->and($profile->fresh()->profile_photo_path)->toBe($path);
});

test('the command fails closed on a noncanonical referenced path without moving unrelated objects', function () {
    $profile = migrationPhotoProfile();
    $unsafe = 'hr/photos/999999/unsafe.jpg';
    $profile->update(['profile_photo_path' => $unsafe]);
    Storage::disk('public')->put($unsafe, 'another profile photo');

    $this->artisan('hr:profile-photos:migrate-private')->assertFailed();

    Storage::disk('public')->assertExists($unsafe);
    Storage::disk('private')->assertMissing($unsafe);
});

test('the command fails closed when a canonical referenced object is missing from both disks', function () {
    $profile = migrationPhotoProfile();
    $path = setCanonicalMigrationPhotoPath($profile, 'missing.jpg');

    $this->artisan('hr:profile-photos:migrate-private')->assertFailed();

    Storage::disk('public')->assertMissing($path);
    Storage::disk('private')->assertMissing($path);
    expect($profile->fresh()->profile_photo_path)->toBe($path);
});

test('the command reports unreferenced public HR photo residue and never deletes it ambiguously', function () {
    $orphan = 'hr/photos/987654/unreferenced.jpg';
    Storage::disk('public')->put($orphan, 'unreferenced public bytes');

    $this->artisan('hr:profile-photos:migrate-private')
        ->expectsOutputToContain('public_residue=1')
        ->assertFailed();

    Storage::disk('public')->assertExists($orphan);
    Storage::disk('private')->assertMissing($orphan);
});

test('the explicit rollback command restores verified legacy compatibility idempotently', function () {
    $profile = migrationPhotoProfile();
    $path = setCanonicalMigrationPhotoPath($profile, 'rollback.jpg');
    Storage::disk('private')->put($path, 'private profile photo');

    $this->artisan('hr:profile-photos:migrate-private', ['--rollback' => true])
        ->expectsOutputToContain('public_residue=skipped')
        ->assertSuccessful();

    expect(Storage::disk('public')->get($path))->toBe('private profile photo');
    Storage::disk('private')->assertMissing($path);

    $this->artisan('hr:profile-photos:migrate-private', ['--rollback' => true])->assertSuccessful();
    Storage::disk('public')->assertExists($path);
    Storage::disk('private')->assertMissing($path);
});
