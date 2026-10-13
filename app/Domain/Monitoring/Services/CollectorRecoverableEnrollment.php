<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Support\SecurityPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class CollectorRecoverableEnrollment
{
    public const DOMAIN = "OBLIVION-COLLECTOR-ENROL-V2\n";

    public function __construct(
        private CollectorEnrollmentService $enrollments,
        private SecurityDevicesAccessService $access,
        private CanonicalDeviceSiteResolver $sites,
    ) {}

    public static function proofMessage(string $token, string $uuid, string $publicKey): string
    {
        return self::DOMAIN.$uuid."\n".hash('sha256', $token)."\n".base64_encode($publicKey);
    }

    /** Returns the same encrypted response only to the same still-authorised identity. */
    public function enrol(string $token, string $uuid, string $publicKey, string $proof): array
    {
        if (preg_match('/\Aofc_enrol_[A-Za-z0-9_-]{64}\z/', $token) !== 1
            || ! Str::isUuid($uuid) || strtolower($uuid) !== $uuid
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($proof) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($proof, self::proofMessage($token, $uuid, $publicKey), $publicKey)) {
            throw new DomainException('Collector recovery request is invalid.');
        }

        return DB::transaction(function () use ($token, $uuid, $publicKey): array {
            $enrollment = CollectorEnrollment::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($enrollment === null || $enrollment->revoked_at !== null
                || $enrollment->expires_at->lte(CarbonImmutable::now('UTC'))) {
                throw new DomainException('Collector enrolment is unavailable.');
            }
            $this->assertIssuerAndSite($enrollment);
            if ($enrollment->consumed_at !== null) {
                $collector = MonitoringCollector::query()->whereKey($enrollment->consumed_collector_id)->lockForUpdate()->first();
                $this->assertCollector($collector, $enrollment, $uuid, $publicKey);
                if (! is_string($enrollment->recovery_response) || strlen($enrollment->recovery_response) > 1_048_576
                    || ! is_string($enrollment->recovery_identity_fingerprint)
                    || ! hash_equals($enrollment->recovery_identity_fingerprint, $this->identityFingerprint($collector))) {
                    throw new DomainException('Collector enrolment recovery is unavailable.');
                }

                return $this->envelope($uuid, $publicKey, $enrollment->recovery_response);
            }

            // This nested call shares the outer transaction and enrolment lock.
            // Certificate issuance, consumption, audit and sealed recovery commit
            // together; a sealing/storage failure rolls all database changes back.
            $result = $this->enrollments->enrol($token, $uuid, $publicKey);
            $this->assertCollector($result->collector, $enrollment, $uuid, $publicKey);
            $payload = json_encode([
                'version' => 2,
                'collector_id' => $uuid,
                'site_id' => (int) $result->collector->site_id,
                'central_signing_public_key' => base64_encode($result->centralSigningPublicKey),
                'client_certificate' => $result->certificate->certificatePem,
                'client_private_key' => $result->certificate->privateKeyPem,
                'client_certificate_fingerprint' => $result->certificate->fingerprint,
                'client_certificate_expires_at' => $result->certificate->expiresAt->toISOString(),
                'acknowledged_source_sequence' => (int) $result->collector->acknowledged_source_sequence,
                'enrolment_expires_at' => $enrollment->expires_at->toISOString(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            try {
                if (strlen($payload) > 700_000) {
                    throw new DomainException('Collector enrolment response exceeds its limit.');
                }
                $sealed = base64_encode(sodium_crypto_box_seal($payload, sodium_crypto_sign_ed25519_pk_to_curve25519($publicKey)));
            } finally {
                sodium_memzero($payload);
            }
            $enrollment->forceFill([
                'recovery_response' => $sealed,
                'recovery_identity_fingerprint' => $this->identityFingerprint($result->collector),
            ])->save();

            return $this->envelope($uuid, $publicKey, $sealed);
        }, 3);
    }

    private function assertIssuerAndSite(CollectorEnrollment $enrollment): void
    {
        // The enrolment is this operation's mutex. Avoid waiting on inverse
        // live RBAC/Site writers; a contested decision fails closed.
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $enrollment->issued_by_user_id, ['*']);
        if (! $actor->approved_at || ! $actor->email_verified_at || $actor->external_clinical_account
            || ! $actor->canDo('securityDevices.viewAny') || ! $actor->canDo('securityDevices.integrations.manage')
            || (SecurityPolicy::forceTwoFactor() && ! $actor->two_factor_confirmed_at)) {
            throw new DomainException('Collector enrolment issuer is unavailable.');
        }
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            if ($actor->role === $role || $actor->hasRole($role)) {
                throw new DomainException('Collector enrolment issuer is unavailable.');
            }
        }
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($enrollment, $actor): void {
            $site = $reads->query(Site::query())->whereKey($enrollment->site_id)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))->first();
            if ($site === null) {
                throw new DomainException('Collector enrolment Site is unavailable.');
            }
            $this->access->forCurrentEvidence($reads, 0)->assertCanViewSite($actor, (int) $site->id);
        });
    }

    private function assertCollector(?MonitoringCollector $collector, CollectorEnrollment $enrollment, string $uuid, string $publicKey): void
    {
        if ($collector === null || $collector->revoked_at !== null || $collector->collector_uuid !== $uuid
            || (int) $collector->site_id !== (int) $enrollment->site_id
            || ! is_string($collector->public_key_fingerprint)
            || ! hash_equals($collector->public_key_fingerprint, hash('sha256', $publicKey))
            || $collector->public_key !== base64_encode($publicKey) || $collector->collector_device_id === null) {
            throw new DomainException('Collector recovery identity is unavailable.');
        }
        try {
            $siteId = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads): int => $this->sites->forCurrentEvidence($reads)->resolve((int) $collector->collector_device_id));
        } catch (Throwable $exception) {
            throw new DomainException('Collector recovery assignment is unavailable.', previous: $exception);
        }
        if ($siteId !== (int) $enrollment->site_id) {
            throw new DomainException('Collector recovery assignment is unavailable.');
        }
    }

    private function identityFingerprint(MonitoringCollector $collector): string
    {
        $encoded = config('monitoring.collector.signing_secret_key');
        $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new DomainException('Collector configuration authority is unavailable.');
        }
        try {
            $central = sodium_crypto_sign_publickey_from_secretkey($secret);
        } finally {
            sodium_memzero($secret);
        }

        return hash('sha256', json_encode([
            $collector->collector_uuid, (int) $collector->site_id, (int) $collector->collector_device_id,
            $collector->public_key_fingerprint, $collector->client_certificate_fingerprint, base64_encode($central),
        ], JSON_THROW_ON_ERROR));
    }

    private function envelope(string $uuid, string $publicKey, string $sealed): array
    {
        return [
            'version' => 2, 'collector_id' => $uuid,
            'recipient_key_fingerprint' => hash('sha256', $publicKey), 'sealed_identity' => $sealed,
        ];
    }
}
