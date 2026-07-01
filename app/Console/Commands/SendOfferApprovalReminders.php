<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Notifications\OfferApprovalNotification;
use Illuminate\Console\Command;

/**
 * Escalates offers stuck awaiting sign-off: if an offer has sat in
 * pending_approval longer than the threshold and the approver hasn't been
 * reminded this cycle, nudge the requisition's hiring manager. Idempotent via
 * approval_reminder_sent_at (cleared on re-submit), so re-running never
 * double-sends. Scheduled dailyAt 08:20 NZ.
 */
class SendOfferApprovalReminders extends Command
{
    protected $signature = 'recruitment:send-offer-approval-reminders
        {--days=2 : Remind only offers pending approval at least this many days}
        {--dry-run : Report eligible offers without sending}';

    protected $description = 'Remind hiring managers about offers still awaiting their approval.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $offers = HrOffer::query()
            ->with(['application.requisition.hiringManager', 'application.candidate'])
            ->where('approval_status', 'pending_approval')
            ->whereNull('sent_at')
            ->whereNull('response')
            ->whereNull('approval_reminder_sent_at')
            ->where('approval_requested_at', '<=', $cutoff)
            ->get();

        $this->info("Found {$offers->count()} offer(s) awaiting approval ≥ {$days} day(s).");

        $sent = 0;
        foreach ($offers as $offer) {
            $approver = $offer->application?->requisition?->hiringManager;
            $candidateName = $offer->application?->candidate?->full_name ?? 'a candidate';

            if (! $approver) {
                $this->warn("  offer #{$offer->id} has no hiring manager to remind — skipping.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would remind {$approver->name} about offer #{$offer->id} ({$candidateName})");

                continue;
            }

            try {
                $approver->notify(new OfferApprovalNotification($offer, 'reminder', $candidateName));
                $offer->forceFill(['approval_reminder_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->warn("  failed to remind about offer #{$offer->id}: {$exception->getMessage()}");
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run complete.' : "Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
