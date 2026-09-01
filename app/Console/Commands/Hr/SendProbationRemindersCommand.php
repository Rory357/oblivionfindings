<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Notifications\ProbationReviewDueNotification;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Daily probation sweep (audit fix round 2, item 1a): active employees whose
 * probation_end_date is within 14 days — or already past — with no concluding
 * probation review on file get a ProbationReviewDueNotification to their
 * current Site-eligible manager (manager_user_id; fallback: the first
 * deterministic current Site-eligible provider_manager).
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

    public function handle(UserSiteAccessService $siteAccess): int
    {
        $startedAt = now();
        $today = $startedAt->copy()->startOfDay();
        $profileEligibilityDate = $startedAt
            ->copy()
            ->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString();
        $horizon = $today->copy()->addDays(14);
        $sent = 0;

        HrEmployeeProfile::query()
            ->where('is_active', true)
            ->where(function ($query) use ($profileEligibilityDate): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $profileEligibilityDate);
            })
            ->where(function ($query) use ($profileEligibilityDate): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $profileEligibilityDate);
            })
            ->whereNotNull('probation_end_date')
            ->where('probation_end_date', '<=', $horizon)
            ->whereNull('probation_reminder_sent_at')
            ->whereNotNull('user_id')
            ->with(['user:id,name', 'manager:id,name,email'])
            ->chunkById(200, function ($profiles) use (&$sent, $siteAccess, $today, $profileEligibilityDate) {
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

                    $recipients = $this->recipientsFor($profile, $siteAccess, $profileEligibilityDate);
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
     * The employee's current Site-eligible manager, followed by the first
     * deterministic current Site-eligible provider-manager fallback.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(
        HrEmployeeProfile $profile,
        UserSiteAccessService $siteAccess,
        string $profileEligibilityDate,
    ): Collection {
        $employeeSiteIds = collect([
            $profile->primary_site_id,
            ...(is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : []),
        ])
            ->filter(fn ($siteId) => filled($siteId))
            ->map(fn ($siteId) => (int) $siteId)
            ->filter(fn (int $siteId) => $siteId > 0)
            ->unique()
            ->values()
            ->all();

        if ($employeeSiteIds === []) {
            return collect();
        }

        $fallbackIds = User::query()
            ->where(function ($query): void {
                $query->where('role', 'provider_manager')
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'provider_manager'));
            })
            ->orderBy('id')
            ->pluck('id');

        $candidateIds = collect([$profile->manager_user_id])
            ->concat($fallbackIds)
            ->filter()
            ->map(fn ($userId) => (int) $userId)
            ->unique();

        foreach ($candidateIds as $candidateId) {
            $recipient = User::query()
                ->whereKey($candidateId)
                ->whereNotNull('approved_at')
                ->whereNotIn('role', ['client', 'next_of_kin'])
                ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['client', 'next_of_kin']))
                ->whereHas('hrEmployeeProfile', function ($query) use ($profileEligibilityDate): void {
                    $query->where('is_active', true)
                        ->where(function ($startQuery) use ($profileEligibilityDate): void {
                            $startQuery->whereNull('start_date')->orWhereDate('start_date', '<=', $profileEligibilityDate);
                        })
                        ->where(function ($endQuery) use ($profileEligibilityDate): void {
                            $endQuery->whereNull('end_date')->orWhereDate('end_date', '>=', $profileEligibilityDate);
                        });
                })
                ->first();

            if (! $recipient) {
                continue;
            }

            $recipientSiteIds = $siteAccess->accessibleSiteIds(
                $recipient,
                UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
            );

            if (array_intersect($employeeSiteIds, $recipientSiteIds) !== []) {
                return collect([$recipient]);
            }
        }

        return collect();
    }
}
