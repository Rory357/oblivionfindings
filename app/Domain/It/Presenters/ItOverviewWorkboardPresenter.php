<?php

namespace App\Domain\It\Presenters;

use App\Domain\It\Services\ItSlaReadService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\User;

/** Single-organisation, approved-Site/record-scoped previews; no private thread bodies. */
final class ItOverviewWorkboardPresenter
{
    private const QUEUES = ['attention', 'mine', 'unassigned', 'breached', 'breaching', 'awaiting_it', 'awaiting_reply', 'waiting_requester', 'aging', 'waiting', 'unmeasured', 'all_open'];

    public function __construct(private readonly ItWorkAccessService $access, private readonly ItSlaReadService $sla) {}

    public function present(User $user): array
    {
        $ready = ItTicket::hasConversationEvidence();
        $base = $this->access->applyViewScope(ItTicket::query(), $user)->whereIn('status', ItTicket::OPEN_STATUSES);
        // Operational waiting views use the same stronger work boundary as the full queue.
        $workIds = array_fill_keys($this->access->applyWorkScope(ItTicket::query(), $user)
            ->whereIn('status', ItTicket::OPEN_STATUSES)->pluck('it_tickets.id')->all(), true);
        $scopes = [];
        foreach (['all', ...ItTicket::PRIORITIES] as $priority) {
            $scopes[$priority] = ['queues' => array_fill_keys(self::QUEUES, ['total' => 0, 'candidates' => []]), 'responses' => [], 'followups' => []];
        }
        $unmeasured = 0;
        $at = $this->sla->evaluatedAt();
        $columns = ['id', 'status', 'priority', 'created_at', 'assigned_to_user_id', 'waiting_party', 'merged_into_ticket_id',
            'first_response_due_at', 'resolution_due_at', 'first_responded_at', 'resolved_at', 'waiting_since',
            'sla_paused_minutes', 'reopened_count', 'sla_policy_snapshot', 'first_response_breached_at', 'resolution_breached_at'];
        if ($ready) {
            $columns[] = 'next_response_party';
        }
        // Stream the full permitted set for truthful totals; retain only six IDs per preview.
        foreach ((clone $base)->select($columns)->lazyById(250) as $ticket) {
            $verdict = $this->sla->present($ticket)['sla'];
            $state = $verdict['state'];
            $first = in_array($ticket->status, ['open', 'in_progress'], true) && $ticket->first_responded_at === null;
            $awaitingIt = $ready && $ticket->next_response_party === 'it' && ! $ticket->isMerged();
            $aging = $ticket->created_at?->lt($at->subDays(7)) === true;
            $priorityRank = array_search($ticket->priority, ['urgent', 'high', 'normal', 'low'], true);
            $ageRank = $ticket->created_at?->toIso8601String() ?? '9999';
            $clockRank = $this->clockRank($verdict['clocks']['first_response']);
            $slaRank = min($clockRank, $this->clockRank($verdict['clocks']['resolution']));
            $attention = in_array($state, ['breached', 'at_risk'], true);
            $keys = ['all_open'];
            if ($attention) {
                $keys[] = 'attention';
            }
            if ($state === 'breached') {
                $keys[] = 'breached';
            }
            if ($state === 'at_risk') {
                $keys[] = 'breaching';
            }
            if ($state === 'unmeasured') {
                $keys[] = 'unmeasured';
            }
            if ($ticket->assigned_to_user_id === $user->id) {
                $keys[] = 'mine';
            }
            if ($ticket->assigned_to_user_id === null) {
                $keys[] = 'unassigned';
            }
            if ($first) {
                $keys[] = 'awaiting_reply';
            }
            if ($awaitingIt) {
                $keys[] = 'awaiting_it';
            }
            if ($aging) {
                $keys[] = 'aging';
            }
            if ($ticket->status === 'waiting') {
                $keys[] = 'waiting';
            }
            if ($ticket->status === 'waiting' && $ticket->waiting_party === 'requester' && isset($workIds[$ticket->id])) {
                $keys[] = 'waiting_requester';
            }
            if ($verdict['coverage'] !== 'full') {
                $unmeasured++;
            }
            foreach (array_unique(['all', $ticket->priority]) as $priority) {
                if (! isset($scopes[$priority])) {
                    continue;
                }
                foreach ($keys as $key) {
                    $queue = &$scopes[$priority]['queues'][$key];
                    $queue['total']++;
                    $rank = $key === 'aging' ? [$ageRank] : [...$slaRank, $priorityRank, $ageRank];
                    if ($key === 'unassigned') {
                        $rank = [$priorityRank, ...$slaRank, $ageRank];
                    }
                    $this->retain($queue['candidates'], (int) $ticket->id, $rank);
                    unset($queue);
                }
                if ($first) {
                    $this->retain($scopes[$priority]['responses'], (int) $ticket->id, [...$clockRank, $priorityRank, $ageRank]);
                }
                if ($aging) {
                    $this->retain($scopes[$priority]['followups'], (int) $ticket->id, [$awaitingIt ? 0 : 1, $ageRank]);
                }
            }
        }
        $retained = [];
        foreach ($scopes as &$scope) {
            $response = $scope['responses'][0]['id'] ?? null;
            $assignment = collect($scope['queues']['unassigned']['candidates'])->first(fn ($row) => $row['id'] !== $response)['id'] ?? null;
            $followup = collect($scope['followups'])->first(fn ($row) => ! in_array($row['id'], [$response, $assignment], true))['id'] ?? null;
            $scope['actions'] = ['response' => $response, 'assignment' => $assignment, 'follow_up' => $followup];
            foreach ($scope['queues'] as &$queue) {
                $queue['ids'] = array_column($queue['candidates'], 'id');
                $retained = [...$retained, ...$queue['ids']];
                unset($queue['candidates']);
            }
            unset($queue, $scope['responses'], $scope['followups']);
            $retained = [...$retained, $response, $assignment, $followup];
        }
        unset($scope);
        $tickets = (clone $base)->whereKey(array_values(array_unique(array_filter($retained))))
            ->with(['assignee:id,name', 'site:id,name'])->get()->mapWithKeys(function (ItTicket $ticket) use ($workIds, $ready): array {
                $canWork = isset($workIds[$ticket->id]);

                return [$ticket->id => [
                    'id' => (int) $ticket->id, 'reference' => $ticket->reference, 'lock_version' => (int) $ticket->lock_version,
                    'title' => $ticket->title, 'priority' => $ticket->priority, 'status' => $ticket->status,
                    'site' => $ticket->site?->name, 'assignee' => $ticket->assignee?->name,
                    'assigned_to_user_id' => $ticket->assigned_to_user_id === null ? null : (int) $ticket->assigned_to_user_id,
                    'age' => $ticket->created_at?->diffForHumans(short: true),
                    'first_reply_needed' => in_array($ticket->status, ['open', 'in_progress'], true) && $ticket->first_responded_at === null,
                    'waiting_party' => $canWork ? $ticket->waiting_party : null,
                    'conversation' => $ready ? app(ItTicketConversationPresenter::class)->present($ticket) : null,
                    'can_manage' => $canWork,
                    ...$this->sla->present($ticket),
                ]];
            });

        return ['scopes' => $scopes, 'tickets' => $tickets, 'unmeasured_total' => $unmeasured, 'evaluated_at' => $at->toIso8601String()];
    }

    private function clockRank(array $clock): array
    {
        return [match ($clock['state']) {
            'breached' => 0, 'at_risk' => 1, default => 2
        }, $clock['due_at'] ?? '9999'];
    }

    private function retain(array &$rows, int $id, array $rank): void
    {
        $rows[] = ['id' => $id, 'rank' => [...$rank, $id]];
        usort($rows, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);
        if (count($rows) > 6) {
            array_pop($rows);
        }
    }
}
