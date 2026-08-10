<?php

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrAudienceAccessService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: HrEmployeeProfile} */
function audienceStaffFixture(array $profileOverrides = [], array $userOverrides = []): array
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-AUDIENCE-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => null,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);

    return [$user, $profile];
}

test('all-current-staff and explicit-person audiences exclude ineligible users', function () {
    [$current] = audienceStaffFixture();
    [$ended] = audienceStaffFixture(['end_date' => now()->subDay()->toDateString()]);
    $portal = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
    $service = app(HrAudienceAccessService::class);

    $allIds = $service->resolveUsers([['type' => 'all', 'value' => null]])->pluck('id');
    $currentPersonIds = $service->resolveUsers([['type' => 'user', 'value' => (string) $current->id]])->pluck('id')->all();
    $endedPersonIds = $service->resolveUsers([['type' => 'user', 'value' => (string) $ended->id]])->pluck('id')->all();

    expect($allIds)->toContain($current->id)
        ->not->toContain($ended->id)
        ->not->toContain($portal->id)
        ->and($currentPersonIds)->toEqualCanonicalizing([$current->id])
        ->and($endedPersonIds)->toBeEmpty();
});

test('site audiences include current primary and secondary assignments only', function () {
    $audienceSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    [$primary] = audienceStaffFixture(['primary_site_id' => $audienceSite->id]);
    [$secondary] = audienceStaffFixture([
        'primary_site_id' => $otherSite->id,
        'secondary_site_ids' => [$audienceSite->id],
    ]);
    [$other] = audienceStaffFixture(['primary_site_id' => $otherSite->id]);

    $ids = app(HrAudienceAccessService::class)
        ->resolveUsers([['type' => 'site', 'value' => (string) $audienceSite->id]])
        ->pluck('id')
        ->all();

    expect($ids)->toEqualCanonicalizing([$primary->id, $secondary->id])
        ->not->toContain($other->id);
});

test('department role and team audiences use current profile relationships', function () {
    [$clinical] = audienceStaffFixture([
        'department' => 'Clinical',
        'position_role' => 'clinical_lead',
        'team' => 'Clinical Support',
    ]);
    [$operations] = audienceStaffFixture([
        'department' => 'Operations',
        'position_role' => 'team_lead',
        'team' => 'Operations',
    ]);
    $service = app(HrAudienceAccessService::class);

    expect($service->resolveUsers([['type' => 'department', 'value' => 'Clinical']])->pluck('id')->all())
        ->toEqualCanonicalizing([$clinical->id])
        ->and($service->resolveUsers([['type' => 'role', 'value' => 'team_lead']])->pluck('id')->all())
        ->toEqualCanonicalizing([$operations->id])
        ->and($service->resolveUsers([['type' => 'team', 'value' => 'Clinical Support']])->pluck('id')->all())
        ->toEqualCanonicalizing([$clinical->id]);
});

test('numeric department targets use the department id namespace without matching free text collisions', function () {
    $department = HrDepartment::query()->create([
        'name' => 'Clinical',
        'code' => 'CLINICAL',
        'is_active' => true,
    ]);
    [$departmentMember] = audienceStaffFixture([
        'department' => 'Clinical',
        'department_id' => $department->id,
    ]);
    [$textCollision] = audienceStaffFixture([
        'department' => (string) $department->id,
        'department_id' => null,
    ]);

    $ids = app(HrAudienceAccessService::class)
        ->resolveUsers([['type' => 'department', 'value' => (string) $department->id]])
        ->pluck('id')
        ->all();

    expect($ids)->toEqualCanonicalizing([$departmentMember->id])
        ->not->toContain($textCollision->id);
});

test('creator and owner management requires a current matching staff identity', function () {
    [$creator] = audienceStaffFixture();
    [$owner] = audienceStaffFixture();
    [$other] = audienceStaffFixture();
    [$ended] = audienceStaffFixture(['end_date' => now()->subDay()->toDateString()]);
    $service = app(HrAudienceAccessService::class);

    expect($service->canManageOwnedAudience($creator, $creator->id, $owner->id))->toBeTrue()
        ->and($service->canManageOwnedAudience($owner, $creator->id, $owner->id))->toBeTrue()
        ->and($service->canManageOwnedAudience($other, $creator->id, $owner->id))->toBeFalse()
        ->and($service->canManageOwnedAudience($ended, $ended->id, null))->toBeFalse();
});

test('conflicting or missing audience evidence fails closed', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    audienceStaffFixture(['primary_site_id' => $site->id]);
    $retiredSite = Site::factory()->create([
        'is_active' => false,
        'archived' => true,
        'archived_at' => now(),
    ]);
    $service = app(HrAudienceAccessService::class);

    expect($service->resolveUsers([
        ['type' => 'all', 'value' => null],
        ['type' => 'site', 'value' => (string) $site->id],
    ]))->toBeEmpty()
        ->and($service->resolveUsers([['type' => 'site', 'value' => '999999999']]))->toBeEmpty()
        ->and($service->resolveUsers([['type' => 'site', 'value' => (string) $retiredSite->id]]))->toBeEmpty()
        ->and($service->resolveUsers([['type' => 'unsupported', 'value' => 'anything']]))->toBeEmpty()
        ->and($service->resolveUsers([]))->toBeEmpty();
});
