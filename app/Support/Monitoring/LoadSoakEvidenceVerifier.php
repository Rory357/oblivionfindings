<?php

namespace App\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class LoadSoakEvidenceVerifier
{
    public const MINIMUM_DURATION_SECONDS = 3600;

    public const MAXIMUM_SAMPLE_INTERVAL_SECONDS = 60;

    public const MAXIMUM_P95_POLICY_MILLISECONDS = 2000;

    public const MAXIMUM_P99_POLICY_MILLISECONDS = 5000;

    public const MAXIMUM_ERROR_POLICY_PERCENT = 1.0;

    public const MAXIMUM_QUEUE_POLICY_DEPTH = 1000;

    public const MAXIMUM_RECOVERY_POLICY_SECONDS = 300;

    public const array WORKER_ROLES = [
        'checks',
        'commands',
        'discovery',
        'events',
        'maintenance',
        'orchestration',
        'provider',
        'topology',
    ];

    public const array LISTENER_ROLES = [
        'flow',
        'snmp_traps',
        'syslog',
    ];

    /**
     * This verifies the value-free source contract only. Release provenance is
     * established separately by LoadSoakPlatformAttestationVerifier.
     *
     * @return array<string, mixed>
     */
    public function verify(array $evidence, DateTimeImmutable $verifiedAt): array
    {
        $verifiedAt = $verifiedAt->setTimezone(new DateTimeZone('UTC'));
        $topLevelSchema = $this->hasExactKeys($evidence, [
            'acceptance_policy',
            'created_at',
            'ended_at',
            'environment_fingerprint',
            'evidence_class',
            'exercise_kinds',
            'generator',
            'load_profile',
            'measurement_contract',
            'recovery',
            'release_revision',
            'run_id',
            'runtime_class',
            'runtime_roster',
            'samples',
            'schema_version',
            'started_at',
            'v09_release_evidence',
        ]);

        $runId = $this->uuid($evidence['run_id'] ?? null);
        $releaseRevision = $this->sha1($evidence['release_revision'] ?? null);
        $environmentFingerprint = $this->sha256($evidence['environment_fingerprint'] ?? null);
        $startedAt = $this->utc($evidence['started_at'] ?? null);
        $endedAt = $this->utc($evidence['ended_at'] ?? null);
        $createdAt = $this->utc($evidence['created_at'] ?? null);
        $recovery = is_array($evidence['recovery'] ?? null) ? $evidence['recovery'] : [];
        $recoveredAt = $this->utc($recovery['recovered_at'] ?? null);

        $classification = ($evidence['schema_version'] ?? null) === 2
            && ($evidence['evidence_class'] ?? null) === 'deployed_monitoring_load_soak_v2'
            && ($evidence['v09_release_evidence'] ?? null) === true
            && ($evidence['runtime_class'] ?? null) === 'isolated_deployed_release'
            && ($evidence['exercise_kinds'] ?? null) === ['load', 'soak'];

        $identity = $runId !== null
            && $releaseRevision !== null
            && $environmentFingerprint !== null;

        $chronology = false;
        $duration = null;
        if ($startedAt !== null && $endedAt !== null && $recoveredAt !== null && $createdAt !== null) {
            $duration = $endedAt->getTimestamp() - $startedAt->getTimestamp();
            $chronology = $duration >= self::MINIMUM_DURATION_SECONDS
                && $endedAt <= $recoveredAt
                && $recoveredAt <= $createdAt
                && $createdAt <= $verifiedAt->modify('+60 seconds');
        }

        $policy = is_array($evidence['acceptance_policy'] ?? null)
            ? $evidence['acceptance_policy']
            : [];
        $loadProfile = is_array($evidence['load_profile'] ?? null)
            ? $evidence['load_profile']
            : [];
        $measurementContract = is_array($evidence['measurement_contract'] ?? null)
            ? $evidence['measurement_contract']
            : [];
        $policyValid = $this->policyIsValid($policy, $startedAt, $verifiedAt)
            && $this->loadProfileIsValid($loadProfile, $policy)
            && $this->measurementContractIsValid($measurementContract, $policy);
        $approvedDuration = $this->integer($policy['min_duration_seconds'] ?? null);
        $chronology = $chronology
            && $approvedDuration !== null
            && $duration !== null
            && $duration >= $approvedDuration;

        $roster = is_array($evidence['runtime_roster'] ?? null)
            ? $evidence['runtime_roster']
            : [];
        $rosterValid = $this->runtimeRosterIsValid($roster);

        $generator = is_array($evidence['generator'] ?? null)
            ? $evidence['generator']
            : [];
        $generatorResult = $this->generatorResult($generator, $duration, $policy, $loadProfile, $runId);

        $samples = is_array($evidence['samples'] ?? null)
            ? $evidence['samples']
            : [];
        $sampleResult = $this->sampleResult(
            $samples,
            $startedAt,
            $endedAt,
            $policy,
            $generator,
            $measurementContract,
            $roster,
        );

        $recoveryValid = $this->recoveryIsValid(
            $recovery,
            $endedAt,
            $createdAt,
            $verifiedAt,
            $policy,
            $generator,
            $measurementContract,
            $roster,
        );
        $measurementReferencesUnique = $this->measurementReferencesAreDistinct($samples, $recovery);

        $checks = [
            'exact_schema' => $topLevelSchema,
            'release_claim_shape' => $classification,
            'opaque_identity' => $identity,
            'utc_chronology_and_duration' => $chronology,
            'preapproved_objective_profile_and_measurement_policy' => $policyValid,
            'exact_distinct_supervisor_runtime_roster' => $rosterValid,
            'generator_scoped_zero_baseline_totals_and_exit' => $generatorResult['valid'],
            'continuous_runtime_sampling' => $sampleResult['continuous'],
            'all_samples_within_objectives' => $sampleResult['within_objectives'],
            'measurement_provenance_and_sample_counts' => $sampleResult['measurements_valid']
                && $measurementReferencesUnique,
            'complete_worker_listener_dependency_roster' => $sampleResult['runtime_available'],
            'bounded_zero_backlog_recovery' => $recoveryValid,
        ];
        $violations = count(array_filter($checks, static fn (bool $passed): bool => ! $passed));

        return [
            'status' => $violations === 0 ? 'contract_valid' : 'failed',
            'checks' => $checks,
            'violations_count' => $violations,
            'run_id' => $runId,
            'release_revision' => $releaseRevision,
            'environment_fingerprint' => $environmentFingerprint,
            'load_profile_sha256' => $this->sha256($loadProfile['profile_sha256'] ?? null),
            'measurement_contract_sha256' => $this->sha256($measurementContract['contract_sha256'] ?? null),
            'supervisor_observation_generation' => $this->integer($roster['supervisor_observation_generation'] ?? null),
            'observed_duration_seconds' => $duration,
            'achieved_throughput_per_second' => $generatorResult['throughput'],
            'aggregate_error_rate_percent' => $generatorResult['error_rate'],
            'sample_count' => count($samples),
            'release_provenance_verified' => false,
        ];
    }

    private function policyIsValid(array $policy, ?DateTimeImmutable $startedAt, DateTimeImmutable $verifiedAt): bool
    {
        if (! $this->hasExactKeys($policy, [
            'approved_at',
            'approved_by',
            'approved_load_profile_sha256',
            'approved_measurement_contract_sha256',
            'max_error_rate_percent',
            'max_latency_p95_ms',
            'max_latency_p99_ms',
            'max_queue_depth',
            'max_recovery_seconds',
            'max_sample_interval_seconds',
            'min_duration_seconds',
            'min_throughput_per_second',
        ])) {
            return false;
        }

        $approvedAt = $this->utc($policy['approved_at'] ?? null);
        $approvedBy = $policy['approved_by'] ?? null;
        $minDuration = $this->integer($policy['min_duration_seconds'] ?? null);
        $minThroughput = $this->number($policy['min_throughput_per_second'] ?? null);
        $maxP95 = $this->number($policy['max_latency_p95_ms'] ?? null);
        $maxP99 = $this->number($policy['max_latency_p99_ms'] ?? null);
        $maxError = $this->number($policy['max_error_rate_percent'] ?? null);
        $maxQueue = $this->integer($policy['max_queue_depth'] ?? null);
        $maxRecovery = $this->integer($policy['max_recovery_seconds'] ?? null);
        $maxSampleInterval = $this->integer($policy['max_sample_interval_seconds'] ?? null);

        return is_string($approvedBy)
            && preg_match('/\A[A-Za-z0-9._-]{3,64}\z/', $approvedBy) === 1
            && $approvedAt !== null
            && $startedAt !== null
            && $approvedAt <= $startedAt
            && $approvedAt <= $verifiedAt
            && $this->sha256($policy['approved_load_profile_sha256'] ?? null) !== null
            && $this->sha256($policy['approved_measurement_contract_sha256'] ?? null) !== null
            && $minDuration !== null
            && $minDuration >= self::MINIMUM_DURATION_SECONDS
            && $minThroughput !== null
            && $minThroughput > 0
            && $maxP95 !== null
            && $maxP95 > 0
            && $maxP95 <= self::MAXIMUM_P95_POLICY_MILLISECONDS
            && $maxP99 !== null
            && $maxP99 >= $maxP95
            && $maxP99 <= self::MAXIMUM_P99_POLICY_MILLISECONDS
            && $maxError !== null
            && $maxError >= 0
            && $maxError <= self::MAXIMUM_ERROR_POLICY_PERCENT
            && $maxQueue !== null
            && $maxQueue >= 0
            && $maxQueue <= self::MAXIMUM_QUEUE_POLICY_DEPTH
            && $maxRecovery !== null
            && $maxRecovery > 0
            && $maxRecovery <= self::MAXIMUM_RECOVERY_POLICY_SECONDS
            && $maxSampleInterval !== null
            && $maxSampleInterval > 0
            && $maxSampleInterval <= self::MAXIMUM_SAMPLE_INTERVAL_SECONDS;
    }

    private function loadProfileIsValid(array $profile, array $policy): bool
    {
        if (! $this->hasExactKeys($profile, [
            'concurrency',
            'event_class_count',
            'event_mix_sha256',
            'generator_mode',
            'profile_sha256',
            'scheduled_rate_per_second',
            'target_scope_sha256',
        ])) {
            return false;
        }
        $concurrency = $this->integer($profile['concurrency'] ?? null);
        $eventClasses = $this->integer($profile['event_class_count'] ?? null);
        $rate = $this->number($profile['scheduled_rate_per_second'] ?? null);
        $minimum = $this->number($policy['min_throughput_per_second'] ?? null);
        $expected = $this->hashDocument([
            'generator_mode' => $profile['generator_mode'] ?? null,
            'concurrency' => $concurrency,
            'scheduled_rate_per_second' => $rate,
            'event_class_count' => $eventClasses,
            'event_mix_sha256' => $profile['event_mix_sha256'] ?? null,
            'target_scope_sha256' => $profile['target_scope_sha256'] ?? null,
        ]);

        return ($profile['generator_mode'] ?? null) === 'constant_rate'
            && $concurrency !== null && $concurrency >= 1 && $concurrency <= 10_000
            && $eventClasses !== null && $eventClasses >= 1 && $eventClasses <= 64
            && $rate !== null && $rate > 0
            && $minimum !== null && $rate >= $minimum
            && $this->sha256($profile['event_mix_sha256'] ?? null) !== null
            && $this->sha256($profile['target_scope_sha256'] ?? null) !== null
            && $this->sha256($profile['profile_sha256'] ?? null) === $expected
            && ($policy['approved_load_profile_sha256'] ?? null) === $expected;
    }

    private function measurementContractIsValid(array $contract, array $policy): bool
    {
        if (! $this->hasExactKeys($contract, [
            'contract_sha256',
            'metric_set_sha256',
            'source_kind',
            'source_sha256',
        ])) {
            return false;
        }
        $expected = $this->hashDocument([
            'source_kind' => $contract['source_kind'] ?? null,
            'source_sha256' => $contract['source_sha256'] ?? null,
            'metric_set_sha256' => $contract['metric_set_sha256'] ?? null,
        ]);

        return ($contract['source_kind'] ?? null) === 'platform_telemetry'
            && $this->sha256($contract['source_sha256'] ?? null) !== null
            && $this->sha256($contract['metric_set_sha256'] ?? null) !== null
            && $this->sha256($contract['contract_sha256'] ?? null) === $expected
            && ($policy['approved_measurement_contract_sha256'] ?? null) === $expected;
    }

    private function runtimeRosterIsValid(array $roster): bool
    {
        if (! $this->hasExactKeys($roster, [
            'listeners',
            'supervisor_observation_generation',
            'workers',
        ])) {
            return false;
        }
        $generation = $this->integer($roster['supervisor_observation_generation'] ?? null);
        $workers = is_array($roster['workers'] ?? null) ? $roster['workers'] : [];
        $listeners = is_array($roster['listeners'] ?? null) ? $roster['listeners'] : [];
        if ($generation === null || $generation < 1
            || ! $this->hasExactKeys($workers, self::WORKER_ROLES)
            || ! $this->hasExactKeys($listeners, self::LISTENER_ROLES)) {
            return false;
        }
        $references = [...array_values($workers), ...array_values($listeners)];

        return count($references) === 11
            && count(array_unique($references)) === 11
            && count(array_filter($references, fn (mixed $reference): bool => $this->sha256($reference) !== null)) === 11;
    }

    /** @return array{valid: bool, throughput: float|null, error_rate: float|null} */
    private function generatorResult(
        array $generator,
        ?int $duration,
        array $policy,
        array $loadProfile,
        ?string $runId,
    ): array {
        $attempted = $this->integer($generator['attempted_events'] ?? null);
        $successful = $this->integer($generator['successful_events'] ?? null);
        $failed = $this->integer($generator['failed_events'] ?? null);
        $baseline = $this->integer($generator['baseline_processed_events'] ?? null);
        $end = $this->integer($generator['end_processed_events'] ?? null);
        $exitCode = $this->integer($generator['exit_code'] ?? null);
        $throughput = $duration !== null && $duration > 0 && $successful !== null
            ? (float) ($successful / $duration)
            : null;
        $errorRate = $attempted !== null && $attempted > 0 && $failed !== null
            ? (float) (($failed / $attempted) * 100)
            : null;
        $minThroughput = $this->number($policy['min_throughput_per_second'] ?? null);
        $maxError = $this->number($policy['max_error_rate_percent'] ?? null);
        $scheduledRate = $this->number($loadProfile['scheduled_rate_per_second'] ?? null);
        $scheduledEvents = $duration !== null
            && $duration > 0
            && $scheduledRate !== null
            && $scheduledRate <= PHP_INT_MAX / $duration
            ? $duration * $scheduledRate
            : null;
        $scheduleTolerance = $scheduledEvents !== null ? max(1.0, $scheduledEvents * 0.001) : null;

        $valid = $this->hasExactKeys($generator, [
            'attempted_events',
            'baseline_processed_events',
            'counter_run_id',
            'end_processed_events',
            'exit_code',
            'failed_events',
            'producer_sha256',
            'successful_events',
        ])
            && $runId !== null
            && ($generator['counter_run_id'] ?? null) === $runId
            && $baseline === 0
            && $exitCode === 0
            && $attempted !== null && $attempted > 0
            && $successful !== null && $successful > 0
            && $failed !== null && $failed >= 0
            && $end === $successful
            && $attempted === $successful + $failed
            && $this->sha256($generator['producer_sha256'] ?? null) !== null
            && $scheduledEvents !== null && $scheduleTolerance !== null
            && abs($attempted - $scheduledEvents) <= $scheduleTolerance
            && $throughput !== null && $minThroughput !== null && $throughput >= $minThroughput
            && $errorRate !== null && $maxError !== null && $errorRate <= $maxError;

        return ['valid' => $valid, 'throughput' => $throughput, 'error_rate' => $errorRate];
    }

    /** @return array{continuous: bool, within_objectives: bool, measurements_valid: bool, runtime_available: bool} */
    private function sampleResult(
        array $samples,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
        array $policy,
        array $generator,
        array $measurementContract,
        array $roster,
    ): array {
        $invalid = ['continuous' => false, 'within_objectives' => false, 'measurements_valid' => false, 'runtime_available' => false];
        $maxInterval = $this->integer($policy['max_sample_interval_seconds'] ?? null);
        $maxP95 = $this->number($policy['max_latency_p95_ms'] ?? null);
        $maxP99 = $this->number($policy['max_latency_p99_ms'] ?? null);
        $maxError = $this->number($policy['max_error_rate_percent'] ?? null);
        $maxQueue = $this->integer($policy['max_queue_depth'] ?? null);
        $minThroughput = $this->number($policy['min_throughput_per_second'] ?? null);
        $baseline = $this->integer($generator['baseline_processed_events'] ?? null);
        $end = $this->integer($generator['end_processed_events'] ?? null);

        if ($samples === [] || ! array_is_list($samples) || $startedAt === null || $endedAt === null
            || $maxInterval === null || $baseline !== 0 || $end === null) {
            return $invalid;
        }

        $continuous = true;
        $withinObjectives = true;
        $measurementsValid = true;
        $runtimeAvailable = true;
        $previousAt = $startedAt;
        $previousProcessed = $baseline;

        foreach ($samples as $sample) {
            if (! is_array($sample) || ! $this->hasExactKeys($sample, [
                'dependencies',
                'error_rate_percent',
                'latency_p95_ms',
                'latency_p99_ms',
                'listeners',
                'measurement',
                'observed_at',
                'processed_events',
                'queue_depth',
                'supervisor_observation_generation',
                'workers',
            ])) {
                return $invalid;
            }

            $observedAt = $this->utc($sample['observed_at'] ?? null);
            $processed = $this->integer($sample['processed_events'] ?? null);
            $p95 = $this->number($sample['latency_p95_ms'] ?? null);
            $p99 = $this->number($sample['latency_p99_ms'] ?? null);
            $errorRate = $this->number($sample['error_rate_percent'] ?? null);
            $queueDepth = $this->integer($sample['queue_depth'] ?? null);
            $intervalSeconds = $observedAt !== null
                ? $observedAt->getTimestamp() - $previousAt->getTimestamp()
                : null;
            $intervalProcessed = $processed !== null ? $processed - $previousProcessed : null;

            if ($observedAt === null
                || $observedAt <= $previousAt
                || $observedAt > $endedAt
                || $intervalSeconds === null
                || $intervalSeconds > $maxInterval
                || $processed === null
                || $processed < $previousProcessed
                || $processed > $end) {
                $continuous = false;
            }

            if ($p95 === null || $p99 === null || $errorRate === null || $queueDepth === null
                || $maxP95 === null || $maxP99 === null || $maxError === null || $maxQueue === null || $minThroughput === null
                || $p95 < 0 || $p99 < $p95 || $p95 > $maxP95 || $p99 > $maxP99
                || $errorRate < 0 || $errorRate > $maxError || $queueDepth < 0 || $queueDepth > $maxQueue
                || $intervalSeconds === null || $intervalSeconds <= 0 || $intervalProcessed === null
                || $intervalProcessed / $intervalSeconds < $minThroughput) {
                $withinObjectives = false;
            }

            if (! $this->measurementIsValid(
                is_array($sample['measurement'] ?? null) ? $sample['measurement'] : [],
                $measurementContract,
                $previousAt,
                $observedAt,
            )) {
                $measurementsValid = false;
            }
            if (! $this->runtimeObservationIsAvailable($sample, $roster)) {
                $runtimeAvailable = false;
            }

            if ($observedAt !== null) {
                $previousAt = $observedAt;
            }
            if ($processed !== null) {
                $previousProcessed = $processed;
            }
        }

        if ($previousAt != $endedAt || $previousProcessed !== $end) {
            $continuous = false;
        }

        return [
            'continuous' => $continuous,
            'within_objectives' => $withinObjectives,
            'measurements_valid' => $measurementsValid,
            'runtime_available' => $runtimeAvailable,
        ];
    }

    private function recoveryIsValid(
        array $recovery,
        ?DateTimeImmutable $endedAt,
        ?DateTimeImmutable $createdAt,
        DateTimeImmutable $verifiedAt,
        array $policy,
        array $generator,
        array $measurementContract,
        array $roster,
    ): bool {
        if (! $this->hasExactKeys($recovery, [
            'dependencies',
            'error_rate_percent',
            'listeners',
            'measurement',
            'processed_events',
            'queue_depth',
            'recovered_at',
            'supervisor_observation_generation',
            'workers',
        ])) {
            return false;
        }

        $recoveredAt = $this->utc($recovery['recovered_at'] ?? null);
        $maxRecovery = $this->integer($policy['max_recovery_seconds'] ?? null);
        $maxError = $this->number($policy['max_error_rate_percent'] ?? null);
        $processed = $this->integer($recovery['processed_events'] ?? null);
        $end = $this->integer($generator['end_processed_events'] ?? null);
        $queueDepth = $this->integer($recovery['queue_depth'] ?? null);
        $errorRate = $this->number($recovery['error_rate_percent'] ?? null);

        return $endedAt !== null
            && $recoveredAt !== null
            && $createdAt !== null
            && $endedAt <= $recoveredAt
            && $recoveredAt <= $createdAt
            && $createdAt <= $verifiedAt->modify('+60 seconds')
            && $maxRecovery !== null
            && $recoveredAt->getTimestamp() - $endedAt->getTimestamp() <= $maxRecovery
            && $processed !== null && $end !== null && $processed === $end
            && $queueDepth === 0
            && $errorRate !== null && $maxError !== null && $errorRate >= 0 && $errorRate <= $maxError
            && $this->measurementIsValid(
                is_array($recovery['measurement'] ?? null) ? $recovery['measurement'] : [],
                $measurementContract,
                $endedAt,
                $recoveredAt,
            )
            && $this->runtimeObservationIsAvailable($recovery, $roster);
    }

    private function measurementIsValid(
        array $measurement,
        array $contract,
        DateTimeImmutable $expectedStart,
        ?DateTimeImmutable $expectedEnd,
    ): bool {
        if ($expectedEnd === null || ! $this->hasExactKeys($measurement, [
            'metric_set_sha256',
            'observation_sha256',
            'sample_count',
            'source_sha256',
            'window_ended_at',
            'window_started_at',
        ])) {
            return false;
        }
        $windowStart = $this->utc($measurement['window_started_at'] ?? null);
        $windowEnd = $this->utc($measurement['window_ended_at'] ?? null);
        $sampleCount = $this->integer($measurement['sample_count'] ?? null);

        return $windowStart == $expectedStart
            && $windowEnd == $expectedEnd
            && $sampleCount !== null && $sampleCount > 0
            && ($measurement['source_sha256'] ?? null) === ($contract['source_sha256'] ?? null)
            && ($measurement['metric_set_sha256'] ?? null) === ($contract['metric_set_sha256'] ?? null)
            && $this->sha256($measurement['observation_sha256'] ?? null) !== null;
    }

    private function measurementReferencesAreDistinct(array $samples, array $recovery): bool
    {
        if (! array_is_list($samples) || ! is_array($recovery['measurement'] ?? null)) {
            return false;
        }

        $references = [];
        foreach ([...$samples, $recovery] as $observation) {
            $reference = is_array($observation)
                && is_array($observation['measurement'] ?? null)
                    ? $this->sha256($observation['measurement']['observation_sha256'] ?? null)
                    : null;
            if ($reference === null) {
                return false;
            }
            $references[] = $reference;
        }

        return count($references) === count(array_unique($references, SORT_STRING));
    }

    private function runtimeObservationIsAvailable(array $observation, array $roster): bool
    {
        $workers = is_array($observation['workers'] ?? null) ? $observation['workers'] : [];
        $listeners = is_array($observation['listeners'] ?? null) ? $observation['listeners'] : [];
        $dependencies = is_array($observation['dependencies'] ?? null) ? $observation['dependencies'] : [];

        return $this->runtimeRosterIsValid($roster)
            && ($observation['supervisor_observation_generation'] ?? null) === ($roster['supervisor_observation_generation'] ?? null)
            && $this->hasExactKeys($workers, self::WORKER_ROLES)
            && count(array_filter($workers, static fn (mixed $state): bool => $state === 'available')) === 8
            && $this->hasExactKeys($listeners, self::LISTENER_ROLES)
            && count(array_filter($listeners, static fn (mixed $state): bool => $state === 'available')) === 3
            && $this->hasExactKeys($dependencies, ['mysql', 'object_storage', 'redis', 'secret_manager', 'time_series'])
            && count(array_filter($dependencies, static fn (mixed $state): bool => $state === 'available')) === 5;
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

    private function hashDocument(array $document): ?string
    {
        try {
            return hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            return null;
        }
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1
                ? $value
                : null;
    }

    private function sha1(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1 ? $value : null;
    }

    private function sha256(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1 ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? (float) $value : null;
    }
}
