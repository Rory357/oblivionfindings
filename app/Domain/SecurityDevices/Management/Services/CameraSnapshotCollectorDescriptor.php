<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Cctv\Services\CameraPrivacy;
use App\Domain\SecurityDevices\Cctv\Services\UniFiProtectLocalCameraClient;
use App\Domain\SecurityDevices\Drivers\Cameras\UniFiProtectCameraDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;
use App\Domain\SecurityDevices\Management\Data\CollectorCommandOutcome;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * S&D Phase B: one still picture from a UniFi Protect camera, taken by the hub that covers the Site.
 *
 * The hub reads the Protect console's certificate first and sends the API key only to the trusted
 * key (Phase A, item A5). The picture comes back once, is kept encrypted for two minutes for the
 * person who asked, and is never stored.
 */
final class CameraSnapshotCollectorDescriptor implements CollectorCommandDescriptor
{
    public const string CAPABILITY = 'camera.snapshot.capture';

    public const string PROTOCOL = 'command.camera_snapshot';

    public const string ADAPTER = 'camera_snapshot_v1';

    public const int MAX_IMAGE_BYTES = 1_048_576;

    public const int PICTURE_SECONDS = 120;

    public const array FAILURE_CODES = [
        'camera_certificate_untrusted', 'camera_certificate_changed', 'camera_tls_unreachable',
        'authentication_required', 'camera_not_mapped', 'snapshot_fetch_failed', 'unreachable',
        'snapshot_too_large', 'invalid_image', 'precheck_failed', 'interrupted',
    ];

    private const array MIME_MAGIC = [
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/png' => "\x89PNG\r\n\x1A\n",
        'image/webp' => 'RIFF',
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly DeviceDriverRegistry $drivers,
        private readonly UniFiProtectLocalCameraClient $protect,
        private readonly CameraEndpointTrustService $trust,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CollectorCommandContract $contracts,
        private readonly CoveringHubResolver $hubs,
    ) {}

    public static function pictureCacheKey(string $commandUuid): string
    {
        return 'security-devices:camera-picture:'.strtolower($commandUuid);
    }

    /** A UniFi Protect camera at a Site some hub covers (whether or not that hub is online now). */
    public function eligible(Device $device): bool
    {
        try {
            return $this->protectCamera($device)
                && $this->hubs->forSite($this->sites->resolve((int) $device->id)) instanceof MonitoringCollector;
        } catch (Throwable) {
            return false;
        }
    }

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function routesThroughCoveringHub(): bool
    {
        return true;
    }

    public function supports(Device $device, MonitoringCollector $collector, int $siteId): bool
    {
        try {
            $endpoint = $this->protect->authoriseConsoleEndpoint($device, checkCertificate: false);

            return $this->protectCamera($device)
                // S&D Phase E: no hub picture while the camera is inside its privacy hours.
                && ! app(CameraPrivacy::class)->active((int) $device->id)
                // The hub needs the camera's Protect ID or its MAC to find it on the console.
                && ($this->protectCameraId($device) !== null || $this->mac($device) !== null)
                && $this->sites->resolve((int) $device->id) === $siteId
                && ($endpoint['ok'] ?? false) === true
                && (int) ($endpoint['site_id'] ?? 0) === $siteId
                && in_array($siteId, $collector->authorisedSiteIds(), true)
                && $collector->isApprovedForWork()
                && $collector->revoked_at === null
                && is_string($collector->public_key)
                && in_array((string) $collector->status, ['online', 'degraded'], true)
                && $this->protect->hasProtectCredential($siteId);
        } catch (Throwable) {
            return false;
        }
    }

    public function build(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, MonitoringCollector $collector): array
    {
        $request->loadMissing('device');
        $device = $request->device;
        $siteId = (int) $request->site_id;
        if ($request->capability !== self::CAPABILITY || ! $device instanceof Device || ! $this->supports($device, $collector, $siteId)) {
            throw new RuntimeException('The hub camera job no longer matches its request.');
        }
        $endpoint = $this->protect->authoriseConsoleEndpoint($device, checkCertificate: false);
        $address = (string) $endpoint['ip'];
        $port = (int) $endpoint['port'];
        $trustedPin = $this->trust->record($siteId, $address, $port)?->trusted_pin;
        // Never build a job the hub's scope guard would refuse: one bad job makes it refuse the whole config.
        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! in_array($port, [443, 8443], true)
            || ($trustedPin !== null && preg_match(CameraEndpointTrustService::PIN_PATTERN, $trustedPin) !== 1)) {
            throw new RuntimeException('The camera job would not pass the hub’s checks.');
        }
        $lease = $this->protect->acquireProtectCredential($siteId)
            ?? throw new RuntimeException('The Site has no usable saved Protect login.');

        return [
            'command_uuid' => (string) $request->command_uuid,
            'attempt_uuid' => (string) $attempt->attempt_uuid,
            'attempt_number' => (int) $attempt->attempt_number,
            'site_id' => $siteId,
            'device_id' => (string) $request->device_id,
            'capability' => self::CAPABILITY,
            'provider' => 'unifi_protect',
            'adapter' => self::ADAPTER,
            'protocol' => self::PROTOCOL,
            'target' => $address,
            'expires_at' => $request->expires_at->utc()->format(DATE_ATOM),
            'idempotency_hash' => hash('sha256', (string) $request->idempotency_key),
            'contract_hash' => $this->contracts->hash($request, $attempt, $collector),
            'parameters' => ['method' => 'protect_integration_v1'],
            'expected_state' => ['picture_captured' => true],
            'endpoint' => [
                'address' => $address,
                'port' => $port,
                'trusted_pin' => $trustedPin,
                'camera_id' => $this->protectCameraId($device),
                'mac' => $this->mac($device),
                'connect_timeout_seconds' => 5,
                'response_timeout_seconds' => 10,
                'max_image_bytes' => self::MAX_IMAGE_BYTES,
            ],
            'credential_lease' => $this->sealer->seal($collector, (string) $request->device_id, self::PROTOCOL, $address, $lease, $siteId),
        ];
    }

    public function maxAttachmentBytes(): int
    {
        // A 1 MB picture barely compresses; base64 adds a third.
        return 1_572_864;
    }

    public function interpret(
        DeviceCommandRequest $request,
        MonitoringCollector $collector,
        string $executionStatus,
        array $safeResult,
        ?string $providerReference,
        ?string $failure,
        ?array $reconciliation,
        ?string $attachment,
    ): CollectorCommandOutcome {
        $device = $request->device;
        if (! $device instanceof Device || $reconciliation !== null || $executionStatus === 'uncertain') {
            throw new RuntimePayloadInvalid('Collector camera result is not a declared typed outcome.');
        }
        if ($executionStatus === 'failed') {
            return $this->failedFromHub($request, $device, $safeResult, $providerReference, $attachment);
        }

        $mime = $safeResult['mime'] ?? null;
        if (array_keys($safeResult) !== ['provider_state', 'mime', 'image_bytes', 'image_sha256']
            || $safeResult['provider_state'] !== 'captured'
            || ! is_string($mime) || ! isset(self::MIME_MAGIC[$mime])
            || ! is_string($attachment) || strlen($attachment) > self::MAX_IMAGE_BYTES
            || $safeResult['image_bytes'] !== strlen($attachment)
            || ! is_string($safeResult['image_sha256']) || ! hash_equals(hash('sha256', $attachment), $safeResult['image_sha256'])
            || ! str_starts_with($attachment, self::MIME_MAGIC[$mime])
            || $providerReference !== 'camera-snapshot:'.$request->command_uuid
            || $failure !== null) {
            throw new RuntimePayloadInvalid('Collector camera success evidence is invalid.');
        }

        $capturedAt = CarbonImmutable::now('UTC');
        // Kept encrypted, for the person who asked, for two minutes; the status check takes it once.
        // S&D Phase E: a picture that arrives inside the camera's privacy hours is never kept.
        if (! app(CameraPrivacy::class)->active((int) $device->id)) {
            Cache::put(self::pictureCacheKey((string) $request->command_uuid), Crypt::encryptString(json_encode([
                'data_uri' => 'data:'.$mime.';base64,'.base64_encode($attachment),
                'captured_at' => $capturedAt->toIso8601String(),
                'hub' => CoveringHubResolver::label($collector),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), self::PICTURE_SECONDS);
        }

        return new CollectorCommandOutcome(
            executionStatus: 'succeeded',
            safeResult: [
                'provider_state' => 'captured',
                'image_bytes' => strlen($attachment),
                'image_sha256' => $safeResult['image_sha256'],
                'routed_through' => 'hub',
            ],
            providerReference: 'camera-snapshot:'.$request->command_uuid,
            failure: null,
            reconciliation: [
                'outcome' => 'matched',
                'observed_state' => ['picture_captured' => true],
                'observation_reference' => 'camera-snapshot:'.$request->command_uuid,
                'safe_evidence_summary' => 'The Site hub returned one fresh camera picture. It is kept for two minutes for the person who asked, then deleted.',
                'observed_at' => $capturedAt->startOfSecond(),
            ],
        );
    }

    public function sameEvidence(DeviceCommandAttempt $attempt, string $executionStatus, array $safeResult, ?string $failure): bool
    {
        $stored = $attempt->safe_result_summary ?? [];

        return $attempt->status->value === $executionStatus
            && ($executionStatus === 'succeeded'
                ? ($stored['image_sha256'] ?? null) === ($safeResult['image_sha256'] ?? false)
                : ($stored['reason_code'] ?? null) === ($safeResult['reason_code'] ?? false));
    }

    /** Where a refused certificate's "Trust this camera?" prompt points: the Protect console. */
    public function trustTarget(Device $device): ?array
    {
        $endpoint = $this->protect->authoriseConsoleEndpoint($device, checkCertificate: false);
        if (($endpoint['ok'] ?? false) !== true) {
            return null;
        }

        return ['site_id' => (int) $endpoint['site_id'], 'host' => (string) $endpoint['ip'], 'port' => (int) $endpoint['port']];
    }

    /** @param array<string, scalar|null> $safeResult */
    private function failedFromHub(DeviceCommandRequest $request, Device $device, array $safeResult, ?string $providerReference, ?string $attachment): CollectorCommandOutcome
    {
        $code = $safeResult['reason_code'] ?? null;
        $certificateCase = in_array($code, ['camera_certificate_untrusted', 'camera_certificate_changed'], true);
        $expectedKeys = $certificateCase
            ? ['reason_code', 'offered_pin', 'offered_fingerprint', 'offered_subject', 'offered_valid_to']
            : ['reason_code'];
        if (! is_string($code) || ! in_array($code, self::FAILURE_CODES, true)
            || array_keys($safeResult) !== $expectedKeys
            || $providerReference !== null || $attachment !== null) {
            throw new RuntimePayloadInvalid('Collector camera failure evidence is invalid.');
        }
        if ($certificateCase) {
            $target = $this->trustTarget($device);
            $recorded = $target === null ? null : $this->trust->recordOfferedFromHub($target['site_id'], $target['host'], $target['port'], [
                'pin' => $safeResult['offered_pin'],
                'fingerprint' => $safeResult['offered_fingerprint'],
                'subject' => $safeResult['offered_subject'],
                'valid_to' => $safeResult['offered_valid_to'],
            ]);
            if ($recorded === null) {
                throw new RuntimePayloadInvalid('Collector camera certificate evidence is invalid.');
            }
        }

        return new CollectorCommandOutcome(
            executionStatus: 'failed',
            safeResult: ['reason_code' => $code, 'routed_through' => 'hub'],
            providerReference: null,
            failure: self::failureText($code),
            reconciliation: null,
        );
    }

    public static function failureText(string $code): string
    {
        return match ($code) {
            'camera_certificate_untrusted' => 'This Protect console’s certificate isn’t trusted yet, so the Site hub sent no API key. Check it and choose Trust this camera.',
            'camera_certificate_changed' => 'This Protect console’s certificate has changed since it was trusted, so the Site hub sent no API key. If the change was planned, an IT admin can replace the trusted certificate.',
            'camera_tls_unreachable' => 'The Site hub couldn’t open a secure connection to the Protect console, so nothing was sent.',
            'authentication_required' => 'The UniFi Protect console rejected the Site API key.',
            'camera_not_mapped' => 'Could not match this camera on the Site’s UniFi Protect console. Check its MAC address or Protect camera ID.',
            'unreachable' => 'The Site hub couldn’t reach the UniFi Protect console.',
            'snapshot_too_large' => 'The camera picture was larger than the hub’s 1 MB limit.',
            'invalid_image' => 'The UniFi Protect console didn’t return a valid picture.',
            'precheck_failed' => 'The Site hub couldn’t complete its safety and login checks, so nothing was sent.',
            'interrupted' => 'The Site hub restarted before the picture came back. Check the camera again.',
            default => 'The UniFi Protect console did not return a camera picture.',
        };
    }

    private function protectCamera(Device $device): bool
    {
        return $device->domain === 'security'
            && $device->category === 'cctv'
            && $device->status !== DeviceStatus::Decommissioned
            && $this->drivers->resolveCameraDriver($device) instanceof UniFiProtectCameraDriver;
    }

    private function protectCameraId(Device $device): ?string
    {
        foreach ([
            data_get($device->external_ref, 'protect_camera_id'),
            data_get($device->config, 'protect.camera_id'),
            data_get($device->meta, 'protect.camera_id'),
        ] as $candidate) {
            if (is_string($candidate) && preg_match('/\A[A-Za-z0-9_-]{4,64}\z/', trim($candidate)) === 1) {
                return trim($candidate);
            }
        }

        return null;
    }

    private function mac(Device $device): ?string
    {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $device->mac_address) ?? '');

        return strlen($mac) === 12 ? $mac : null;
    }
}
