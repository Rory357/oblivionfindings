<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: HrEmployeeProfile} */
function currentStaffFixture(array $profileOverrides = [], array $userOverrides = []): array
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-CURRENT-'.$user->id,
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

test('current recipient eligibility requires approved staff with a live employment profile', function () {
    [$current] = currentStaffFixture();
    [$unapproved] = currentStaffFixture([], ['approved_at' => null]);
    [$inactive] = currentStaffFixture(['is_active' => false]);
    [$ended] = currentStaffFixture(['end_date' => now()->subDay()->toDateString()]);
    [$future] = currentStaffFixture(['start_date' => now()->addDay()->toDateString()]);
    [$portal] = currentStaffFixture([], ['role' => 'client']);
    [$family] = currentStaffFixture([], ['role' => 'next_of_kin']);
    $missingProfile = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    [$archived, $archivedProfile] = currentStaffFixture();
    $archivedProfile->delete();

    $ids = app(HrCurrentStaffService::class)->currentUserIds();

    expect($ids)->toContain($current->id)
        ->not->toContain($unapproved->id)
        ->not->toContain($inactive->id)
        ->not->toContain($ended->id)
        ->not->toContain($future->id)
        ->not->toContain($portal->id)
        ->not->toContain($family->id)
        ->not->toContain($missingProfile->id)
        ->not->toContain($archived->id);
});

test('historical staff lookup remains separate from current recipient eligibility', function () {
    [$ended, $profile] = currentStaffFixture([
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ]);
    $profile->delete();

    $service = app(HrCurrentStaffService::class);

    expect($service->isCurrent($ended))->toBeFalse()
        ->and($service->historicalProfileFor($ended)->id)->toBe($profile->id)
        ->and($service->historicalProfileFor($ended)->trashed())->toBeTrue();
});

test('the reusable recipient rule accepts only current staff', function () {
    [$current] = currentStaffFixture();
    [$ended] = currentStaffFixture(['end_date' => now()->subDay()->toDateString()]);
    $service = app(HrCurrentStaffService::class);

    $currentValidator = Validator::make(
        ['user_id' => $current->id],
        ['user_id' => [$service->recipientRule()]],
    );
    $endedValidator = Validator::make(
        ['user_id' => $ended->id],
        ['user_id' => [$service->recipientRule()]],
    );

    expect($currentValidator->passes())->toBeTrue()
        ->and($endedValidator->fails())->toBeTrue()
        ->and($endedValidator->errors()->first('user_id'))
        ->toBe('The selected person must be current approved staff.');
});
