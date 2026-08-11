<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class CollectorReleaseEvidenceVerifier
{
    private const array TRANSPORT_KEYS = [
        'collector_reference_sha256',
        'expected_identity_state',
        'identity_generation_reference_sha256',
        'initial_response',
        'observed_from_utc',
        'observed_until_utc',
        'pinned_https_contract',
        'replay_attempt',
        'samples',
        'schema',
        'signing_key_reference_sha256',
        'state',
    ];

    private const array EVIDENCE_KEYS = [
        'active_transport_sha256',
        'authority_reference',
        'authority_sha256',
        'credentialed_protocol',
        'deployment',
        'environment_reference_sha256',
        'evidence_class',
        'evidence_reference',
        'exercise_completed_at',
        'exercise_started_at',
        'load_balancer_reference_sha256',
        'outage',
        'release_revision',
        'remote_site_reference_sha256',
        'replacement_transport_sha256',
        'revocation',
        'revoked_transport_sha256',
        'schema_version',
    ];

    private const array DEPLOYMENT_KEYS = [
        'application_instances',
        'cross_instance_replay_reference_sha256',
        'dedicated_ca_configuration_sha256',
        'legacy_fingerprint_header_disabled',
        'load_balancer_routing_reference_sha256',
        'mtls_header_replacement_verified',
        'nginx_validation_reference_sha256',
        'proxy_configuration_sha256',
        'reviewed_at',
        'reviewed_instances',
        'same_shared_redis_verified',
        'shared_redis_configuration_sha256',
    ];

    private const array OUTAGE_KEYS = [
        'acknowledged_source_sequence',
        'affected_devices',
        'affected_monitors',
        'backlog_items_after',
        'backlog_items_before',
        'configuration_sequence_after',
        'corrupted_frames_after',
        'correlation_reference_sha256',
        'downstream_recovered',
        'exactly_one_root_correlation',
        'gap_count_after',
        'highest_source_sequence',
        'outage_started_at',
        'pinned_monitor_roster_sha256',
        'post_boundary_observations',
        'recovery_completed_at',
        'roster_drift_negative_reference_sha256',
        'stale_detected_at',
        'unrelated_site_observation_sha256',
    ];

    private const array CREDENTIAL_KEYS = [
        'fresh_observation_verified',
        'lease_accepted',
        'lease_reference_sha256',
        'observation_reference_sha256',
        'observed_at',
        'plaintext_scan_clean',
        'plaintext_scan_reference_sha256',
        'protocol',
    ];

    private const array REVOCATION_KEYS = [
        'central_revocation_audit_reference_sha256',
        'general_site_token_denied_at',
        'general_site_token_denial_reference_sha256',
        'old_identity_forwarded_and_denied',
        'replacement_consumed_at',
        'replacement_consume_audit_reference_sha256',
        'replacement_heartbeat_current',
        'replacement_heartbeat_observed_at',
        'replacement_heartbeat_reference_sha256',
        'replacement_issued_at',
        'replacement_issue_audit_reference_sha256',
        'replacement_token_reuse_denied_at',
        'replacement_token_reuse_denial_reference_sha256',
        'replacement_zero_backlog',
        'revoked_at',
        'service_restored_at',
        'restored_service_reference_sha256',
    ];

    /**
     * @param  array<string, int|string>  $authority
     * @return array<string, bool|int|string>
     */
    public function verify(
        string $rawActiveTransport,
        string $rawRevokedTransport,
        string $rawReplacementTransport,
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
            $active = $decoder->decode($rawActiveTransport, 16);
            $revoked = $decoder->decode($rawRevokedTransport, 16);
            $replacement = $decoder->decode($rawReplacementTransport, 16);
            $evidence = $decoder->decode($rawEvidence, 32);
            if (! $this->exactKeys($evidence, self::EVIDENCE_KEYS)
                || ! is_array($evidence['deployment'] ?? null)
                || array_is_list($evidence['deployment'])
                || ! is_array($evidence['outage'] ?? null)
                || array_is_list($evidence['outage'])
                || ! is_array($evidence['credentialed_protocol'] ?? null)
                || array_is_list($evidence['credentialed_protocol'])
                || ! is_array($evidence['revocation'] ?? null)
                || array_is_list($evidence['revocation'])) {
                $this->refuse();
            }

            $activeWindow = $this->transportWindow($active, 'active');
            $revokedWindow = $this->transportWindow($revoked, 'revoked');
            $replacementWindow = $this->transportWindow($replacement, 'active');
            $this->identityProgressionIsValid($active, $revoked, $replacement);

            $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
            $notBefore = (new DateTimeImmutable('@'.(int) $authority['not_before_epoch']))
                ->setTimezone(new DateTimeZone('UTC'));
            $notAfter = (new DateTimeImmutable('@'.(int) $authority['not_after_epoch']))
                ->setTimezone(new DateTimeZone('UTC'));
            $started = $this->utc($evidence['exercise_started_at'] ?? null);
            $completed = $this->utc($evidence['exercise_completed_at'] ?? null);
            if (($evidence['schema_version'] ?? null) !== 2
                || ($evidence['evidence_class'] ?? null) !== 'monitoring_collector_release_evidence_v2'
                || ! $this->matches($evidence['evidence_reference'] ?? null, '/\ACOLLECTOR-[a-f0-9]{32}\z/')
                || ! $this->linked($evidence, $authority, 'authority_reference')
                || ! $this->linked($evidence, $authority, 'authority_sha256')
                || ! $this->linked($evidence, $authority, 'environment_reference_sha256')
                || ! $this->linked($evidence, $authority, 'release_revision')
                || ! $this->linked($evidence, $authority, 'remote_site_reference_sha256')
                || ! $this->linked($evidence, $authority, 'load_balancer_reference_sha256')
                || ! $this->rawHashMatches($evidence['active_transport_sha256'] ?? null, $rawActiveTransport)
                || ! $this->rawHashMatches($evidence['revoked_transport_sha256'] ?? null, $rawRevokedTransport)
                || ! $this->rawHashMatches($evidence['replacement_transport_sha256'] ?? null, $rawReplacementTransport)
                || $started === null
                || $completed === null
                || $started < $notBefore
                || $started > $activeWindow['from']
                || $revokedWindow['until'] > $replacementWindow['from']
                || $replacementWindow['until'] > $completed
                || $completed > $notAfter
                || $completed > $now->modify('+60 seconds')) {
                $this->refuse();
            }

            $deployment = $this->deployment(
                $evidence['deployment'],
                $started,
                $activeWindow['from'],
            );
            $outage = $this->outage($evidence['outage'], $activeWindow['until'], $revokedWindow['from']);
            $outageRecovered = $this->utc($outage['recovery_completed_at']);
            if ($outageRecovered === null) {
                $this->refuse();
            }
            $credential = $this->credential(
                $evidence['credentialed_protocol'],
                $outageRecovered,
                $revokedWindow['from'],
            );
            $credentialObserved = $this->utc($credential['observed_at']);
            if ($credentialObserved === null) {
                $this->refuse();
            }
            $revocation = $evidence['revocation'];
            $this->revocation(
                $revocation,
                $credentialObserved,
                $revokedWindow,
                $replacementWindow,
                $completed,
            );
            $this->exerciseEvidenceReferencesAreDistinct(
                $deployment,
                $outage,
                $credential,
                $revocation,
            );

            return [
                'status' => 'verified',
                'evidence_class' => 'monitoring_collector_release_verification_v2',
                'authority_reference' => $authority['authority_reference'],
                'authority_sha256' => $authority['authority_sha256'],
                'environment_reference_sha256' => $authority['environment_reference_sha256'],
                'release_revision' => $authority['release_revision'],
                'remote_site_reference_sha256' => $authority['remote_site_reference_sha256'],
                'load_balancer_reference_sha256' => $authority['load_balancer_reference_sha256'],
                'evidence_reference' => $evidence['evidence_reference'],
                'signed_collector_evidence_sha256' => hash('sha256', $rawEvidence),
                'detached_signature_sha256' => hash('sha256', $signature),
                'active_transport_sha256' => $evidence['active_transport_sha256'],
                'revoked_transport_sha256' => $evidence['revoked_transport_sha256'],
                'replacement_transport_sha256' => $evidence['replacement_transport_sha256'],
                'transport_samples_verified' => 15,
                'application_instances_verified' => $deployment['application_instances'],
                'affected_monitors_verified' => $outage['affected_monitors'],
                'credentialed_protocol' => $credential['protocol'],
                'acknowledged_source_sequence' => $outage['acknowledged_source_sequence'],
                'configuration_sequence' => $outage['configuration_sequence_after'],
                'exercise_started_at' => $evidence['exercise_started_at'],
                'exercise_completed_at' => $evidence['exercise_completed_at'],
                'collector_release_evidence' => true,
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('Collector release evidence is invalid.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $transport @return array{from: DateTimeImmutable, until: DateTimeImmutable} */
    private function transportWindow(array $transport, string $expectedState): array
    {
        if (! $this->exactKeys($transport, self::TRANSPORT_KEYS)) {
            $this->refuse();
        }
        $from = $this->utc($transport['observed_from_utc'] ?? null);
        $until = $this->utc($transport['observed_until_utc'] ?? null);
        $active = $expectedState === 'active';
        if (($transport['schema'] ?? null) !== 'oblivion-collector-transport-evidence-v2'
            || ($transport['state'] ?? null) !== 'response_contract_matched'
            || ! $this->sha($transport['collector_reference_sha256'] ?? null)
            || ! $this->sha($transport['signing_key_reference_sha256'] ?? null)
            || ! $this->sha($transport['identity_generation_reference_sha256'] ?? null)
            || ($transport['expected_identity_state'] ?? null) !== $expectedState
            || ($transport['pinned_https_contract'] ?? null) !== 'matched'
            || ($transport['initial_response'] ?? null) !== ($active ? 'validation_rejected' : 'authentication_denied')
            || ($transport['replay_attempt'] ?? null) !== ($active ? 'authentication_denied' : 'not_exercised')
            || ($transport['samples'] ?? null) !== 5
            || $from === null
            || $until === null
            || $from > $until) {
            $this->refuse();
        }

        return ['from' => $from, 'until' => $until];
    }

    /** @param array<string, mixed> $active @param array<string, mixed> $revoked @param array<string, mixed> $replacement */
    private function identityProgressionIsValid(array $active, array $revoked, array $replacement): void
    {
        foreach (['collector_reference_sha256', 'signing_key_reference_sha256', 'identity_generation_reference_sha256'] as $key) {
            if (! hash_equals((string) $active[$key], (string) $revoked[$key])) {
                $this->refuse();
            }
        }
        if (! hash_equals((string) $active['collector_reference_sha256'], (string) $replacement['collector_reference_sha256'])
            || hash_equals((string) $active['signing_key_reference_sha256'], (string) $replacement['signing_key_reference_sha256'])
            || hash_equals((string) $active['identity_generation_reference_sha256'], (string) $replacement['identity_generation_reference_sha256'])) {
            $this->refuse();
        }
    }

    /** @param array<string, mixed> $deployment @return array<string, mixed> */
    private function deployment(
        array $deployment,
        DateTimeImmutable $exerciseStarted,
        DateTimeImmutable $activeTransportFrom,
    ): array {
        $reviewedAt = $this->utc($deployment['reviewed_at'] ?? null);
        if (! $this->exactKeys($deployment, self::DEPLOYMENT_KEYS)
            || ! is_int($deployment['application_instances'] ?? null)
            || $deployment['application_instances'] < 2
            || ($deployment['reviewed_instances'] ?? null) !== $deployment['application_instances']
            || ($deployment['same_shared_redis_verified'] ?? null) !== true
            || ($deployment['mtls_header_replacement_verified'] ?? null) !== true
            || ($deployment['legacy_fingerprint_header_disabled'] ?? null) !== true
            || $reviewedAt === null
            || $reviewedAt < $exerciseStarted
            || $reviewedAt > $activeTransportFrom) {
            $this->refuse();
        }
        $evidenceHashes = [
            'cross_instance_replay_reference_sha256',
            'dedicated_ca_configuration_sha256',
            'load_balancer_routing_reference_sha256',
            'nginx_validation_reference_sha256',
            'proxy_configuration_sha256',
            'shared_redis_configuration_sha256',
        ];
        foreach ($evidenceHashes as $key) {
            if (! $this->sha($deployment[$key] ?? null)) {
                $this->refuse();
            }
        }
        $values = array_map(fn (string $key): string => (string) $deployment[$key], $evidenceHashes);
        if (count($values) !== count(array_unique($values, SORT_STRING))) {
            $this->refuse();
        }

        return $deployment;
    }

    /** @param array<string, mixed> $outage @return array<string, mixed> */
    private function outage(array $outage, DateTimeImmutable $activeUntil, DateTimeImmutable $revokedFrom): array
    {
        if (! $this->exactKeys($outage, self::OUTAGE_KEYS)) {
            $this->refuse();
        }
        $started = $this->utc($outage['outage_started_at'] ?? null);
        $stale = $this->utc($outage['stale_detected_at'] ?? null);
        $recovered = $this->utc($outage['recovery_completed_at'] ?? null);
        if ($started === null
            || $stale === null
            || $recovered === null
            || $activeUntil > $started
            || $started > $stale
            || $stale > $recovered
            || $recovered > $revokedFrom
            || ($outage['exactly_one_root_correlation'] ?? null) !== true
            || ($outage['downstream_recovered'] ?? null) !== true
            || ! is_int($outage['affected_monitors'] ?? null)
            || $outage['affected_monitors'] < 1
            || ! is_int($outage['affected_devices'] ?? null)
            || $outage['affected_devices'] < 1
            || ($outage['post_boundary_observations'] ?? null) !== $outage['affected_monitors']
            || ! is_int($outage['backlog_items_before'] ?? null)
            || $outage['backlog_items_before'] < 1
            || ($outage['backlog_items_after'] ?? null) !== 0
            || ! is_int($outage['acknowledged_source_sequence'] ?? null)
            || $outage['acknowledged_source_sequence'] < 1
            || ($outage['highest_source_sequence'] ?? null) !== $outage['acknowledged_source_sequence']
            || ($outage['gap_count_after'] ?? null) !== 0
            || ($outage['corrupted_frames_after'] ?? null) !== 0
            || ! is_int($outage['configuration_sequence_after'] ?? null)
            || $outage['configuration_sequence_after'] < 1) {
            $this->refuse();
        }
        foreach ([
            'correlation_reference_sha256',
            'pinned_monitor_roster_sha256',
            'roster_drift_negative_reference_sha256',
            'unrelated_site_observation_sha256',
        ] as $key) {
            if (! $this->sha($outage[$key] ?? null)) {
                $this->refuse();
            }
        }

        return $outage;
    }

    /** @param array<string, mixed> $credential @return array<string, mixed> */
    private function credential(
        array $credential,
        DateTimeImmutable $outageRecovered,
        DateTimeImmutable $revokedTransportFrom,
    ): array {
        $observedAt = $this->utc($credential['observed_at'] ?? null);
        if (! $this->exactKeys($credential, self::CREDENTIAL_KEYS)
            || ! in_array($credential['protocol'] ?? null, ['snmpv3', 'ssh_read_only', 'winrm_approved'], true)
            || ($credential['lease_accepted'] ?? null) !== true
            || ($credential['fresh_observation_verified'] ?? null) !== true
            || ($credential['plaintext_scan_clean'] ?? null) !== true
            || $observedAt === null
            || $observedAt < $outageRecovered
            || $observedAt > $revokedTransportFrom) {
            $this->refuse();
        }
        foreach (['lease_reference_sha256', 'observation_reference_sha256', 'plaintext_scan_reference_sha256'] as $key) {
            if (! $this->sha($credential[$key] ?? null)) {
                $this->refuse();
            }
        }

        return $credential;
    }

    /** @param array<string, mixed> $revocation */
    /** @param array{from: DateTimeImmutable, until: DateTimeImmutable} $revokedWindow @param array{from: DateTimeImmutable, until: DateTimeImmutable} $replacementWindow */
    private function revocation(
        array $revocation,
        DateTimeImmutable $credentialObserved,
        array $revokedWindow,
        array $replacementWindow,
        DateTimeImmutable $exerciseCompleted,
    ): void {
        $revokedAt = $this->utc($revocation['revoked_at'] ?? null);
        $issuedAt = $this->utc($revocation['replacement_issued_at'] ?? null);
        $consumedAt = $this->utc($revocation['replacement_consumed_at'] ?? null);
        $replacementReuseDeniedAt = $this->utc($revocation['replacement_token_reuse_denied_at'] ?? null);
        $generalTokenDeniedAt = $this->utc($revocation['general_site_token_denied_at'] ?? null);
        $replacementHeartbeatObservedAt = $this->utc($revocation['replacement_heartbeat_observed_at'] ?? null);
        $serviceRestoredAt = $this->utc($revocation['service_restored_at'] ?? null);
        if (! $this->exactKeys($revocation, self::REVOCATION_KEYS)
            || ($revocation['old_identity_forwarded_and_denied'] ?? null) !== true
            || ($revocation['replacement_heartbeat_current'] ?? null) !== true
            || ($revocation['replacement_zero_backlog'] ?? null) !== true
            || $revokedAt === null
            || $issuedAt === null
            || $consumedAt === null
            || $replacementReuseDeniedAt === null
            || $generalTokenDeniedAt === null
            || $replacementHeartbeatObservedAt === null
            || $serviceRestoredAt === null
            || $revokedAt < $credentialObserved
            || $revokedAt > $revokedWindow['from']
            || $revokedWindow['until'] > $issuedAt
            || $issuedAt > $consumedAt
            || $consumedAt > $replacementWindow['from']
            || $replacementReuseDeniedAt < $consumedAt
            || $replacementReuseDeniedAt < $replacementWindow['until']
            || $replacementReuseDeniedAt > $replacementHeartbeatObservedAt
            || $replacementReuseDeniedAt > $exerciseCompleted
            || $generalTokenDeniedAt < $consumedAt
            || $generalTokenDeniedAt < $replacementWindow['until']
            || $generalTokenDeniedAt > $replacementHeartbeatObservedAt
            || $generalTokenDeniedAt > $exerciseCompleted
            || $replacementHeartbeatObservedAt < $replacementWindow['until']
            || $replacementHeartbeatObservedAt > $serviceRestoredAt
            || $exerciseCompleted->getTimestamp() - $replacementHeartbeatObservedAt->getTimestamp() > 180
            || $serviceRestoredAt < $replacementWindow['until']
            || $serviceRestoredAt > $exerciseCompleted) {
            $this->refuse();
        }
        foreach ([
            'central_revocation_audit_reference_sha256',
            'general_site_token_denial_reference_sha256',
            'replacement_consume_audit_reference_sha256',
            'replacement_issue_audit_reference_sha256',
            'replacement_heartbeat_reference_sha256',
            'replacement_token_reuse_denial_reference_sha256',
            'restored_service_reference_sha256',
        ] as $key) {
            if (! $this->sha($revocation[$key] ?? null)) {
                $this->refuse();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @param  array<string, mixed>  $outage
     * @param  array<string, mixed>  $credential
     * @param  array<string, mixed>  $revocation
     */
    private function exerciseEvidenceReferencesAreDistinct(
        array $deployment,
        array $outage,
        array $credential,
        array $revocation,
    ): void {
        $references = [
            $deployment['cross_instance_replay_reference_sha256'],
            $deployment['load_balancer_routing_reference_sha256'],
            $deployment['nginx_validation_reference_sha256'],
            $outage['correlation_reference_sha256'],
            $outage['pinned_monitor_roster_sha256'],
            $outage['roster_drift_negative_reference_sha256'],
            $outage['unrelated_site_observation_sha256'],
            $credential['lease_reference_sha256'],
            $credential['observation_reference_sha256'],
            $credential['plaintext_scan_reference_sha256'],
            $revocation['central_revocation_audit_reference_sha256'],
            $revocation['general_site_token_denial_reference_sha256'],
            $revocation['replacement_consume_audit_reference_sha256'],
            $revocation['replacement_issue_audit_reference_sha256'],
            $revocation['replacement_heartbeat_reference_sha256'],
            $revocation['replacement_token_reuse_denial_reference_sha256'],
            $revocation['restored_service_reference_sha256'],
        ];

        if (count($references) !== count(array_unique($references, SORT_STRING))) {
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
            'load_balancer_reference_sha256',
            'not_after_epoch',
            'not_before_epoch',
            'release_revision',
            'remote_site_reference_sha256',
        ] as $key) {
            if (! array_key_exists($key, $authority)) {
                $this->refuse();
            }
        }
        if (! $this->sha($authority['attestation_public_key_sha256'])
            || ! $this->matches($authority['authority_reference'], '/\AAUTHORITY-[a-f0-9]{32}\z/')
            || ! $this->sha($authority['authority_sha256'])
            || ! $this->sha($authority['environment_reference_sha256'])
            || ! $this->sha($authority['load_balancer_reference_sha256'])
            || ! $this->sha($authority['remote_site_reference_sha256'])
            || ! $this->sha($authority['release_revision'], 40)
            || ! is_int($authority['not_before_epoch'])
            || ! is_int($authority['not_after_epoch'])
            || $authority['not_before_epoch'] >= $authority['not_after_epoch']) {
            $this->refuse();
        }
    }

    /** @param array<string, mixed> $evidence @param array<string, int|string> $authority */
    private function linked(array $evidence, array $authority, string $key): bool
    {
        return is_string($evidence[$key] ?? null)
            && is_string($authority[$key] ?? null)
            && hash_equals($authority[$key], $evidence[$key]);
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
        try {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
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
        throw new RuntimeException('Collector release evidence is invalid.');
    }
}
