<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Events\ClinicalEventRecorded;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Enums\AlertSeverity;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ClinicalEventService
{
    public const TIMELINE_TYPE_CLINICAL_EVENT = 'clinical_event';

    public function __construct(
        protected HsEventService $hsEventService,
        protected ClinicalSignalService $signalService,
    ) {}

    /**
     * Record a clinical event.
     *
     * @param  array{
     *     event_type: ClinicalEventType|string,
     *     severity: string,
     *     description: string,
     *     occurred_at?: \DateTimeInterface|string|null,
     *     immediate_action_taken?: string|null,
     *     outcome?: string|null,
     *     witnesses?: array|null,
     *     requires_followup?: bool,
     * } $input
     */
    public function record(
        Client $client,
        User $reporter,
        array $input,
        ?Shift $shift = null,
    ): ClinicalEvent {
        $type = $input['event_type'] instanceof ClinicalEventType
            ? $input['event_type']
            : ClinicalEventType::from($input['event_type']);

        $severity = AlertSeverity::normalise($input['severity'] ?? AlertSeverity::MEDIUM);

        $event = ClinicalEvent::create([
            'client_id' => $client->id,
            'shift_id' => $shift?->id,
            'site_id' => $shift?->site_id ?? $client->site_id,
            'reported_by' => $reporter->id,
            'event_type' => $type,
            'severity' => $severity,
            'occurred_at' => $input['occurred_at'] ?? now(),
            'reported_at' => now(),
            'description' => $input['description'],
            'immediate_action_taken' => $input['immediate_action_taken'] ?? null,
            'outcome' => $input['outcome'] ?? null,
            'witnesses' => $input['witnesses'] ?? null,
            'requires_followup' => $input['requires_followup'] ?? false,
            'status' => 'open',
        ]);

        $this->createTimelineEvent($event, $reporter);

        if ($type->shouldLinkToHs()) {
            $this->linkToHsEvent($event);
        }

        $this->signalService->emitForEvent($event);

        ClinicalEventRecorded::dispatch($event);

        Log::info('ClinicalEventService: event recorded', [
            'clinical_event_id' => $event->id,
            'event_type' => $type->value,
            'severity' => $severity,
            'client_id' => $client->id,
            'shift_id' => $shift?->id,
            'linked_to_hs' => $event->linked_hs_event_id !== null,
        ]);

        return $event;
    }

    /**
     * Get clinical events for a client, optionally filtered by type.
     */
    public function getForClient(
        Client $client,
        ?ClinicalEventType $type = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): Collection {
        return ClinicalEvent::query()
            ->forClient($client->id)
            ->when($type, fn ($q) => $q->ofType($type))
            ->when($from && $to, fn ($q) => $q->whereBetween('occurred_at', [$from, $to]))
            ->orderByDesc('occurred_at')
            ->get();
    }

    /**
     * Get event frequency for a client and type over a number of days.
     */
    public function getFrequencyCount(
        Client $client,
        ClinicalEventType $type,
        int $days = 30,
    ): int {
        return ClinicalEvent::query()
            ->forClient($client->id)
            ->ofType($type)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->count();
    }

    // ── H&S Event linking ────────────────────────────────────────────────

    /**
     * Auto-link to HsEvent using the existing HsEventService pattern.
     *
     * Only called for event types where shouldLinkToHs() returns true
     * (falls, seizures, choking — defined in ClinicalEventType enum).
     */
    protected function linkToHsEvent(ClinicalEvent $event): void
    {
        $hsCategory = $event->event_type->hsEventCategory();

        if (! $hsCategory) {
            return;
        }

        $event->loadMissing('client');

        $hsEvent = $this->hsEventService->recordEvent([
            'source' => $event,
            'event_category' => $hsCategory,
            'severity' => $event->severity,
            'occurred_at' => $event->occurred_at,
            'reported_at' => $event->reported_at,
            'site_id' => $event->site_id,
            'client_id' => $event->client_id,
            'shift_id' => $event->shift_id,
            'created_by' => $event->reported_by,
        ]);

        if ($hsEvent) {
            $event->updateQuietly(['linked_hs_event_id' => $hsEvent->id]);
        }
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    protected function createTimelineEvent(ClinicalEvent $event, User $reporter): TimelineEvent
    {
        return TimelineEvent::create([
            'type' => self::TIMELINE_TYPE_CLINICAL_EVENT,
            'source_type' => ClinicalEvent::class,
            'source_id' => $event->id,
            'occurred_at' => $event->occurred_at,
            'actor_user_id' => $reporter->id,
            'client_id' => $event->client_id,
            'shift_id' => $event->shift_id,
            'site_id' => $event->site_id,
            'subject' => $event->event_type->label() . ' reported',
            'body' => $this->buildTimelineBody($event),
            'meta' => [
                'clinical_event_id' => $event->id,
                'event_type' => $event->event_type->value,
                'severity' => $event->severity,
            ],
            'visibility' => 'internal',
            'created_by' => $reporter->id,
        ]);
    }

    protected function buildTimelineBody(ClinicalEvent $event): string
    {
        $parts = [
            $event->event_type->label(),
            'Severity: ' . $event->severity,
        ];

        if ($event->description) {
            $parts[] = $event->description;
        }

        if ($event->immediate_action_taken) {
            $parts[] = 'Action taken: ' . $event->immediate_action_taken;
        }

        return implode(' · ', $parts);
    }
}
