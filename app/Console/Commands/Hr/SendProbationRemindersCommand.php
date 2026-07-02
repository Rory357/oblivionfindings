<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Notifications\ProbationReviewDueNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily probation sweep (audit fix round 2, item 1a): active employees whose
 * probation_end_date is within 14 days — or already past — with no concluding
 * probation review on file get a ProbationReviewDueNotification to their
 * manager (manager_user_id; fallback: every provider_manager).
 *
 * A review "concludes" probation when its status is passed / failed, or it is
 * completed with a pass / fail recommendation ({@see HrProbationReview} —
 * scheduled and extended reviews leave the employee still on probation; an
 * extension moves probation_end_date forward via
 * PerformanceReviewController::storeProbation, which also clears the dedupe
 * stamp so the new end date earns a fresh reminder).
 *
 * Dedupe: one reminder per probation end date, stamped on
 * hr_employee_profiles.probation_reminder_sent_at.
 */
class SendProbationRemindersCommand extends Command
{
    protected $signature = 'hr:probation-reminders';

    protected $description = 'Notify managers of probation reviews due within 14 days (or overdue) with no completed review recorded.';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(14);
        $sent = 0;

        HrEmployeeProfile::query()
            ->where('is_active', true)
            ->whereNotNull('probation_end_date')
            ->where('probation_end_date', '<=', $horizon)
            ->whereNull('probation_reminder_sent_at')
            ->whereNotNull('user_id')
            ->with(['user:id,name', 'manager:id,name,email'])
            ->chunkById(200, function ($profiles) use (&$sent, $today) {
                foreach ($profiles as $profile) {
                    if (! $profile->user) {
                        continue;
                    }

                    // Probation already concluded → nothing to chase.
                    $concluded = HrProbationReview::query()
                        ->where('tenant_id', $profile->tenant_id)
                        ->where('employee_user_id', $profile->user_id)
                        ->where(function ($q) {
                            $q->whereIn('status', ['passed', 'failed'])
                                ->orWhere(function ($q2) {
                                    $q2->where('status', 'completed')
                                        ->whereIn('recommendation', ['pass', 'fail']);
                                });
                        })
                        ->exists();
                    if ($concluded) {
                        continue;
                    }

                    $recipients = $this->recipientsFor($profile);
                    if ($recipients->isEmpty()) {
                        Log::info('Probation reminder skipped — no manager or provider_manager recipient.', [
                            'employee_profile_id' => $profile->id,
                        ]);

                        continue;
                    }

                    $notification = new ProbationReviewDueNotification(
                        $profile->user->name,
                        $profile->user_id,
                        $profile->probation_end_date->toDateString(),
                        $profile->probation_end_date->lt($today),
                    );

                    foreach ($recipients as $recipient) {
                        try {
                            $recipient->notify($notification);
                            $sent++;
                        } catch (\Throwable $e) {
                            Log::warning('Probation reminder failed to send.', [
                                'employee_profile_id' => $profile->id,
                                'recipient_id' => $recipient->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // Dedupe: one reminder per probation end date (cleared on extension).
                    $profile->forceFill(['probation_reminder_sent_at' => now()])->save();
                }
            });

        $this->info("Probation reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * The employee's manager; when none is set, every provider_manager
     * (matches the approver-fallback convention in LeaveService).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipientsFor(HrEmployeeProfile $profile): \Illuminate\Support\Collection
    {
        if ($profile->manager_user_id && $profile->manager) {
            return collect([$profile->manager]);
        }

        return User::query()
            ->where(function ($q) {
                $q->where('role', 'provider_manager')
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'provider_manager'));
            })
            ->whereNotNull('approved_at')
            ->get();
    }
}
