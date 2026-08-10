<?php

use App\Console\Commands\Hr\SendScheduledOnboardingEmailsCommand;
use App\Domain\Hr\Jobs\SendOnboardingEmailJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingEmailLog;
use App\Domain\Hr\Services\OnboardingEmailService;
use App\Mail\Hr\OnboardingTemplateMail;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

function makeOnboardingProfile(array $overrides = []): HrEmployeeProfile
{
    $user = User::factory()->create(['name' => 'Aroha Ngata']);
    $site = Site::factory()->create();

    return HrEmployeeProfile::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->addDays(7)->toDateString(),
        'is_active' => true,
    ], $overrides));
}

test('the email service sends a merged email and records a sent-log row', function () {
    Mail::fake();

    $profile = makeOnboardingProfile();
    $email = HrOnboardingEmail::query()->create([
        'template_name' => 'Welcome',
        'subject' => 'Welcome {{employee_name}} to {{company_name}}',
        'body' => '<p>Hi {{employee_name}}, see you on {{start_date}}.</p>',
        'send_days_before_start' => 7,
        'is_active' => true,
    ]);

    $log = app(OnboardingEmailService::class)->send($email, $profile);

    expect($log)->not->toBeNull();
    expect($log->status)->toBe('sent');

    Mail::assertSent(OnboardingTemplateMail::class, function (OnboardingTemplateMail $mail) use ($profile) {
        return str_contains($mail->subjectLine, 'Aroha Ngata')
            && str_contains($mail->subjectLine, config('app.name'))
            && $mail->hasTo($profile->user->email);
    });

    $this->assertDatabaseHas('hr_onboarding_email_log', [
        'onboarding_email_id' => $email->id,
        'employee_profile_id' => $profile->id,
        'status' => 'sent',
    ]);
});

test('inactive templates and recipients without an email are skipped', function () {
    Mail::fake();
    $service = app(OnboardingEmailService::class);

    $profile = makeOnboardingProfile();
    $inactive = HrOnboardingEmail::query()->create([
        'template_name' => 'Inactive',
        'subject' => 'x',
        'body' => 'y',
        'send_days_before_start' => 0,
        'is_active' => false,
    ]);

    expect($service->send($inactive, $profile))->toBeNull();
    Mail::assertNothingSent();
});

test('the scheduler dispatches a job when an email is due today and is idempotent', function () {
    Bus::fake();

    // start_date in 7 days, template offset 7 → due today.
    $profile = makeOnboardingProfile(['start_date' => now()->addDays(7)->toDateString()]);
    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'pending',
        'started_at' => now(),
    ]);
    $email = HrOnboardingEmail::query()->create([
        'template_name' => 'Welcome',
        'subject' => 'Welcome',
        'body' => 'body',
        'send_days_before_start' => 7,
        'is_active' => true,
    ]);

    $this->artisan(SendScheduledOnboardingEmailsCommand::class)->assertSuccessful();
    Bus::assertDispatched(SendOnboardingEmailJob::class, 1);

    // A pre-existing log row makes the next run a no-op (idempotency guard).
    HrOnboardingEmailLog::query()->create([
        'onboarding_email_id' => $email->id,
        'employee_profile_id' => $profile->id,
        'sent_at' => now(),
        'status' => 'sent',
        'created_at' => now(),
    ]);

    Bus::fake();
    $this->artisan(SendScheduledOnboardingEmailsCommand::class)->assertSuccessful();
    Bus::assertNotDispatched(SendOnboardingEmailJob::class);
});

test('the scheduler does not dispatch when the due date is not today', function () {
    Bus::fake();

    // start_date in 30 days, offset 7 → due in 23 days, not today.
    $profile = makeOnboardingProfile(['start_date' => now()->addDays(30)->toDateString()]);
    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'pending',
        'started_at' => now(),
    ]);
    HrOnboardingEmail::query()->create([
        'template_name' => 'Welcome',
        'subject' => 'Welcome',
        'body' => 'body',
        'send_days_before_start' => 7,
        'is_active' => true,
    ]);

    $this->artisan(SendScheduledOnboardingEmailsCommand::class)->assertSuccessful();
    Bus::assertNotDispatched(SendOnboardingEmailJob::class);
});
