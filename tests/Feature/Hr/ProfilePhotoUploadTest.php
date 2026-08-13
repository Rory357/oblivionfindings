<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrProfilePhotoStorageService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Hr\DirectoryController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

class FailingDirectoryPhotoStoreController extends DirectoryController
{
    protected function storeProfilePhoto(
        UploadedFile $photo,
        int $profileId,
        HrProfilePhotoStorageService $profilePhotos,
    ): string|false {
        return false;
    }
}

class EndingPhotoTargetLockService extends PeopleMutationLockService
{
    public function __construct(private readonly int $targetProfileId) {}

    public function lock(iterable $userIds, iterable $profileIds = []): array
    {
        HrEmployeeProfile::query()->whereKey($this->targetProfileId)->update([
            'end_date' => now()->subDay()->toDateString(),
        ]);

        return parent::lock($userIds, $profileIds);
    }
}

class FailingDirectoryPhotoPersistController extends DirectoryController
{
    protected function persistProfilePhoto(HrEmployeeProfile $profile, string $path): void
    {
        throw new RuntimeException('Forced profile photo persistence failure.');
    }
}

class FailingDirectoryPhotoCleanupLookupController extends DirectoryController
{
    protected function persistedProfilePhotoPath(int $profileId): ?string
    {
        throw new RuntimeException('Forced post-commit photo lookup failure.');
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    Storage::fake('private');
    Storage::fake('public');

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->hr->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.employees.viewAllSites')->firstOrFail()->id => ['allowed' => false],
    ]);
    $this->allowedSite = Site::factory()->create(['name' => 'Allowed Photo Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Photo Site']);
    photoProfile($this->hr, $this->allowedSite, ['position_role' => 'hr']);
});

function photoProfile(User $user, ?Site $site = null, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-PHOTO-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
        ...$overrides,
    ]);
}

test('a manager can upload a profile photo', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertRedirect();

    $path = $profile->fresh()->profile_photo_path;
    expect($path)->not->toBeNull();
    Storage::disk('private')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

test('current staff can upload their own profile photo without a management permission', function () {
    Storage::fake('public');

    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    $profile = photoProfile($worker, $this->allowedSite);

    $this->actingAs($worker)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('self.jpg', 200, 200),
        ])
        ->assertRedirect();

    $path = $profile->fresh()->profile_photo_path;
    Storage::disk('private')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

test('a non-manager cannot upload a photo for someone else', function () {
    Storage::fake('public');

    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    photoProfile($worker, $this->allowedSite);
    $profile = photoProfile($other, $this->allowedSite);

    $this->actingAs($worker)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('x.jpg'),
        ])
        ->assertForbidden();
});

test('hidden and noncurrent photo targets are concealed before file validation', function () {
    Storage::fake('public');

    $hidden = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $hiddenProfile = photoProfile($hidden, $this->hiddenSite);
    $ended = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $endedProfile = photoProfile($ended, $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $future = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $futureProfile = photoProfile($future, $this->allowedSite, [
        'start_date' => now()->addDay()->toDateString(),
    ]);
    $inactive = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $inactiveProfile = photoProfile($inactive, $this->allowedSite, ['is_active' => false]);
    $unapproved = User::factory()->create(['role' => 'support_worker', 'approved_at' => null]);
    $unapprovedProfile = photoProfile($unapproved, $this->allowedSite);
    $client = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
    $clientProfile = photoProfile($client, $this->allowedSite);
    $unproven = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $unprovenProfile = photoProfile($unproven);

    foreach ([$hiddenProfile, $endedProfile, $futureProfile, $inactiveProfile, $unapprovedProfile, $clientProfile, $unprovenProfile] as $concealed) {
        $this->actingAs($this->hr)
            ->post("/hr/directory/{$concealed->id}/photo", [])
            ->assertNotFound();
        expect($concealed->fresh()->profile_photo_path)->toBeNull();
    }

    $this->actingAs($this->hr)
        ->post('/hr/directory/99999999/photo', [])
        ->assertNotFound();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('hidden missing noncurrent and malformed photo IDs have identical responses before validation', function () {
    config(['app.debug' => false]);
    Storage::fake('public');

    $hidden = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $hiddenProfile = photoProfile($hidden, $this->hiddenSite);
    $ended = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $endedProfile = photoProfile($ended, $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $responses = [
        $this->actingAs($this->hr)->postJson("/hr/directory/{$hiddenProfile->id}/photo", []),
        $this->actingAs($this->hr)->postJson("/hr/directory/{$endedProfile->id}/photo", []),
        $this->actingAs($this->hr)->postJson('/hr/directory/99999999/photo', []),
        $this->actingAs($this->hr)->postJson('/hr/directory/not-a-number/photo', []),
        $this->actingAs($this->hr)->postJson('/hr/directory/'.str_repeat('9', 80).'/photo', []),
    ];

    expect(collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);
});

test('an invalid photo payload is validated only after a current visible target is authorised', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [])
        ->assertInvalid('photo');
});

test('replacing a profile photo deletes the superseded file', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);
    $oldPath = "hr/photos/{$profile->id}/legacy.jpg";
    Storage::disk('public')->put($oldPath, 'legacy photo');
    $profile->update(['profile_photo_path' => $oldPath]);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('replacement.png', 200, 200),
        ])
        ->assertRedirect();

    $replacement = $profile->fresh()->profile_photo_path;
    expect($replacement)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('private')->assertExists($replacement);
    Storage::disk('public')->assertMissing($replacement);
});

test('a failed private disk write is rejected without persisting a false path', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);
    $this->app->bind(DirectoryController::class, fn () => new FailingDirectoryPhotoStoreController);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('failed.jpg', 200, 200),
        ])
        ->assertInvalid('photo');

    expect($profile->fresh()->profile_photo_path)->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('replacement never deletes a noncanonical or differently owned prior path', function () {
    Storage::fake('public');

    $other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $otherProfile = photoProfile($other, $this->allowedSite);
    $victimPath = "hr/photos/{$otherProfile->id}/victim.jpg";
    Storage::disk('public')->put($victimPath, 'must survive');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite, [
        'profile_photo_path' => $victimPath,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('safe-replacement.jpg', 200, 200),
        ])
        ->assertRedirect();

    Storage::disk('public')->assertExists($victimPath);
    Storage::disk('private')->assertExists($profile->fresh()->profile_photo_path);
    Storage::disk('public')->assertMissing($profile->fresh()->profile_photo_path);
});

test('photo mutation reauthorises current visibility under the shared People lock', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);
    $this->app->instance(PeopleMutationLockService::class, new EndingPhotoTargetLockService($profile->id));

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('raced.jpg', 200, 200),
        ])
        ->assertNotFound();

    expect($profile->fresh()->profile_photo_path)->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a rolled back photo mutation removes the new file and retains the committed old file', function () {
    Storage::fake('public');

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);
    $oldPath = "hr/photos/{$profile->id}/committed-old.jpg";
    Storage::disk('public')->put($oldPath, 'committed old photo');
    $profile->update(['profile_photo_path' => $oldPath]);
    $this->app->bind(DirectoryController::class, fn () => new FailingDirectoryPhotoPersistController);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('rolled-back.jpg', 200, 200),
        ]))->toThrow(RuntimeException::class, 'Forced profile photo persistence failure.');

    expect($profile->fresh()->profile_photo_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('private')->allFiles())->toBe([]);
    expect(Storage::disk('public')->allFiles())->toBe([$oldPath]);
});

test('an after commit cleanup lookup failure is reported without deleting the committed new photo', function () {
    Exceptions::fake();

    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = photoProfile($staff, $this->allowedSite);
    $oldPath = "hr/photos/{$profile->id}/legacy-public.jpg";
    Storage::disk('public')->put($oldPath, 'legacy public photo');
    $profile->update(['profile_photo_path' => $oldPath]);
    $this->app->bind(DirectoryController::class, fn () => new FailingDirectoryPhotoCleanupLookupController);

    $this->actingAs($this->hr)
        ->post("/hr/directory/{$profile->id}/photo", [
            'photo' => UploadedFile::fake()->image('committed-private.jpg', 200, 200),
        ])
        ->assertRedirect();

    $newPath = $profile->fresh()->profile_photo_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('private')->assertExists($newPath);
    Storage::disk('public')->assertMissing($newPath);
    Storage::disk('public')->assertExists($oldPath);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Forced post-commit photo lookup failure.');
});
