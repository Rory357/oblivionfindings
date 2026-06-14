<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Services\OnboardingEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued delivery of a single onboarding email template to one new hire. Used
 * both by the daily scheduler (start_date − send_days_before_start) and by the
 * onboarding wizard's "send welcome email on launch" toggle.
 */
class SendOnboardingEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $emailId,
        public int $employeeProfileId,
    ) {}

    public function handle(OnboardingEmailService $service): void
    {
        $email = HrOnboardingEmail::find($this->emailId);
        $profile = HrEmployeeProfile::find($this->employeeProfileId);

        if (! $email || ! $profile) {
            return;
        }

        $service->send($email, $profile);
    }
}
