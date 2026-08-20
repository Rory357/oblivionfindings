<?php

namespace App\Services\Incidents;

use App\Jobs\DispatchIncidentLifecycleSignalOutbox;
use App\Models\ClientIncident;
use App\Models\IncidentLifecycleSignal;
use App\Models\IncidentLifecycleSignalOutbox;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IncidentAlertLifecycleSignalService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly IncidentJourneyService $journeys,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Record the Control Room close request while the authoritative incident is
     * locked in the caller's transaction.
     *
     * @param  array{closed_outcome: string, closed_notes?: string|null}  $closure
     */
    public function recordClose(
        ClientIncident $incident,
        IncidentJourney $journey,
        User $actor,
        CarbonInterface $effectiveAt,
        array $closure,
    ): IncidentLifecycleSignalOutbox {
        if ($incident->status !== 'closed'
            || (int) $incident->closed_by !== (int) $actor->id
            || $incident->closed_at === null) {
            throw new DomainException('The incident close signal does not match the authoritative close transition.');
        }

        return $this->record(
            incident: $incident,
            journey: $journey,
            actor: $actor,
            effectiveAt: $effectiveAt,
            signalType: IncidentLifecycleSignal::TYPE_CLOSED,
            fromStatus: 'reviewed',
            targetStatus: 'closed',
            payload: [
                'closed_outcome' => trim((string) $closure['closed_outcome']),
                'closed_notes' => filled($closure['closed_notes'] ?? null)
                    ? trim((string) $closure['closed_notes'])
                    : null,
            ],
        );
    }

    /**
     * Record the Control Room reopen request while the authoritative incident is
     * locked in the caller's transaction.
     */
    public function recordReopen(
        ClientIncident $incident,
        IncidentJourney $journey,
        User $actor,
        CarbonInterface $effectiveAt,
        string $reason,
    ): IncidentLifecycleSignalOutbox {
        $reason = trim($reason);
        if ($incident->status !== 'reviewed'
            || (int) $incident->reopened_by !== (int) $actor->id
            || $incident->reopened_at === null
            || $reason === '') {
            throw new DomainException('The incident reopen signal does not match the authoritative reopen transition.');
        }

        return $this->record(
            incident: $incident,
            journey: $journey,
            actor: $actor,
            effectiveAt: $effectiveAt,
            signalType: IncidentLifecycleSignal::TYPE_REOPENED,
            fromStatus: 'closed',
            targetStatus: 'reviewed',
            payload: ['reopened_reason' => $reason],
        );
    }

    /**
     * Reconcile a lifecycle transition written through an older/event-skipping
     * origin. Route writers record atomically; this is the scheduled/observer
     * safety net for the latest authoritative state.
     */
    public function reconcileLatestTransition(ClientIncident $incident): ?IncidentLifecycleSignalOutbox
    {
        return DB::transaction(function () use ($incident): ?IncidentLifecycleSignalOutbox {
            $locked = ClientIncident::query()
                ->whereKey($incident->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status === 'draft') {
                return null;
            }

            if ($locked->status === 'closed'
                && $locked->closed_by !== null
                && $locked->closed_at !== null
                && filled($locked->closed_outcome)) {
                $actor = User::query()->find($locked->closed_by);
                if ($actor === null) {
                    throw new DomainException('The incident close actor is unavailable.');
                }
                $journey = $this->journeys->ensureForSubmittedIncident($locked, $actor);

                return $this->recordClose(
                    $locked,
                    $journey,
                    $actor,
                    $locked->closed_at,
                    [
                        'closed_outcome' => (string) $locked->closed_outcome,
                        'closed_notes' => $locked->closed_notes,
                    ],
                );
            }

            if ($locked->status === 'reviewed'
                && $locked->reopened_by !== null
                && $locked->reopened_at !== null
                && filled($locked->reopened_reason)) {
                $actor = User::query()->find($locked->reopened_by);
                if ($actor === null) {
                    throw new DomainException('The incident reopen actor is unavailable.');
                }
                $journey = $this->journeys->ensureForSubmittedIncident($locked, $actor);

                return $this->recordReopen(
                    $locked,
                    $journey,
                    $actor,
                    $locked->reopened_at,
                    (string) $locked->reopened_reason,
                );
            }

            return null;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function dispatch(int $outboxId): void
    {
        try {
            DispatchIncidentLifecycleSignalOutbox::dispatch($outboxId);
        } catch (Throwable $exception) {
            // The source transition and outbox are already durable. The shared
            // scheduled recovery sweep will dispatch this exact intent again.
            Log::error('Incident lifecycle signal queue dispatch failed', [
                'outbox_id' => $outboxId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        ClientIncident $incident,
        IncidentJourney $journey,
        User $actor,
        CarbonInterface $effectiveAt,
        string $signalType,
        string $fromStatus,
        string $targetStatus,
        array $payload,
    ): IncidentLifecycleSignalOutbox {
        $siteId = $this->siteAccess->effectiveClientIncidentSiteId($incident);
        if ((int) $incident->client_id <= 0) {
            throw new DomainException('The incident lifecycle signal has no canonical Client.');
        }
        if ($journey->hsEvent === null
            || (int) $journey->hsEvent->client_id !== (int) $incident->client_id
            || (int) $journey->hsEvent->site_id !== $siteId) {
            throw new DomainException('The incident lifecycle signal has no canonical H&S event tuple.');
        }

        $effectiveAt = CarbonImmutable::instance($effectiveAt)->startOfSecond();
        $fingerprint = hash('sha256', json_encode([
            'signal_type' => $signalType,
            'actor_user_id' => $actor->id,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s'),
            'target_status' => $targetStatus,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
        $payload = array_merge($payload, [
            'actor_name' => $actor->name,
            'incident_reference' => $incident->reference_number,
            'hs_event_status' => $journey->hsEvent->status,
        ]);
        $payload['source_fingerprint'] = $fingerprint;

        $latest = IncidentLifecycleSignal::query()
            ->where('client_incident_id', $incident->id)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();

        if ($latest !== null
            && $latest->signal_type === $signalType
            && hash_equals((string) data_get($latest->payload, 'source_fingerprint'), $fingerprint)) {
            return IncidentLifecycleSignalOutbox::query()->firstOrCreate(
                ['incident_lifecycle_signal_id' => $latest->id],
                ['status' => 'pending'],
            );
        }

        $sequence = (int) ($latest?->sequence ?? 0) + 1;
        $signal = IncidentLifecycleSignal::query()->create([
            'client_incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'site_id' => $siteId,
            'client_id' => $incident->client_id,
            'hs_event_id' => $journey->hsEvent->id,
            'control_room_alert_id' => $journey->alert?->id,
            'sequence' => $sequence,
            'signal_type' => $signalType,
            'incident_source' => $incident->source ?: 'unknown',
            'from_status' => $fromStatus,
            'target_status' => $targetStatus,
            'effective_at' => $effectiveAt,
            'idempotency_key' => hash('sha256', implode('|', [
                'client-incident',
                $incident->id,
                'lifecycle',
                $sequence,
                $signalType,
            ])),
            'payload' => $payload,
        ]);

        return IncidentLifecycleSignalOutbox::query()->create([
            'incident_lifecycle_signal_id' => $signal->id,
            'status' => 'pending',
        ]);
    }
}
