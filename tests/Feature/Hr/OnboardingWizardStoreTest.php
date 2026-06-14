<?php

use App\Domain\Hr\Jobs\SendOnboardingEmailJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;

function makeWizardProfile(): HrEmployeeProfile
{
    $user = User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->addDays(10)->toDateString(),
        'is_active' => true,
    ]);
}

function makeOnboardingTemplate(string $role = 'support_worker', string $site = 'all'): HrOnboardingTemplate
{
    return HrOnboardingTemplate::query()->create([
        'tenant_id' => 1,
        'role' => $role,
        'site_type' => $site,
        'is_active' => true,
        'tasks' => [
            ['category' => 'admin', 'title' => 'Sign contract', 'is_required' => true, 'sort_order' => 1],
            ['category' => 'it', 'title' => 'Issue laptop', 'is_required' => true, 'sort_order' => 2, 'sign_off_required' => true],
        ],
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the wizard store creates a checklist via the auto-matched template', function () {
    makeOnboardingTemplate();
    $profile = makeWizardProfile();

    $this->actingAs($this->hr)
        ->post('/hr/onboarding', [
            'employee_profile_id' => $profile->id,
            'assign_compliance' => false,
            'send_welcome_email' => false,
        ])
        ->assertRedirect();

    $checklist = HrOnboardingChecklist::query()
        ->where('employee_profile_id', $profile->id)
        ->first();

    expect($checklist)->not->toBeNull();
    expect($checklist->template_key)->toBe('support_worker:all');
    expect($checklist->tasks()->count())->toBe(2);
});

test('an explicit template_id overrides the auto-match', function () {
    makeOnboardingTemplate('support_worker', 'all'); // would auto-match
    $special = makeOnboardingTemplate('default', 'all'); // chosen explicitly
    $profile = makeWizardProfile();

    $this->actingAs($this->hr)
        ->post('/hr/onboarding', [
            'employee_profile_id' => $profile->id,
            'template_id' => $special->id,
        ])
        ->assertRedirect();

    $checklist = HrOnboardingChecklist::query()
        ->where('employee_profile_id', $profile->id)
        ->first();

    expect($checklist->template_key)->toBe('default:all');
});

test('send_welcome_email dispatches the send job for the chosen email', function () {
    Bus::fake();
    makeOnboardingTemplate();
    $profile = makeWizardProfile();
    $email = HrOnboardingEmail::query()->create([
        'tenant_id' => 1,
        'template_name' => 'Welcome',
        'subject' => 'Welcome',
        'body' => 'body',
        'send_days_before_start' => 7,
        'is_active' => true,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/onboarding', [
            'employee_profile_id' => $profile->id,
            'send_welcome_email' => true,
            'welcome_email_id' => $email->id,
        ])
        ->assertRedirect();

    Bus::assertDispatched(SendOnboardingEmailJob::class, function (SendOnboardingEmailJob $job) use ($email, $profile) {
        return $job->emailId === $email->id && $job->employeeProfileId === $profile->id;
    });
});

test('send_welcome_email without an email id is rejected', function () {
    makeOnboardingTemplate();
    $profile = makeWizardProfile();

    $this->actingAs($this->hr)
        ->post('/hr/onboarding', [
            'employee_profile_id' => $profile->id,
            'send_welcome_email' => true,
        ])
        ->assertSessionHasErrors('welcome_email_id');
});

test('the onboarding index ships the wizard employees and email templates', function () {
    makeOnboardingTemplate();
    $profile = makeWizardProfile();
    HrOnboardingEmail::query()->create([
        'tenant_id' => 1,
        'template_name' => 'Welcome',
        'subject' => 'Welcome',
        'body' => 'body',
        'send_days_before_start' => 7,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/onboarding');
    $response->assertOk();

    $employees = collect($response->inertiaProps('employees'));
    expect($employees->pluck('id'))->toContain($profile->id);
    expect($employees->first())->toHaveKeys(['position_role', 'primary_site_type', 'start_date']);

    expect(collect($response->inertiaProps('emailTemplates')))->not->toBeEmpty();
});
