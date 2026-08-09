<?php

namespace App\Domain\Monitoring\Services;

use App\Support\Monitoring\StrictJsonObjectDecoder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProductionRetentionEndpointAttestation
{
    /**
     * @param  array{mysql_endpoint_sha256: string, influx_scope_sha256: string, influx_tls_certificate_sha256: string}  $observed
     * @return array<string, mixed>
     */
    public function load(
        string $path,
        array $observed,
        string $releaseRevision,
        ?CarbonImmutable $now = null,
        ?string $publicKey = null,
    ): array {
        if (! $this->absolute($path) || is_link($path)) {
            throw new RuntimeException('Production endpoint attestation path is invalid.');
        }
        $resolved = realpath($path);
        $base = realpath(base_path());
        if (! is_string($resolved) || ! is_string($base) || ! is_file($resolved)
            || $this->within($resolved, $base)) {
            throw new RuntimeException('Production endpoint attestation path is invalid.');
        }
        $size = filesize($resolved);
        if (! is_int($size) || $size < 2 || $size > 32_768) {
            throw new RuntimeException('Production endpoint attestation is invalid.');
        }
        try {
            $document = (new StrictJsonObjectDecoder)->decode(
                (string) file_get_contents($resolved),
                16,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Production endpoint attestation is invalid.', previous: $exception);
        }

        return $this->verify($document, $observed, $releaseRevision, $now, $publicKey);
    }

    /**
     * @param  array{mysql_endpoint_sha256: string, influx_scope_sha256: string, influx_tls_certificate_sha256: string}  $observed
     * @return array<string, mixed>
     */
    public function verify(
        mixed $document,
        array $observed,
        string $releaseRevision,
        ?CarbonImmutable $now = null,
        ?string $publicKey = null,
    ): array {
        if (! is_array($document) || array_is_list($document)) {
            throw new RuntimeException('Production endpoint attestation is invalid.');
        }
        $this->exactKeys($document, [
            'schema', 'run_id', 'release_revision', 'valid_from_utc', 'valid_until_utc',
            'mysql_endpoint_sha256', 'influx_scope_sha256', 'influx_tls_certificate_sha256',
            'key_reference', 'signature_base64',
        ]);
        if (($document['schema'] ?? null) !== 'monitoring-production-retention-endpoint-attestation-v1'
            || ! is_string($document['run_id'] ?? null)
            || ! Str::isUuid($document['run_id'])
            || preg_match('/^[a-f0-9]{40}$/', (string) ($document['release_revision'] ?? '')) !== 1
            || ! hash_equals($releaseRevision, (string) $document['release_revision'])) {
            throw new RuntimeException('Production endpoint attestation is invalid.');
        }
        foreach (array_keys($observed) as $key) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($document[$key] ?? '')) !== 1
                || ! hash_equals($observed[$key], (string) $document[$key])) {
                throw new RuntimeException('Production endpoint attestation does not match the live endpoints.');
            }
        }
        $from = $this->utc($document['valid_from_utc'] ?? null);
        $until = $this->utc($document['valid_until_utc'] ?? null);
        $now ??= CarbonImmutable::now('UTC');
        if ($from === null
            || $until === null
            || ! $from->lt($until)
            || $now->lt($from)
            || $now->gt($until)
            || $from->diffInSeconds($until, true) > 86_400) {
            throw new RuntimeException('Production endpoint attestation is outside its approved window.');
        }

        if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Production endpoint attestation public key is unavailable.');
        }
        $expectedReference = 'ATTEST-'.substr(hash('sha256', $publicKey), 0, 32);
        $signature = base64_decode((string) ($document['signature_base64'] ?? ''), true);
        $unsigned = $document;
        unset($unsigned['signature_base64']);
        if (! hash_equals($expectedReference, (string) ($document['key_reference'] ?? ''))
            || ! is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached(
                $signature,
                "oblivion-a05-production-endpoints-v1\n".$this->canonicalJson($unsigned),
                $publicKey,
            )) {
            throw new RuntimeException('Production endpoint attestation signature is invalid.');
        }

        return $document;
    }

    /** @param array<string, mixed> $value */
    public function canonicalJson(array $value): string
    {
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item) && ! array_is_list($item)) {
                $item = json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        unset($item);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new RuntimeException('Production endpoint attestation is invalid.');
        }
    }

    private function utc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');

            return $parsed instanceof CarbonImmutable
                && $parsed->format('Y-m-d\TH:i:s\Z') === $value
                    ? $parsed
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1;
    }

    private function within(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $parent = rtrim(str_replace('\\', '/', $parent), '/').'/';

        return str_starts_with(strtolower($path), strtolower($parent));
    }
}
