<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\TrainingService;
use App\Models\Site;
use App\Models\User;

/** @return array{0: User, 1: HrEmployeeProfile} */
function trainingAudienceCurrentStaff(
    array $profileOverrides = [],
    array $userOverrides = [],
): array {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'TRAINING-AUDIENCE-'.$user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => null,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        ...$profileOverrides,
    ]);

    return [$user, $profile];
}

test('role audiences contain only current approved staff in the requested role', function () {
    [$current] = trainingAudienceCurrentStaff();
    [$unapproved] = trainingAudienceCurrentStaff([], ['approved_at' => null]);
    [$inactive] = trainingAudienceCurrentStaff(['is_active' => false]);
    [$ended] = trainingAudienceCurrentStaff(['end_date' => today()->subDay()]);
    [$client] = trainingAudienceCurrentStaff([], ['role' => 'client']);
    [$nextOfKin] = trainingAudienceCurrentStaff([], ['role' => 'next_of_kin']);
    [$otherRole] = trainingAudienceCurrentStaff(['position_role' => 'clinical_lead']);

    $ids = app(TrainingService::class)->resolveAudience([
        'audience_type' => 'role',
        'role' => 'support_worker',
    ]);

    expect($ids)->toEqualCanonicalizing([$current->id])
        ->not->toContain($unapproved->id)
        ->not->toContain($inactive->id)
        ->not->toContain($ended->id)
        ->not->toContain($client->id)
        ->not->toContain($nextOfKin->id)
        ->not->toContain($otherRole->id);
});

test('site audiences contain current approved staff at primary or secondary site assignments only', function () {
    $audienceSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    [$primary] = trainingAudienceCurrentStaff(['primary_site_id' => $audienceSite->id]);
    [$secondary] = trainingAudienceCurrentStaff([
        'primary_site_id' => $otherSite->id,
        'secondary_site_ids' => [$audienceSite->id],
    ]);
    [$unapproved] = trainingAudienceCurrentStaff(
        ['primary_site_id' => $audienceSite->id],
        ['approved_at' => null],
    );
    [$inactive] = trainingAudienceCurrentStaff([
        'primary_site_id' => $audienceSite->id,
        'is_active' => false,
    ]);
    [$ended] = trainingAudienceCurrentStaff([
        'primary_site_id' => $audienceSite->id,
        'end_date' => today()->subDay(),
    ]);
    [$portal] = trainingAudienceCurrentStaff(
        ['primary_site_id' => $audienceSite->id],
        ['role' => 'client'],
    );
    [$other] = trainingAudienceCurrentStaff(['primary_site_id' => $otherSite->id]);

    $ids = app(TrainingService::class)->resolveAudience([
        'audience_type' => 'site',
        'site_id' => $audienceSite->id,
    ]);

    expect($ids)->toEqualCanonicalizing([$primary->id, $secondary->id])
        ->not->toContain($unapproved->id)
        ->not->toContain($inactive->id)
        ->not->toContain($ended->id)
        ->not->toContain($portal->id)
        ->not->toContain($other->id);
});

test('cohort audiences contain all and only current approved staff', function () {
    [$current] = trainingAudienceCurrentStaff();
    [$unapproved] = trainingAudienceCurrentStaff([], ['approved_at' => null]);
    [$inactive] = trainingAudienceCurrentStaff(['is_active' => false]);
    [$ended] = trainingAudienceCurrentStaff(['end_date' => today()->subDay()]);
    [$client] = trainingAudienceCurrentStaff([], ['role' => 'client']);
    [$nextOfKin] = trainingAudienceCurrentStaff([], ['role' => 'next_of_kin']);

    $ids = app(TrainingService::class)->resolveAudience([
        'audience_type' => 'cohort',
    ]);

    expect($ids)->toEqualCanonicalizing([$current->id])
        ->not->toContain($unapproved->id)
        ->not->toContain($inactive->id)
        ->not->toContain($ended->id)
        ->not->toContain($client->id)
        ->not->toContain($nextOfKin->id);
});

test('individual audiences discard requested users who are not current approved staff', function () {
    [$current] = trainingAudienceCurrentStaff();
    [$unapproved] = trainingAudienceCurrentStaff([], ['approved_at' => null]);
    [$inactive] = trainingAudienceCurrentStaff(['is_active' => false]);
    [$ended] = trainingAudienceCurrentStaff(['end_date' => today()->subDay()]);
    [$client] = trainingAudienceCurrentStaff([], ['role' => 'client']);
    [$nextOfKin] = trainingAudienceCurrentStaff([], ['role' => 'next_of_kin']);
    $missingProfile = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $ids = app(TrainingService::class)->resolveAudience([
        'audience_type' => 'individuals',
        'user_ids' => [
            $current->id,
            $current->id,
            $unapproved->id,
            $inactive->id,
            $ended->id,
            $client->id,
            $nextOfKin->id,
            $missingProfile->id,
            999999999,
        ],
    ]);

    expect($ids)->toEqualCanonicalizing([$current->id])
        ->not->toContain($unapproved->id)
        ->not->toContain($inactive->id)
        ->not->toContain($ended->id)
        ->not->toContain($client->id)
        ->not->toContain($nextOfKin->id)
        ->not->toContain($missingProfile->id)
        ->not->toContain(999999999);
});

test('role and Site audiences with missing selectors fail closed', function () {
    trainingAudienceCurrentStaff();

    expect(app(TrainingService::class)->resolveAudience([
        'audience_type' => 'role',
    ]))->toBe([])
        ->and(app(TrainingService::class)->resolveAudience([
            'audience_type' => 'site',
        ]))->toBe([]);
});
