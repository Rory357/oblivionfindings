<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $role = Role::where('name', 'hr')->first();
    if ($role) {
        $this->hr->roles()->syncWithoutDetaching([$role->id]);
    }

    $this->site = Site::factory()->create([
        'type' => 'house',
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);

    HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'tasks' => [],
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
});

test('hr user can approve send and accept an offer workflow', function () {
    $candidate = HrCandidate::factory()->create([
        'first_name' => 'Mia',
        'last_name' => 'Candidate',
        'personal_email' => 'mia.candidate@example.test',
        'source' => 'direct',
        'status' => 'new',
        'created_by' => $this->hr->id,
    ]);

    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'target_site_id' => $this->site->id,
        'status' => 'active',
    ]);

    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 30,
        'primary_site_id' => $this->site->id,
        'approval_status' => 'draft',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/approve")
        ->assertSessionHas('success');

    $offer->refresh();
    expect($offer->approval_status)->toBe('approved');
    expect($offer->approved_by)->toBe($this->hr->id);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/send")
        ->assertSessionHas('success');

    $offer->refresh();
    expect($offer->sent_at)->not->toBeNull();
    expect($offer->candidate_portal_token)->not->toBeNull();

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", [
            'response' => 'accepted',
            'signature_name' => 'Mia Candidate',
            'terms_accepted' => '1',
        ])
        ->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();

    expect($offer->response)->toBe('accepted');
    expect($offer->signed_full_name)->toBe('Mia Candidate');
    expect($offer->signed_at)->not->toBeNull();
    // Accepting now flows straight into employment + onboarding.
    expect($candidate->status)->toBe('hired');
    expect(
        HrEmployeeProfile::query()->where('candidate_id', $candidate->id)->exists()
    )->toBeTrue();
});

function hrAcceptedOfferFixture(User $hr, Site $site, string $email, string $role = 'support_worker'): array
{
    $candidate = HrCandidate::factory()->create([
        'first_name' => 'Mia',
        'last_name' => 'Candidate',
        'personal_email' => $email,
        'source' => 'direct',
        'status' => 'offer_accepted',
        'created_by' => $hr->id,
    ]);

    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => $role,
        'target_site_id' => $site->id,
        'status' => 'offered',
    ]);

    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => $role,
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 30,
        'primary_site_id' => $site->id,
        'approval_status' => 'approved',
        'approved_by' => $hr->id,
        'approved_at' => now(),
        'response' => 'accepted',
        'response_at' => now(),
        'created_by' => $hr->id,
    ]);

    return [$candidate, $application, $offer];
}

test('converting an accepted offer creates a role-backed user and password reset invite', function () {
    Notification::fake();

    [$candidate, $application, $offer] = hrAcceptedOfferFixture(
        $this->hr,
        $this->site,
        'mia.converted@example.test',
    );

    $profile = app(RecruitmentService::class)->convertToEmployee($candidate, $offer, $this->hr->id);
    $user = User::query()->where('email', 'mia.converted@example.test')->firstOrFail();
    $roleId = Role::query()->where('name', 'support_worker')->value('id');

    expect($profile->user_id)->toBe($user->id);
    $this->assertDatabaseHas('role_user', [
        'user_id' => $user->id,
        'role_id' => $roleId,
    ]);
    Notification::assertSentTo($user, ResetPassword::class);

    $candidate->refresh();
    $application->refresh();
    expect($candidate->status)->toBe('hired');
    expect($application->status)->toBe('hired');
});

test('converting a candidate cannot rebind an existing user profile from another candidate', function () {
    Notification::fake();

    $existingUser = User::factory()->create([
        'email' => 'shared.candidate@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $existingCandidate = HrCandidate::factory()->create([
        'first_name' => 'Existing',
        'last_name' => 'Candidate',
        'personal_email' => 'first.candidate@example.test',
        'source' => 'direct',
        'status' => 'hired',
        'created_by' => $this->hr->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $existingUser->id,
        'employee_number' => 'EMP-EXISTING',
        'work_email' => $existingUser->email,
        'personal_email' => 'first.candidate@example.test',
        'position_role' => 'support_worker',
        'offer_id' => null,
        'candidate_id' => $existingCandidate->id,
        'created_by' => $this->hr->id,
    ]);

    [$candidate, , $offer] = hrAcceptedOfferFixture(
        $this->hr,
        $this->site,
        'shared.candidate@example.test',
    );

    expect(fn () => app(RecruitmentService::class)->convertToEmployee($candidate, $offer, $this->hr->id))
        ->toThrow(LogicException::class, 'already linked');
});
