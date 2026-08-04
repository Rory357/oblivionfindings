<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->manager = User::factory()->create([
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->firstOrFail()->id,
    ]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->manager->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
});

function makeVettingStaffAtSite(Site $site, array $profileOverrides = []): User
{
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ], $profileOverrides));

    return $staff;
}

test('a vetting check for inactive staff cannot be accessed by direct url', function () {
    $formerStaff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $formerStaff->id,
        'primary_site_id' => $this->site->id,
        'is_active' => false,
    ]);
    $check = StaffBackgroundCheck::query()->create([
        'user_id' => $formerStaff->id,
        'check_type' => 'police_check',
        'status' => 'clear',
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$check->id}/consent", [
            'consent_given' => true,
        ])
        ->assertNotFound();

    expect((string) $check->fresh()->notes)->not->toContain('[Consent recorded');
});

test('a vetting check for current staff remains accessible without user partition metadata', function () {
    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
    $check = StaffBackgroundCheck::query()->create([
        'user_id' => $staff->id,
        'check_type' => 'police_check',
        'status' => 'clear',
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$check->id}/consent", [
            'consent_given' => true,
        ])
        ->assertSessionHas('success');
});

test('vetting rows summary and pickers share the current accessible Site boundary', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $visible = makeVettingStaffAtSite($this->site);
    $secondary = makeVettingStaffAtSite($otherSite, ['secondary_site_ids' => [$this->site->id]]);
    $hidden = makeVettingStaffAtSite($otherSite);
    $inactive = makeVettingStaffAtSite($this->site, ['is_active' => false]);

    $visibleCheck = StaffBackgroundCheck::query()->create([
        'user_id' => $visible->id, 'check_type' => 'police_check', 'status' => 'clear',
    ]);
    $secondaryCheck = StaffBackgroundCheck::query()->create([
        'user_id' => $secondary->id, 'check_type' => 'police_check', 'status' => 'pending',
    ]);
    $hiddenCheck = StaffBackgroundCheck::query()->create([
        'user_id' => $hidden->id, 'check_type' => 'police_check', 'status' => 'flagged',
    ]);
    StaffBackgroundCheck::query()->create([
        'user_id' => $inactive->id, 'check_type' => 'police_check', 'status' => 'pending',
    ]);

    $index = $this->actingAs($this->manager)->get('/hr/compliance/vetting');
    $index->assertOk();
    expect(collect($index->inertiaProps('checks.data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$visibleCheck->id, $secondaryCheck->id])
        ->not->toContain($hiddenCheck->id)
        ->and($index->inertiaProps('summary.total'))->toBe(2)
        ->and($index->inertiaProps('summary.clear'))->toBe(1)
        ->and($index->inertiaProps('summary.pending'))->toBe(1);

    $create = $this->actingAs($this->manager)->get('/hr/compliance/vetting/create');
    $create->assertOk();
    expect(collect($create->inertiaProps('staff'))->pluck('id')->all())
        ->toEqualCanonicalizing([$this->manager->id, $visible->id, $secondary->id]);
});

test('vetting direct objects and create selections conceal hidden Site staff', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $hidden = makeVettingStaffAtSite($otherSite);
    $check = StaffBackgroundCheck::query()->create([
        'user_id' => $hidden->id,
        'check_type' => 'police_check',
        'status' => 'pending',
    ]);

    $this->actingAs($this->manager)->get("/hr/compliance/vetting/{$check->id}")->assertNotFound();
    $this->actingAs($this->manager)->get("/hr/compliance/vetting/{$check->id}/edit")->assertNotFound();
    $this->actingAs($this->manager)
        ->put("/hr/compliance/vetting/{$check->id}", ['status' => 'clear'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$check->id}/clear")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$check->id}/renew")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$check->id}/consent", ['consent_given' => true])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->delete("/hr/compliance/vetting/{$check->id}")
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->post('/hr/compliance/vetting', [
            'user_id' => $hidden->id,
            'check_type' => 'right_to_work',
        ])
        ->assertSessionHasErrors('user_id');

    expect($check->fresh()->status)->toBe('pending');
    $this->assertDatabaseMissing('staff_background_checks', [
        'user_id' => $hidden->id,
        'check_type' => 'right_to_work',
    ]);
});

test('vetting mutations reauthorise the staff Site boundary after the shared People lock', function () {
    $staff = makeVettingStaffAtSite($this->site);
    $check = StaffBackgroundCheck::query()->create([
        'user_id' => $staff->id,
        'check_type' => 'police_check',
        'status' => 'pending',
    ]);
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $locks = new class extends PeopleMutationLockService
    {
        public ?Closure $beforeLock = null;

        public function lock(iterable $userIds, iterable $profileIds = []): array
        {
            if ($this->beforeLock) {
                ($this->beforeLock)();
                $this->beforeLock = null;
            }

            return parent::lock($userIds, $profileIds);
        }
    };
    $locks->beforeLock = function () use ($staff, $otherSite): void {
        $staff->hrEmployeeProfile()->update([
            'primary_site_id' => $otherSite->id,
            'secondary_site_ids' => [],
        ]);
    };
    $this->app->instance(PeopleMutationLockService::class, $locks);

    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$check->id}/consent", [
            'consent_given' => true,
        ])
        ->assertNotFound();

    expect((string) $check->fresh()->notes)->not->toContain('[Consent recorded');
});
