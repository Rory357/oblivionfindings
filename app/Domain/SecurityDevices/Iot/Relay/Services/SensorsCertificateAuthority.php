<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\SecurityDevices\Iot\Relay\Models\HubRelayCertificate;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorCertificateAuthority;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * S&D Phase E (E5): the sensors certificate authority. Each hub makes its own relay key and sends
 * only a certificate request; Oblivion signs it for the hub's own house-network addresses, and a
 * sensor gateway that trusts the sensors certificate can then send readings to its hub over TLS on
 * port 8883. The authority's private key is kept encrypted with the app key and never leaves the
 * server.
 */
final class SensorsCertificateAuthority
{
    public const string PURPOSE = 'sensors';

    public const string HUB_FEATURE = 'sensor_relay_tls_v1';

    private const int CA_DAYS = 3650;

    private const int LEAF_DAYS = 397;

    /** A hub asks again when its certificate has fewer days left than this. */
    public const int RENEW_WITHIN_DAYS = 30;

    private const int MAX_ADDRESSES = 8;

    public function __construct(private readonly CidrMatcher $cidrs) {}

    /** The sensors certificate (public), made on first use. */
    public function certificate(): string
    {
        return $this->authority()->certificate_pem;
    }

    /** The sensors certificate if the authority exists yet (it's made when the first hub asks). */
    public function existingCertificate(): ?string
    {
        return SensorCertificateAuthority::query()->where('purpose', self::PURPOSE)->value('certificate_pem');
    }

    /** The sensors certificate's SHA-256 fingerprint, to compare on a gateway's screen. */
    public function existingFingerprint(): ?string
    {
        $pem = $this->existingCertificate();
        $fingerprint = is_string($pem) ? openssl_x509_fingerprint($pem, 'sha256') : false;

        return is_string($fingerprint) ? strtoupper(implode(':', str_split($fingerprint, 2))) : null;
    }

    /** The hub's current relay certificate, if it has one. */
    public function currentFor(MonitoringCollector $hub): ?HubRelayCertificate
    {
        return HubRelayCertificate::query()
            ->where('collector_id', $hub->id)
            ->current()
            ->latest('id')
            ->first();
    }

    /**
     * Sign a hub's relay certificate request. Returns null (and signs nothing) when the request isn't
     * the hub's own, uses a weak key, or names an address outside the hub's Sites' networks.
     *
     * @param  list<mixed>  $addresses
     */
    public function issue(MonitoringCollector $hub, string $csrPem, array $addresses): ?HubRelayCertificate
    {
        if (! $hub->isApprovedForWork() || $hub->revoked_at !== null || strlen($csrPem) > 8192
            || ! str_starts_with(trim($csrPem), '-----BEGIN CERTIFICATE REQUEST-----')) {
            return null;
        }
        $ips = $this->hubAddresses($hub, $addresses);
        if ($ips === null) {
            return null;
        }
        try {
            $subject = openssl_csr_get_subject($csrPem, false);
            $publicKey = openssl_csr_get_public_key($csrPem);
            $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($subject) || ($subject['commonName'] ?? null) !== self::commonName($hub) || count($subject) !== 1
            || ! is_array($details) || ! self::strongKey($details)) {
            return null;
        }
        $keyHash = hash('sha256', (string) $details['key']);
        $current = $this->currentFor($hub);
        if ($current !== null && $current->public_key_sha256 === $keyHash && $current->ip_addresses === $ips
            && $current->expires_at->gt(CarbonImmutable::now()->addDays(self::RENEW_WITHIN_DAYS))) {
            return $current;
        }

        return DB::transaction(function () use ($hub, $csrPem, $ips, $keyHash): HubRelayCertificate {
            $authority = $this->authority(lock: true);
            $serial = $authority->next_serial;
            $authority->forceFill(['next_serial' => $serial + 1])->save();
            $config = $this->opensslConfig($ips, self::commonName($hub));
            try {
                $caKey = openssl_pkey_get_private($authority->private_key_encrypted);
                $signed = $caKey === false ? false : openssl_csr_sign($csrPem, $authority->certificate_pem, $caKey, self::LEAF_DAYS, [
                    'digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_leaf',
                ], $serial);
                if ($signed === false || ! openssl_x509_export($signed, $pem)) {
                    throw new RuntimeException('The sensors certificate authority could not sign the hub’s request.');
                }
            } finally {
                @unlink($config);
            }
            $parsed = openssl_x509_parse($pem) ?: [];
            HubRelayCertificate::query()->where('collector_id', $hub->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $certificate = HubRelayCertificate::query()->create([
                'collector_id' => $hub->id,
                'certificate_pem' => $pem,
                'serial' => (string) $serial,
                'fingerprint_sha256' => (string) openssl_x509_fingerprint($pem, 'sha256'),
                'public_key_sha256' => $keyHash,
                'ip_addresses' => $ips,
                'issued_at' => CarbonImmutable::createFromTimestampUTC((int) ($parsed['validFrom_time_t'] ?? time())),
                'expires_at' => CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? time())),
            ]);
            AuditLogger::logOrFail('security_devices.sensor_relay.certificate_issued', $certificate, [
                'collector_id' => (int) $hub->id,
                'serial' => (string) $serial,
                'ip_addresses' => $ips,
            ]);

            return $certificate;
        });
    }

    public static function commonName(MonitoringCollector $hub): string
    {
        return 'oblivion-relay-'.strtolower((string) $hub->collector_uuid);
    }

    /**
     * The hub's own addresses that are private and inside an active network range of a Site it
     * covers (a hub PC's other adapters, such as a VPN, are left out). Null when none is.
     *
     * @param  list<mixed>  $addresses
     * @return list<string>|null
     */
    private function hubAddresses(MonitoringCollector $hub, array $addresses): ?array
    {
        if ($addresses === [] || count($addresses) > self::MAX_ADDRESSES) {
            return null;
        }
        $ranges = DiscoveryScope::query()
            ->whereIn('site_id', $hub->authorisedSiteIds())
            ->where('status', 'active')
            ->get(['cidrs'])
            ->flatMap(fn (DiscoveryScope $scope): array => array_values(array_filter((array) $scope->cidrs, 'is_string')))
            ->all();
        $out = [];
        foreach ($addresses as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
                || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                continue;
            }
            try {
                $canonical = $this->cidrs->canonicalAddress($address);
                $inside = array_any($ranges, fn (string $range): bool => $this->cidrs->contains($range, $canonical));
            } catch (Throwable) {
                continue;
            }
            if ($inside) {
                $out[] = $canonical;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out === [] ? null : $out;
    }

    /** @param array<string, mixed> $details */
    private static function strongKey(array $details): bool
    {
        return match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_EC => in_array($details['ec']['curve_name'] ?? null, ['prime256v1', 'secp384r1'], true),
            OPENSSL_KEYTYPE_RSA => (int) ($details['bits'] ?? 0) >= 2048,
            default => false,
        };
    }

    private function authority(bool $lock = false): SensorCertificateAuthority
    {
        $query = SensorCertificateAuthority::query()->where('purpose', self::PURPOSE);
        $existing = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($existing instanceof SensorCertificateAuthority) {
            return $existing;
        }

        return DB::transaction(function (): SensorCertificateAuthority {
            $config = $this->opensslConfig([], 'Oblivion sensors');
            try {
                $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
                $csr = $key === false ? false : openssl_csr_new(['commonName' => 'Oblivion sensors'], $key, ['digest_alg' => 'sha256', 'config' => $config]);
                $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, self::CA_DAYS, [
                    'digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_ca',
                ], 1);
                if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! openssl_pkey_export($key, $privatePem, null, ['config' => $config])) {
                    throw new RuntimeException('The sensors certificate authority could not be made.');
                }
            } finally {
                @unlink($config);
            }
            $authority = SensorCertificateAuthority::query()->firstOrCreate(['purpose' => self::PURPOSE], [
                'certificate_pem' => $pem,
                'private_key_encrypted' => $privatePem,
                'next_serial' => 2,
                'expires_at' => CarbonImmutable::now('UTC')->addDays(self::CA_DAYS),
            ]);
            AuditLogger::logOrFail('security_devices.sensor_relay.authority_created', $authority, [
                'fingerprint_sha256' => (string) openssl_x509_fingerprint($authority->certificate_pem, 'sha256'),
            ]);

            return $authority;
        });
    }

    /**
     * A short-lived OpenSSL config with the two certificate profiles (PHP's OpenSSL needs a file).
     *
     * @param  list<string>  $ips
     */
    private function opensslConfig(array $ips, string $commonName): string
    {
        $names = $ips === []
            ? 'DNS:'.preg_replace('/[^a-z0-9.-]/', '', strtolower($commonName))
            : implode(',', array_map(static fn (string $ip): string => 'IP:'.$ip, $ips));
        $path = tempnam(sys_get_temp_dir(), 'oblivion-sensors-ca-');
        if ($path === false || file_put_contents($path, implode("\n", [
            '[req]', 'distinguished_name = dn', 'prompt = no', '[dn]', 'CN = '.$commonName,
            '[v3_ca]', 'basicConstraints = critical,CA:TRUE,pathlen:0', 'keyUsage = critical,keyCertSign,cRLSign', 'subjectKeyIdentifier = hash',
            '[v3_leaf]', 'basicConstraints = critical,CA:FALSE', 'keyUsage = critical,digitalSignature,keyAgreement,keyEncipherment',
            'extendedKeyUsage = serverAuth', 'subjectKeyIdentifier = hash', 'authorityKeyIdentifier = keyid', 'subjectAltName = '.$names, '',
        ])) === false) {
            throw new RuntimeException('The sensors certificate authority could not prepare its settings.');
        }

        return $path;
    }
}
