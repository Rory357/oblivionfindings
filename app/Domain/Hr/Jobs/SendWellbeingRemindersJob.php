<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Notifications\WellbeingFollowUpDueNotification;
use App\Domain\Hr\Services\EngagementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
 * Pass a tenant id to scope a single tenant; null sweeps all tenants.
 */
class SendWellbeingRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $tenantId = null) {}

    public function handle(EngagementService $engagement): void
    {
        $closed = $this->autoCloseExpiredSurveys($engagement);
        $reminded = $this->sendFollowUpReminders();

        Log::info('SendWellbeingRemindersJob: wellbeing sweep completed.', [
            'tenant_id' => $this->tenantId,
            'surveys_closed' => $closed,
            'follow_up_reminders' => $reminded,
        ]);
    }

    protected function autoCloseExpiredSurveys(EngagementService $engagement): int
    {
        $closed = 0;

        HrEngagementSurvey::query()
            ->when($this->tenantId !== null, fn ($query) => $query->forTenant($this->tenantId))
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

    protected function sendFollowUpReminders(): int
    {
        $reminded = 0;

        HrWellbeingCheckin::query()
            ->when($this->tenantId !== null, fn ($query) => $query->forTenant($this->tenantId))
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', now()->toDateString())
            ->whereNull('follow_up_reminder_sent_at')
            ->with(['staff:id,name', 'manager:id,name'])
            ->chunkById(200, function ($checkins) use (&$reminded) {
                foreach ($checkins as $checkin) {
                    try {
                        if ($checkin->manager) {
                            $checkin->manager->notify(new WellbeingFollowUpDueNotification($checkin));
                            $reminded++;
                        }

                        // Stamp even when the manager is gone so orphan rows
                        // aren't rescanned every day.
                        $checkin->forceFill(['follow_up_reminder_sent_at' => now()])->saveQuietly();
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
}
