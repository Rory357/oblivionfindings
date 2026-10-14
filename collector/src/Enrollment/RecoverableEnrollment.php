<?php

namespace Oblivion\Collector\Enrollment;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Spool\CheckpointFile;
use RuntimeException;

final readonly class RecoverableEnrollment
{
    public const DOMAIN = "OBLIVION-COLLECTOR-ENROL-V2\n";

    /** @param null|Closure(string, string, string, string, string, string): array $transport */
    public function __construct(private ?Closure $transport = null) {}

    /** Caller creates/validates the private state directory, including Windows ACLs. */
    public function enrol(string $identityPath, string $uuid, int $siteId, string $origin, string $pin, string $stateDirectory, string $token, bool $confirmCurrent = false, ?string $expectedServerKey = null): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) !== 1
            || $siteId < 1 || preg_match('/\Aofc_enrol_[A-Za-z0-9_-]{64}\z/', $token) !== 1) {
            throw new RuntimeException('Recoverable collector enrolment parameters are invalid.');
        }
        if ($expectedServerKey !== null) {
            $expected = base64_decode($expectedServerKey, true);
            if (! is_string($expected) || strlen($expected) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || base64_encode($expected) !== $expectedServerKey) {
                throw new RuntimeException('Expected collector authority is invalid.');
            }
        }
        $api = new HttpsCentralApi($origin, $pin);
        $decodedPin = str_starts_with($pin, 'sha256//') ? base64_decode(substr($pin, 8), true) : false;
        if (! is_string($decodedPin) || strlen($decodedPin) !== 32 || base64_encode($decodedPin) !== substr($pin, 8)) {
            throw new RuntimeException('Collector TLS public-key pin is invalid.');
        }
        $root = realpath($stateDirectory);
        if (! is_string($root) || ! is_dir($root) || is_link($stateDirectory)
            || realpath(dirname($identityPath)) !== $root || basename($identityPath) !== 'collector.identity.json') {
            throw new RuntimeException('Prepare a private collector state directory before enrolment.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('Collector state directory must be private to its service account.');
        }
        $identityPath = $root.DIRECTORY_SEPARATOR.'collector.identity.json';
        $lockPath = $root.DIRECTORY_SEPARATOR.'enrolment.lock';
        if (file_exists($lockPath) || is_link($lockPath)) {
            $this->ordinaryFile($lockPath, 128);
            $lock = fopen($lockPath, 'r+b');
        } else {
            $lock = fopen($lockPath, 'x+b');
            if (is_resource($lock)) {
                chmod($lockPath, 0600);
            }
        }
        if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another collector enrolment is running.');
        }

        $secret = null;
        try {
            $binding = [
                'version' => 2, 'collector_id' => $uuid, 'site_id' => $siteId,
                'central_url' => rtrim($origin, '/'), 'tls_public_key_pin' => $pin,
                'enrolment_token_hash' => hash('sha256', $token),
            ];
            if (file_exists($identityPath) || is_link($identityPath)) {
                $identity = $this->readObject($identityPath);
                foreach (array_diff_key($binding, ['version' => true]) as $name => $value) {
                    if (($identity[$name] ?? null) !== $value) {
                        throw new RuntimeException('Existing collector identity requires approved repair or replacement.');
                    }
                }
                $this->assertFuture($identity['enrolment_expires_at'] ?? null);
                $this->validateIdentityFiles($identity, $root, $uuid, $siteId);
                if ($expectedServerKey !== null && ! hash_equals($expectedServerKey, $identity['central_signing_public_key'])) {
                    throw new RuntimeException('Saved collector authority differs from its installation profile.');
                }
                if ($confirmCurrent) {
                    $secret = $this->secret($identity['request_signing_secret_key']);
                    $payload = $this->requestIdentity($api, $origin, $pin, $token, $uuid, $siteId, $secret, $expectedServerKey);
                    // Confirmation authorises the original commit, never replacing it or resetting runtime progress.
                    foreach (['central_signing_public_key', 'client_certificate_fingerprint', 'enrolment_expires_at'] as $field) {
                        if (! hash_equals($identity[$field], $payload[$field])) {
                            throw new RuntimeException('Current collector grant differs from the saved identity.');
                        }
                    }
                    foreach (['client_certificate' => 'client_certificate_file', 'client_private_key' => 'client_private_key_file'] as $field => $file) {
                        if (! hash_equals(file_get_contents($identity[$file]), $payload[$field])) {
                            throw new RuntimeException('Current collector certificate differs from the saved identity.');
                        }
                    }
                }
                // The original commit may have completed just before interruption.
                $this->removePending($root, $binding, $identity['request_signing_secret_key']);

                return;
            }

            $pendingPath = $root.DIRECTORY_SEPARATOR.'enrolment.pending.json';
            if (file_exists($pendingPath) || is_link($pendingPath)) {
                $pending = $this->readObject($pendingPath);
                if (count($pending) !== count($binding) + 1) {
                    throw new RuntimeException('Pending collector enrolment is invalid.');
                }
                foreach ($binding as $name => $value) {
                    if (($pending[$name] ?? null) !== $value) {
                        throw new RuntimeException('Pending enrolment belongs to a different server, Site, identity or token.');
                    }
                }
                $secret = $this->secret($pending['request_signing_secret_key'] ?? null);
            } else {
                foreach (scandir($root) ?: [] as $name) {
                    if (! in_array($name, ['.', '..', 'enrolment.lock'], true)) {
                        throw new RuntimeException('Existing collector state requires approved recovery.');
                    }
                }
                $pair = sodium_crypto_sign_keypair();
                $secret = sodium_crypto_sign_secretkey($pair);
                sodium_memzero($pair);
                $this->writeOnce($pendingPath, $this->json([...$binding, 'request_signing_secret_key' => base64_encode($secret)]));
            }

            $payload = $this->requestIdentity($api, $origin, $pin, $token, $uuid, $siteId, $secret, $expectedServerKey);
            $identity = [
                'collector_id' => $uuid, 'site_id' => $siteId, 'central_url' => rtrim($origin, '/'),
                'tls_public_key_pin' => $pin, 'central_signing_public_key' => $payload['central_signing_public_key'],
                'request_signing_secret_key' => base64_encode($secret), 'state_directory' => $root,
                'client_certificate_file' => $root.DIRECTORY_SEPARATOR.'collector.crt.pem',
                'client_private_key_file' => $root.DIRECTORY_SEPARATOR.'collector.key.pem',
                'client_certificate_fingerprint' => $payload['client_certificate_fingerprint'],
                'enrolment_token_hash' => hash('sha256', $token), 'enrolment_expires_at' => $payload['enrolment_expires_at'],
            ];
            // Each final file is created once, or must already contain the exact
            // accepted bytes from this attempt. Never overwrite another identity.
            $this->writeOnce($identity['client_certificate_file'], $payload['client_certificate']);
            $this->writeOnce($identity['client_private_key_file'], $payload['client_private_key']);
            $checkpointPath = $root.DIRECTORY_SEPARATOR.'checkpoint.json';
            $checkpoint = new CheckpointFile($checkpointPath);
            if (file_exists($checkpointPath) || is_link($checkpointPath)) {
                $this->ordinaryFile($checkpointPath, 131072);
                $current = $checkpoint->read();
                if ($current['config_sequence'] !== 0 || $current['config_hash'] !== null || $current['acknowledged_ids'] !== []
                    || $current['corrupted_frames'] !== 0 || $current['acknowledged_source_sequence'] !== $payload['acknowledged_source_sequence']) {
                    throw new RuntimeException('Collector checkpoint changed during enrolment.');
                }
            } else {
                $checkpoint->merge(['acknowledged_source_sequence' => $payload['acknowledged_source_sequence']]);
            }
            $this->writeOnce($identityPath, $this->json($identity));
            $this->removePending($root, $binding, $identity['request_signing_secret_key']);
        } finally {
            if (is_string($secret)) {
                sodium_memzero($secret);
            }
            sodium_memzero($token);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function requestIdentity(HttpsCentralApi $api, string $origin, string $pin, string $token, string $uuid, int $siteId, string $secret, ?string $expectedServerKey): array
    {
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $proof = sodium_crypto_sign_detached(self::DOMAIN.$uuid."\n".hash('sha256', $token)."\n".base64_encode($public), $secret);
        $response = $this->transport === null
            ? $api->enrolRecoverable($token, $uuid, $public, $proof)
            : ($this->transport)($origin, $pin, $token, $uuid, $public, $proof);
        $payload = $this->open($response, $secret, $uuid);
        $this->validatePayload($payload, $uuid, $siteId);
        if ($expectedServerKey !== null && ! hash_equals($expectedServerKey, $payload['central_signing_public_key'])) {
            throw new RuntimeException('Collector authority differs from its installation profile.');
        }

        return $payload;
    }

    /** Native host must verify Windows directory/file ACLs before this check. */
    public function verifyInstalled(string $identityPath): void
    {
        $identity = $this->readObject($identityPath);
        $root = realpath(dirname($identityPath));
        $uuid = $identity['collector_id'] ?? null;
        $site = $identity['site_id'] ?? null;
        $pin = $identity['tls_public_key_pin'] ?? null;
        if (! is_string($root) || basename($identityPath) !== 'collector.identity.json'
            || ! is_string($uuid) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) !== 1
            || ! is_int($site) || $site < 1 || ! is_string($identity['central_url'] ?? null)
            || ! is_string($pin) || ! str_starts_with($pin, 'sha256//')) {
            throw new RuntimeException('Installed collector identity is invalid.');
        }
        $decodedPin = base64_decode(substr($pin, 8), true);
        if (! is_string($decodedPin) || strlen($decodedPin) !== 32 || base64_encode($decodedPin) !== substr($pin, 8)) {
            throw new RuntimeException('Installed collector TLS pin is invalid.');
        }
        $this->validateIdentityFiles($identity, $root, $uuid, $site);
        $secret = $this->secret($identity['request_signing_secret_key']);
        try {
            new HttpsCentralApi($identity['central_url'], $pin, $secret,
                $identity['client_certificate_file'], $identity['client_private_key_file']);
        } finally {
            sodium_memzero($secret);
        }
        // The setup grant expires after enrolment; it is not a runtime lease.
    }

    private function open(array $response, string $secret, string $uuid): array
    {
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        if (count($response) !== 4 || ($response['version'] ?? null) !== 2 || ($response['collector_id'] ?? null) !== $uuid
            || ($response['recipient_key_fingerprint'] ?? null) !== hash('sha256', $public) || ! is_string($response['sealed_identity'] ?? null)
            || strlen($response['sealed_identity']) > 1_048_576) {
            throw new RuntimeException('Recoverable collector response is invalid.');
        }
        $ciphertext = base64_decode($response['sealed_identity'], true);
        if (! is_string($ciphertext) || strlen($ciphertext) <= SODIUM_CRYPTO_BOX_SEALBYTES || base64_encode($ciphertext) !== $response['sealed_identity']) {
            throw new RuntimeException('Recoverable collector response is invalid.');
        }
        $curveSecret = sodium_crypto_sign_ed25519_sk_to_curve25519($secret);
        $box = sodium_crypto_box_keypair_from_secretkey_and_publickey($curveSecret, sodium_crypto_sign_ed25519_pk_to_curve25519($public));
        try {
            $plaintext = sodium_crypto_box_seal_open($ciphertext, $box);
        } finally {
            sodium_memzero($curveSecret);
            sodium_memzero($box);
        }
        if (! is_string($plaintext) || strlen($plaintext) > 700_000) {
            throw new RuntimeException('Collector response is not bound to this installation.');
        }
        try {
            $payload = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new RuntimeException('Collector identity payload is invalid.');
            }

            return $payload;
        } finally {
            sodium_memzero($plaintext);
        }
    }

    private function validatePayload(array $p, string $uuid, int $siteId): void
    {
        $public = is_string($p['central_signing_public_key'] ?? null) ? base64_decode($p['central_signing_public_key'], true) : false;
        if (count($p) !== 10 || ($p['version'] ?? null) !== 2 || ($p['collector_id'] ?? null) !== $uuid
            || ($p['site_id'] ?? null) !== $siteId || ! is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($public) !== $p['central_signing_public_key'] || ! is_int($p['acknowledged_source_sequence'] ?? null)
            || $p['acknowledged_source_sequence'] < 0 || ! is_string($p['client_certificate_expires_at'] ?? null)) {
            throw new RuntimeException('Collector identity scope or authority is invalid.');
        }
        $this->assertFuture($p['enrolment_expires_at'] ?? null);
        $this->validateCertificate($p['client_certificate'] ?? null, $p['client_private_key'] ?? null, $p['client_certificate_fingerprint'] ?? null, $uuid);
    }

    private function validateCertificate(mixed $pem, mixed $key, mixed $fingerprint, string $uuid): void
    {
        if (! is_string($pem) || ! is_string($key) || strlen($pem) > 262144 || strlen($key) > 262144
            || ! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Collector mTLS identity is invalid.');
        }
        $certificate = openssl_x509_read($pem);
        $private = openssl_pkey_get_private($key);
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate, true);
        $actual = $certificate === false ? false : openssl_x509_fingerprint($certificate, 'sha256');
        if ($certificate === false || $private === false || ! openssl_x509_check_private_key($certificate, $private)
            || ! is_array($parsed) || ! is_string($actual) || ! hash_equals($fingerprint, strtolower($actual))
            || ($parsed['subject']['CN'] ?? null) !== 'oblivion-collector-'.$uuid
            || ! is_int($parsed['validFrom_time_t'] ?? null) || ! is_int($parsed['validTo_time_t'] ?? null)
            || time() < $parsed['validFrom_time_t'] || time() >= $parsed['validTo_time_t']) {
            throw new RuntimeException('Collector mTLS identity is invalid.');
        }
    }

    private function validateIdentityFiles(array $identity, string $root, string $uuid, int $siteId): void
    {
        $central = is_string($identity['central_signing_public_key'] ?? null) ? base64_decode($identity['central_signing_public_key'], true) : false;
        if (count($identity) !== 12 || ! is_string($central) || strlen($central) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($central) !== $identity['central_signing_public_key']
            || ($identity['state_directory'] ?? null) !== $root || ($identity['site_id'] ?? null) !== $siteId
            || ($identity['client_certificate_file'] ?? null) !== $root.DIRECTORY_SEPARATOR.'collector.crt.pem'
            || ($identity['client_private_key_file'] ?? null) !== $root.DIRECTORY_SEPARATOR.'collector.key.pem') {
            throw new RuntimeException('Collector identity paths have changed.');
        }
        $key = $this->secret($identity['request_signing_secret_key'] ?? null);
        sodium_memzero($key);
        $this->validateCertificate(
            $this->readFile($identity['client_certificate_file'], 262144),
            $this->readFile($identity['client_private_key_file'], 262144),
            $identity['client_certificate_fingerprint'] ?? null, $uuid,
        );
    }

    private function assertFuture(mixed $value): void
    {
        if (! is_string($value) || strlen($value) > 40 || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            throw new RuntimeException('Collector enrolment expiry is invalid.');
        }
        $expiry = new DateTimeImmutable($value);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
            throw new RuntimeException('Collector enrolment expiry is invalid.');
        }
        if ($expiry <= new DateTimeImmutable || $expiry > (new DateTimeImmutable)->modify('+1 day 5 minutes')) {
            throw new RuntimeException('Collector enrolment has expired or requires review.');
        }
    }

    private function secret(mixed $value): string
    {
        $secret = is_string($value) ? base64_decode($value, true) : false;
        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || base64_encode($secret) !== $value) {
            throw new RuntimeException('Pending collector signing identity is invalid.');
        }
        $pair = sodium_crypto_sign_seed_keypair(substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
        try {
            if (! hash_equals(sodium_crypto_sign_secretkey($pair), $secret)) {
                throw new RuntimeException('Pending collector signing identity is invalid.');
            }
        } finally {
            sodium_memzero($pair);
        }

        return $secret;
    }

    private function removePending(string $root, array $binding, string $expectedSecret): void
    {
        $path = $root.DIRECTORY_SEPARATOR.'enrolment.pending.json';
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        $pending = $this->readObject($path);
        if (count($pending) !== count($binding) + 1 || ! is_string($pending['request_signing_secret_key'] ?? null)
            || ! hash_equals($expectedSecret, $pending['request_signing_secret_key'])) {
            throw new RuntimeException('Completed enrolment has a different pending identity.');
        }
        foreach ($binding as $name => $value) {
            if (($pending[$name] ?? null) !== $value) {
                throw new RuntimeException('Completed enrolment has a different pending attempt.');
            }
        }
        if (! unlink($path)) {
            throw new RuntimeException('Collector identity saved; pending-key cleanup requires review.');
        }
    }

    private function readObject(string $path): array
    {
        $object = json_decode($this->readFile($path, 16384), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($object) || array_is_list($object)) {
            throw new RuntimeException('Collector enrolment record is invalid.');
        }

        return $object;
    }

    private function ordinaryFile(string $path, int $limit): void
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['nlink'] ?? 1) !== 1
            || $stat['size'] < 0 || $stat['size'] > $limit || is_link($path)
            || (PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Collector private state is unsafe or unreadable.');
        }
    }

    private function readFile(string $path, int $limit): string
    {
        $this->ordinaryFile($path, $limit);
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Collector private state is unreadable.');
        }
        try {
            $bytes = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($bytes) || strlen($bytes) > $limit) {
            throw new RuntimeException('Collector private state is too large.');
        }

        return $bytes;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function writeOnce(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) {
            if (! hash_equals($this->readFile($path, 700000), $bytes)) {
                throw new RuntimeException('Collector enrolment cannot replace existing private state.');
            }

            return;
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('Collector enrolment could not be staged.');
        }
        try {
            chmod($temporary, 0600);
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Collector enrolment could not be saved durably.');
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            @unlink($temporary);
            throw $exception;
        }
        fclose($stream);
        // No-overwrite commit. The staging file and final name share a directory.
        if (! link($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Collector state commit requires recovery; no existing file was replaced.');
        }
        if (! unlink($temporary)) {
            throw new RuntimeException('Collector state committed with an extra link; cleanup requires review.');
        }
    }
}
