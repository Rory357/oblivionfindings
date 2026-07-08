<?php

namespace App\Console\Commands;

use App\Models\ItTicket;
use App\Models\ItTicketEvent;
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

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $closed = 0;

        ItTicket::query()
            ->where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($tickets) use (&$closed, $days) {
                foreach ($tickets as $ticket) {
                    $ticket->status = 'closed';
                    $ticket->closed_at = now();
                    $ticket->save();

                    ItTicketEvent::record($ticket, 'closed', null, [
                        'via' => 'auto_close',
                        'after_days' => $days,
                    ]);
                    $closed++;
                }
            });

        $this->info("Auto-closed {$closed} resolved ticket(s).");

        return self::SUCCESS;
    }
}
