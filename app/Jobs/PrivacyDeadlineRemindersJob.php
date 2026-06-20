<?php

namespace App\Jobs;

use App\Domain\Privacy\Services\PrivacyRecipients;
use App\Domain\Privacy\Services\StatutoryDueDate;
use App\Models\DataBreachLog;
use App\Models\DataSubjectRequest;
use App\Notifications\Privacy\PrivacyDeadlineDigestNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Daily privacy-deadline sweep: alerts privacy officers about access/correction
 * requests that are overdue or due soon, and notifiable breaches still awaiting
 * notification to the Office of the Privacy Commissioner. One digest per officer;
 * sends nothing when there is nothing outstanding.
 */
class PrivacyDeadlineRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Working days of lead time before a deadline counts as "due soon". */
    private const DUE_SOON_WORKING_DAYS = 3;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $today = now()->startOfDay();
        $soonThreshold = app(StatutoryDueDate::class)->addWorkingDays($today, self::DUE_SOON_WORKING_DAYS);

        $overdue = DataSubjectRequest::query()->overdue()->get();

        $dueSoon = DataSubjectRequest::query()
            ->open()
            ->whereRaw('COALESCE(extended_due_date, due_date) >= ?', [$today->toDateString()])
            ->whereRaw('COALESCE(extended_due_date, due_date) <= ?', [$soonThreshold->toDateString()])
            ->get();

        $breachOpc = DataBreachLog::query()
            ->where('requires_authority_notification', true)
            ->whereNull('authority_notified_at')
            ->get();

        $breachSubjects = DataBreachLog::query()
            ->where('requires_subject_notification', true)
            ->whereNull('subjects_notified_at')
            ->get();

        if ($overdue->isEmpty() && $dueSoon->isEmpty() && $breachOpc->isEmpty() && $breachSubjects->isEmpty()) {
            return;
        }

        $officers = PrivacyRecipients::withPermission('privacy.viewRequests');

        if ($officers->isEmpty()) {
            return;
        }

        $notification = new PrivacyDeadlineDigestNotification(
            overdueCount: $overdue->count(),
            dueSoonCount: $dueSoon->count(),
            breachOpcCount: $breachOpc->count(),
            breachSubjectCount: $breachSubjects->count(),
            overdueRefs: $overdue->take(5)->pluck('reference_number')->filter()->values()->all(),
            dueSoonRefs: $dueSoon->take(5)->pluck('reference_number')->filter()->values()->all(),
        );

        Notification::send($officers, $notification);
    }
}
