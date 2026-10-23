<?php

namespace App\Services\Operations;

use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ShiftTimelineService;
use Carbon\CarbonInterface;

/** Explicit Operations dependency; never registered as the global timeline service. */
final class OperationsCheckedHandoverTimeline extends ShiftTimelineService
{
    private array $proofs = [];
    public function resetProofs(): void { $this->proofs = []; }
    public function proofs(): array { return $this->proofs; }
    public function recordHandoverCreated(
        ShiftHandover $handover,
        Shift $shift,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null
    ): TimelineEvent {
        [$handover, $shift] = $this->canonical($handover, $shift);
        $handover->loadMissing(['incomingShift', 'outgoingStaff', 'incomingStaff']);
        $occurredAt = $occurredAt ?? $handover->created_at ?? now();

        return $this->persistTimeline(
            [
                'type' => self::HANDOVER_CREATED_EVENT_TYPE,
                'source_type' => ShiftHandover::class,
                'source_id' => $handover->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => ShiftHandover::class,
                'source_id' => $handover->id,
                'actor_user_id' => $actor?->id ?? $handover->outgoing_staff_id,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => 'Shift handover created',
                'body' => $this->handoverBody($handover, 'drafted'),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'handover_created',
                    'handover_id' => $handover->id,
                    'handover_status' => $handover->status,
                    'incoming_shift_id' => $handover->incoming_shift_id,
                    'incoming_staff_id' => $handover->incoming_staff_id,
                ]),
                'visibility' => 'internal',
                'created_by' => $actor?->id ?? $handover->outgoing_staff_id ?? $shift->created_by,
            ]
        );
    }

    public function recordHandoverSubmitted(
        ShiftHandover $handover,
        Shift $shift,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null
    ): TimelineEvent {
        [$handover, $shift] = $this->canonical($handover, $shift);
        $handover->loadMissing(['incomingShift', 'outgoingStaff', 'incomingStaff']);
        $occurredAt = $occurredAt ?? $handover->submitted_at ?? now();

        return $this->persistTimeline(
            [
                'type' => self::HANDOVER_SUBMITTED_EVENT_TYPE,
                'source_type' => ShiftHandover::class,
                'source_id' => $handover->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => ShiftHandover::class,
                'source_id' => $handover->id,
                'actor_user_id' => $actor?->id ?? $handover->submitted_by ?? $handover->outgoing_staff_id,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => 'Shift handover submitted',
                'body' => $this->handoverBody($handover, 'submitted'),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'handover_submitted',
                    'handover_id' => $handover->id,
                    'handover_status' => $handover->status,
                    'incoming_shift_id' => $handover->incoming_shift_id,
                    'incoming_staff_id' => $handover->incoming_staff_id,
                ]),
                'visibility' => 'internal',
                'created_by' => $actor?->id ?? $handover->submitted_by ?? $handover->outgoing_staff_id ?? $shift->created_by,
            ]
        );
    }


    private function persistTimeline(array $keys, array $values): TimelineEvent
    {
        $rows = array_values(array_filter(OperationsHandoverRecords::rows('timeline_events', 'source_id', [$keys['source_id']]),
            fn (array $row): bool => $row['type'] === $keys['type'] && $row['source_type'] === $keys['source_type']));
        abort_unless(count($rows) <= 1, 409);
        $event = $rows ? OperationsHandoverRecords::model(TimelineEvent::class, $rows[0]) : new TimelineEvent;
        $event->forceFill([...$keys, ...$values]);
        $proof = OperationsHandoverRecords::write($event);
        $this->proofs[$proof['id']] = $proof;
        return $event;
    }

    /** Preserve the existing two timeline builders, using actual rows rather than retrieved replacements. */
    private function canonical(ShiftHandover $supplied, Shift $suppliedShift): array
    {
        $rows = OperationsHandoverRecords::rows('shift_handovers', 'outgoing_shift_id', [(int) $suppliedShift->id]);
        abort_unless(count($rows) === 1 && (int) $rows[0]['id'] === (int) $supplied->id, 409);
        $handover = OperationsHandoverRecords::model(ShiftHandover::class, $rows[0]);
        $shifts = OperationsHandoverRecords::rows('shifts', 'id', array_values(array_filter([$handover->outgoing_shift_id, $handover->incoming_shift_id])));
        $outgoing = collect($shifts)->first(fn ($row) => (int) $row['id'] === (int) $handover->outgoing_shift_id);
        abort_unless($outgoing !== null, 409);
        $shift = OperationsHandoverRecords::model(Shift::class, $outgoing);
        $clientRows = OperationsHandoverRecords::rows('clients', 'id', [(int) $shift->client_id]);
        $siteRows = OperationsHandoverRecords::rows('sites', 'id', [(int) $shift->site_id]);
        abort_unless(count($clientRows) === 1 && count($siteRows) === 1, 409);
        $client = OperationsHandoverRecords::model(\App\Models\Client::class, $clientRows[0]);
        $shift->setRelation('client', $client)->setRelation('site', OperationsHandoverRecords::model(\App\Models\Site::class, $siteRows[0]));
        $contexts = OperationsHandoverRecords::rows('service_contexts', 'id', array_values(array_filter([$shift->service_context_id])));
        $shift->setRelation('serviceContext', $contexts ? OperationsHandoverRecords::model(\App\Models\ServiceContext::class, $contexts[0]) : null);
        $users = collect(OperationsHandoverRecords::rows('users', 'id', array_values(array_filter([$shift->user_id, $handover->outgoing_staff_id, $handover->incoming_staff_id]))))
            ->keyBy('id')->map(fn ($row) => OperationsHandoverRecords::model(User::class, $row));
        $shift->setRelation('staff', $users->get($shift->user_id));
        $incoming = collect($shifts)->first(fn ($row) => (int) $row['id'] === (int) $handover->incoming_shift_id);
        $handover->setRelation('incomingShift', $incoming ? OperationsHandoverRecords::model(Shift::class, $incoming) : null)
            ->setRelation('outgoingStaff', $users->get($handover->outgoing_staff_id))->setRelation('incomingStaff', $users->get($handover->incoming_staff_id));
        return [$handover, $shift];
    }
}
