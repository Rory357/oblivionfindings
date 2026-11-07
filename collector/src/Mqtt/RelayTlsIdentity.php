<?php

namespace Oblivion\Collector\Mqtt;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * S&D Phase E (E5): the hub's key and certificate for its encrypted sensor relay (TLS on 8883).
 * The hub makes its own key, which never leaves it, and sends Oblivion only a certificate request
 * naming its house-network addresses. Oblivion's sensors authority signs it; a gateway that trusts
 * the sensors certificate can then check it's really talking to this hub.
 */
final readonly class RelayTlsIdentity
{
    public const string KEY_FILE = 'relay-tls-key.pem';

    public const string CERTIFICATE_FILE = 'relay-tls-cert.pem';

    private const string ASKED_FILE = 'relay-tls-requested.json';

    private const int ASK_EVERY_SECONDS = 86_400;

    private const int RENEW_WITHIN_SECONDS = 30 * 86_400;

    public function __construct(private string $stateDirectory, private string $collectorId) {}

    public function commonName(): string
    {
        return 'oblivion-relay-'.strtolower($this->collectorId);
    }

    /** The private key file, made (EC P-256) the first time. */
    public function keyFile(): string
    {
        $path = $this->path(self::KEY_FILE);
        if (is_file($path)) {
            return $path;
        }
        $config = self::opensslConfig();
        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
            if ($key === false || ! openssl_pkey_export($key, $pem, null, ['config' => $config])) {
                throw new RuntimeException('The hub could not make its relay key.');
            }
        } finally {
            @unlink($config);
        }
        self::writePrivate($path, $pem);
        sodium_memzero($pem);

        return $path;
    }

    /**
     * Keep the certificate Oblivion signed, with the sensors certificate after it, but only when it
     * is for this hub's own key and signed by that sensors certificate.
     */
    public function install(string $certificate, string $caCertificate): bool
    {
        try {
            $key = openssl_pkey_get_private((string) file_get_contents($this->keyFile()));
            $caKey = openssl_pkey_get_public($caCertificate);
            if ($key === false || $caKey === false || ! openssl_x509_check_private_key($certificate, $key)
                || openssl_x509_verify($certificate, $caKey) !== 1) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        $chain = rtrim($certificate)."\n".rtrim($caCertificate)."\n";
        $path = $this->path(self::CERTIFICATE_FILE);
        if (! is_file($path) || file_get_contents($path) !== $chain) {
            self::writePrivate($path, $chain);
        }

        return true;
    }

    /** The certificate file, when there is one for this hub's key that hasn't expired. */
    public function certificateFile(?DateTimeImmutable $now = null): ?string
    {
        $path = $this->path(self::CERTIFICATE_FILE);
        $parsed = is_file($path) ? @openssl_x509_parse((string) file_get_contents($path)) : false;
        $now ??= new DateTimeImmutable('now');

        return is_array($parsed) && (int) ($parsed['validTo_time_t'] ?? 0) > $now->getTimestamp() ? $path : null;
    }

    /**
     * The certificate request to send, at most once a day, while the hub has no certificate for its
     * key or it expires within 30 days. Null when there is nothing to ask.
     *
     * @param  list<string>  $addresses
     * @return array{item_type: string, csr_pem: string, ip_addresses: list<string>, requested_at: string}|null
     */
    public function request(array $addresses, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable('now');
        if ($addresses === []) {
            return null;
        }
        $current = $this->certificateFile($now);
        if ($current !== null) {
            $parsed = openssl_x509_parse((string) file_get_contents($current)) ?: [];
            $key = openssl_pkey_get_private((string) file_get_contents($this->keyFile()));
            $sans = (string) ($parsed['extensions']['subjectAltName'] ?? '');
            $coversAll = array_all($addresses, static fn (string $ip): bool => str_contains($sans.',', 'IP Address:'.$ip.','));
            if ($key !== false && openssl_x509_check_private_key((string) file_get_contents($current), $key) && $coversAll
                && (int) ($parsed['validTo_time_t'] ?? 0) > $now->getTimestamp() + self::RENEW_WITHIN_SECONDS) {
                return null;
            }
        }
        $asked = is_file($this->path(self::ASKED_FILE)) ? json_decode((string) file_get_contents($this->path(self::ASKED_FILE)), true) : null;
        if (is_array($asked) && is_int($asked['at'] ?? null) && $asked['at'] > $now->getTimestamp() - self::ASK_EVERY_SECONDS) {
            return null;
        }
        $config = self::opensslConfig();
        try {
            $key = openssl_pkey_get_private((string) file_get_contents($this->keyFile()));
            $csr = $key === false ? false : openssl_csr_new(['commonName' => $this->commonName()], $key, ['digest_alg' => 'sha256', 'config' => $config]);
            if ($csr === false || ! openssl_csr_export($csr, $pem)) {
                return null;
            }
        } finally {
            @unlink($config);
        }
        self::writePrivate($this->path(self::ASKED_FILE), json_encode(['at' => $now->getTimestamp()], JSON_THROW_ON_ERROR));

        return [
            'item_type' => 'relay_certificate_request',
            'csr_pem' => $pem,
            'ip_addresses' => array_values($addresses),
            'requested_at' => $now->format(DATE_ATOM),
        ];
    }

    /** Remove the relay key and certificate (the hub was removed). */
    public function forget(): void
    {
        foreach ([self::KEY_FILE, self::CERTIFICATE_FILE, self::ASKED_FILE] as $file) {
            if (is_file($this->path($file))) {
                @unlink($this->path($file));
            }
        }
    }

    /**
     * This machine's private IPv4 addresses on the house network (no loopback or link-local), at
     * most 8. Oblivion signs only those inside the hub's Sites' networks.
     *
     * @return list<string>
     */
    public static function localAddresses(): array
    {
        $found = [];
        foreach ((function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : []) as $interface) {
            foreach ((array) ($interface['unicast'] ?? []) as $unicast) {
                $address = is_array($unicast) ? ($unicast['address'] ?? null) : null;
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
                    && ! str_starts_with($address, '169.254.') && ! str_starts_with($address, '127.')) {
                    $found[$address] = true;
                }
            }
        }
        $addresses = array_keys($found);
        sort($addresses);

        return array_slice($addresses, 0, 8);
    }

    private function path(string $file): string
    {
        return $this->stateDirectory.DIRECTORY_SEPARATOR.$file;
    }

    private static function writePrivate(string $path, string $contents): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('The hub could not write its relay certificate files.');
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The hub could not write its relay certificate files.');
        }
    }

    /** PHP's OpenSSL needs a settings file to make keys and requests; the hub's bundled PHP may have none. */
    private static function opensslConfig(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oblivion-relay-tls-');
        if ($path === false || file_put_contents($path, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = oblivion-relay\n") === false) {
            throw new RuntimeException('The hub could not prepare its relay key settings.');
        }

        return $path;
    }
}
