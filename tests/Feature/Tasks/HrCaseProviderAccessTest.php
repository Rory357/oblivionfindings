<?php

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\HrCaseProvider;
use App\Support\LegacyStorageContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->allowedSite = Site::factory()->create([
        ...LegacyStorageContext::attributes(),
        'name' => 'Allowed HR case Site',
    ]);
    $this->hiddenSite = Site::factory()->create([
        ...LegacyStorageContext::attributes(),
        'name' => 'Hidden HR case Site',
    ]);

    $this->manager = hrCaseProviderStaff($this->allowedSite, 'hr');
    foreach (['hr.cases.view', 'hr.cases.manage'] as $key) {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $this->manager->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    $this->allowedStaff = hrCaseProviderStaff($this->allowedSite);
    $this->hiddenStaff = hrCaseProviderStaff($this->hiddenSite);
});

test('HR case tasks include only cases whose subjects are at approved Sites', function () {
    $allowed = HrCase::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $this->allowedStaff->id,
        'status' => 'open',
        'title' => 'Allowed Site case',
    ]);
    $hidden = HrCase::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $this->hiddenStaff->id,
        'status' => 'open',
        'title' => 'Hidden Site case',
    ]);

    $items = app(HrCaseProvider::class)->tasks($this->manager);

    expect(collect($items)->pluck('id')->all())
        ->toContain("hr_case-{$allowed->id}")
        ->not->toContain("hr_case-{$hidden->id}");
});

test('HR case task assignment conceals cases whose subjects are at hidden Sites', function () {
    $hidden = HrCase::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $this->hiddenStaff->id,
        'status' => 'open',
    ]);

    expect(fn () => app(HrCaseProvider::class)->assign(
        $this->manager,
        $hidden->id,
        $this->allowedStaff->id,
    ))->toThrow(ValidationException::class);

    expect($hidden->fresh()->assigned_to)->toBeNull();
});

test('HR case task assignment accepts only current staff visible at approved Sites', function () {
    $case = HrCase::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $this->allowedStaff->id,
        'status' => 'open',
    ]);
    $portal = User::factory()->create([
        'role' => 'client',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $portal->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);

    foreach ([$this->hiddenStaff, $portal] as $invalidAssignee) {
        expect(fn () => app(HrCaseProvider::class)->assign(
            $this->manager,
            $case->id,
            $invalidAssignee->id,
        ))->toThrow(ValidationException::class);
    }

    expect($case->fresh()->assigned_to)->toBeNull();

    app(HrCaseProvider::class)->assign($this->manager, $case->id, $this->allowedStaff->id);

    expect($case->fresh()->assigned_to)->toBe($this->allowedStaff->id);
});

test('HR case tasks retain allowed Site history while concealing hidden Site history', function () {
    $historicalStaff = function (Site $site): User {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => null,
        ]);
        HrEmployeeProfile::factory()->create([
            ...LegacyStorageContext::attributes(),
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'is_active' => false,
            'end_date' => now()->subDay()->toDateString(),
        ]);

        return $user;
    };

    $allowedFormerStaff = $historicalStaff($this->allowedSite);
    $hiddenFormerStaff = $historicalStaff($this->hiddenSite);
    $allowed = HrCase::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $allowedFormerStaff->id,
        'status' => 'open',
    ]);
    $hidden = HrCase::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $hiddenFormerStaff->id,
        'status' => 'open',
    ]);

    $items = app(HrCaseProvider::class)->tasks($this->manager);
    expect(collect($items)->pluck('id')->all())
        ->toContain("hr_case-{$allowed->id}")
        ->not->toContain("hr_case-{$hidden->id}");

    app(HrCaseProvider::class)->assign($this->manager, $allowed->id, $this->allowedStaff->id);
    expect($allowed->fresh()->assigned_to)->toBe($this->allowedStaff->id);

    expect(fn () => app(HrCaseProvider::class)->assign(
        $this->manager,
        $hidden->id,
        $this->allowedStaff->id,
    ))->toThrow(ValidationException::class);
});

function hrCaseProviderStaff(Site $site, string $role = 'support_worker'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        ...LegacyStorageContext::attributes(),
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
    ]);

    return $user;
}
