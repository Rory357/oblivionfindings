<?php

namespace App\Domain\SecurityDevices\Alarms\SiaDc09;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\SignalSource;
use Carbon\CarbonImmutable;
use Throwable;
use UnexpectedValueException;

/**
 * Authenticates, authorises, and records inbound ANSI/SIA DC-09 alarm frames.
 *
 * Fails closed whenever:
 *  - Frame CRC-16/ARC or length is invalid,
 *  - Account number does not match an active Security alarm/access panel, or
 *  - Sender IP is outside the panel's Site approved `DiscoveryScope` CIDRs / configured communicator CIDRs.
 */
final class SiaDc09IntakeService
{
    public function __construct(
        private readonly SiaDc09FrameDecoder $decoder,
        private readonly ContactIdEventMapper $mapper,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly CidrMatcher $cidrMatcher,
        private readonly CredentialLeaseProvider $leases,
        private readonly CredentialLeaseLifecycleService $leaseLifecycle,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    /**
     * @return array{
     *     accepted: bool,
     *     reason_code: string,
     *     response_frame: string|null,
     *     device_id: int|null,
     *     event_id: int|null
     * }
     */
    public function ingest(string $rawFrame, string $senderIp, ?CarbonImmutable $receivedAt = null): array
    {
        $receivedAt ??= CarbonImmutable::now('UTC');

        if (filter_var($senderIp, FILTER_VALIDATE_IP) === false) {
            return [
                'accepted' => false,
                'reason_code' => 'invalid_sender_ip',
                'response_frame' => null,
                'device_id' => null,
                'event_id' => null,
            ];
        }

        $canonicalSenderIp = $this->cidrMatcher->canonicalAddress($senderIp);
        $decoded = $this->decoder->decode($rawFrame);

        // If frame is encrypted (`*ADM-CID` / `*SIA-DCS`), look up the matching account's Site AES key and decode
        if (! $decoded['ok'] && ($decoded['reason_code'] ?? '') === 'encryption_key_required' && isset($decoded['account'])) {
            $candidate = $this->findDeviceByAccount($decoded['account']);
            if ($candidate !== null) {
                $siteId = $this->resolveSiteId($candidate);
                if ($siteId !== null && $this->isSenderAuthorised($candidate, $siteId, $canonicalSenderIp)) {
                    $decoded = $this->decodeEncryptedForSite($rawFrame, $siteId);
                }
            }
        }

        if (! $decoded['ok']) {
            return [
                'accepted' => false,
                'reason_code' => $decoded['reason_code'],
                'response_frame' => null,
                'device_id' => null,
                'event_id' => null,
            ];
        }

        $account = (string) $decoded['account'];
        $device = $this->findDeviceByAccount($account);
        if ($device === null) {
            return [
                'accepted' => false,
                'reason_code' => 'unknown_account',
                'response_frame' => null,
                'device_id' => null,
                'event_id' => null,
            ];
        }

        $siteId = $this->resolveSiteId($device);
        if ($siteId === null || ! $this->isSenderAuthorised($device, $siteId, $canonicalSenderIp)) {
            return [
                'accepted' => false,
                'reason_code' => 'sender_out_of_scope',
                'response_frame' => null,
                'device_id' => (int) $device->id,
                'event_id' => null,
            ];
        }

        $mapped = $this->mapper->map((string) $decoded['token'], (string) ($decoded['data'] ?? ''));
        if (! $mapped['ok']) {
            return [
                'accepted' => false,
                'reason_code' => 'unparseable_event_payload',
                'response_frame' => SiaDc09FrameDecoder::buildResponseFrame(
                    'DUH',
                    (string) $decoded['sequence'],
                    $decoded['receiver'] ?? null,
                    (string) ($decoded['line_prefix'] ?? 'L0'),
                    $account,
                    $receivedAt->toDateTimeImmutable(),
                ),
                'device_id' => (int) $device->id,
                'event_id' => null,
            ];
        }

        $ackFrame = SiaDc09FrameDecoder::buildResponseFrame(
            'ACK',
            (string) $decoded['sequence'],
            $decoded['receiver'] ?? null,
            (string) ($decoded['line_prefix'] ?? 'L0'),
            $account,
            $receivedAt->toDateTimeImmutable(),
        );

        $device->forceFill(['last_seen_at' => $receivedAt])->save();

        // Supervision NULL heartbeats refresh `last_seen_at` and ACK without spamming event history
        if ($decoded['token'] === 'NULL') {
            return [
                'accepted' => true,
                'reason_code' => 'heartbeat_acknowledged',
                'response_frame' => $ackFrame,
                'device_id' => (int) $device->id,
                'event_id' => null,
            ];
        }

        $zoneId = $mapped['zone'];
        $configuredZones = data_get($device->config, 'zones', []);
        $zoneName = null;
        if ($zoneId !== null && is_array($configuredZones)) {
            $candidateLabel = $configuredZones[$zoneId] ?? $configuredZones[(string) (int) $zoneId] ?? null;
            if (is_string($candidateLabel) && trim($candidateLabel) !== '') {
                $zoneName = substr(trim($candidateLabel), 0, 48);
            }
        }

        $eventOccurredAt = $decoded['timestamp'] instanceof \DateTimeImmutable
            ? CarbonImmutable::instance($decoded['timestamp'])
            : $receivedAt;

        $payload = array_filter([
            'protocol' => 'sia_dc09',
            'token' => $decoded['token'],
            'account' => $account,
            'sequence' => $decoded['sequence'],
            'partition' => $mapped['partition'],
            'zone' => $zoneId,
            'zone_name' => $zoneName,
            'zone_state' => $mapped['zone_state'],
            'cid_qualifier' => $mapped['cid_qualifier'] ?? null,
            'cid_code' => $mapped['cid_code'] ?? null,
            'sia_code' => $mapped['sia_code'] ?? null,
            'summary' => $mapped['summary'],
            'sender_ip' => $canonicalSenderIp,
        ], static fn (mixed $v): bool => $v !== null);

        $eventAttributes = [
            'device_id' => $device->id,
            'event_type' => $mapped['event_type'],
            'severity' => $mapped['severity'],
            'payload' => $payload,
            'source' => 'sia_dc09',
            'occurred_at' => $eventOccurredAt,
        ];
        $event = SignalSource::query()->where('slug', 'security_devices')->exists()
            ? DeviceEvent::query()->create($eventAttributes)
            : DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create($eventAttributes));

        // Stage critical/warning events onto the runtime monitoring outbox so alert routes fire
        if (in_array($mapped['severity'], ['critical', 'warning'], true)) {
            $idempotencyKey = hash('sha256', implode('|', [
                'sia_dc09',
                $siteId,
                $device->id,
                $account,
                (string) $decoded['sequence'],
                $mapped['event_type'],
                $eventOccurredAt->format('Y-m-d\TH:i'),
            ]));

            try {
                $this->outbox->stage(
                    type: RuntimeMessageType::Event,
                    stream: (string) config('monitoring.queues.events', 'monitoring-events'),
                    source: "central:sia_dc09:site:{$siteId}:device:{$device->id}",
                    idempotencyKey: 'sia-dc09:'.$idempotencyKey,
                    payload: [
                        'event_family' => 'sia_dc09',
                        'site_id' => $siteId,
                        'device_id' => (int) $device->id,
                        'device_event_id' => (int) $event->id,
                        'protocol' => 'sia_dc09',
                        'severity' => $mapped['severity'],
                        'event_type' => $mapped['event_type'],
                        'summary' => $mapped['summary'],
                        'occurred_at' => $eventOccurredAt->toIso8601String(),
                    ],
                );
            } catch (\RuntimeException) {
                // Local dev environments without a Redis sequence lock store still persist the canonical DeviceEvent.
            }
        }

        return [
            'accepted' => true,
            'reason_code' => 'event_recorded',
            'response_frame' => $ackFrame,
            'device_id' => (int) $device->id,
            'event_id' => (int) $event->id,
        ];
    }

    private function findDeviceByAccount(string $account): ?Device
    {
        $upper = strtoupper(trim($account));
        if ($upper === '') {
            return null;
        }

        $candidates = Device::query()
            ->where('domain', 'security')
            ->whereIn('category', ['alarm', 'perimeter', 'access_control'])
            ->where('status', '!=', DeviceStatus::Decommissioned->value)
            ->orderBy('id')
            ->limit(256)
            ->get();

        foreach ($candidates as $device) {
            foreach ([
                data_get($device->config, 'sia_dc09.account'),
                data_get($device->config, 'alarm.sia_account'),
                data_get($device->config, 'sia_account'),
                data_get($device->external_ref, 'sia_account'),
                data_get($device->meta, 'sia_dc09.account'),
                data_get($device->meta, 'sia_account'),
            ] as $configuredAccount) {
                if (is_scalar($configuredAccount) && strtoupper(trim((string) $configuredAccount)) === $upper) {
                    return $device;
                }
            }
        }

        return null;
    }

    private function resolveSiteId(Device $device): ?int
    {
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);

            return $siteId >= 1 ? $siteId : null;
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    private function isSenderAuthorised(Device $device, int $siteId, string $canonicalSenderIp): bool
    {
        // 1. Exact match against the panel's own configured IP
        if (is_string($device->ip_address) && filter_var($device->ip_address, FILTER_VALIDATE_IP) !== false) {
            try {
                if ($this->cidrMatcher->canonicalAddress($device->ip_address) === $canonicalSenderIp) {
                    return true;
                }
            } catch (Throwable) {
                // continue
            }
        }

        // 2. Explicit communicator CIDRs configured on the alarm panel (`config.sia_dc09.allowed_cidrs`)
        $panelCidrs = data_get($device->config, 'sia_dc09.allowed_cidrs', []);
        if (is_array($panelCidrs)) {
            foreach ($panelCidrs as $cidr) {
                if (! is_string($cidr) || trim($cidr) === '') {
                    continue;
                }
                try {
                    if ($this->cidrMatcher->contains(trim($cidr), $canonicalSenderIp)) {
                        return true;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        // 3. Active Site DiscoveryScope CIDRs (honouring exclusions)
        $scopes = DiscoveryScope::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->limit(64)
            ->get(['cidrs', 'exclusions']);

        foreach ($scopes as $scope) {
            $excluded = false;
            foreach ($scope->exclusions ?? [] as $exclusion) {
                if (! is_string($exclusion) || trim($exclusion) === '') {
                    continue;
                }
                $trimmed = trim($exclusion);
                try {
                    if ((str_contains($trimmed, '/') && $this->cidrMatcher->contains($trimmed, $canonicalSenderIp))
                        || (! str_contains($trimmed, '/') && $this->cidrMatcher->canonicalAddress($trimmed) === $canonicalSenderIp)) {
                        $excluded = true;
                        break;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
            if ($excluded) {
                continue;
            }
            foreach ($scope->cidrs ?? [] as $cidr) {
                if (! is_string($cidr) || trim($cidr) === '') {
                    continue;
                }
                try {
                    if ($this->cidrMatcher->contains($cidr, $canonicalSenderIp)) {
                        return true;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeEncryptedForSite(string $rawFrame, int $siteId): array
    {
        $reference = CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('provider', 'sia_dc09')
            ->whereIn('purpose', ['monitoring', 'device_management'])
            ->where('status', CredentialReferenceStatus::Active->value)
            ->whereNotIn('rotation_status', [
                CredentialRotationStatus::Overdue->value,
                CredentialRotationStatus::Failed->value,
            ])
            ->orderByDesc('id')
            ->first();

        if (! $reference) {
            return ['ok' => false, 'reason_code' => 'encryption_key_required'];
        }

        $capabilities = array_values(array_filter($reference->capabilities ?? [], 'is_string'));
        if ($capabilities === []) {
            return ['ok' => false, 'reason_code' => 'encryption_key_required'];
        }

        $lease = null;
        $material = [];
        try {
            $lease = $this->leases->acquire($siteId, $reference->reference_key, [$capabilities[0]]);
            $material = $lease->material();
            $rawHexOrAscii = trim((string) ($material['aes_key'] ?? $material['secret'] ?? ''));
            $keyBytes = ctype_xdigit($rawHexOrAscii) && in_array(strlen($rawHexOrAscii), [32, 48, 64], true)
                ? (string) hex2bin($rawHexOrAscii)
                : $rawHexOrAscii;

            return $this->decoder->decode($rawFrame, $keyBytes);
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'decryption_failed'];
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '' && function_exists('sodium_memzero')) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);

            if (isset($keyBytes) && is_string($keyBytes) && $keyBytes !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($keyBytes);
            }

            if ($lease !== null) {
                $this->leaseLifecycle->release($lease);
            }
        }
    }
}
