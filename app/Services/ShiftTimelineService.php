<?php

namespace App\Services;

use BackedEnum;
use App\Models\FamilyPortalSetting;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\TimelineEvent;
use App\Models\User;
use Carbon\CarbonInterface;

class ShiftTimelineService
{
    public const SNAPSHOT_EVENT_TYPE = 'shift';

    public const STARTED_EVENT_TYPE = 'shift_started';

    public const COMPLETED_EVENT_TYPE = 'shift_completed';

    public const CANCELLED_EVENT_TYPE = 'shift_cancelled';

    public const CANCELLATION_CASCADE_EVENT_TYPE = 'shift_cancellation_cascade';

    public const HANDOVER_CREATED_EVENT_TYPE = 'shift_handover_created';

    public const HANDOVER_SUBMITTED_EVENT_TYPE = 'shift_handover_submitted';

    public const HANDOVER_ACKNOWLEDGED_EVENT_TYPE = 'shift_handover_acknowledged';

    public const HANDOVER_WAIVED_EVENT_TYPE = 'shift_handover_waived';

    /**
     * @return array<int, string>
     */
    public static function shiftEventTypes(): array
    {
        return [
            self::SNAPSHOT_EVENT_TYPE,
            self::STARTED_EVENT_TYPE,
            self::COMPLETED_EVENT_TYPE,
            self::CANCELLED_EVENT_TYPE,
            self::CANCELLATION_CASCADE_EVENT_TYPE,
            self::HANDOVER_CREATED_EVENT_TYPE,
            self::HANDOVER_SUBMITTED_EVENT_TYPE,
            self::HANDOVER_ACKNOWLEDGED_EVENT_TYPE,
            self::HANDOVER_WAIVED_EVENT_TYPE,
        ];
    }

    public function syncSnapshot(Shift $shift): TimelineEvent
    {
        $shift = $this->loadShiftContext($shift);

        return TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::SNAPSHOT_EVENT_TYPE,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $shift->starts_at ?? now(),
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => null,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => $this->snapshotSubject($shift),
                'body' => $this->snapshotBody($shift),
                'meta' => $this->baseMeta($shift),
                'visibility' => $this->portalVisibilityForShift($shift),
                'created_by' => $shift->created_by,
            ]
        );
    }

    public function recordStarted(
        Shift $shift,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null,
        bool $notifyPortal = true
    ): TimelineEvent
    {
        $shift = $this->loadShiftContext($shift);
        $occurredAt = $occurredAt ?? $shift->actual_starts_at ?? now();

        $event = TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::STARTED_EVENT_TYPE,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => $actor?->id ?? $shift->started_by,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => $this->shiftTypeLabel($shift).' started',
                'body' => $this->startedBody($shift, $occurredAt),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'started',
                ]),
                'visibility' => $this->portalVisibilityForShift($shift),
                'created_by' => $actor?->id ?? $shift->started_by ?? $shift->created_by,
            ]
        );

        if ($notifyPortal) {
            $this->notifyPortalUsers(
                $shift,
                'notify_shift_arrival',
                'portal.shift.arrival',
                $actor,
                $this->shiftTypeLabel($shift).' started',
                $this->startedBody($shift, $occurredAt)
            );
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    public function recordCompleted(
        Shift $shift,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null,
        array $extraMeta = [],
        bool $notifyPortal = true
    ): TimelineEvent {
        $shift = $this->loadShiftContext($shift);
        $occurredAt = $occurredAt ?? $shift->actual_ends_at ?? now();

        $event = TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::COMPLETED_EVENT_TYPE,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => $actor?->id ?? $shift->completed_by,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => $this->shiftTypeLabel($shift).' completed',
                'body' => $this->completedBody($shift, $occurredAt),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'completed',
                ], array_filter($extraMeta, fn ($value) => $value !== null && $value !== '')),
                'visibility' => $this->portalVisibilityForShift($shift),
                'created_by' => $actor?->id ?? $shift->completed_by ?? $shift->created_by,
            ]
        );

        if ($notifyPortal) {
            $this->notifyPortalUsers(
                $shift,
                'notify_shift_completion',
                'portal.shift.completion',
                $actor,
                'Shift completed',
                $this->completedBody($shift, $occurredAt)
            );
        }

        return $event;
    }

    public function recordCancelled(Shift $shift, ?User $actor = null, ?CarbonInterface $occurredAt = null): TimelineEvent
    {
        $shift = $this->loadShiftContext($shift);
        $occurredAt = $occurredAt ?? now();

        return TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::CANCELLED_EVENT_TYPE,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => $actor?->id ?? $shift->created_by,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => $this->shiftTypeLabel($shift).' cancelled',
                'body' => $this->cancelledBody($shift),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'cancelled',
                ]),
                'visibility' => $this->portalVisibilityForShift($shift),
                'created_by' => $actor?->id ?? $shift->created_by,
            ]
        );
    }

    /**
     * @param  array<string, array{count:int, ids:array<int,int>}>  $impact
     */
    public function recordCancellationCascade(
        Shift $shift,
        array $impact,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null
    ): TimelineEvent {
        $shift = $this->loadShiftContext($shift);
        $occurredAt = $occurredAt ?? now();

        return TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::CANCELLATION_CASCADE_EVENT_TYPE,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => $actor?->id ?? $shift->created_by,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => 'Shift cancellation cascade applied',
                'body' => $this->cancellationCascadeBody($impact),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'cancellation_cascade',
                    'impact' => $impact,
                ]),
                'visibility' => 'internal',
                'created_by' => $actor?->id ?? $shift->created_by,
            ]
        );
    }

    public function recordHandoverCreated(
        ShiftHandover $handover,
        Shift $shift,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null
    ): TimelineEvent {
        $shift = $this->loadShiftContext($shift);
        $handover->loadMissing(['incomingShift', 'outgoingStaff', 'incomingStaff']);
        $occurredAt = $occurredAt ?? $handover->created_at ?? now();

        return TimelineEvent::query()->updateOrCreate(
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
        $shift = $this->loadShiftContext($shift);
        $handover->loadMissing(['incomingShift', 'outgoingStaff', 'incomingStaff']);
        $occurredAt = $occurredAt ?? $handover->submitted_at ?? now();

        return TimelineEvent::query()->updateOrCreate(
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

    public function recordHandoverAcknowledged(
        ShiftHandover $handover,
        Shift $shift,
        ?User $actor = null,
        ?CarbonInterface $occurredAt = null
    ): TimelineEvent {
        $shift = $this->loadShiftContext($shift);
        $handover->loadMissing(['incomingShift', 'outgoingStaff', 'incomingStaff', 'acknowledger']);
        $occurredAt = $occurredAt ?? $handover->acknowledged_at ?? now();

        return TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::HANDOVER_ACKNOWLEDGED_EVENT_TYPE,
                'source_type' => ShiftHandover::class,
                'source_id' => $handover->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => ShiftHandover::class,
                'source_id' => $handover->id,
                'actor_user_id' => $actor?->id ?? $handover->acknowledged_by,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => 'Shift handover acknowledged',
                'body' => $this->handoverAcknowledgedBody($handover),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'handover_acknowledged',
                    'handover_id' => $handover->id,
                    'handover_status' => $handover->status,
                    'incoming_shift_id' => $handover->incoming_shift_id,
                    'incoming_staff_id' => $handover->incoming_staff_id,
                    'acknowledged_by' => $handover->acknowledged_by,
                ]),
                'visibility' => 'internal',
                'created_by' => $actor?->id ?? $handover->acknowledged_by ?? $shift->created_by,
            ]
        );
    }

    public function recordHandoverWaived(
        Shift $shift,
        string $reason,
        ?User $actor = null,
        ?Shift $matchedIncomingShift = null,
        bool $ambiguous = false,
        ?CarbonInterface $occurredAt = null
    ): TimelineEvent {
        $shift = $this->loadShiftContext($shift);
        $matchedIncomingShift?->loadMissing(['staff:id,name']);
        $occurredAt = $occurredAt ?? $shift->handover_waived_at ?? now();

        return TimelineEvent::query()->updateOrCreate(
            [
                'type' => self::HANDOVER_WAIVED_EVENT_TYPE,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => $actor?->id ?? $shift->handover_waived_by,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'subject' => 'Shift handover waived',
                'body' => $this->handoverWaivedBody($reason, $matchedIncomingShift, $ambiguous),
                'meta' => array_merge($this->baseMeta($shift), [
                    'event' => 'handover_waived',
                    'handover_waiver_reason' => $reason,
                    'matched_incoming_shift_id' => $matchedIncomingShift?->id,
                    'matched_incoming_staff_id' => $matchedIncomingShift?->user_id,
                    'ambiguous_match' => $ambiguous,
                ]),
                'visibility' => 'internal',
                'created_by' => $actor?->id ?? $shift->handover_waived_by ?? $shift->created_by,
            ]
        );
    }

    public function deleteForShift(Shift $shift): void
    {
        TimelineEvent::query()
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->delete();
    }

    protected function loadShiftContext(Shift $shift): Shift
    {
        $shift->loadMissing([
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'client.portalUsers:id',
            'staff:id,name',
            'serviceContext:id,name,type',
        ]);

        return $shift;
    }

    protected function snapshotSubject(Shift $shift): string
    {
        $service = $this->shiftTypeLabel($shift);
        $clientName = $this->clientName($shift);

        return $clientName !== ''
            ? $service.' for '.$clientName
            : $service;
    }

    protected function snapshotBody(Shift $shift): string
    {
        $parts = [];

        if ($shift->starts_at && $shift->ends_at) {
            $parts[] = 'Scheduled '.$this->formatWindow($shift->starts_at, $shift->ends_at);
        }

        if ($shift->staff?->name) {
            $parts[] = 'Staff: '.$shift->staff->name;
        } elseif ($shift->status !== 'draft') {
            $parts[] = 'Staff assignment pending';
        }

        if ($shift->serviceContext?->name) {
            $parts[] = 'Service: '.$shift->serviceContext->name;
        }

        if ($shift->location) {
            $parts[] = 'Location: '.$shift->location;
        }

        $flags = $this->flagLabels($shift);
        if ($flags !== []) {
            $parts[] = implode(', ', $flags);
        }

        return implode(' · ', $parts);
    }

    protected function startedBody(Shift $shift, CarbonInterface $occurredAt): string
    {
        $parts = [];
        $staffName = $shift->staff?->name;

        $parts[] = $staffName
            ? $staffName.' started support at '.$occurredAt->format('g:i A')
            : 'Support started at '.$occurredAt->format('g:i A');

        if ($shift->location) {
            $parts[] = 'Location: '.$shift->location;
        }

        if ($shift->serviceContext?->name) {
            $parts[] = 'Service: '.$shift->serviceContext->name;
        }

        return implode(' · ', $parts);
    }

    protected function completedBody(Shift $shift, CarbonInterface $occurredAt): string
    {
        $parts = [
            'Completed at '.$occurredAt->format('g:i A'),
        ];

        if ($shift->staff?->name) {
            $parts[] = 'Staff: '.$shift->staff->name;
        }

        if ($shift->location) {
            $parts[] = 'Location: '.$shift->location;
        }

        return implode(' · ', $parts);
    }

    protected function cancelledBody(Shift $shift): string
    {
        if ($shift->starts_at && $shift->ends_at) {
            return 'Cancelled '.$this->formatWindow($shift->starts_at, $shift->ends_at);
        }

        return 'This scheduled support shift was cancelled.';
    }

    /**
     * @param  array<string, array{count:int, ids:array<int,int>}>  $impact
     */
    protected function cancellationCascadeBody(array $impact): string
    {
        $lines = [];

        if (($impact['timesheets']['count'] ?? 0) > 0) {
            $lines[] = 'Timesheets returned: '.$impact['timesheets']['count'].' (#'.implode(', #', $impact['timesheets']['ids']).')';
        }

        if (($impact['medication_administrations']['count'] ?? 0) > 0) {
            $lines[] = 'Medication records flagged: '.$impact['medication_administrations']['count'].' (#'.implode(', #', $impact['medication_administrations']['ids']).')';
        }

        if (($impact['medication_rounds']['count'] ?? 0) > 0) {
            $lines[] = 'Medication rounds flagged: '.$impact['medication_rounds']['count'].' (#'.implode(', #', $impact['medication_rounds']['ids']).')';
        }

        if (($impact['resident_transports']['count'] ?? 0) > 0) {
            $lines[] = 'Resident transports flagged: '.$impact['resident_transports']['count'].' (#'.implode(', #', $impact['resident_transports']['ids']).')';
        }

        if (($impact['fleet_vehicle_bookings']['count'] ?? 0) > 0) {
            $lines[] = 'Vehicle bookings flagged: '.$impact['fleet_vehicle_bookings']['count'].' (#'.implode(', #', $impact['fleet_vehicle_bookings']['ids']).')';
        }

        if (($impact['incidents']['count'] ?? 0) > 0) {
            $lines[] = 'Incident linkage preserved after cancellation: '.$impact['incidents']['count'].' (#'.implode(', #', $impact['incidents']['ids']).')';
        }

        return $lines === []
            ? 'No linked operational records required follow-up during shift cancellation.'
            : implode("\n", $lines);
    }

    protected function handoverBody(ShiftHandover $handover, string $state): string
    {
        $parts = [
            'Handover '.$state,
        ];

        if ($handover->incomingStaff?->name) {
            $parts[] = 'Incoming staff: '.$handover->incomingStaff->name;
        } elseif ($handover->incomingShift?->starts_at) {
            $parts[] = 'Incoming shift starts '.$handover->incomingShift->starts_at->format('g:i A');
        }

        if ($handover->client_mood) {
            $parts[] = 'Mood: '.$handover->client_mood;
        }

        if ($handover->handover_notes) {
            $parts[] = $handover->handover_notes;
        }

        return implode(' · ', $parts);
    }

    protected function handoverAcknowledgedBody(ShiftHandover $handover): string
    {
        $parts = ['Handover acknowledged'];

        if ($handover->acknowledger?->name) {
            $parts[] = 'By '.$handover->acknowledger->name;
        }

        if ($handover->incomingShift?->starts_at) {
            $parts[] = 'Incoming shift '.$handover->incomingShift->starts_at->format('D j M, g:i A');
        }

        return implode(' · ', $parts);
    }

    protected function handoverWaivedBody(string $reason, ?Shift $matchedIncomingShift, bool $ambiguous): string
    {
        $parts = ['Completion proceeded without a submitted handover.'];

        if ($matchedIncomingShift) {
            $parts[] = 'Matched next shift #'.$matchedIncomingShift->id;
        } elseif ($ambiguous) {
            $parts[] = 'Multiple possible next shifts matched';
        }

        $parts[] = 'Reason: '.$reason;

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseMeta(Shift $shift): array
    {
        return array_filter([
            'shift_id' => $shift->id,
            'status' => $shift->status,
            'shift_type' => $shift->shift_type ?? 'standard',
            'starts_at' => $shift->starts_at?->toISOString(),
            'ends_at' => $shift->ends_at?->toISOString(),
            'actual_starts_at' => $shift->actual_starts_at?->toISOString(),
            'actual_ends_at' => $shift->actual_ends_at?->toISOString(),
            'location' => $shift->location,
            'service_context' => $shift->serviceContext?->name,
            'service_context_type' => $this->serviceContextType($shift),
            'staff_id' => $shift->staff?->id,
            'staff_name' => $shift->staff?->name,
            'is_sleepover' => (bool) $shift->is_sleepover,
            'is_on_call' => (bool) $shift->is_on_call,
            'expected_break_minutes' => $shift->expected_break_minutes,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function portalVisibilityForShift(Shift $shift): string
    {
        return $this->showOnFamilyPortal($shift) ? 'portal' : 'internal';
    }

    protected function showOnFamilyPortal(Shift $shift): bool
    {
        if (! $shift->client_id || $shift->status === 'draft') {
            return false;
        }

        $settings = FamilyPortalSetting::query()->where('client_id', $shift->client_id)->first();

        return $settings?->show_shift_schedule ?? true;
    }

    protected function notifyPortalUsers(
        Shift $shift,
        string $settingColumn,
        string $eventKey,
        ?User $actor,
        string $title,
        string $body
    ): void {
        $shift = $this->loadShiftContext($shift);

        if (! $shift->client) {
            return;
        }

        if (! $this->showOnFamilyPortal($shift)) {
            return;
        }

        $settings = FamilyPortalSetting::query()->where('client_id', $shift->client_id)->first();
        $enabled = $settings?->{$settingColumn};
        if ($enabled === false) {
            return;
        }

        $portalUserIds = $shift->client->portalUsers->pluck('id')->filter()->values()->all();
        if ($portalUserIds === []) {
            return;
        }

        app(NotificationService::class)->notifyCrud($actor, 'updated', 'shift', $shift, $shift->client, [
            'event_key' => $eventKey,
            'title' => $title,
            'body' => $body,
            'url' => url("/portal/clients/{$shift->client_id}/timeline"),
            'include_managers' => false,
            'include_assigned_workers' => false,
            'include_entity_user' => false,
            'target_user_ids' => $portalUserIds,
            'context' => array_filter([
                'Shift' => $this->shiftTypeLabel($shift),
                'When' => $shift->starts_at && $shift->ends_at ? $this->formatWindow($shift->starts_at, $shift->ends_at) : null,
                'Staff' => $shift->staff?->name,
                'Location' => $shift->location,
            ]),
        ]);
    }

    protected function serviceContextType(Shift $shift): ?string
    {
        $type = $shift->serviceContext?->type;

        if ($type instanceof BackedEnum) {
            return $type->value;
        }

        return is_string($type) ? $type : null;
    }

    protected function clientName(Shift $shift): string
    {
        return trim(($shift->client?->first_name ?? '').' '.($shift->client?->last_name ?? ''));
    }

    protected function shiftTypeLabel(Shift $shift): string
    {
        return match ($shift->shift_type ?? 'standard') {
            'sleepover' => 'Sleepover shift',
            'on_call' => 'On-call shift',
            'split' => 'Split shift',
            'travel' => 'Transport shift',
            default => 'Support shift',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function flagLabels(Shift $shift): array
    {
        $flags = [];

        if ($shift->is_sleepover) {
            $flags[] = 'Sleepover';
        }

        if ($shift->is_on_call) {
            $flags[] = 'On-call';
        }

        if ($shift->expected_break_minutes) {
            $flags[] = 'Break '.$shift->expected_break_minutes.' min';
        }

        return $flags;
    }

    protected function formatWindow(CarbonInterface $startsAt, CarbonInterface $endsAt): string
    {
        $sameDay = $startsAt->toDateString() === $endsAt->toDateString();

        if ($sameDay) {
            return $startsAt->format('D j M, g:i A').' - '.$endsAt->format('g:i A');
        }

        return $startsAt->format('D j M, g:i A').' - '.$endsAt->format('D j M, g:i A');
    }
}
