<?php

namespace App\Services\ControlRoom;

use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sensor → incident bridge (Gap B).
 *
 * A non-interactive sensor detection (e.g. a `fall_detected` Signal → ControlRoomAlert)
 * is triaged by an operator:
 *  - CONFIRM  → create a ClientIncident (source=sensor, interactive=false), carrying the
 *               signal evidence, with the canonical journey service linking the
 *               alert, incident, and H&S event in one transaction.
 *  - DISMISS  → log a false-positive reason on the alert (for sensor tuning) and
 *               suppress its signals. No incident is created.
 */
class SensorIncidentBridgeService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly IncidentJourneyService $journeys,
        private readonly ControlRoomAlertLifecycleService $lifecycle,
    ) {}

    /**
     * Confirm a sensor alert into a ClientIncident. Idempotent: if the alert is
     * already linked to an incident, that incident is returned unchanged.
     *
     * @param  array{type?: string, severity?: string, note?: string}  $overrides
     */
    public function confirm(ControlRoomAlert $alert, User $operator, array $overrides = []): ClientIncident
    {
        return DB::transaction(function () use ($alert, $operator, $overrides) {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $this->lifecycle->isDetectionAlert($lockedAlert)) {
                throw new InvalidArgumentException("Alert {$lockedAlert->id} is not a sensor alert.");
            }
            $isConfirmedRepair = $lockedAlert->status === ControlRoomAlert::STATUS_CONFIRMED;
            if (! $isConfirmedRepair && ! $lockedAlert->canTransitionTo(ControlRoomAlert::STATUS_CONFIRMED)) {
                throw new InvalidArgumentException(
                    "Alert {$lockedAlert->id} cannot be confirmed from status '{$lockedAlert->status}'.",
                );
            }

            $signal = $lockedAlert->signals()->latest('occurred_at')->first();
            $evidence = $this->buildEvidence($lockedAlert, $signal);
            $type = $overrides['type'] ?? $this->inferType($signal);
            $journey = $this->journeys->submitFromAlert($lockedAlert, [
                'client_id' => $lockedAlert->client_id,
                'site_id' => $lockedAlert->site_id,
                'type' => $type,
                'severity' => $overrides['severity'] ?? $this->mapSeverity($lockedAlert->severity),
                'occurred_at' => $signal?->occurred_at ?? $lockedAlert->triggered_at ?? now(),
                'description' => $overrides['note'] ?? $this->describe($signal),
                'title' => ucfirst(str_replace('_', ' ', $type)).' incident',
                'metadata' => ['sensor_evidence' => $evidence],
            ], $operator);
            $incident = $journey->incident;

            if ($isConfirmedRepair) {
                return $incident;
            }

            $this->lifecycle->confirmSensor($lockedAlert->refresh(), $operator);

            return $incident;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Dismiss a sensor alert as a false positive. Logs the reason for sensor-tuning
     * analytics and suppresses the underlying signals. No incident is created.
     */
    public function dismiss(ControlRoomAlert $alert, string $reason, User $operator): void
    {
        $this->lifecycle->dismissSensor($alert, $operator, $reason);
    }

    /**
     * The signal evidence carried onto the incident (device, payload, timestamp).
     *
     * @return array<string, mixed>
     */
    private function buildEvidence(ControlRoomAlert $alert, ?Signal $signal): array
    {
        return array_filter([
            'device' => $alert->device?->name ?? $signal?->device?->name,
            'signal_type' => $signal?->signal_type_code,
            'payload' => $signal?->payload,
            'detected_at' => ($signal?->occurred_at ?? $alert->triggered_at)?->toISOString(),
        ], fn ($v) => $v !== null);
    }

    private function inferType(?Signal $signal): string
    {
        return match ($signal?->signal_type_code) {
            'fall_detected' => 'fall',
            default => 'other',
        };
    }

    /** ClientIncident severity is low|medium|high; an alert may also be critical. */
    private function mapSeverity(?string $alertSeverity): string
    {
        return $alertSeverity === 'critical' ? 'high' : ($alertSeverity ?: 'medium');
    }

    private function describe(?Signal $signal): string
    {
        if ($signal?->signal_type_code === 'fall_detected') {
            $confidence = $signal->payload['confidence'] ?? null;

            return 'Fall detected by sensor'.($confidence !== null ? " (confidence {$confidence})" : '').', confirmed by the operator.';
        }

        return 'Sensor detection confirmed by the operator.';
    }
}
