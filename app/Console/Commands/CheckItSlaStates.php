<?php

namespace App\Console\Commands;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketSlaNotification;
use App\Support\It\BusinessHours;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * §G scheduler, run hourly: re-derives every open ticket's SLA state from
 * its clocks (waiting pauses honoured — banked minutes plus the live pause
 * on a currently-waiting ticket), records at-risk/breach transitions on the
 * event trail, and notifies — the assignee when at risk, the assignee plus
 * every it.manage agent on breach, admins when an urgent ticket has sat
 * unassigned for 30+ minutes. The persisted state change is the idempotency
 * guard for clock transitions (a re-run with no movement sends nothing);
 * the escalation, having no state column, is guarded by its
 * it_ticket_events row.
 */
class CheckItSlaStates extends Command
{
    protected $signature = 'it:check-sla';

    protected $description = 'Transition IT ticket SLA states (at-risk/breach) and send escalations';

    /** Fraction of the SLA window remaining at which a ticket turns at-risk. */
    private const AT_RISK_THRESHOLD = 0.25;

    /** Minutes an urgent ticket may sit unassigned before admins hear. */
    private const UNASSIGNED_URGENT_MINUTES = 30;

    private ItEmailDeliveryService $emailDeliveries;

    private ItWorkAccessService $workAccess;

    public function handle(ItEmailDeliveryService $emailDeliveries, ItWorkAccessService $workAccess): int
    {
        $this->emailDeliveries = $emailDeliveries;
        $this->workAccess = $workAccess;
        $now = now();
        $atRisk = $breached = $escalated = 0;

        ItTicket::query()
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->where('sla_state', '!=', 'met')
            ->orderBy('id')
            ->chunkById(200, function ($tickets) use ($now, &$atRisk, &$breached, &$escalated) {
                foreach ($tickets as $ticket) {
                    $transition = $this->applyClockState($ticket, $now);
                    $atRisk += (int) ($transition === 'at_risk');
                    $breached += (int) ($transition === 'breached');
                    $escalated += (int) $this->escalateUnassignedUrgent($ticket, $now);
                }
            });

        $this->info("SLA check: {$atRisk} newly at risk, {$breached} newly breached, {$escalated} escalated to admins.");

        return self::SUCCESS;
    }

    /**
     * Recompute the ticket's SLA state from its clocks and persist any
     * change. Returns the state when the ticket ENTERED at_risk/breached —
     * which is also when the event and notifications fire. Relaxations
     * (a growing waiting pause can un-breach; a priority restamp can
     * un-risk) save silently: the state field stays honest, nobody is
     * paged about good news.
     */
    private function applyClockState(ItTicket $ticket, CarbonInterface $now): ?string
    {
        $verdict = $this->clockVerdict($ticket, $now);
        if ($verdict === null) {
            return null; // nothing stamped (legacy row) — nothing to check
        }

        [$state, $clock, $dueAt] = $verdict;
        if ($state === $ticket->sla_state) {
            return null;
        }

        $entered = in_array($state, ['at_risk', 'breached'], true);
        $ticket->sla_state = $state;
        $ticket->save();

        if (! $entered) {
            return null;
        }

        ItTicketEvent::record($ticket, 'sla_'.$state, null, [
            'clock' => $clock,
            'due_at' => $dueAt->toIso8601String(),
        ]);
        $this->notifyTransition($ticket, $state, $clock);

        return $state;
    }

    /**
     * The nearest live deadline decides the state. The first-response clock
     * runs until the first public agent reply; the resolution clock runs
     * until resolved, shifted out by every paused minute (§G: "waiting on
     * requester" pauses the resolution clock only).
     *
     * @return array{0: string, 1: string, 2: CarbonInterface}|null [state, clock, effective due]
     */
    private function clockVerdict(ItTicket $ticket, CarbonInterface $now): ?array
    {
        $pausedMinutes = (int) $ticket->sla_paused_minutes;
        if ($ticket->waiting_since) {
            $pausedMinutes += max(0, (int) floor(($now->getTimestamp() - $ticket->waiting_since->getTimestamp()) / 60));
        }

        $deadlines = [];
        if (! $ticket->first_responded_at && $ticket->first_response_due_at) {
            $deadlines['first_response'] = $ticket->first_response_due_at;
        }
        if ($ticket->resolution_due_at) {
            $deadlines['resolution'] = $ticket->resolution_due_at->copy()->addMinutes($pausedMinutes);
        }
        if ($deadlines === []) {
            return null;
        }

        $clock = array_key_first($deadlines);
        foreach ($deadlines as $name => $due) {
            if ($due->lt($deadlines[$clock])) {
                $clock = $name;
            }
        }
        $due = $deadlines[$clock];

        if ($now->getTimestamp() >= $due->getTimestamp()) {
            return ['breached', $clock, $due];
        }

        // At-risk fires once the SLA window is ~75% spent. With a business-
        // hours calendar we measure the WORKING slice of the window (else a
        // Friday ticket trips at-risk over the weekend when nobody is on
        // shift); without one it stays wall-clock seconds — the unchanged
        // 24/7 path. Timestamp/working-minute arithmetic on purpose: Carbon
        // diff signs have bitten this codebase before.
        $calendar = ItSlaPolicy::calendarFor((string) $ticket->priority);
        if ($calendar !== null) {
            $window = BusinessHours::workingMinutesBetween($ticket->created_at, $due, $calendar);
            $remaining = BusinessHours::workingMinutesBetween($now, $due, $calendar);
        } else {
            $window = $due->getTimestamp() - $ticket->created_at->getTimestamp();
            $remaining = $due->getTimestamp() - $now->getTimestamp();
        }
        if ($window > 0 && $remaining <= $window * self::AT_RISK_THRESHOLD) {
            return ['at_risk', $clock, $due];
        }

        return ['ok', $clock, $due];
    }

    /**
     * At risk → the assignee's problem; breached → everyone on the queue
     * hears too. An unassigned at-risk ticket notifies nobody (the state
     * and event still land) — ownership alarms are the escalation's job.
     */
    private function notifyTransition(ItTicket $ticket, string $state, string $clock): void
    {
        $recipients = collect();
        if ($ticket->assignee) {
            $recipients->push($ticket->assignee);
        }
        if ($state === 'breached') {
            $recipients = $recipients->merge(ItStaffDirectory::agentsForTicket($ticket));
        }

        $recipients = $recipients->unique('id');
        if ($recipients->isNotEmpty()) {
            $this->emailDeliveries->send($recipients, new TicketSlaNotification($ticket, $state, $clock));
        }
    }

    /**
     * §G escalation of last resort: an urgent ticket nobody owns after 30
     * minutes goes to the admins. Fires once per ticket, re-armed only by
     * a reopen (a fresh episode) — the sla_escalated event row is the guard.
     */
    private function escalateUnassignedUrgent(ItTicket $ticket, CarbonInterface $now): bool
    {
        if ($ticket->assigned_to_user_id || $ticket->priority !== 'urgent') {
            return false;
        }

        $lastReopenAt = $ticket->events()
            ->where('type', 'reopened')
            ->latest('created_at')
            ->value('created_at');
        $lastReopenAt = $lastReopenAt ? Carbon::parse($lastReopenAt) : null;
        $unassignedSince = $lastReopenAt ?? $ticket->created_at;

        if ($now->getTimestamp() - $unassignedSince->getTimestamp() < self::UNASSIGNED_URGENT_MINUTES * 60) {
            return false;
        }

        $alreadyEscalated = $ticket->events()
            ->where('type', 'sla_escalated')
            ->when($lastReopenAt, fn ($q) => $q->where('created_at', '>=', $lastReopenAt))
            ->exists();
        if ($alreadyEscalated) {
            return false;
        }

        ItTicketEvent::record($ticket, 'sla_escalated', null, [
            'reason' => 'unassigned_urgent',
            'unassigned_minutes' => (int) floor(($now->getTimestamp() - $unassignedSince->getTimestamp()) / 60),
        ]);

        $admins = ItStaffDirectory::admins()
            ->filter(fn (User $admin): bool => $this->workAccess->canWork($admin, $ticket))
            ->values();
        if ($admins->isNotEmpty()) {
            $this->emailDeliveries->send($admins, new TicketSlaNotification($ticket, 'escalation'));
        }

        return true;
    }
}
