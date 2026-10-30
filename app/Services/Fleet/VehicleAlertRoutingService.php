<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTrip;
use App\Models\FleetVehicleAlertAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sending a recorded vehicle event to Control Room from the vehicle profile.
 *
 * The event is re-read from the recorded data on the server (a client never
 * supplies the evidence) and emitted through the canonical Fleet signal and
 * outbox (FleetSignalService), which Control Room processes like any other
 * vehicle signal. One recorded event always has one Fleet signal identity,
 * so a repeat joins the same Control Room response and is kept here as a
 * duplicate report. Nothing is sent to the device and no emergency call is
 * made. A failed delivery is retried through the canonical recovery service.
 */
final class VehicleAlertRoutingService
{
    public const RECENT_DAYS = 7;

    public const FAILED_DELIVERIES = ['failed', 'dead_letter', 'unroutable'];

    public function __construct(
        private readonly VehicleTripHistoryService $trips,
        private readonly RecordedVehicleEvents $events,
        private readonly FleetSignalService $signals,
        private readonly VehicleSpeedLimitService $limits,
        private readonly SafetySignalDeliveryRecoveryService $recovery,
    ) {}

    public function canRoute(User $user): bool
    {
        return $user->canDo('fleet.manage');
    }

    public function canRetry(User $user): bool
    {
        return $user->canDo('controlRoom.alerts.manage');
    }

    /**
     * @param  array<string,mixed>  $input  kind (overspeed|telemetry), trip_id + event_key or event_id,
     *                                      optional evaluation {segment, direction}
     * @return array<string,mixed>
     */
    public function route(User $actor, int $assetId, array $input, string $requestKey): array
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($input, [
            'kind' => ['required', 'in:overspeed,telemetry'],
            'trip_id' => ['required_if:kind,overspeed', 'nullable', 'integer', 'min:1'],
            'event_key' => ['required_if:kind,overspeed', 'nullable', 'string', 'max:120'],
            'event_id' => ['required_if:kind,telemetry', 'nullable', 'integer', 'min:1'],
            'evaluation' => ['nullable', 'array'],
            'evaluation.segment' => ['required_with:evaluation', 'string', 'max:160'],
            'evaluation.direction' => ['required_with:evaluation', 'string', 'max:40'],
        ], [
            'trip_id.required_if' => 'Choose a recorded overspeed episode.',
            'event_key.required_if' => 'Choose a recorded overspeed episode.',
            'event_id.required_if' => 'Choose a recorded vehicle event.',
        ])->validate();
        $identity = $input['kind'] === 'overspeed'
            ? ['trip' => (int) $input['trip_id'], 'event' => (string) $input['event_key']]
            : ['event' => (int) $input['event_id']];
        $evaluation = isset($input['evaluation']) ? [
            'segment' => trim((string) $input['evaluation']['segment']),
            'direction' => trim((string) $input['evaluation']['direction']),
        ] : null;
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId,
            'route' => $identity, 'evaluation' => $evaluation]);

        [$vehicle, $action, $replayed] = DB::transaction(function () use ($actor, $assetId, $input, $evaluation, $requestKey, $fingerprint): array {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->canRoute($current), 403);
            // Recorded positions and trips follow the trip Site rule.
            $vehicle = $this->trips->vehicle($current, $assetId, true);
            $prior = FleetVehicleAlertAction::query()->where('asset_id', $vehicle->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->action === 'routed' && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different change.');

                return [$vehicle, $prior, true];
            }

            if ($input['kind'] === 'overspeed') {
                $tripId = (int) $input['trip_id'];
                $trip = FleetTrip::query()->whereKey($tripId)->where('asset_id', $vehicle->id)->first() ?? abort(404);
                abort_if($trip->is_personal || $trip->consent_blocked, 404);
                $episode = $this->events->overspeedEpisode($current, $vehicle, $tripId, (string) $input['event_key']);
                if ($episode === null) {
                    throw ValidationException::withMessages(['event_key' => 'This overspeed episode is no longer in the trip\'s recorded data. Reload and choose again.']);
                }
                $check = $evaluation ? $this->limits->evaluate($vehicle, $episode, $evaluation['segment'], $evaluation['direction']) : null;
                if ($check !== null && ! $check['qualifies']) {
                    throw ValidationException::withMessages(['evaluation' => 'No qualifying overspeed episode for the selected evidence and rule. Nothing was sent.']);
                }
                $sourceKey = $episode['source_key'];
                $policy = TripBehaviourAnalyzer::policy();
                $evidence = [
                    'kind' => 'overspeed',
                    'trip_id' => $tripId,
                    'event_key' => $episode['event_key'],
                    'observed_at' => $episode['at'],
                    'peak_kph' => $episode['peak_kph'],
                    'seconds' => $episode['seconds'],
                    'source' => $episode['source'],
                    'threshold_kph' => (float) $policy['speed_threshold_kph'],
                    'policy_version' => (int) ($policy['version'] ?? 1),
                    'detail' => $episode['detail'],
                    'road_limit' => $check === null ? 'not_checked' : ($check['road'] === null ? 'unknown' : $check['road']),
                    'evaluation' => $check === null ? null : [
                        'segment' => $check['segment'], 'direction' => $check['direction'], 'limit_id' => $check['limit_id'],
                        'trigger_kph' => $check['trigger_kph'], 'source' => $check['source'], 'summary' => $check['summary'],
                        'rule_source' => $check['rule']['source'], 'plan_version' => $check['rule']['plan_version'],
                    ],
                    'location' => $this->episodeLocation($vehicle, $episode),
                ];
                $signalType = RecordedVehicleEvents::SIGNAL_TYPES['overspeed'];
                $occurredAt = CarbonImmutable::parse((string) $episode['at'])->utc();
                $privacyBlocked = false;
                $signalTripId = $tripId;
                $driverSession = $trip->driver_session_id ? (int) $trip->driver_session_id : null;
            } else {
                $event = $this->events->powerEvent($vehicle, (int) $input['event_id']) ?? abort(404);
                $sourceKey = $event['source_key'];
                $evidence = [
                    'kind' => $event['kind'],
                    'event_id' => $event['event_id'],
                    'observed_at' => $event['at'],
                    'battery_pct' => $event['battery_pct'],
                    'detail' => $event['detail'],
                    'withheld' => $event['withheld'],
                    'location' => $event['lat'] !== null ? ['lat' => $event['lat'], 'lng' => $event['lng']] : null,
                ];
                $signalType = RecordedVehicleEvents::SIGNAL_TYPES[$event['kind']];
                $occurredAt = CarbonImmutable::parse((string) $event['at'])->utc();
                // Device state only: no trip, driver or position for a
                // consent-blocked or personal-trip report.
                $privacyBlocked = $event['withheld'] !== null;
                $signalTripId = $event['trip_id'];
                $driverSession = $signalTripId ? FleetTrip::query()->whereKey($signalTripId)->value('driver_session_id') : null;
            }

            $signal = $this->signals->emit([
                'asset_id' => $vehicle->id,
                'trip_id' => $signalTripId,
                'driver_session_id' => $driverSession ? (int) $driverSession : null,
                'signal_type' => $signalType,
                // A review priority; Control Room rules own any escalation.
                'severity_hint' => 'medium',
                'occurred_at' => $occurredAt,
                'idempotency_key' => RecordedVehicleEvents::signalKey((int) $vehicle->id, $sourceKey),
                'payload' => [
                    'source' => 'vehicle_profile',
                    'source_key' => $sourceKey,
                    'sent_by_user_id' => (int) $current->id,
                    'privacy_blocked' => $privacyBlocked,
                    'evidence' => array_diff_key($evidence, ['location' => true]),
                ],
            ]);
            $duplicate = FleetVehicleAlertAction::query()->where('asset_id', $vehicle->id)
                ->where('fleet_signal_id', $signal->id)->where('action', 'routed')->lockForUpdate()->exists();
            $action = FleetVehicleAlertAction::query()->create([
                'asset_id' => $vehicle->id,
                'fleet_signal_id' => $signal->id,
                'action' => 'routed',
                'note' => $duplicate ? 'duplicate' : null,
                'evidence' => $evidence,
                'source_key' => $sourceKey,
                'actor_user_id' => $current->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.vehicle.alert_routed', $vehicle, [
                'actor_id' => $current->id,
                'asset_id' => $vehicle->id,
                'fleet_signal_id' => $signal->id,
                'signal_type' => $signalType,
                'source_key' => $sourceKey,
                'duplicate' => $duplicate,
                'evaluated' => $evaluation !== null,
            ]);

            return [$vehicle, $action, false];
        }, 3);

        // Read after commit: the outbox is processed once the signal is saved.
        return $this->result($vehicle, $action, $replayed, VehicleDrivingInsightsService::canViewAlerts($actor));
    }

    /**
     * Retry a vehicle signal whose Control Room delivery failed, through the
     * canonical recovery service.
     *
     * @return array<string,mixed>
     */
    public function retry(User $actor, int $assetId, int $signalId, int $expectedAttempts, string $requestKey): array
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'signal' => $signalId, 'retry' => $expectedAttempts]);
        [$outboxId, $replayed] = DB::transaction(function () use ($actor, $assetId, $signalId, $expectedAttempts, $requestKey, $fingerprint): array {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->canRetry($current), 403);
            $vehicle = $this->trips->vehicle($current, $assetId, true);
            $signal = FleetSignal::query()->whereKey($signalId)->where('asset_id', $vehicle->id)->first() ?? abort(404);
            $prior = FleetVehicleAlertAction::query()->where('asset_id', $vehicle->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->action === 'delivery_retried' && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different change.');

                return [(int) FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->value('id'), true];
            }
            $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->lockForUpdate()->first() ?? abort(404);
            if (! in_array($outbox->status, self::FAILED_DELIVERIES, true) || (int) $outbox->attempts !== $expectedAttempts) {
                abort(409, 'This delivery changed while you were looking at it. Reload the alerts.');
            }
            FleetVehicleAlertAction::query()->create([
                'asset_id' => $vehicle->id,
                'fleet_signal_id' => $signal->id,
                'action' => 'delivery_retried',
                'note' => 'Previous delivery: '.$outbox->status,
                'actor_user_id' => $current->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.vehicle.alert_delivery_retried', $vehicle, [
                'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'fleet_signal_id' => $signal->id,
                'outbox_id' => $outbox->id, 'previous_status' => $outbox->status, 'attempts' => (int) $outbox->attempts,
            ]);

            return [(int) $outbox->id, false];
        }, 3);

        if (! $replayed) {
            try {
                $this->recovery->retry('fleet', $outboxId);
            } catch (DomainException|InvalidArgumentException) {
                // Someone else already recovered it; the fresh status below says so.
            }
        }
        $outbox = FleetSignalOutbox::query()->find($outboxId);

        return [
            'signal_id' => $signalId,
            'delivery' => (string) ($outbox?->status ?? 'pending'),
            'message' => in_array($outbox?->status, self::FAILED_DELIVERIES, true)
                ? 'Control Room still could not receive this signal. The cause needs fixing before another retry.'
                : 'Delivery retried. Control Room will show the response once it is received.',
        ];
    }

    /** @return array<string,mixed> */
    private function result(Asset $vehicle, FleetVehicleAlertAction $action, bool $replayed, bool $withResponse): array
    {
        $state = $this->events->routeStates($vehicle, [(string) $action->source_key], $withResponse)[(string) $action->source_key] ?? null;
        $duplicate = $action->note === 'duplicate';

        return [
            'signal_id' => (int) $action->fleet_signal_id,
            'source_key' => $action->source_key,
            'duplicate' => $duplicate,
            'replayed' => $replayed,
            'delivery' => $state['delivery'] ?? 'pending',
            'response' => $state['response'] ?? null,
            'message' => $duplicate
                ? 'Already sent. This report was added to the existing Control Room response.'
                : (in_array($state['delivery'] ?? null, self::FAILED_DELIVERIES, true)
                    ? 'Control Room could not receive this signal yet. It is kept and can be retried.'
                    : 'Sent to Control Room. No emergency call or device command was made.'),
        ];
    }

    /**
     * The recorded position where an overspeed episode began, when it may be kept.
     *
     * @param  array<string,mixed>  $episode
     * @return array{lat:float,lng:float}|null
     */
    private function episodeLocation(Asset $vehicle, array $episode): ?array
    {
        $rowId = RecordedVehicleEvents::sourceRowId((string) $episode['event_key']);
        if ($rowId === null) {
            return null;
        }
        $row = DB::table('fleet_telemetry_events')->where('id', $rowId)->where('asset_id', $vehicle->getKey())
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')->whereNotNull('longitude')->first(['latitude', 'longitude']);

        return $row ? ['lat' => round((float) $row->latitude, 7), 'lng' => round((float) $row->longitude, 7)] : null;
    }
}
