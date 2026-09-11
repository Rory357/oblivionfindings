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
        'resolution_confirmed',
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
        if (! $this->workAccess->canView($viewer, $ticket)) {
            return [];
        }

        $canWork = $this->workAccess->canWork($viewer, $ticket);

        return $ticket->events()
            ->when(
                ! $canWork,
                fn ($query) => $query->whereIn('type', self::REQUESTER_VISIBLE_TYPES),
            )
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ItTicketEvent $event) => $this->projectEvent($event, $canWork, $viewer))
            ->values()
            ->all();
    }

    /** The hub feed follows the same record audience as the detail timeline. */
    public function presentEvent(ItTicketEvent $event, User $viewer): ?array
    {
        $ticket = $event->subject;
        if (! $ticket instanceof ItTicket || ! $this->workAccess->canView($viewer, $ticket)) {
            return null;
        }

        $canWork = $this->workAccess->canWork($viewer, $ticket);
        if (! $canWork && ! in_array($event->type, self::REQUESTER_VISIBLE_TYPES, true)) {
            return null;
        }

        return $this->projectEvent($event, $canWork, $viewer);
    }

    /** @return array<string, mixed> */
    private function projectEvent(ItTicketEvent $event, bool $canWork, User $viewer): array
    {
        $payload = $canWork ? $event->payload : $this->publicPayload($event);
        if (in_array($event->type, ['related_work_linked', 'related_work_unlinked'], true)) {
            $counterpart = ItTicket::query()->find($event->payload['target_id'] ?? null);
            if (! $counterpart || ! $this->workAccess->canView($viewer, $counterpart)) {
                $payload = Arr::only($event->payload ?? [], ['relationship']);
            }
        }
        if ($event->type === 'merged') {
            $evidence = $event->payload ?? [];
            $counterpartId = ($evidence['direction'] ?? null) === 'from'
                ? ($evidence['source_id'] ?? null) : ($evidence['target_id'] ?? null);
            $counterpart = is_numeric($counterpartId) ? ItTicket::query()->find($counterpartId) : null;
            // Historical references and free-text reasons can identify another
            // private ticket. Check that record at read time, including hub feeds.
            $payload = ! $counterpart || ! $this->workAccess->canView($viewer, $counterpart)
                ? Arr::only($evidence, ['direction'])
                : ($canWork && $this->workAccess->canWork($viewer, $counterpart)
                    ? $evidence : $this->publicPayload($event));
        }

        return [
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $payload,
            'actor' => $event->actor?->name,
            'at' => $event->created_at?->toIso8601String(),
            'at_human' => $event->created_at?->diffForHumans(short: true),
        ];
    }

    /** @return array<string, mixed>|null */
    private function publicPayload(ItTicketEvent $event): ?array
    {
        $payload = $event->payload ?? [];
        $safe = match ($event->type) {
            'created' => Arr::only($payload, ['source']),
            'status_changed', 'workflow_transitioned', 'reopened', 'resolved', 'resolution_confirmed', 'closed' => Arr::only($payload, [
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
