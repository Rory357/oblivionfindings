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
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HsEventService;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
     *     occurred_at?: DateTimeInterface|string|null,
     *     immediate_action_taken?: string|null,
     *     outcome?: string|null,
     *     witnesses?: array|null,
     *     requires_followup?: bool,
     *     followup_notes?: string|null,
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
        Validator::make([...$input, 'event_type' => $type->value], [
            'hospital_discharged_at' => ['prohibited_unless:event_type,hospital_admission', 'nullable', 'date'],
        ])->validate();

        $severity = AlertSeverity::normalise($input['severity'] ?? AlertSeverity::MEDIUM);
        $siteId = $this->resolveCanonicalSiteId($client, $shift, $type->shouldLinkToHs());
        $immediateAction = $input['immediate_action_taken'] ?? null;
        $hasImmediateAction = is_string($immediateAction) && trim($immediateAction) !== '';

        if ($type->requiresImmediateAction() && ! $hasImmediateAction) {
            throw new DomainException('Immediate action taken is required for clinical events linked to Health & Safety.');
        }

        $events = DB::transaction(function () use (
            $client,
            $hasImmediateAction,
            $immediateAction,
            $input,
            $reporter,
            $severity,
            $shift,
            $siteId,
            $type,
        ): array {
            $hospital = [];
            if (in_array($type, [ClinicalEventType::HospitalAdmission, ClinicalEventType::HospitalDischarge], true)) {
                $client = Client::query()->lockForUpdate()->findOrFail($client->id);
                $reporter = $this->lockHospitalAuthority($reporter, $client);
                $siteId = $this->resolveCanonicalSiteId($client, $shift, true);
                $hospital = $this->hospitalFacts($client, $type, $input);
            }
            $event = ClinicalEvent::create([
                'client_id' => $client->id,
                'shift_id' => $shift?->id,
                'site_id' => $siteId,
                'reported_by' => $reporter->id,
                'event_type' => $type,
                'severity' => $severity,
                'occurred_at' => WorkerClock::toUtc($input['occurred_at'] ?? null) ?? now(),
                'reported_at' => now(),
                'description' => $input['description'],
                // Store only the operator's exact, non-blank input. Never infer a default.
                'immediate_action_taken' => $hasImmediateAction ? $immediateAction : null,
                'outcome' => $input['outcome'] ?? null,
                'witnesses' => $input['witnesses'] ?? null,
                'requires_followup' => $input['requires_followup'] ?? false,
                'followup_notes' => ($input['requires_followup'] ?? false) ? ($input['followup_notes'] ?? null) : null,
                'status' => 'open',
                ...$hospital,
            ]);

            $this->createTimelineEvent($event, $reporter);

            if ($type->shouldLinkToHs()) {
                $this->linkToHsEvent($event);
            }

            $events = [$event];
            $dischargedAt = WorkerClock::toUtc($input['hospital_discharged_at'] ?? null);
            if ($type === ClinicalEventType::HospitalAdmission && $dischargedAt !== null) {
                // A closed historical stay is two canonical, immutable events.
                // No intermediate open admission is visible outside this transaction.
                $discharge = ClinicalEvent::create([
                    'client_id' => $client->id, 'shift_id' => $shift?->id, 'site_id' => $siteId,
                    'reported_by' => $reporter->id, 'event_type' => ClinicalEventType::HospitalDischarge,
                    'severity' => $severity, 'occurred_at' => $dischargedAt, 'reported_at' => $event->reported_at,
                    'description' => $input['description'], 'status' => 'open', 'requires_followup' => false,
                    'hospital_admitted_at' => null, 'hospital_discharged_at' => $dischargedAt,
                    'hospital_admission_id' => $event->id,
                ]);
                $this->createTimelineEvent($discharge, $reporter);
                $events[] = $discharge;
                foreach ($events as $recorded) {
                    AuditLogger::logOrFail('clinicalevent.hospital_pair', $recorded, [
                        'actor_id' => (int) $reporter->id, 'site_id' => $siteId,
                        'hospital_admission_id' => (int) $event->id, 'hospital_discharge_id' => (int) $discharge->id,
                        'event_type' => $recorded->event_type->value, 'occurred_at' => $recorded->occurred_at->toISOString(),
                    ]);
                }
                $event->setRelation('hospitalDischarges', collect([$discharge]));
            }

            return $events;
        }, 3);

        foreach ($events as $event) {
            $this->signalService->emitForEvent($event);

            ClinicalEventRecorded::dispatch($event);

            Log::info('ClinicalEventService: event recorded', [
                'clinical_event_id' => $event->id,
                'event_type' => $event->event_type->value,
                'severity' => $severity,
                'client_id' => $client->id,
                'shift_id' => $shift?->id,
                'linked_to_hs' => $event->linked_hs_event_id !== null,
            ]);
        }

        return $events[0];
    }

    /** Thin, current-person references for the admission chosen by a discharge form. */
    public function openHospitalAdmissions(Client $client, User $viewer): Collection
    {
        app(ClinicalSiteAccessService::class)->assertCanAccessClient($viewer, $client);
        abort_unless($viewer->canDo('clinical.events.record') || $viewer->canDo('clinical.events.viewAny')
            || $viewer->canDo('clinical.events.viewAssigned'), 403);

        // Presence belongs to this person across house moves. Only these thin
        // references cross the historical Site snapshot; full event readers keep
        // their existing integrity boundary and disclose no old event details.
        return ClinicalEvent::query()
            ->where('client_id', $client->id)->where('event_type', ClinicalEventType::HospitalAdmission->value)
            ->whereNotNull('hospital_admitted_at')->whereColumn('hospital_admitted_at', 'occurred_at')->where('hospital_admitted_at', '<=', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clinical_events as discharge')
                ->whereColumn('discharge.hospital_admission_id', 'clinical_events.id')
                ->whereColumn('discharge.client_id', 'clinical_events.client_id')
                ->where('discharge.event_type', ClinicalEventType::HospitalDischarge->value)->whereNull('discharge.deleted_at')
                ->whereNotNull('discharge.hospital_discharged_at')->whereColumn('discharge.hospital_discharged_at', 'discharge.occurred_at')
                ->whereColumn('discharge.hospital_discharged_at', '>=', 'clinical_events.hospital_admitted_at'))
            ->orderBy('hospital_admitted_at')->orderBy('id')->get(['id', 'hospital_admitted_at', 'reported_at'])
            ->map(fn (ClinicalEvent $event): array => ['id' => (int) $event->id,
                'occurred_at' => $event->hospital_admitted_at->toISOString(), 'reported_at' => $event->reported_at?->toISOString()]);
    }

    private function lockHospitalAuthority(User $reporter, Client $client): User
    {
        $reporter = app(AuthorizationEvidenceLockService::class)->lockForUser($reporter,
            ['clinical.events.record', 'clinical.accessAllSites', 'sites.viewAll']);
        abort_unless($reporter->approved_at !== null && $reporter->canDo('clinical.events.record'), 403);
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($reporter, $client): void {
            abort_unless(in_array((int) $client->site_id, app(UserSiteAccessService::class)
                ->accessibleSiteIds($reporter, ClinicalSiteAccessService::SITE_BYPASS_PERMISSIONS, $reads), true), 404);
        });

        return $reporter;
    }

    private function hospitalFacts(Client $client, ClinicalEventType $type, array $input): array
    {
        $explicitOffset = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof DateTimeInterface && (! is_string($value) || preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) !== 1)) {
                $fail('Choose an actual time with its timezone offset.');
            }
        };
        Validator::make($input, [
            'occurred_at' => ['required', 'date', $explicitOffset],
            'hospital_discharged_at' => ['nullable', 'date', $explicitOffset],
        ])->validate();
        $at = WorkerClock::toUtc($input['occurred_at'] ?? null);
        if ($at === null || $at->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'Record the actual admission or discharge time; future plans do not establish absence.']);
        }
        $dischargedAt = WorkerClock::toUtc($input['hospital_discharged_at'] ?? null);
        if ($dischargedAt !== null && ($dischargedAt->isFuture() || $dischargedAt->lessThan($at))) {
            throw ValidationException::withMessages(['hospital_discharged_at' => 'The actual discharge must be at or after admission and must not be in the future.']);
        }
        if ($type === ClinicalEventType::HospitalAdmission) {
            if (! empty($input['hospital_admission_id'])) {
                throw ValidationException::withMessages(['hospital_admission_id' => 'An admission must not link to another admission.']);
            }
            $admissions = ClinicalEvent::query()->where('client_id', $client->id)
                ->where('event_type', $type->value)->whereNotNull('hospital_admitted_at')->lockForUpdate()->get();
            foreach ($admissions as $admission) {
                if ($dischargedAt !== null && $dischargedAt->equalTo($at)) {
                    break; // An explicitly empty half-open interval establishes no absence.
                }
                $start = CarbonImmutable::parse($admission->getRawOriginal('hospital_admitted_at'), 'UTC');
                $discharge = $admission->hospitalDischarges()->where('client_id', $admission->client_id)
                    ->whereNotNull('hospital_discharged_at')->whereColumn('hospital_discharged_at', 'occurred_at')
                    ->where('hospital_discharged_at', '>=', $admission->hospital_admitted_at)->orderBy('hospital_discharged_at')->lockForUpdate()->first();
                $end = $discharge === null ? null : CarbonImmutable::parse($discharge->getRawOriginal('occurred_at'), 'UTC');
                if ($end !== null && $end->equalTo($start)) {
                    continue;
                }
                if (($dischargedAt === null || $start->lessThan($dischargedAt)) && ($end === null || $end->greaterThan($at))) {
                    throw ValidationException::withMessages(['occurred_at' => 'These actual times overlap another recorded hospital stay. Check the times; for a stay that has ended, record admission and discharge together.']);
                }
            }

            return ['hospital_admitted_at' => $at, 'hospital_discharged_at' => null, 'hospital_admission_id' => null];
        }
        $id = filter_var($input['hospital_admission_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $admission = is_int($id) ? ClinicalEvent::query()->where('client_id', $client->id)
            ->where('event_type', ClinicalEventType::HospitalAdmission->value)->whereNotNull('hospital_admitted_at')->whereColumn('hospital_admitted_at', 'occurred_at')
            ->lockForUpdate()->find($id) : null;
        // Opaque references belong to the currently authorized person, including
        // an admission recorded before that person's house changed.
        abort_unless($admission !== null, 404);
        if ($admission->hospitalDischarges()->where('client_id', $admission->client_id)
            ->whereNotNull('hospital_discharged_at')->whereColumn('hospital_discharged_at', 'occurred_at')
            ->where('hospital_discharged_at', '>=', $admission->hospital_admitted_at)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['hospital_admission_id' => 'This admission already has a recorded discharge.']);
        }
        if ($at->lessThan(CarbonImmutable::parse($admission->getRawOriginal('hospital_admitted_at'), 'UTC'))) {
            throw ValidationException::withMessages(['occurred_at' => 'Discharge cannot precede the actual hospital admission.']);
        }

        return ['hospital_admitted_at' => null, 'hospital_discharged_at' => $at, 'hospital_admission_id' => $admission->id];
    }

    protected function resolveCanonicalSiteId(Client $client, ?Shift $shift, bool $required): ?int
    {
        if ($shift && (int) $shift->client_id !== (int) $client->id) {
            throw new DomainException('The shift does not belong to the clinical event client.');
        }

        $clientSiteId = (int) ($client->site_id ?? 0) ?: null;
        $shiftSiteId = (int) ($shift?->site_id ?? 0) ?: null;

        if ($clientSiteId && $shiftSiteId && $clientSiteId !== $shiftSiteId) {
            throw new DomainException('The shift Site does not match the clinical event client Site.');
        }

        $siteId = $shiftSiteId ?? $clientSiteId;

        if ($required && ! $siteId) {
            throw new DomainException('A canonical Site is required for clinical events linked to Health & Safety.');
        }

        return $siteId;
    }

    // ── Workflow actions (review / follow-up / escalate) ─────────────────

    /**
     * Review & sign off an event (RN gate `clinical.events.review`).
     */
    public function review(ClinicalEvent $event, User $reviewer): ClinicalEvent
    {
        $event->update([
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->recordActionTimeline($event, $reviewer, 'Clinical event reviewed', 'Reviewed and signed off by '.$reviewer->name);

        return $event->fresh();
    }

    /**
     * Mark an event's follow-up complete.
     */
    public function completeFollowup(ClinicalEvent $event, User $user): ClinicalEvent
    {
        $event->update([
            'followup_completed_at' => now(),
            'followup_completed_by' => $user->id,
        ]);

        $this->recordActionTimeline($event, $user, 'Follow-up completed', 'Follow-up marked complete by '.$user->name);

        return $event->fresh();
    }

    /**
     * Escalate an event to on-call clinical leadership — raises a forced
     * high-priority Control Room signal (the app's escalation surface).
     */
    public function escalate(ClinicalEvent $event, User $user): ClinicalEvent
    {
        $event->loadMissing('client');
        $this->signalService->emitForEscalation($event, $user);
        $this->recordActionTimeline($event, $user, 'Clinical event escalated', 'Escalated to on-call leadership by '.$user->name);

        return $event->fresh();
    }

    protected function recordActionTimeline(ClinicalEvent $event, User $actor, string $subject, string $body): void
    {
        app(TimelineEmitter::class)->record([
            'type' => self::TIMELINE_TYPE_CLINICAL_EVENT,
            'source_type' => ClinicalEvent::class,
            'source_id' => $event->id,
            'occurred_at' => now(),
            'actor_user_id' => $actor->id,
            'client_id' => $event->client_id,
            'shift_id' => $event->shift_id,
            'site_id' => $event->site_id,
            'subject' => $subject,
            'body' => $body,
            'meta' => ['clinical_event_id' => $event->id],
            'visibility' => 'internal',
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Get clinical events for a client, optionally filtered by type.
     */
    public function getForClient(
        Client $client,
        ?ClinicalEventType $type = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
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
            throw new DomainException('The clinical event does not define a Health & Safety category.');
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

        if (! $hsEvent) {
            throw new DomainException('The clinical event could not be linked to Health & Safety.');
        }

        if ((int) $hsEvent->site_id !== (int) $event->site_id) {
            throw new DomainException('The Health & Safety event Site does not match the clinical event Site.');
        }

        $event->updateQuietly(['linked_hs_event_id' => $hsEvent->id]);
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    protected function createTimelineEvent(ClinicalEvent $event, User $reporter): TimelineEvent
    {
        return app(TimelineEmitter::class)->record([
            'type' => self::TIMELINE_TYPE_CLINICAL_EVENT,
            'source_type' => ClinicalEvent::class,
            'source_id' => $event->id,
            'occurred_at' => $event->occurred_at,
            'actor_user_id' => $reporter->id,
            'client_id' => $event->client_id,
            'shift_id' => $event->shift_id,
            'site_id' => $event->site_id,
            'subject' => $event->event_type->label().' reported',
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
            'Severity: '.$event->severity,
        ];

        if ($event->description) {
            $parts[] = $event->description;
        }

        if ($event->immediate_action_taken) {
            $parts[] = 'Action taken: '.$event->immediate_action_taken;
        }

        if ($event->outcome) {
            $parts[] = 'Outcome: '.$event->outcome;
        }

        if ($event->requires_followup) {
            $parts[] = 'Follow-up required';
        }

        if ($event->followup_notes) {
            $parts[] = 'Follow-up notes: '.$event->followup_notes;
        }

        return implode(' · ', $parts);
    }
}
