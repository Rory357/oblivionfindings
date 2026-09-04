<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('newly hired staff member with pending onboarding triggers eligibility block', function () {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $user = User::factory()->create([]);

    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
    ]);

    $shift = Shift::factory()->create([
        'site_id' => $site->id,
        'starts_at' => now()->addDays(2)->setTime(9, 0),
        'ends_at' => now()->addDays(2)->setTime(17, 0),
        'status' => 'draft',
    ]);

    // 1. Pending onboarding checklist blocks shift eligibility
    $checklist = HrOnboardingChecklist::factory()->create([
        'employee_profile_id' => $profile->id,
        'status' => 'in_progress',
        'started_at' => now()->subDay(),
        'completed_at' => null,
    ]);

    $service = app(ShiftStaffEligibilityService::class);
    $result = $service->evaluate($shift, $user);

    expect($result->hasBlocks())->toBeTrue()
        ->and($result->is_allowed)->toBeFalse()
        ->and(implode(' ', $result->blocking_reasons))->toContain('onboarding');

    // 2. Marking onboarding completed resolves the block
    $checklist->update([
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $updatedResult = $service->evaluate($shift, $user);
    expect($updatedResult->blocking_reasons)->toBeEmpty();
});
