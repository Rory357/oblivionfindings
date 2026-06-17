<?php

namespace App\Services\ControlRoom;

use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sensor → incident bridge (Gap B).
 *
 * A non-interactive sensor detection (e.g. a `fall_detected` Signal → ControlRoomAlert)
 * is triaged by an operator:
 *  - CONFIRM  → create a ClientIncident (source=sensor, interactive=false), carrying the
 *               signal evidence, bidirectionally linked to the alert; the
 *               ClientIncidentObserver then opens the HsEvent and back-links it.
 *  - DISMISS  → log a false-positive reason on the alert (for sensor tuning) and
 *               suppress its signals. No incident is created.
 */
class SensorIncidentBridgeService
{
    /**
     * Confirm a sensor alert into a ClientIncident. Idempotent: if the alert is
     * already linked to an incident, that incident is returned unchanged.
     *
     * @param  array{type?: string, severity?: string, note?: string}  $overrides
     */
    public function confirm(ControlRoomAlert $alert, User $operator, array $overrides = []): ClientIncident
    {
        if (! empty($alert->context['incident_id'])) {
            return ClientIncident::findOrFail($alert->context['incident_id']);
        }

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_CONFIRMED)) {
            throw new InvalidArgumentException("Alert {$alert->id} cannot be confirmed from status '{$alert->status}'.");
        }

        return DB::transaction(function () use ($alert, $operator, $overrides) {
            $signal = $alert->signals()->latest('occurred_at')->first();
            $evidence = $this->buildEvidence($alert, $signal);

            $incident = ClientIncident::create([
                'client_id' => $alert->client_id,
                'reported_by' => $operator->id,
                'type' => $overrides['type'] ?? $this->inferType($signal),
                'source' => 'sensor',
                'severity' => $overrides['severity'] ?? $this->mapSeverity($alert->severity),
                'status' => 'submitted',
                'submitted_at' => now(),
                'occurred_at' => $signal?->occurred_at ?? $alert->triggered_at ?? now(),
                'description' => $overrides['note'] ?? $this->describe($signal),
                'title' => ($overrides['type'] ?? $this->inferType($signal)) . ' incident',
                'control_room_alert_id' => $alert->id,
                'metadata' => ['sensor_evidence' => $evidence],
            ]);

            $context = $alert->context ?? [];
            $context['incident_id'] = $incident->id;
            $context['confirmed_by'] = $operator->name;
            $context['confirmed_at'] = now()->toISOString();

            $alert->update([
                'status' => ControlRoomAlert::STATUS_CONFIRMED,
                'context' => $context,
                'acknowledged_at' => $alert->acknowledged_at ?? now(),
                'acknowledged_by_user_id' => $alert->acknowledged_by_user_id ?? $operator->id,
            ]);

            AuditLogger::log('controlRoom.alert.confirm', $alert, [
                'alert_id' => $alert->id,
                'incident_id' => $incident->id,
                'confirmed_by' => $operator->id,
            ]);

            return $incident;
        });
    }

    /**
     * Dismiss a sensor alert as a false positive. Logs the reason for sensor-tuning
     * analytics and suppresses the underlying signals. No incident is created.
     */
    public function dismiss(ControlRoomAlert $alert, string $reason, User $operator): void
    {
        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_DISMISSED)) {
            throw new InvalidArgumentException("Alert {$alert->id} cannot be dismissed from status '{$alert->status}'.");
        }

        DB::transaction(function () use ($alert, $reason, $operator) {
            $context = $alert->context ?? [];
            $context['dismissed_reason'] = $reason;
            $context['dismissed_by'] = $operator->name;
            $context['dismissed_at'] = now()->toISOString();

            $alert->update([
                'status' => ControlRoomAlert::STATUS_DISMISSED,
                'resolution_code' => 'false_positive',
                'context' => $context,
                'resolved_at' => now(),
                'resolved_by_user_id' => $operator->id,
            ]);

            foreach ($alert->signals as $signal) {
                $signal->markSuppressed("false_positive: {$reason}");
            }

            AuditLogger::log('controlRoom.alert.dismiss', $alert, [
                'alert_id' => $alert->id,
                'reason' => $reason,
                'dismissed_by' => $operator->id,
            ]);
        });
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
            return 'Fall detected by sensor' . ($confidence !== null ? " (confidence {$confidence})" : '') . ', confirmed by the operator.';
        }

        return 'Sensor detection confirmed by the operator.';
    }
}
