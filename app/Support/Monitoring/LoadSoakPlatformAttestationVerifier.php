<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class LoadSoakPlatformAttestationVerifier
{
    private const array CLAIM_KEYS = [
        'schema_version',
        'evidence_class',
        'source_sha256',
        'run_id',
        'release_revision',
        'environment_fingerprint',
        'runtime_class',
        'load_profile_sha256',
        'measurement_contract_sha256',
        'supervisor_observation_generation',
        'issued_at',
        'expires_at',
    ];

    /**
     * @param  array<string, mixed>  $attestation
     * @param  array<string, mixed>  $evidence
     * @return array{valid: bool, public_key_sha256: ?string}
     */
    public function verify(
        array $attestation,
        string $sourceSha256,
        array $evidence,
        string $publicKeyBase64,
        string $expectedPublicKeySha256,
        DateTimeImmutable $verifiedAt,
    ): array {
        $publicKey = base64_decode(trim($publicKeyBase64), true);
        $publicKeyHash = is_string($publicKey) ? hash('sha256', $publicKey) : null;
        if (! function_exists('sodium_crypto_sign_verify_detached')
            || ! is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedPublicKeySha256) !== 1
            || ! is_string($publicKeyHash)
            || ! hash_equals($expectedPublicKeySha256, $publicKeyHash)
            || ! $this->hasExactKeys($attestation, [...self::CLAIM_KEYS, 'signature_base64'])) {
            return ['valid' => false, 'public_key_sha256' => $publicKeyHash];
        }

        $signatureValue = $attestation['signature_base64'] ?? null;
        $signature = is_string($signatureValue)
            ? base64_decode($signatureValue, true)
            : false;
        $issuedAt = $this->utc($attestation['issued_at'] ?? null);
        $expiresAt = $this->utc($attestation['expires_at'] ?? null);
        $createdAt = $this->utc($evidence['created_at'] ?? null);
        $generation = $evidence['runtime_roster']['supervisor_observation_generation'] ?? null;
        $matches = ($attestation['schema_version'] ?? null) === 1
            && ($attestation['evidence_class'] ?? null) === 'monitoring_load_soak_platform_attestation_v1'
            && ($attestation['source_sha256'] ?? null) === $sourceSha256
            && ($attestation['run_id'] ?? null) === ($evidence['run_id'] ?? null)
            && ($attestation['release_revision'] ?? null) === ($evidence['release_revision'] ?? null)
            && ($attestation['environment_fingerprint'] ?? null) === ($evidence['environment_fingerprint'] ?? null)
            && ($attestation['runtime_class'] ?? null) === ($evidence['runtime_class'] ?? null)
            && ($attestation['load_profile_sha256'] ?? null) === ($evidence['load_profile']['profile_sha256'] ?? null)
            && ($attestation['measurement_contract_sha256'] ?? null) === ($evidence['measurement_contract']['contract_sha256'] ?? null)
            && ($attestation['supervisor_observation_generation'] ?? null) === $generation;
        $verifiedAt = $verifiedAt->setTimezone(new DateTimeZone('UTC'));
        $chronology = $issuedAt !== null
            && $expiresAt !== null
            && $createdAt !== null
            && $issuedAt >= $createdAt
            && $issuedAt <= $verifiedAt->modify('+60 seconds')
            && $expiresAt >= $issuedAt
            && $verifiedAt <= $expiresAt;

        if (! $matches || ! $chronology
            || ! is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['valid' => false, 'public_key_sha256' => $publicKeyHash];
        }

        $claims = $attestation;
        unset($claims['signature_base64']);
        try {
            $valid = sodium_crypto_sign_verify_detached(
                $signature,
                self::message($claims),
                $publicKey,
            );
        } catch (Throwable) {
            $valid = false;
        }

        return ['valid' => $valid, 'public_key_sha256' => $publicKeyHash];
    }

    /** @param array<string, mixed> $claims */
    public static function message(array $claims): string
    {
        $ordered = [];
        foreach (self::CLAIM_KEYS as $key) {
            $ordered[$key] = $claims[$key] ?? null;
        }

        try {
            return json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return '';
        }
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function utc(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }
        try {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                $value,
                new DateTimeZone('UTC'),
            );
            $errors = DateTimeImmutable::getLastErrors();

            return $parsed instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format('Y-m-d\TH:i:s\Z') === $value
                    ? $parsed
                    : null;
        } catch (Throwable) {
            return null;
        }
    }
}
