<?php

namespace App\Domain\It\Presenters;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use Illuminate\Support\Arr;

final class ItTicketActivityPresenter
{
    /** @var list<string> */
    private const REQUESTER_VISIBLE_TYPES = [
        'created',
        'status_changed',
        'workflow_transitioned',
        'reopened',
        'resolved',
        'closed',
        'first_response_recorded',
        'approval_requested',
        'approval_approved',
        'approval_rejected',
        'csat_submitted',
        'csat_updated',
        'email_received',
        'api_public_comment',
        'merged',
    ];

    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    /** @return list<array<string, mixed>> */
    public function present(ItTicket $ticket, User $viewer): array
    {
        $canWork = $this->workAccess->canWork($viewer, $ticket);

        return $ticket->events()
            ->when(
                ! $canWork,
                fn ($query) => $query->whereIn('type', self::REQUESTER_VISIBLE_TYPES),
            )
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ItTicketEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'payload' => $canWork ? $event->payload : $this->publicPayload($event),
                'actor' => $event->actor?->name,
                'at' => $event->created_at?->toIso8601String(),
                'at_human' => $event->created_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function publicPayload(ItTicketEvent $event): ?array
    {
        $payload = $event->payload ?? [];
        $safe = match ($event->type) {
            'created' => Arr::only($payload, ['source']),
            'status_changed', 'workflow_transitioned', 'reopened', 'resolved', 'closed' => Arr::only($payload, [
                'from',
                'to',
                'from_workflow_state',
                'to_workflow_state',
            ]),
            'csat_submitted', 'csat_updated' => Arr::only($payload, ['score']),
            'merged' => Arr::only($payload, ['direction', 'target_reference', 'source_reference']),
            default => [],
        };

        return $safe === [] ? null : $safe;
    }
}
