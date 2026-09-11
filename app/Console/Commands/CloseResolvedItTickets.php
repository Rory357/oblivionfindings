<?php

namespace App\Console\Commands;

use App\Domain\It\Exceptions\ItSettlementBlocked;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\ItTicket;
use Illuminate\Console\Command;

/**
 * §G auto-close: a ticket resolved 7+ days ago with no pushback closes
 * itself, with a closing event on the trail (actor: system). The requester
 * keeps the full 7-day reopen window before this fires.
 */
class CloseResolvedItTickets extends Command
{
    protected $signature = 'it:close-resolved {--days=7 : Days a ticket stays resolved before auto-closing}';

    protected $description = 'Auto-close IT tickets resolved more than N days ago (default 7)';

    public function handle(ItWorkTransitionService $transitions): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $closed = 0;
        $blocked = 0;
        $changed = 0;

        ItTicket::query()
            ->where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($tickets) use (&$closed, &$blocked, &$changed, $days, $cutoff, $transitions) {
                foreach ($tickets as $ticket) {
                    try {
                        $transitions->autoCloseResolved((int) $ticket->id, $cutoff, $days)
                            ? $closed++ : $changed++;
                    } catch (ItSettlementBlocked) {
                        // Ordinary work blockers stay visible on their ticket.
                        // Storage/audit failures still fail the scheduler run.
                        $blocked++;
                    }
                }
            });

        $this->info("Auto-closed {$closed} resolved ticket(s).");
        if ($blocked > 0 || $changed > 0) {
            $this->info("Skipped {$blocked} ticket(s) with unfinished work and {$changed} no longer eligible.");
        }

        return self::SUCCESS;
    }
}
