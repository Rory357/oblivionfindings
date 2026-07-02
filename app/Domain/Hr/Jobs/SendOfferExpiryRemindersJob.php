<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Notifications\OfferExpiryInternalNotification;
use App\Domain\Hr\Notifications\OfferExpiryReminderNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Daily offer-expiry sweep (scheduled in routes/console.php):
 *
 * - Offers sent, unanswered, with the portal window closing within 3 days →
 *   remind the candidate (personal email) and the hiring manager. A second
 *   reminder fires once inside the final day; the `expiry_reminder_sent_at`
 *   stamp prevents anything beyond those two.
 * - Offers whose window has already closed unanswered → a one-time "offer
 *   expired" notice to the hiring manager (`expired_notice_sent_at`).
 *
 * All sends are best-effort per offer. Pass a tenant id to scope one tenant.
 */
class SendOfferExpiryRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $tenantId = null) {}

    public function handle(): void
    {
        $candidateReminders = 0;
        $managerReminders = 0;
        $expiredNotices = 0;

        $query = HrOffer::query()
            ->with(['application.candidate', 'application.requisition.hiringManager'])
            ->whereNotNull('sent_at')
            ->whereNull('response')
            ->whereNotNull('portal_expires_at')
            ->where('portal_expires_at', '<=', now()->addDays(3)->endOfDay());

        if ($this->tenantId !== null) {
            $query->whereHas('application', fn ($q) => $q->where('tenant_id', $this->tenantId));
        }

        $query->chunkById(100, function ($offers) use (&$candidateReminders, &$managerReminders, &$expiredNotices) {
            foreach ($offers as $offer) {
                $candidate = $offer->application?->candidate;
                if (! $candidate) {
                    continue;
                }

                if ($offer->portal_expires_at->isPast()) {
                    if ($offer->expired_notice_sent_at === null) {
                        $sent = $this->notifyManagers($offer, new OfferExpiryInternalNotification($offer, $candidate, 'expired'));
                        $expiredNotices += $sent;
                        $offer->update(['expired_notice_sent_at' => now()]);
                    }

                    continue;
                }

                $daysLeft = max(0, (int) ceil(now()->diffInDays($offer->portal_expires_at, false)));

                // First reminder on entering the 3-day window; one more once
                // inside the final day (only if the first fired earlier).
                $firstDue = $offer->expiry_reminder_sent_at === null;
                $finalDue = $daysLeft <= 1
                    && $offer->expiry_reminder_sent_at !== null
                    && $offer->expiry_reminder_sent_at->lt($offer->portal_expires_at->copy()->subDay());

                if (! $firstDue && ! $finalDue) {
                    continue;
                }

                if ($candidate->personal_email) {
                    try {
                        Notification::route('mail', $candidate->personal_email)
                            ->notify(new OfferExpiryReminderNotification($offer, $candidate, $daysLeft));
                        $candidateReminders++;
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }

                $managerReminders += $this->notifyManagers($offer, new OfferExpiryInternalNotification($offer, $candidate, 'expiring', $daysLeft));

                $offer->update(['expiry_reminder_sent_at' => now()]);
            }
        });

        Log::info('SendOfferExpiryRemindersJob: offer expiry sweep completed.', [
            'tenant_id' => $this->tenantId,
            'candidate_reminders' => $candidateReminders,
            'manager_reminders' => $managerReminders,
            'expired_notices' => $expiredNotices,
        ]);
    }

    /** Best-effort send to the requisition's hiring manager + the offer author, deduped. */
    private function notifyManagers(HrOffer $offer, OfferExpiryInternalNotification $notification): int
    {
        $recipients = collect([
            $offer->application?->requisition?->hiringManager,
            $offer->created_by ? User::find($offer->created_by) : null,
        ])->filter()->unique('id');

        $sent = 0;
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify($notification);
                $sent++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }
}
