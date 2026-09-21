<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\Client;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\User;
use App\Services\Integration\IntegrationEventHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClientLocationHistoryService
{
    public function __construct(private ClientLocationAccessService $access, private IntegrationEventHistoryService $history) {}

    public function current(User $actor, Client $client): ?array
    {
        $assignment = $this->access->resolve($actor, $client);
        $fingerprint = $this->access->fingerprint($assignment);
        $result = $this->read($actor, $client, []);
        $point = $result['locations']->first(fn (array $item) => $this->validCoordinates($item['lat'] ?? null, $item['lng'] ?? null));
        if ($point) {
            // History fields belong to this exact observation. Missing quality is unknown.
            $point = [...$point, 'accuracy' => null, 'heading' => null, 'altitude' => null];
        }

        $device = $assignment->device;
        $meta = $device->meta ?? [];
        $lat = $meta['lat'] ?? $meta['latitude'] ?? null;
        $lng = $meta['lng'] ?? $meta['longitude'] ?? null;
        $timestamp = $meta['last_location_at'] ?? null;
        // The telemetry writer updates this metadata tuple together. A bare device
        // coordinate or last-contact time does not establish observation provenance.
        if (is_string($timestamp) && $this->validCoordinates($lat, $lng)
            && is_numeric($device->latitude) && is_numeric($device->longitude)
            && abs((float) $device->latitude - (float) $lat) < 0.000001
            && abs((float) $device->longitude - (float) $lng) < 0.000001) {
            try {
                $observedAt = CarbonImmutable::parse($timestamp);
                $withinWindow = $observedAt->betweenIncluded(CarbonImmutable::parse($result['window']['from']), CarbonImmutable::parse($result['window']['to']));
                if ($withinWindow && (! $point || $observedAt->greaterThan(CarbonImmutable::parse($point['timestamp'])))) {
                    $coordinates = sprintf('%.6f, %.6f', (float) $lat, (float) $lng);
                    $point = [
                        'lat' => (float) $lat, 'lng' => (float) $lng, 'timestamp' => $observedAt->toISOString(),
                        'address' => null, 'coordinates' => $coordinates, 'display_location' => $coordinates,
                        'speed' => $meta['speed'] ?? null, 'heading' => $meta['heading'] ?? null,
                        'accuracy' => $meta['accuracy'] ?? null, 'altitude' => $meta['altitude'] ?? null,
                    ];
                }
            } catch (\Throwable) {
                // Invalid timestamps cannot make an old device projection current.
            }
        }
        $point = app(ClientLocationAddressService::class)->enrich($point);
        $this->access->recheck($actor, $client, $fingerprint);

        return $point;
    }

    private function validCoordinates(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && is_finite((float) $lat) && is_finite((float) $lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
    }

    /** Exact provider-correlated evidence, never the Device's latest projection. */
    public function forLocateRequest(User $actor, Client $client,
        DeviceCommandRequest $command,
        QueclinkPendingCommand $pending): ?array
    {
        $assignment = $this->access->resolve($actor, $client, true);
        $fingerprint = $this->access->fingerprint($assignment);
        $origin = ClientLocationCommandOrigin::fromArray($command->origin_context);
        abort_unless($origin->clientId === (int) $client->id && $origin->assignmentId === (int) $assignment->id
            && $origin->consentId === (int) $assignment->consent_id && hash_equals($origin->accessFingerprint, $fingerprint), 403);
        $point = null;
        $event = FleetTelemetryEvent::query()->whereKey($pending->fulfilled_telemetry_event_id)
            ->where('device_id', $assignment->device_id)->where('vendor', 'queclink')->where('consent_blocked', false)->first();
        $from = collect([CarbonImmutable::now()->subDays((int) $assignment->retention_days),
            CarbonImmutable::parse($assignment->assigned_at), CarbonImmutable::parse($assignment->collection_started_at),
            CarbonImmutable::parse($assignment->consent->given_at)])->max();
        if ((int) $pending->device_command_request_id === (int) $command->id
            && $pending->device?->device_id === $assignment->device_id
            && $pending->governedAttempt?->device_command_request_id === $command->id
            && $pending->sent_at && $pending->expires_at && $event?->occurred_at && $event->received_at
            && $event->received_at->betweenIncluded($pending->sent_at, $pending->expires_at)
            && $event->received_at->lessThanOrEqualTo($command->expires_at)
            && $event->occurred_at->betweenIncluded($from, now())
            && $this->validCoordinates($event->latitude, $event->longitude)) {
            $point = ['lat' => (float) $event->latitude, 'lng' => (float) $event->longitude,
                'timestamp' => $event->occurred_at->toISOString(), 'received_at' => $event->received_at->toISOString(),
                'is_new' => $event->occurred_at->greaterThan($command->created_at),
                'accuracy' => $event->accuracy_m, 'speed' => $event->speed_kph,
                'address' => $event->address,
                'display_location' => $event->address ?: sprintf('%.6f, %.6f', $event->latitude, $event->longitude)];
        }
        $point = app(ClientLocationAddressService::class)->enrich($point);
        $this->access->recheck($actor, $client, $fingerprint, true);

        return $point;
    }

    public function read(User $actor, Client $client, array $filters): array
    {
        $assignment = $this->access->resolve($actor, $client);
        $fingerprint = $this->access->fingerprint($assignment);
        $data = Validator::make($filters, [
            'date_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ])->validate();
        $today = CarbonImmutable::now('Pacific/Auckland')->toDateString();
        if (($data['date_from'] ?? '') > $today || ($data['date_to'] ?? '') > $today
            || (isset($data['date_from'], $data['date_to']) && $data['date_from'] > $data['date_to'])) {
            throw ValidationException::withMessages(['date_to' => 'Choose a date range ending today or earlier, with To on or after From.']);
        }
        $from = collect([
            CarbonImmutable::now()->subDays((int) $assignment->retention_days),
            CarbonImmutable::parse($assignment->assigned_at),
            CarbonImmutable::parse($assignment->collection_started_at),
            CarbonImmutable::parse($assignment->consent->given_at),
        ])->max();
        if (isset($data['date_from'])) {
            $from = $from->max(CarbonImmutable::parse($data['date_from'], 'Pacific/Auckland')->startOfDay());
        }
        $to = CarbonImmutable::now();
        if (isset($data['date_to'])) {
            $to = $to->min(CarbonImmutable::parse($data['date_to'], 'Pacific/Auckland')->endOfDay());
        }
        $locations = $from->greaterThan($to) ? collect() : $this->history->forDevice(
            $assignment->device,
            ['date_from' => $from->utc()->toDateTimeString(), 'date_to' => $to->utc()->toDateTimeString()],
            true,
            (int) $assignment->retention_days,
        )->filter(function (array $point) use ($from, $to): bool {
            if (! is_string($point['timestamp'] ?? null)) {
                return false;
            }
            try {
                return CarbonImmutable::parse($point['timestamp'])->betweenIncluded($from, $to);
            } catch (\Throwable) {
                return false;
            }
        })->values();
        $this->access->recheck($actor, $client, $fingerprint);

        return ['locations' => $locations, 'access_fingerprint' => $fingerprint, 'checked_at' => now()->toISOString(),
            'window' => ['from' => $from->toISOString(), 'to' => $to->toISOString(), 'timezone' => 'Pacific/Auckland']];
    }
}
