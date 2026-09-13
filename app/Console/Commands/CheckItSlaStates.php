<?php

namespace App\Console\Commands;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItSlaClockService;
use App\Domain\It\Services\ItTicketRoutingEligibility;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketSlaNotification;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** The shared verdict, transition evidence and notification intent commit together. */
class CheckItSlaStates extends Command
{
    protected $signature = 'it:check-sla';

    protected $description = 'Evaluate IT SLA clocks and prepare recoverable escalation notifications';

    private const UNASSIGNED_URGENT_MINUTES = 30;

    public function handle(ItSlaClockService $clocks, ItEmailDeliveryService $deliveries, ItTicketRoutingEligibility $eligibility, ItAutomationRunRecorder $runs): int
    {
        $run = $runs->begin('it.check-sla', '0 * * * *');
        $started = hrtime(true);
        $now = now();
        $counts = ['checked' => 0, 'at_risk' => 0, 'breached' => 0, 'escalated' => 0];

        try {
            ItTicket::query()->whereNull('merged_into_ticket_id')->orderBy('id')
                ->chunkById(200, function ($tickets) use ($clocks, $deliveries, $eligibility, $now, &$counts) {
                    foreach ($tickets as $candidate) {
                        DB::transaction(function () use ($candidate, $clocks, $deliveries, $eligibility, $now, &$counts) {
                            $ticket = ItTicket::query()->lockForUpdate()->findOrFail($candidate->id);
                            $verdict = $clocks->synchronize($ticket, $now);
                            $ticket->save();
                            $counts['checked']++;
                            if (! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
                                return;
                            }
                            foreach ($verdict['clocks'] as $name => $clock) {
                                if (! in_array($clock['state'], ['at_risk', 'breached'], true)) {
                                    continue;
                                }
                                $type = 'sla_'.$clock['state'];
                                $evidenceAt = $clock['state'] === 'breached' ? $clock['breached_at'] : $clock['due_at'];
                                $event = $ticket->events()->where('type', $type)->where('payload->clock', $name)
                                    ->when($clock['state'] === 'at_risk', fn ($query) => $query->where('payload->due_at', $clock['due_at']))
                                    ->first();
                                if ($event && ($event->payload['recipient_count'] ?? 1) > 0) {
                                    continue;
                                }
                                if ($event && $ticket->events()->where('type', 'sla_notification_coverage_restored')
                                    ->where('payload->original_event_id', $event->id)->exists()) {
                                    continue;
                                }
                                $recipients = $this->responsibleRecipients($ticket, $eligibility);
                                if ($clock['state'] === 'breached') {
                                    $recipients = $recipients->merge(ItStaffDirectory::agentsForTicket($ticket)
                                        ->filter(fn (User $user) => $eligibility->agent($user->id, $ticket) !== null));
                                }
                                $recipients = $recipients->unique('id');
                                if ($event && $recipients->isEmpty()) {
                                    continue;
                                }
                                $payload = [
                                    'clock' => $name, 'due_at' => $clock['due_at'], 'evidence_at' => $evidenceAt,
                                    'recipient_count' => $recipients->count(), 'coverage_gap' => $recipients->isEmpty(),
                                ];
                                if ($event) {
                                    ItTicketEvent::record($ticket, 'sla_notification_coverage_restored', null, [
                                        ...$payload, 'original_event_id' => $event->id, 'transition' => $clock['state'],
                                    ]);
                                } else {
                                    ItTicketEvent::record($ticket, $type, null, $payload);
                                    $counts[$clock['state']]++;
                                }
                                $deliveries->prepare($recipients, new TicketSlaNotification($ticket, $clock['state'], $name));
                            }
                            $counts['escalated'] += (int) $this->escalate($ticket, $now, $eligibility, $deliveries);
                        });
                        // The committed outbox can be drained again after a killed worker.
                        $deliveries->dispatchPending(100, $candidate->id);
                    }
                });
            $runs->completeRun($run, 'succeeded', (int) ((hrtime(true) - $started) / 1_000_000), result: $counts);
        } catch (Throwable $exception) {
            $runs->completeRun($run, 'failed', (int) ((hrtime(true) - $started) / 1_000_000), 'The SLA watchdog did not complete. Review the local error trace before retrying.');
            throw $exception;
        }

        $this->info("SLA check: {$counts['checked']} checked, {$counts['at_risk']} newly at risk, {$counts['breached']} newly breached, {$counts['escalated']} escalated.");

        return self::SUCCESS;
    }

    private function responsibleRecipients(ItTicket $ticket, ItTicketRoutingEligibility $eligibility): Collection
    {
        $ids = [$ticket->assigned_to_user_id, $ticket->owner_user_id];
        $recipients = collect($ids)->filter()->unique()->map(fn ($id) => $eligibility->agent((int) $id, $ticket))->filter();
        if ($recipients->isEmpty()) {
            $cover = $ticket->queue?->filter_rules['cover_user_id'] ?? null;
            if ($cover) {
                $recipients = collect([$eligibility->agent((int) $cover, $ticket)])->filter();
            }
        }

        return $recipients;
    }

    private function escalate(ItTicket $ticket, CarbonInterface $now, ItTicketRoutingEligibility $eligibility, ItEmailDeliveryService $deliveries): bool
    {
        if ($ticket->priority !== 'urgent'
            || $eligibility->agent($ticket->assigned_to_user_id, $ticket) !== null
            || $eligibility->agent($ticket->owner_user_id, $ticket) !== null) {
            return false;
        }
        $lastReopenAt = $ticket->events()->where('type', 'reopened')->latest('created_at')->value('created_at');
        $lastReopenAt = $lastReopenAt ? Carbon::parse($lastReopenAt) : null;
        $unassignedSince = $lastReopenAt ?? $ticket->created_at;
        if ($now->getTimestamp() - $unassignedSince->getTimestamp() < self::UNASSIGNED_URGENT_MINUTES * 60) {
            return false;
        }
        $event = $ticket->events()->where('type', 'sla_escalated')
            ->when($lastReopenAt, fn ($query) => $query->where('created_at', '>=', $lastReopenAt))->first();
        if ($event && ($event->payload['recipient_count'] ?? 1) > 0) {
            return false;
        }
        if ($event && $ticket->events()->where('type', 'sla_escalation_coverage_restored')
            ->where('payload->original_event_id', $event->id)->exists()) {
            return false;
        }
        $recipients = $this->responsibleRecipients($ticket, $eligibility);
        if ($recipients->isEmpty()) {
            $recipients = ItStaffDirectory::admins()->filter(fn (User $admin) => $eligibility->agent($admin->id, $ticket) !== null);
        }
        if ($event && $recipients->isEmpty()) {
            return false;
        }
        $payload = [
            'reason' => $ticket->assigned_to_user_id || $ticket->owner_user_id ? 'unavailable_owner' : 'unassigned_urgent',
            'unassigned_minutes' => (int) (($now->getTimestamp() - $unassignedSince->getTimestamp()) / 60),
            'recipient_count' => $recipients->count(),
            'coverage_gap' => $recipients->isEmpty(),
        ];
        if ($event) {
            ItTicketEvent::record($ticket, 'sla_escalation_coverage_restored', null, [
                ...$payload, 'original_event_id' => $event->id,
            ]);
        } else {
            ItTicketEvent::record($ticket, 'sla_escalated', null, $payload);
        }
        $deliveries->prepare($recipients, new TicketSlaNotification($ticket, 'escalation'));

        return true;
    }
}
