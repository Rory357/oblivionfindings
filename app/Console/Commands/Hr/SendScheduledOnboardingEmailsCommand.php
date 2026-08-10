<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Jobs\SendOnboardingEmailJob;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingEmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dispatch onboarding emails whose scheduled send date falls today.
 *
 * For every active onboarding checklist with a known start date and every active
 * email template, the template is due when today == start_date − send_days_before_start.
 * The sent-log row (one per email × profile) is the idempotency guard, so the
 * command is safe to run repeatedly and across re-deploys.
 */
class SendScheduledOnboardingEmailsCommand extends Command
{
    protected $signature = 'hr:onboarding-emails';

    protected $description = 'Dispatch onboarding emails due today (start_date − send_days_before_start).';

    public function handle(): int
    {
        $today = Carbon::today();

        $emails = HrOnboardingEmail::query()->where('is_active', true)->get();

        if ($emails->isEmpty()) {
            $this->info('No active onboarding email templates.');

            return self::SUCCESS;
        }

        $checklists = HrOnboardingChecklist::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->with('employeeProfile:id,user_id,position_title,start_date,end_date,is_active,manager_user_id,primary_site_id,secondary_site_ids')
            ->get();

        $dispatched = 0;

        foreach ($checklists as $checklist) {
            $profile = $checklist->employeeProfile;

            $siteIds = collect([
                $profile?->primary_site_id,
                ...($profile?->secondary_site_ids ?? []),
            ])->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0);
            if (! $profile
                || ! $profile->start_date
                || ! $profile->is_active
                || ($profile->end_date && $profile->end_date->isBefore($today))
                || $siteIds->isEmpty()
            ) {
                continue;
            }

            $startDate = Carbon::parse($profile->start_date)->startOfDay();

            foreach ($emails as $email) {
                $sendOn = $startDate->copy()->subDays((int) $email->send_days_before_start);

                if (! $sendOn->isSameDay($today)) {
                    continue;
                }

                $alreadySent = HrOnboardingEmailLog::query()
                    ->where('onboarding_email_id', $email->id)
                    ->where('employee_profile_id', $profile->id)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                SendOnboardingEmailJob::dispatch($email->id, $profile->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} onboarding email(s).");

        return self::SUCCESS;
    }
}
