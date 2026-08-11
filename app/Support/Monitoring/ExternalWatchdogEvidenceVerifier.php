<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class ExternalWatchdogEvidenceVerifier
{
    private const array CENTRAL_EVIDENCE_KEYS = [
        'authority_reference',
        'authority_sha256',
        'checkout_clean_verified',
        'completed_at',
        'environment_reference_sha256',
        'evidence_class',
        'observation_seconds',
        'protected_authority_verified',
        'release_revision',
        'samples',
        'started_at',
        'state',
        'supervised_programs',
        'verified_sites',
        'watchdog_attestation_public_key_sha256',
    ];

    private const array EVIDENCE_KEYS = [
        'authority_reference',
        'authority_sha256',
        'central_runtime_evidence_sha256',
        'environment_reference_sha256',
        'events',
        'evidence_class',
        'exercise_completed_at',
        'exercise_started_at',
        'provider_receipt_sha256',
        'release_revision',
        'schema_version',
        'watchdog_evidence_reference',
    ];

    private const array EVENT_KEYS = [
        'alarm_raised_at',
        'alarm_recovered_at',
        'delivery_restored_at',
        'kind',
        'observation_reference_sha256',
        'outage_started_at',
        'recovery_started_at',
    ];

    private const array EVENT_KINDS = [
        'scheduler_outage',
        'worker_outage',
        'listener_outage',
        'regional_outage',
    ];

    private const int MAXIMUM_ALARM_SECONDS = 360;

    private const int MAXIMUM_RECOVERY_SECONDS = 1_800;

    /**
     * @param  array<string, int|string>  $authority
     * @return array<string, int|string|bool>
     */
    public function verify(
        string $rawEvidence,
        string $encodedSignature,
        string $encodedPublicKey,
        string $rawCentralRuntimeEvidence,
        string $rawProviderReceipt,
        array $authority,
        ?DateTimeImmutable $now = null,
    ): array {
        try {
            $publicKey = $this->decodeBase64($encodedPublicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            $signature = $this->decodeBase64($encodedSignature, SODIUM_CRYPTO_SIGN_BYTES);
            if (! hash_equals(
                (string) ($authority['watchdog_attestation_public_key_sha256'] ?? ''),
                hash('sha256', $publicKey),
            ) || ! sodium_crypto_sign_verify_detached($signature, $rawEvidence, $publicKey)) {
                $this->refuse();
            }

            $central = (new StrictJsonObjectDecoder)->decode($rawCentralRuntimeEvidence, 16);
            $evidence = (new StrictJsonObjectDecoder)->decode($rawEvidence, 32);
            if (! $this->exactKeys($central, self::CENTRAL_EVIDENCE_KEYS)
                || ! $this->exactKeys($evidence, self::EVIDENCE_KEYS)) {
                $this->refuse();
            }

            $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
            $centralStarted = $this->utc($central['started_at'] ?? null);
            $centralCompleted = $this->utc($central['completed_at'] ?? null);
            $exerciseStarted = $this->utc($evidence['exercise_started_at'] ?? null);
            $exerciseCompleted = $this->utc($evidence['exercise_completed_at'] ?? null);
            $notBefore = $authority['not_before_epoch'] ?? null;
            $notAfter = $authority['not_after_epoch'] ?? null;

            if (! is_int($notBefore)
                || ! is_int($notAfter)
                || $notBefore >= $notAfter
                || $centralStarted === null
                || $centralCompleted === null
                || $exerciseStarted === null
                || $exerciseCompleted === null
                || $centralStarted >= $centralCompleted
                || $centralStarted->getTimestamp() < $notBefore
                || $centralCompleted->getTimestamp() > $notAfter
                || $exerciseStarted < $centralCompleted
                || $exerciseStarted->getTimestamp() < $notBefore
                || $exerciseCompleted->getTimestamp() > $notAfter
                || $exerciseStarted >= $exerciseCompleted
                || $exerciseCompleted > $now->modify('+60 seconds')) {
                $this->refuse();
            }

            $samples = $central['samples'] ?? null;
            $observationSeconds = $central['observation_seconds'] ?? null;
            if (($central['state'] ?? null) !== 'verified'
                || ($central['evidence_class'] ?? null) !== 'monitoring_central_runtime_release_evidence_v1'
                || ($central['checkout_clean_verified'] ?? null) !== true
                || ($central['protected_authority_verified'] ?? null) !== true
                || ! is_int($samples)
                || $samples < 15
                || ! is_int($observationSeconds)
                || $observationSeconds < 840
                || ($centralCompleted->getTimestamp() - $centralStarted->getTimestamp()) < $observationSeconds
                || ($centralCompleted->getTimestamp() - $centralStarted->getTimestamp()) > $observationSeconds + 300
                || ! is_int($central['verified_sites'] ?? null)
                || $central['verified_sites'] < 1
                || ($central['supervised_programs'] ?? null) !== 11) {
                $this->refuse();
            }

            if (($evidence['schema_version'] ?? null) !== 1
                || ($evidence['evidence_class'] ?? null) !== 'monitoring_external_watchdog_release_evidence_v1'
                || ! $this->matches($evidence['watchdog_evidence_reference'] ?? null, '/\AWATCHDOG-[a-f0-9]{32}\z/')
                || ! $this->sha($evidence['provider_receipt_sha256'] ?? null)
                || $rawProviderReceipt === ''
                || strlen($rawProviderReceipt) > 65_536
                || ! hash_equals(
                    (string) $evidence['provider_receipt_sha256'],
                    hash('sha256', $rawProviderReceipt),
                )
                || ! $this->sha($evidence['central_runtime_evidence_sha256'] ?? null)
                || ! hash_equals(
                    (string) $evidence['central_runtime_evidence_sha256'],
                    hash('sha256', $rawCentralRuntimeEvidence),
                )) {
                $this->refuse();
            }

            foreach ([
                'authority_reference',
                'authority_sha256',
                'environment_reference_sha256',
                'release_revision',
            ] as $key) {
                $expected = $authority[$key] ?? null;
                if (! is_string($expected)
                    || ! hash_equals($expected, (string) ($central[$key] ?? ''))
                    || ! hash_equals($expected, (string) ($evidence[$key] ?? ''))) {
                    $this->refuse();
                }
            }
            if (! hash_equals(
                (string) $authority['watchdog_attestation_public_key_sha256'],
                (string) ($central['watchdog_attestation_public_key_sha256'] ?? ''),
            )) {
                $this->refuse();
            }

            $events = $evidence['events'] ?? null;
            if (! is_array($events) || ! array_is_list($events) || count($events) !== count(self::EVENT_KINDS)) {
                $this->refuse();
            }
            $previousRecovered = $exerciseStarted;
            $observationReferences = [];
            foreach (self::EVENT_KINDS as $index => $kind) {
                $event = $events[$index] ?? null;
                if (! is_array($event)
                    || ! $this->exactKeys($event, self::EVENT_KEYS)
                    || ($event['kind'] ?? null) !== $kind
                    || ! $this->sha($event['observation_reference_sha256'] ?? null)) {
                    $this->refuse();
                }
                $observationReference = (string) $event['observation_reference_sha256'];
                if (isset($observationReferences[$observationReference])) {
                    $this->refuse();
                }
                $observationReferences[$observationReference] = true;
                $outageStarted = $this->utc($event['outage_started_at'] ?? null);
                $alarmRaised = $this->utc($event['alarm_raised_at'] ?? null);
                $recoveryStarted = $this->utc($event['recovery_started_at'] ?? null);
                $deliveryRestored = $this->utc($event['delivery_restored_at'] ?? null);
                $alarmRecovered = $this->utc($event['alarm_recovered_at'] ?? null);
                if ($outageStarted === null
                    || $alarmRaised === null
                    || $recoveryStarted === null
                    || $deliveryRestored === null
                    || $alarmRecovered === null
                    || $outageStarted < $previousRecovered
                    || $outageStarted >= $alarmRaised
                    || ($alarmRaised->getTimestamp() - $outageStarted->getTimestamp()) > self::MAXIMUM_ALARM_SECONDS
                    || $alarmRaised > $recoveryStarted
                    || $recoveryStarted >= $deliveryRestored
                    || $deliveryRestored > $alarmRecovered
                    || ($alarmRecovered->getTimestamp() - $recoveryStarted->getTimestamp()) > self::MAXIMUM_RECOVERY_SECONDS
                    || $alarmRecovered > $exerciseCompleted) {
                    $this->refuse();
                }
                $previousRecovered = $alarmRecovered;
            }
            if (isset($observationReferences[(string) $evidence['provider_receipt_sha256']])
                || hash_equals(
                    (string) $evidence['provider_receipt_sha256'],
                    (string) $evidence['central_runtime_evidence_sha256'],
                )) {
                $this->refuse();
            }

            return [
                'status' => 'verified',
                'evidence_class' => 'monitoring_external_watchdog_release_verification_v1',
                'authority_reference' => $authority['authority_reference'],
                'authority_sha256' => $authority['authority_sha256'],
                'environment_reference_sha256' => $authority['environment_reference_sha256'],
                'release_revision' => $authority['release_revision'],
                'central_runtime_evidence_sha256' => hash('sha256', $rawCentralRuntimeEvidence),
                'signed_watchdog_evidence_sha256' => hash('sha256', $rawEvidence),
                'detached_signature_sha256' => hash('sha256', $signature),
                'watchdog_evidence_reference' => $evidence['watchdog_evidence_reference'],
                'provider_receipt_sha256' => $evidence['provider_receipt_sha256'],
                'events_verified' => count(self::EVENT_KINDS),
                'exercise_started_at' => $evidence['exercise_started_at'],
                'exercise_completed_at' => $evidence['exercise_completed_at'],
                'external_watchdog_release_evidence' => true,
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('External watchdog release evidence is invalid.', previous: $exception);
        }
    }

    private function decodeBase64(string $encoded, int $length): string
    {
        $trimmed = trim($encoded);
        $decoded = base64_decode($trimmed, true);
        if (! is_string($decoded)
            || strlen($decoded) !== $length
            || ! hash_equals(rtrim(base64_encode($decoded), '='), rtrim($trimmed, '='))) {
            $this->refuse();
        }

        return $decoded;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
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

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function refuse(): never
    {
        throw new RuntimeException('External watchdog release evidence is invalid.');
    }
}
