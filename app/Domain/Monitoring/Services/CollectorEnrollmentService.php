<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorEnrollmentIssue;
use App\Domain\Monitoring\Data\CollectorEnrollmentResult;
use App\Domain\Monitoring\Models\CollectorCheckpoint;
use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CollectorEnrollmentService
{
    public function __construct(
        private CollectorCertificateIssuer $certificates,
        private DeviceRegistryService $devices,
    ) {}

    public function issue(
        int $siteId,
        int $actorId,
        ?CarbonImmutable $expiresAt = null,
        ?int $replacesCollectorId = null,
    ): CollectorEnrollmentIssue {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->first();
        $actor = User::query()->whereKey($actorId)->whereNotNull('approved_at')->first();
        if ($site === null || $actor === null) {
            throw new DomainException('Collector enrolment scope is unavailable.');
        }
        $expiresAt ??= CarbonImmutable::now('UTC')->addMinutes(15);
        if ($expiresAt->lte(CarbonImmutable::now('UTC')) || $expiresAt->gt(CarbonImmutable::now('UTC')->addDay())) {
            throw new DomainException('Collector enrolment expiry is invalid.');
        }

        return DB::transaction(function () use ($site, $actor, $expiresAt, $replacesCollectorId): CollectorEnrollmentIssue {
            $replacement = $replacesCollectorId === null
                ? null
                : MonitoringCollector::query()
                    ->whereKey($replacesCollectorId)
                    ->where('site_id', $site->id)
                    ->whereNotNull('revoked_at')
                    ->lockForUpdate()
                    ->first();
            if ($replacesCollectorId !== null && $replacement === null) {
                throw new DomainException('Collector re-enrolment scope is unavailable.');
            }

            $plainToken = 'ofc_enrol_'.rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $enrollment = CollectorEnrollment::query()->create([
                'site_id' => $site->id,
                'issued_by_user_id' => $actor->id,
                'replacement_collector_id' => $replacement?->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => $expiresAt,
            ]);
            AuditLogger::logOrFail('monitoring.collector.enrolment.issued', $enrollment, [
                'actor_id' => $actor->id,
                'site_id' => $site->id,
                'expires_at' => $expiresAt->toISOString(),
                'purpose' => $replacement === null ? 'new_collector' : 'collector_re_enrolment',
                ...($replacement === null ? [] : ['replaces_collector_id' => (int) $replacement->id]),
            ]);

            return new CollectorEnrollmentIssue($enrollment, $plainToken);
        }, 3);
    }

    public function enrol(string $plainToken, string $collectorUuid, string $publicKey): CollectorEnrollmentResult
    {
        if (! str_starts_with($plainToken, 'ofc_enrol_') || strlen($plainToken) > 128
            || ! Str::isUuid($collectorUuid)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DomainException('Collector enrolment request is invalid.');
        }
        $centralSecretKey = $this->centralSigningSecretKey();
        $centralPublicKey = sodium_crypto_sign_publickey_from_secretkey($centralSecretKey);

        return DB::transaction(function () use (
            $plainToken,
            $collectorUuid,
            $publicKey,
            $centralPublicKey,
        ): CollectorEnrollmentResult {
            $enrollment = CollectorEnrollment::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();
            if ($enrollment === null || $enrollment->consumed_at !== null || $enrollment->revoked_at !== null
                || $enrollment->expires_at->lte(CarbonImmutable::now('UTC'))) {
                throw new DomainException('Collector enrolment token is unavailable.');
            }
            $site = Site::query()
                ->whereKey($enrollment->site_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
                ->lockForUpdate()
                ->first();
            if ($site === null) {
                throw new DomainException('Collector enrolment Site is unavailable.');
            }
            $replacement = $enrollment->replacement_collector_id === null
                ? null
                : MonitoringCollector::query()
                    ->whereKey($enrollment->replacement_collector_id)
                    ->where('site_id', $site->id)
                    ->whereNotNull('revoked_at')
                    ->lockForUpdate()
                    ->first();
            if ($enrollment->replacement_collector_id !== null
                && ($replacement === null || $replacement->collector_uuid !== $collectorUuid)) {
                throw new DomainException('Collector identity is unavailable for enrolment.');
            }
            $collector = MonitoringCollector::query()
                ->where('collector_uuid', $collectorUuid)
                ->lockForUpdate()
                ->first();
            if ($collector !== null && ((int) $collector->site_id !== (int) $site->id
                || ($collector->enrolled_at !== null && $collector->revoked_at === null))) {
                throw new DomainException('Collector identity is unavailable for enrolment.');
            }
            if (MonitoringCollector::query()
                ->where('public_key_fingerprint', hash('sha256', $publicKey))
                ->when($collector !== null, fn ($query) => $query->where('id', '!=', $collector->id))
                ->exists()) {
                throw new DomainException('Collector identity is unavailable for enrolment.');
            }

            $certificate = $this->certificates->issue($collectorUuid);
            if (preg_match('/\A[a-f0-9]{64}\z/', $certificate->fingerprint) !== 1
                || $certificate->expiresAt->lte(CarbonImmutable::now('UTC'))) {
                throw new RuntimeException('Collector certificate issuance returned an invalid identity.');
            }
            $publicKeyFingerprint = hash('sha256', $publicKey);
            if ($collector === null) {
                $collector = MonitoringCollector::query()->create([
                    'collector_uuid' => $collectorUuid,
                    'name' => 'Remote collector '.substr($collectorUuid, 0, 8),
                    'site_id' => $site->id,
                    'status' => 'pending',
                    'config' => [],
                ]);
            }
            if ($collector->collector_device_id === null) {
                $projection = $this->devices->registerDiscoveredDevice([
                    'name' => $collector->name,
                    'domain' => 'it_infrastructure',
                    'category' => 'monitoring_collector',
                    'subcategory' => 'remote_collector',
                    'manufacturer' => 'Oblivion Findings',
                    'provider' => 'oblivion_monitoring',
                    'external_ref' => ['collector_uuid' => $collectorUuid],
                ], (int) $site->id, (int) $enrollment->issued_by_user_id);
                $collector->collector_device_id = $projection->id;
            }
            $collector->forceFill([
                'public_key' => base64_encode($publicKey),
                'public_key_fingerprint' => $publicKeyFingerprint,
                'client_certificate_fingerprint' => $certificate->fingerprint,
                'status' => 'pending',
                'last_seen_at' => null,
                'last_heartbeat_at' => null,
                'enrolled_at' => now(),
                'revoked_at' => null,
            ])->save();
            CollectorCheckpoint::query()->firstOrCreate(
                ['collector_id' => $collector->id],
                ['acknowledged_source_sequence' => (int) $collector->acknowledged_source_sequence],
            );
            $enrollment->forceFill([
                'consumed_at' => now(),
                'consumed_collector_id' => $collector->id,
            ])->save();
            AuditLogger::logOrFail('monitoring.collector.enrolment.consumed', $collector, [
                'actor_id' => (int) $enrollment->issued_by_user_id,
                'site_id' => (int) $site->id,
                'public_key_fingerprint' => $publicKeyFingerprint,
                'client_certificate_fingerprint' => $certificate->fingerprint,
            ]);

            return new CollectorEnrollmentResult(
                collector: $collector->fresh(['collectorDevice.assignments', 'checkpoint']),
                certificate: $certificate,
                centralSigningPublicKey: $centralPublicKey,
            );
        }, 3);
    }

    public function revoke(
        MonitoringCollector $collector,
        int $actorId,
        ?string $reason = null,
    ): MonitoringCollector {
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
            throw new DomainException('Collector revocation reason is invalid.');
        }

        return DB::transaction(function () use ($collector, $actorId, $reason): MonitoringCollector {
            $actor = User::query()->whereKey($actorId)->whereNotNull('approved_at')->lockForUpdate()->first();
            $locked = MonitoringCollector::query()->whereKey($collector->id)->lockForUpdate()->firstOrFail();
            if ($actor === null) {
                throw new DomainException('Collector revocation actor is unavailable.');
            }
            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now(), 'status' => 'revoked'])->save();
                AuditLogger::logOrFail('monitoring.collector.revoked', $locked, [
                    'actor_id' => $actor->id,
                    'site_id' => (int) $locked->site_id,
                    ...($reason === null ? [] : ['reason' => $reason]),
                ]);
            }

            return $locked->fresh();
        }, 3);
    }

    private function centralSigningSecretKey(): string
    {
        $encoded = config('monitoring.collector.signing_secret_key');
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector configuration signing key is unavailable.');
        }

        return $decoded;
    }
}
