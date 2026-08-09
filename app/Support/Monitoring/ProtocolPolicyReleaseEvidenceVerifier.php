<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class ProtocolPolicyReleaseEvidenceVerifier
{
    private const array PROTOCOLS = [
        'dns',
        'flow',
        'http',
        'https',
        'icmp',
        'provider_milesight',
        'provider_unifi',
        'snmp_traps',
        'snmp_v3',
        'ssh_read_only',
        'syslog',
        'tcp',
        'tls',
        'winrm_read_only',
    ];

    private const array POLICIES = [
        'baselines',
        'confirmation',
        'coverage',
        'dependencies',
        'hysteresis',
        'maintenance',
        'profiles',
        'rollups',
        'stale_unknown',
    ];

    private const array TRANSITION_DRILLS = [
        'baselines',
        'confirmation',
        'dependencies',
        'hysteresis',
        'maintenance',
        'stale_unknown',
    ];

    private const array S10_KEYS = [
        'artifact_id',
        'authority_reference',
        'authority_sha256',
        'created_at',
        'environment_reference_sha256',
        'evidence_class',
        'output_storage_semantics',
        'protocol_policy_evidence',
        'provider_api_contracts',
        'queclink_native_listener_evidence',
        'queclink_transport',
        'release_provenance_verified',
        'release_revision',
        'runtime_environment_sha256',
        's10_release_evidence',
        'schema_version',
        'verification_artifact_contains_targets_credentials_or_payloads',
        'worm_receipt_verified',
    ];

    private const array SUSTAINED_KEYS = [
        'completed_at',
        'observation_seconds',
        'samples',
        'sha256',
        'started_at',
        'window_minutes',
    ];

    private const array QUECLINK_KEYS = [
        'canonical_paired_trackers',
        'completed_at',
        'fresh_trackers_observed',
        'max_frame_age_seconds',
        'observation_seconds',
        'samples',
        'sha256',
        'started_at',
    ];

    private const array EVIDENCE_KEYS = [
        'authority_reference',
        'authority_sha256',
        'environment_reference_sha256',
        'evidence_class',
        'evidence_reference',
        'exercise_completed_at',
        'exercise_started_at',
        'no_targets_credentials_payloads_retained',
        'operator_signoff_reference_sha256',
        'policy_attestations',
        'protocol_attestations',
        'provider_audit_reference_sha256',
        'release_revision',
        's10_release_evidence_sha256',
        'schema_version',
        'supervision_reference_sha256',
        'target_side_logs_reference_sha256',
        'transition_drills',
    ];

    /** @param array<string, int|string> $authority @return array<string, bool|int|string> */
    public function verify(
        string $rawS10Evidence,
        string $rawEvidence,
        string $encodedSignature,
        string $encodedPublicKey,
        array $authority,
        ?DateTimeImmutable $now = null,
    ): array {
        try {
            $this->authorityIsValid($authority);
            $publicKey = $this->decodeBase64($encodedPublicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            $signature = $this->decodeBase64($encodedSignature, SODIUM_CRYPTO_SIGN_BYTES);
            if (! hash_equals((string) $authority['attestation_public_key_sha256'], hash('sha256', $publicKey))
                || ! sodium_crypto_sign_verify_detached($signature, $rawEvidence, $publicKey)) {
                $this->refuse();
            }

            $decoder = new StrictJsonObjectDecoder;
            $s10 = $decoder->decode($rawS10Evidence, 32);
            $evidence = $decoder->decode($rawEvidence, 32);
            if (! $this->exactKeys($s10, self::S10_KEYS)
                || ! $this->exactKeys($evidence, self::EVIDENCE_KEYS)
                || ! is_array($s10['protocol_policy_evidence'] ?? null)
                || array_is_list($s10['protocol_policy_evidence'])
                || ! is_array($s10['queclink_native_listener_evidence'] ?? null)
                || array_is_list($s10['queclink_native_listener_evidence'])
                || ! is_array($evidence['protocol_attestations'] ?? null)
                || ! array_is_list($evidence['protocol_attestations'])
                || ! is_array($evidence['policy_attestations'] ?? null)
                || ! array_is_list($evidence['policy_attestations'])
                || ! is_array($evidence['transition_drills'] ?? null)
                || ! array_is_list($evidence['transition_drills'])) {
                $this->refuse();
            }

            $sustained = $s10['protocol_policy_evidence'];
            $queclink = $s10['queclink_native_listener_evidence'];
            $sustainedStarted = $this->utc($sustained['started_at'] ?? null);
            $sustainedCompleted = $this->utc($sustained['completed_at'] ?? null);
            $queclinkStarted = $this->utc($queclink['started_at'] ?? null);
            $queclinkCompleted = $this->utc($queclink['completed_at'] ?? null);
            $s10Created = $this->utcFlexible($s10['created_at'] ?? null);
            if (($s10['schema_version'] ?? null) !== 1
                || ($s10['evidence_class'] ?? null) !== 'security_devices_s10_release_evidence_v1'
                || ! $this->matches($s10['artifact_id'] ?? null, '/\A[a-f0-9]{32}\z/')
                || ! $this->matches($s10['authority_reference'] ?? null, '/\AAUTHORITY-[a-f0-9]{32}\z/')
                || ! $this->sha($s10['authority_sha256'] ?? null)
                || ! $this->linked($s10, $authority, 'environment_reference_sha256')
                || ! $this->linked($s10, $authority, 'release_revision')
                || ! $this->sha($s10['runtime_environment_sha256'] ?? null)
                || ($s10['provider_api_contracts'] ?? null) !== ['unifi', 'milesight']
                || ($s10['queclink_transport'] ?? null) !== 'native_tcp'
                || ($s10['release_provenance_verified'] ?? null) !== true
                || ($s10['s10_release_evidence'] ?? null) !== true
                || ($s10['verification_artifact_contains_targets_credentials_or_payloads'] ?? null) !== false
                || ($s10['output_storage_semantics'] ?? null) !== 'collision_safe_exclusive_create'
                || ($s10['worm_receipt_verified'] ?? null) !== false
                || ! $this->exactKeys($sustained, self::SUSTAINED_KEYS)
                || ! $this->sha($sustained['sha256'] ?? null)
                || ! is_int($sustained['samples'] ?? null)
                || $sustained['samples'] < 15
                || ($sustained['observation_seconds'] ?? null) !== (($sustained['samples'] - 1) * 60)
                || ($sustained['window_minutes'] ?? null) !== 60
                || $sustainedStarted === null
                || $sustainedCompleted === null
                || $sustainedStarted > $sustainedCompleted
                || $sustainedCompleted->getTimestamp() - $sustainedStarted->getTimestamp() < $sustained['observation_seconds']
                || ! $this->exactKeys($queclink, self::QUECLINK_KEYS)
                || ! $this->sha($queclink['sha256'] ?? null)
                || ! is_int($queclink['samples'] ?? null)
                || $queclink['samples'] < 5
                || ($queclink['observation_seconds'] ?? null) !== (($queclink['samples'] - 1) * 60)
                || ! is_int($queclink['max_frame_age_seconds'] ?? null)
                || $queclink['max_frame_age_seconds'] < 60
                || $queclink['max_frame_age_seconds'] > 900
                || ! is_int($queclink['canonical_paired_trackers'] ?? null)
                || $queclink['canonical_paired_trackers'] < 1
                || ($queclink['fresh_trackers_observed'] ?? null) !== $queclink['canonical_paired_trackers']
                || $queclinkStarted === null
                || $queclinkCompleted === null
                || $queclinkStarted > $queclinkCompleted
                || $queclinkCompleted->getTimestamp() - $queclinkStarted->getTimestamp() < $queclink['observation_seconds']
                || $s10Created === null
                || $s10Created < $sustainedCompleted
                || $s10Created < $queclinkCompleted) {
                $this->refuse();
            }

            $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
            $notBefore = (new DateTimeImmutable('@'.(int) $authority['not_before_epoch']))
                ->setTimezone(new DateTimeZone('UTC'));
            $notAfter = (new DateTimeImmutable('@'.(int) $authority['not_after_epoch']))
                ->setTimezone(new DateTimeZone('UTC'));
            $exerciseStarted = $this->utc($evidence['exercise_started_at'] ?? null);
            $exerciseCompleted = $this->utc($evidence['exercise_completed_at'] ?? null);
            if (($evidence['schema_version'] ?? null) !== 1
                || ($evidence['evidence_class'] ?? null) !== 'monitoring_protocol_policy_release_evidence_v1'
                || ! $this->matches($evidence['evidence_reference'] ?? null, '/\APROTOCOL-[a-f0-9]{32}\z/')
                || ! $this->linked($evidence, $authority, 'authority_reference')
                || ! $this->linked($evidence, $authority, 'authority_sha256')
                || ! $this->linked($evidence, $authority, 'environment_reference_sha256')
                || ! $this->linked($evidence, $authority, 'release_revision')
                || ! $this->rawHashMatches($evidence['s10_release_evidence_sha256'] ?? null, $rawS10Evidence)
                || ! $this->sha($evidence['supervision_reference_sha256'] ?? null)
                || ! $this->sha($evidence['provider_audit_reference_sha256'] ?? null)
                || ! $this->sha($evidence['target_side_logs_reference_sha256'] ?? null)
                || ! $this->sha($evidence['operator_signoff_reference_sha256'] ?? null)
                || ($evidence['no_targets_credentials_payloads_retained'] ?? null) !== true
                || $exerciseStarted === null
                || $exerciseCompleted === null
                || $exerciseStarted < $notBefore
                || $exerciseStarted > $sustainedStarted
                || $sustainedCompleted > $s10Created
                || $s10Created > $exerciseCompleted
                || $exerciseCompleted > $notAfter
                || $exerciseCompleted > $now->modify('+60 seconds')) {
                $this->refuse();
            }

            $earliestEvidence = $sustainedStarted->modify('-60 minutes');
            $this->protocolAttestations($evidence['protocol_attestations'], $earliestEvidence, $sustainedCompleted);
            $this->policyAttestations($evidence['policy_attestations'], $earliestEvidence, $sustainedCompleted);
            $this->transitionDrills($evidence['transition_drills'], $earliestEvidence, $sustainedCompleted);

            return [
                'status' => 'verified',
                'evidence_class' => 'monitoring_protocol_policy_release_verification_v1',
                'authority_reference' => $authority['authority_reference'],
                'authority_sha256' => $authority['authority_sha256'],
                'environment_reference_sha256' => $authority['environment_reference_sha256'],
                'release_revision' => $authority['release_revision'],
                's10_release_evidence_sha256' => $evidence['s10_release_evidence_sha256'],
                'evidence_reference' => $evidence['evidence_reference'],
                'protocols_verified' => count(self::PROTOCOLS),
                'policies_verified' => count(self::POLICIES),
                'transition_drills_verified' => count(self::TRANSITION_DRILLS),
                'sustained_samples_verified' => $sustained['samples'],
                'exercise_started_at' => $evidence['exercise_started_at'],
                'exercise_completed_at' => $evidence['exercise_completed_at'],
                'a07_release_evidence' => true,
                'a08_release_evidence' => true,
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('Protocol-policy release evidence is invalid.', previous: $exception);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function protocolAttestations(array $rows, DateTimeImmutable $earliest, DateTimeImmutable $latest): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || array_is_list($row)
                || ! $this->exactKeys($row, ['name', 'observed_at', 'runtime_reference_sha256', 'state', 'target_side_reference_sha256'])) {
                $this->refuse();
            }
            $name = $row['name'] ?? null;
            $observedAt = $this->utc($row['observed_at'] ?? null);
            if (! is_string($name)
                || ! in_array($name, self::PROTOCOLS, true)
                || isset($seen[$name])
                || ($row['state'] ?? null) !== 'verified'
                || ! $this->sha($row['runtime_reference_sha256'] ?? null)
                || ! $this->sha($row['target_side_reference_sha256'] ?? null)
                || $observedAt === null
                || $observedAt < $earliest
                || $observedAt > $latest) {
                $this->refuse();
            }
            $seen[$name] = true;
        }
        $names = array_keys($seen);
        sort($names, SORT_STRING);
        if ($names !== self::PROTOCOLS) {
            $this->refuse();
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function policyAttestations(array $rows, DateTimeImmutable $earliest, DateTimeImmutable $latest): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || array_is_list($row)
                || ! $this->exactKeys($row, ['evidence_reference_sha256', 'name', 'verified_at'])) {
                $this->refuse();
            }
            $name = $row['name'] ?? null;
            $verifiedAt = $this->utc($row['verified_at'] ?? null);
            if (! is_string($name)
                || ! in_array($name, self::POLICIES, true)
                || isset($seen[$name])
                || ! $this->sha($row['evidence_reference_sha256'] ?? null)
                || $verifiedAt === null
                || $verifiedAt < $earliest
                || $verifiedAt > $latest) {
                $this->refuse();
            }
            $seen[$name] = true;
        }
        $names = array_keys($seen);
        sort($names, SORT_STRING);
        if ($names !== self::POLICIES) {
            $this->refuse();
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function transitionDrills(array $rows, DateTimeImmutable $earliest, DateTimeImmutable $latest): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || array_is_list($row)
                || ! $this->exactKeys($row, [
                    'after_reference_sha256',
                    'before_reference_sha256',
                    'during_at',
                    'during_reference_sha256',
                    'name',
                    'notification_storm_count',
                    'recovered_at',
                    'started_at',
                    'ticket_storm_count',
                ])) {
                $this->refuse();
            }
            $name = $row['name'] ?? null;
            $started = $this->utc($row['started_at'] ?? null);
            $during = $this->utc($row['during_at'] ?? null);
            $recovered = $this->utc($row['recovered_at'] ?? null);
            if (! is_string($name)
                || ! in_array($name, self::TRANSITION_DRILLS, true)
                || isset($seen[$name])
                || ! $this->sha($row['before_reference_sha256'] ?? null)
                || ! $this->sha($row['during_reference_sha256'] ?? null)
                || ! $this->sha($row['after_reference_sha256'] ?? null)
                || ($row['notification_storm_count'] ?? null) !== 0
                || ($row['ticket_storm_count'] ?? null) !== 0
                || $started === null
                || $during === null
                || $recovered === null
                || $started < $earliest
                || $started > $during
                || $during > $recovered
                || $recovered > $latest) {
                $this->refuse();
            }
            $seen[$name] = true;
        }
        $names = array_keys($seen);
        sort($names, SORT_STRING);
        if ($names !== self::TRANSITION_DRILLS) {
            $this->refuse();
        }
    }

    /** @param array<string, int|string> $authority */
    private function authorityIsValid(array $authority): void
    {
        foreach ([
            'attestation_public_key_sha256',
            'authority_reference',
            'authority_sha256',
            'environment_reference_sha256',
            'not_after_epoch',
            'not_before_epoch',
            'release_revision',
        ] as $key) {
            if (! array_key_exists($key, $authority)) {
                $this->refuse();
            }
        }
        if (! $this->sha($authority['attestation_public_key_sha256'])
            || ! $this->matches($authority['authority_reference'], '/\AAUTHORITY-[a-f0-9]{32}\z/')
            || ! $this->sha($authority['authority_sha256'])
            || ! $this->sha($authority['environment_reference_sha256'])
            || ! $this->sha($authority['release_revision'], 40)
            || ! is_int($authority['not_before_epoch'])
            || ! is_int($authority['not_after_epoch'])
            || $authority['not_before_epoch'] >= $authority['not_after_epoch']) {
            $this->refuse();
        }
    }

    /** @param array<string, mixed> $record @param array<string, int|string> $authority */
    private function linked(array $record, array $authority, string $key): bool
    {
        return is_string($record[$key] ?? null)
            && is_string($authority[$key] ?? null)
            && hash_equals($authority[$key], $record[$key]);
    }

    private function rawHashMatches(mixed $expected, string $raw): bool
    {
        return $this->sha($expected) && hash_equals($expected, hash('sha256', $raw));
    }

    private function decodeBase64(string $encoded, int $length): string
    {
        $encoded = trim($encoded);
        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded)
            || strlen($decoded) !== $length
            || ! hash_equals(rtrim(base64_encode($decoded), '='), rtrim($encoded, '='))) {
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

        return $this->parseUtc($value, 'Y-m-d\TH:i:s\Z');
    }

    private function utcFlexible(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $value) !== 1) {
            return null;
        }
        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));

            return $parsed->getOffset() === 0 ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseUtc(string $value, string $format): ?DateTimeImmutable
    {
        try {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();

            return $parsed instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($format) === $value
                    ? $parsed
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sha(mixed $value, int $length = 64): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function refuse(): never
    {
        throw new RuntimeException('Protocol-policy release evidence is invalid.');
    }
}
