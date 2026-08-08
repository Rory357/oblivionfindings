<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final readonly class CollectorTransportAuthenticator
{
    public function __construct(private CidrMatcher $cidrs) {}

    public function authenticate(Request $request): MonitoringCollector
    {
        $this->assertTrustedProxy((string) $request->server('REMOTE_ADDR'));
        $collectorUuid = $request->input('collector_id');
        $collector = is_string($collectorUuid)
            ? MonitoringCollector::query()->where('collector_uuid', $collectorUuid)->first()
            : null;
        if ($collector === null || $collector->revoked_at !== null || $collector->status === 'revoked') {
            throw new DomainException('collector_authentication_failed');
        }
        $certificate = $this->verifiedCertificateFingerprint($request);
        if (! is_string($collector->client_certificate_fingerprint)
            || ! is_string($certificate)
            || ! hash_equals(strtolower($collector->client_certificate_fingerprint), $certificate)) {
            throw new DomainException('collector_authentication_failed');
        }
        $timestamp = (string) $request->header('X-Oblivion-Collector-Timestamp');
        $nonce = (string) $request->header('X-Oblivion-Collector-Nonce');
        $encodedSignature = (string) $request->header('X-Oblivion-Collector-Signature');
        if (preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $nonce) !== 1) {
            throw new DomainException('collector_authentication_failed');
        }
        try {
            $sentAt = CarbonImmutable::parse($timestamp)->utc();
        } catch (\Throwable) {
            throw new DomainException('collector_authentication_failed');
        }
        $skew = max(30, min(900, (int) config('monitoring.collector.request_clock_skew_seconds', 300)));
        if (abs(CarbonImmutable::now('UTC')->diffInSeconds($sentAt, false)) > $skew) {
            throw new DomainException('collector_authentication_failed');
        }
        $publicKey = is_string($collector->public_key) ? base64_decode($collector->public_key, true) : false;
        $signature = base64_decode($encodedSignature, true);
        if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new DomainException('collector_authentication_failed');
        }
        $material = strtoupper($request->method())."\n"
            .$request->getPathInfo()."\n"
            .$timestamp."\n"
            .$nonce."\n"
            .hash('sha256', $request->getContent());
        if (! sodium_crypto_sign_verify_detached($signature, $material, $publicKey)) {
            throw new DomainException('collector_authentication_failed');
        }
        $store = (string) config('monitoring.collector.replay_store', 'redis');
        $driver = config("cache.stores.{$store}.driver");
        $allowLocalTestStore = app()->environment('testing')
            && $store === 'array'
            && $driver === 'array'
            && (bool) config('monitoring.collector.allow_local_replay_store_for_tests', false);
        if ($driver !== 'redis' && ! $allowLocalTestStore) {
            throw new DomainException('collector_authentication_failed');
        }
        $replayKey = 'monitoring:collector:request:'.hash('sha256', $collector->id.':'.$nonce);
        if (! Cache::store($store)->add($replayKey, true, $skew * 2)) {
            throw new DomainException('collector_authentication_failed');
        }

        return $collector;
    }

    private function assertTrustedProxy(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new DomainException('collector_authentication_failed');
        }
        $trusted = config('monitoring.collector.trusted_proxy_cidrs', []);
        if (! is_array($trusted)) {
            throw new DomainException('collector_authentication_failed');
        }
        foreach ($trusted as $cidr) {
            try {
                if (is_string($cidr) && $this->cidrs->contains($cidr, $address)) {
                    return;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw new DomainException('collector_authentication_failed');
    }

    private function verifiedCertificateFingerprint(Request $request): ?string
    {
        $pemHeader = (string) config(
            'monitoring.collector.certificate_pem_header',
            'X-Oblivion-Verified-Client-Certificate',
        );
        $encodedPem = trim((string) $request->header($pemHeader));
        if ($encodedPem !== '') {
            if (strlen($encodedPem) > 262_144) {
                return null;
            }

            $pem = rawurldecode($encodedPem);
            if (strlen($pem) > 262_144
                || ! str_starts_with($pem, '-----BEGIN CERTIFICATE-----')
                || ! str_ends_with(trim($pem), '-----END CERTIFICATE-----')) {
                return null;
            }

            $certificate = openssl_x509_read($pem);
            $fingerprint = $certificate === false
                ? false
                : openssl_x509_fingerprint($certificate, 'sha256');

            return is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/i', $fingerprint) === 1
                ? strtolower($fingerprint)
                : null;
        }

        if (! config('monitoring.collector.allow_proxy_fingerprint_header', false)) {
            return null;
        }

        $fingerprintHeader = (string) config(
            'monitoring.collector.certificate_header',
            'X-Oblivion-Client-Certificate-Fingerprint',
        );
        $fingerprint = strtolower(trim((string) $request->header($fingerprintHeader)));

        return preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) === 1 ? $fingerprint : null;
    }
}
