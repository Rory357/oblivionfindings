<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingEmailLog;
use App\Mail\Hr\OnboardingTemplateMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Single source of truth for onboarding-email mail-merge + delivery.
 *
 * Until this service existed the HrOnboardingEmail templates were CRUD-only:
 * nothing ever sent them and the sent log was always empty. The renderer is
 * shared with the controller preview so what an admin previews is exactly what a
 * new hire receives, and {@see send()} records every attempt in the sent log.
 */
class OnboardingEmailService
{
    /**
     * Build the mail-merge token map for a real employee profile. Mirrors the
     * token set used by {@see sampleData()} so previews and real sends match.
     *
     * @return array<string, string>
     */
    public function mergeDataFor(HrEmployeeProfile $profile): array
    {
        $profile->loadMissing(['user', 'manager']);

        return [
            'employee_name' => $profile->user?->name ?? 'New starter',
            'position_title' => (string) ($profile->position_title ?? ''),
            'start_date' => $profile->start_date
                ? Carbon::parse($profile->start_date)->format('d/m/Y')
                : '',
            'manager_name' => $profile->manager?->name ?? '',
            'company_name' => (string) config('app.name', 'Company'),
        ];
    }

    /**
     * Representative token map for previews where no profile is in scope.
     *
     * @return array<string, string>
     */
    public function sampleData(): array
    {
        return [
            'employee_name' => 'Jane Smith',
            'position_title' => 'Support Worker',
            'start_date' => now()->addDays(7)->format('d/m/Y'),
            'manager_name' => 'John Manager',
            'company_name' => (string) config('app.name', 'Company'),
        ];
    }

    /**
     * Simple {{token}} mail-merge.
     *
     * @param  array<string, string>  $data
     */
    public function render(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{{'.$key.'}}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * Render and send one onboarding email to the new hire, recording the outcome
     * in the sent log. Returns null (no log row) when the template is inactive or
     * the recipient has no email address; otherwise returns the log row with a
     * 'sent' or 'failed' status.
     */
    public function send(HrOnboardingEmail $email, HrEmployeeProfile $profile): ?HrOnboardingEmailLog
    {
        if (! $email->is_active) {
            return null;
        }

        $profile->loadMissing('user');
        $recipient = $profile->user?->email;

        if (! $recipient) {
            return null;
        }

        $data = $this->mergeDataFor($profile);
        $subject = $this->render($email->subject, $data);
        $body = $this->render($email->body, $data);

        try {
            Mail::to($recipient)->send(new OnboardingTemplateMail($subject, $body));

            return HrOnboardingEmailLog::create([
                'onboarding_email_id' => $email->id,
                'employee_profile_id' => $profile->id,
                'sent_at' => now(),
                'status' => 'sent',
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send onboarding email', [
                'email_id' => $email->id,
                'profile_id' => $profile->id,
                'error' => $exception->getMessage(),
            ]);

            return HrOnboardingEmailLog::create([
                'onboarding_email_id' => $email->id,
                'employee_profile_id' => $profile->id,
                'sent_at' => now(),
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'created_at' => now(),
            ]);
        }
    }
}
