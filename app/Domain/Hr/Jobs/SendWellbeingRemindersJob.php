<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Notifications\WellbeingFollowUpDueNotification;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\HrWellbeingAccessService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily wellbeing sweep (scheduled 08:00 NZ in routes/console.php):
 *
 *  (a) Auto-close published engagement surveys whose ends_at has passed —
 *      responses are already refused after ends_at (EngagementService::
 *      submitResponse), so this simply lands the status where it belongs,
 *      via the same closeSurvey() path the manual Close action uses.
 *
 *  (b) Wellbeing check-ins whose follow_up_date has arrived with no follow-up
 *      recorded → notify the manager who logged the check-in, exactly once
 *      (deduped via the follow_up_reminder_sent_at stamp).
 *
 * Oblivion Findings is one application across all Sites, so this sweep always
 * evaluates every due record and applies canonical staff/Site access before a
 * confidential reminder is delivered.
 */
class SendWellbeingRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EngagementService $engagement, HrWellbeingAccessService $access): void
    {
        $closed = $this->autoCloseExpiredSurveys($engagement);
        $reminded = $this->sendFollowUpReminders($access);

        Log::info('SendWellbeingRemindersJob: wellbeing sweep completed.', [
            'surveys_closed' => $closed,
            'follow_up_reminders' => $reminded,
        ]);
    }

    protected function autoCloseExpiredSurveys(EngagementService $engagement): int
    {
        $closed = 0;

        HrEngagementSurvey::query()
            ->where('status', 'published')
            ->whereNotNull('ends_at')
            // ends_at is a date (midnight); "<= today" mirrors submitResponse's
            // ends_at->isPast() refusal at the time this job runs.
            ->whereDate('ends_at', '<=', now()->toDateString())
            ->get()
            ->each(function (HrEngagementSurvey $survey) use ($engagement, &$closed) {
                try {
                    $engagement->closeSurvey($survey, null);
                    $closed++;
                } catch (\Throwable $e) {
                    Log::warning('Failed to auto-close engagement survey', [
                        'survey_id' => $survey->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $closed;
    }

    protected function sendFollowUpReminders(HrWellbeingAccessService $access): int
    {
        $reminded = 0;

        HrWellbeingCheckin::query()
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', now()->toDateString())
            ->whereNull('follow_up_reminder_sent_at')
            ->chunkById(200, function ($checkins) use ($access, &$reminded) {
                foreach ($checkins as $checkin) {
                    try {
                        $sent = DB::transaction(function () use ($access, $checkin): bool {
                            $locked = HrWellbeingCheckin::query()
                                ->whereNull('follow_up_reminder_sent_at')
                                ->lockForUpdate()
                                ->find($checkin->getKey());
                            if (! $locked) {
                                return false;
                            }

                            $locked->load(['staff:id,name', 'manager:id,name']);
                            $manager = $locked->manager;
                            if (! $manager || ! $this->managerCanAccessCheckin($access, $manager, $locked)) {
                                $locked->forceFill(['follow_up_reminder_sent_at' => now()])->saveQuietly();

                                return false;
                            }

                            $alreadySent = $manager->notifications()
                                ->where('type', WellbeingFollowUpDueNotification::class)
                                ->where('data->checkin_id', $locked->id)
                                ->exists();
                            if (! $alreadySent) {
                                $manager->notify(new WellbeingFollowUpDueNotification($locked));
                            }
                            $locked->forceFill(['follow_up_reminder_sent_at' => now()])->saveQuietly();

                            return ! $alreadySent;
                        }, attempts: 1);
                        if ($sent) {
                            $reminded++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Failed to send wellbeing follow-up reminder', [
                            'checkin_id' => $checkin->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $reminded;
    }

    private function managerCanAccessCheckin(
        HrWellbeingAccessService $access,
        User $manager,
        HrWellbeingCheckin $checkin,
    ): bool {
        try {
            $access->currentStaff($manager, $manager);
            $access->checkin($manager, $checkin);
        } catch (ModelNotFoundException) {
            return false;
        }

        return true;
    }
}
