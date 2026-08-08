<?php

use App\Support\Monitoring\LoadSoakEvidenceVerifier;
use App\Support\Monitoring\LoadSoakPlatformAttestationVerifier;
use App\Support\Monitoring\StrictJsonObjectDecoder;
use Symfony\Component\Process\Process;

function validLoadSoakEvidence(): array
{
    $runId = '123e4567-e89b-42d3-a456-426614174000';
    $start = new DateTimeImmutable('2026-08-08T18:00:00Z');
    $loadProfileDimensions = [
        'generator_mode' => 'constant_rate',
        'concurrency' => 4,
        'scheduled_rate_per_second' => 10.0,
        'event_class_count' => 3,
        'event_mix_sha256' => str_repeat('1', 64),
        'target_scope_sha256' => str_repeat('2', 64),
    ];
    $loadProfileHash = hash('sha256', json_encode($loadProfileDimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $measurementDimensions = [
        'source_kind' => 'platform_telemetry',
        'source_sha256' => str_repeat('3', 64),
        'metric_set_sha256' => str_repeat('4', 64),
    ];
    $measurementContractHash = hash('sha256', json_encode($measurementDimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $workerReferences = [];
    foreach (LoadSoakEvidenceVerifier::WORKER_ROLES as $role) {
        $workerReferences[$role] = hash('sha256', 'worker-'.$role);
    }
    $listenerReferences = [];
    foreach (LoadSoakEvidenceVerifier::LISTENER_ROLES as $role) {
        $listenerReferences[$role] = hash('sha256', 'listener-'.$role);
    }
    $workerStates = array_fill_keys(LoadSoakEvidenceVerifier::WORKER_ROLES, 'available');
    $listenerStates = array_fill_keys(LoadSoakEvidenceVerifier::LISTENER_ROLES, 'available');
    $dependencies = [
        'mysql' => 'available',
        'object_storage' => 'available',
        'redis' => 'available',
        'secret_manager' => 'available',
        'time_series' => 'available',
    ];
    $samples = [];
    for ($minute = 1; $minute <= 60; $minute++) {
        $observedAt = $start->modify("+{$minute} minutes");
        $windowStartedAt = $start->modify('+'.($minute - 1).' minutes');
        $samples[] = [
            'observed_at' => $observedAt->format('Y-m-d\TH:i:s\Z'),
            'processed_events' => $minute * 600,
            'latency_p95_ms' => 150.0,
            'latency_p99_ms' => 300.0,
            'error_rate_percent' => 0.1,
            'queue_depth' => 20,
            'supervisor_observation_generation' => 7,
            'workers' => $workerStates,
            'listeners' => $listenerStates,
            'dependencies' => $dependencies,
            'measurement' => [
                'window_started_at' => $windowStartedAt->format('Y-m-d\TH:i:s\Z'),
                'window_ended_at' => $observedAt->format('Y-m-d\TH:i:s\Z'),
                'sample_count' => 600,
                'source_sha256' => $measurementDimensions['source_sha256'],
                'metric_set_sha256' => $measurementDimensions['metric_set_sha256'],
                'observation_sha256' => hash('sha256', 'observation-'.$minute),
            ],
        ];
    }

    return [
        'schema_version' => 2,
        'evidence_class' => 'deployed_monitoring_load_soak_v2',
        'v09_release_evidence' => true,
        'runtime_class' => 'isolated_deployed_release',
        'exercise_kinds' => ['load', 'soak'],
        'run_id' => $runId,
        'release_revision' => str_repeat('a', 40),
        'environment_fingerprint' => str_repeat('b', 64),
        'created_at' => '2026-08-08T19:01:00Z',
        'started_at' => '2026-08-08T18:00:00Z',
        'ended_at' => '2026-08-08T19:00:00Z',
        'load_profile' => [
            ...$loadProfileDimensions,
            'profile_sha256' => $loadProfileHash,
        ],
        'measurement_contract' => [
            ...$measurementDimensions,
            'contract_sha256' => $measurementContractHash,
        ],
        'acceptance_policy' => [
            'approved_at' => '2026-08-08T17:00:00Z',
            'approved_by' => 'release-reviewer',
            'approved_load_profile_sha256' => $loadProfileHash,
            'approved_measurement_contract_sha256' => $measurementContractHash,
            'min_duration_seconds' => 3600,
            'min_throughput_per_second' => 9.0,
            'max_latency_p95_ms' => 500.0,
            'max_latency_p99_ms' => 1000.0,
            'max_error_rate_percent' => 0.5,
            'max_queue_depth' => 100,
            'max_recovery_seconds' => 120,
            'max_sample_interval_seconds' => 60,
        ],
        'runtime_roster' => [
            'supervisor_observation_generation' => 7,
            'workers' => $workerReferences,
            'listeners' => $listenerReferences,
        ],
        'generator' => [
            'exit_code' => 0,
            'counter_run_id' => $runId,
            'baseline_processed_events' => 0,
            'end_processed_events' => 36000,
            'attempted_events' => 36000,
            'successful_events' => 36000,
            'failed_events' => 0,
            'producer_sha256' => str_repeat('c', 64),
        ],
        'samples' => $samples,
        'recovery' => [
            'recovered_at' => '2026-08-08T19:01:00Z',
            'processed_events' => 36000,
            'error_rate_percent' => 0.0,
            'queue_depth' => 0,
            'supervisor_observation_generation' => 7,
            'workers' => $workerStates,
            'listeners' => $listenerStates,
            'dependencies' => $dependencies,
            'measurement' => [
                'window_started_at' => '2026-08-08T19:00:00Z',
                'window_ended_at' => '2026-08-08T19:01:00Z',
                'sample_count' => 10,
                'source_sha256' => $measurementDimensions['source_sha256'],
                'metric_set_sha256' => $measurementDimensions['metric_set_sha256'],
                'observation_sha256' => hash('sha256', 'recovery-observation'),
            ],
        ],
    ];
}

/** @return array{attestation: array<string, mixed>, public: string, pin: string} */
function signedLoadSoakAttestation(array $evidence, string $rawEvidence): array
{
    $keypair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($keypair);
    $secret = sodium_crypto_sign_secretkey($keypair);
    $claims = [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_load_soak_platform_attestation_v1',
        'source_sha256' => hash('sha256', $rawEvidence),
        'run_id' => $evidence['run_id'],
        'release_revision' => $evidence['release_revision'],
        'environment_fingerprint' => $evidence['environment_fingerprint'],
        'runtime_class' => $evidence['runtime_class'],
        'load_profile_sha256' => $evidence['load_profile']['profile_sha256'],
        'measurement_contract_sha256' => $evidence['measurement_contract']['contract_sha256'],
        'supervisor_observation_generation' => $evidence['runtime_roster']['supervisor_observation_generation'],
        'issued_at' => '2026-08-08T19:01:00Z',
        'expires_at' => '2027-08-08T19:01:00Z',
    ];
    $signature = sodium_crypto_sign_detached(
        LoadSoakPlatformAttestationVerifier::message($claims),
        $secret,
    );

    return [
        'attestation' => [...$claims, 'signature_base64' => base64_encode($signature)],
        'public' => base64_encode($public),
        'pin' => hash('sha256', $public),
    ];
}

it('accepts the complete source contract without claiming release provenance', function (): void {
    $result = (new LoadSoakEvidenceVerifier)->verify(
        validLoadSoakEvidence(),
        new DateTimeImmutable('2026-08-08T19:02:00Z'),
    );

    expect($result['status'])->toBe('contract_valid')
        ->and($result['release_provenance_verified'])->toBeFalse()
        ->and($result['violations_count'])->toBe(0)
        ->and($result['observed_duration_seconds'])->toBe(3600)
        ->and($result['achieved_throughput_per_second'])->toBe(10.0)
        ->and($result['sample_count'])->toBe(60)
        ->and(array_values(array_unique($result['checks'])))->toBe([true]);
});

it('fails closed for sparse samples degraded runtime and postdated weak policy', function (): void {
    $evidence = validLoadSoakEvidence();
    $evidence['acceptance_policy']['approved_at'] = '2026-08-08T18:30:00Z';
    $evidence['acceptance_policy']['min_duration_seconds'] = 60;
    $evidence['samples'][30]['observed_at'] = '2026-08-08T18:32:30Z';
    $evidence['samples'][31]['workers']['checks'] = 'unavailable';
    $evidence['samples'][32]['latency_p99_ms'] = 1001.0;
    $evidence['recovery']['queue_depth'] = 1;

    $result = (new LoadSoakEvidenceVerifier)->verify(
        $evidence,
        new DateTimeImmutable('2026-08-08T19:02:00Z'),
    );

    expect($result['status'])->toBe('failed')
        ->and($result['checks']['preapproved_objective_profile_and_measurement_policy'])->toBeFalse()
        ->and($result['checks']['continuous_runtime_sampling'])->toBeFalse()
        ->and($result['checks']['all_samples_within_objectives'])->toBeFalse()
        ->and($result['checks']['measurement_provenance_and_sample_counts'])->toBeFalse()
        ->and($result['checks']['complete_worker_listener_dependency_roster'])->toBeFalse()
        ->and($result['checks']['bounded_zero_backlog_recovery'])->toBeFalse();
});

it('rejects future recovery fractional time and a post-run record created before recovery', function (Closure $mutate): void {
    $evidence = validLoadSoakEvidence();
    $mutate($evidence);

    $result = (new LoadSoakEvidenceVerifier)->verify(
        $evidence,
        new DateTimeImmutable('2026-08-08T19:02:00Z'),
    );

    expect($result['status'])->toBe('failed')
        ->and($result['checks']['utc_chronology_and_duration'])->toBeFalse();
})->with([
    'created before recovery' => fn (array &$value) => $value['created_at'] = '2026-08-08T19:00:00Z',
    'future recovery' => function (array &$value): void {
        $value['recovery']['recovered_at'] = '2026-08-08T19:04:00Z';
        $value['recovery']['measurement']['window_ended_at'] = '2026-08-08T19:04:00Z';
        $value['created_at'] = '2026-08-08T19:04:00Z';
    },
    'fractional source time' => fn (array &$value) => $value['started_at'] = '2026-08-08T18:00:00.000001Z',
]);

it('rejects substituted roster identities counter history profiles and measurement provenance', function (Closure $mutate, string $check): void {
    $evidence = validLoadSoakEvidence();
    $mutate($evidence);
    $result = (new LoadSoakEvidenceVerifier)->verify(
        $evidence,
        new DateTimeImmutable('2026-08-08T19:02:00Z'),
    );

    expect($result['status'])->toBe('failed')
        ->and($result['checks'][$check])->toBeFalse();
})->with([
    'duplicate runtime reference' => [
        function (array &$value): void {
            $value['runtime_roster']['workers']['checks'] = $value['runtime_roster']['workers']['events'];
        },
        'exact_distinct_supervisor_runtime_roster',
    ],
    'unknown worker role' => [
        function (array &$value): void {
            unset($value['runtime_roster']['workers']['checks']);
            $value['runtime_roster']['workers']['default'] = str_repeat('f', 64);
        },
        'exact_distinct_supervisor_runtime_roster',
    ],
    'nonzero inherited counter' => [
        fn (array &$value) => $value['generator']['baseline_processed_events'] = 1000,
        'generator_scoped_zero_baseline_totals_and_exit',
    ],
    'counter from another run' => [
        fn (array &$value) => $value['generator']['counter_run_id'] = '123e4567-e89b-42d3-a456-426614174001',
        'generator_scoped_zero_baseline_totals_and_exit',
    ],
    'profile dimension changed after approval' => [
        fn (array &$value) => $value['load_profile']['concurrency'] = 5,
        'preapproved_objective_profile_and_measurement_policy',
    ],
    'overflowing scheduled event total' => [
        function (array &$value): void {
            $value['load_profile']['scheduled_rate_per_second'] = 1.0e308;
            $dimensions = [
                'generator_mode' => $value['load_profile']['generator_mode'],
                'concurrency' => $value['load_profile']['concurrency'],
                'scheduled_rate_per_second' => 1.0e308,
                'event_class_count' => $value['load_profile']['event_class_count'],
                'event_mix_sha256' => $value['load_profile']['event_mix_sha256'],
                'target_scope_sha256' => $value['load_profile']['target_scope_sha256'],
            ];
            $hash = hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $value['load_profile']['profile_sha256'] = $hash;
            $value['acceptance_policy']['approved_load_profile_sha256'] = $hash;
        },
        'generator_scoped_zero_baseline_totals_and_exit',
    ],
    'zero metric sample count' => [
        fn (array &$value) => $value['samples'][0]['measurement']['sample_count'] = 0,
        'measurement_provenance_and_sample_counts',
    ],
]);

it('rejects duplicate JSON object keys including escaped equivalents', function (string $json): void {
    expect(fn () => (new StrictJsonObjectDecoder)->decode($json))
        ->toThrow(InvalidArgumentException::class, 'duplicate object key');
})->with([
    'literal duplicate' => ['{"run_id":"first","run_id":"second"}'],
    'escaped duplicate' => ['{"run_id":"first","run_\u0069d":"second"}'],
    'nested duplicate' => ['{"generator":{"exit_code":1,"exit_code":0}}'],
]);

it('requires an exact pinned Ed25519 authority and source binding', function (): void {
    $evidence = validLoadSoakEvidence();
    $raw = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signed = signedLoadSoakAttestation($evidence, $raw);
    $verifier = new LoadSoakPlatformAttestationVerifier;
    $verifiedAt = new DateTimeImmutable('2026-08-08T19:02:00Z');

    $valid = $verifier->verify(
        $signed['attestation'],
        hash('sha256', $raw),
        $evidence,
        $signed['public'],
        $signed['pin'],
        $verifiedAt,
    );
    $wrongPin = $verifier->verify(
        $signed['attestation'],
        hash('sha256', $raw),
        $evidence,
        $signed['public'],
        str_repeat('0', 64),
        $verifiedAt,
    );
    $wrongSource = $verifier->verify(
        $signed['attestation'],
        str_repeat('0', 64),
        $evidence,
        $signed['public'],
        $signed['pin'],
        $verifiedAt,
    );
    $tamperedAttestation = $signed['attestation'];
    $tamperedSignature = base64_decode($tamperedAttestation['signature_base64'], true);
    $tamperedSignature[0] = chr(ord($tamperedSignature[0]) ^ 1);
    $tamperedAttestation['signature_base64'] = base64_encode($tamperedSignature);
    $invalidSignature = $verifier->verify(
        $tamperedAttestation,
        hash('sha256', $raw),
        $evidence,
        $signed['public'],
        $signed['pin'],
        $verifiedAt,
    );

    expect($valid['valid'])->toBeTrue()
        ->and($valid['public_key_sha256'])->toBe($signed['pin'])
        ->and($wrongPin['valid'])->toBeFalse()
        ->and($wrongSource['valid'])->toBeFalse()
        ->and($invalidSignature['valid'])->toBeFalse();
});

it('writes a collision-safe test-authority artifact that cannot claim V09 release provenance', function (): void {
    $root = dirname(__DIR__, 3);
    $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-load-soak-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700, true);
    $evidence = validLoadSoakEvidence();
    $rawEvidence = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signed = signedLoadSoakAttestation($evidence, $rawEvidence);
    $evidencePath = $temporary.DIRECTORY_SEPARATOR.'evidence.json';
    $attestationPath = $temporary.DIRECTORY_SEPARATOR.'attestation.json';
    $publicKeyPath = $temporary.DIRECTORY_SEPARATOR.'public-key.txt';
    file_put_contents($evidencePath, $rawEvidence);
    file_put_contents($attestationPath, json_encode($signed['attestation'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    file_put_contents($publicKeyPath, $signed['public']);

    try {
        $process = new Process([
            PHP_BINARY,
            $root.'/scripts/monitoring/verify-load-soak-evidence.php',
            '--evidence='.$evidencePath,
            '--attestation='.$attestationPath,
            '--public-key='.$publicKeyPath,
            '--output-directory='.$temporary,
            '--test-authority',
        ], $root, [
            'MONITORING_LOAD_SOAK_ATTESTATION_PUBLIC_KEY_SHA256' => $signed['pin'],
        ]);
        $process->run();

        $output = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $artifacts = glob($temporary.DIRECTORY_SEPARATOR.'monitoring-load-soak-verification-*.json');

        expect($process->getExitCode())->toBe(0)
            ->and($output['status'])->toBe('contract_valid_test_authority')
            ->and($output['authority_scope'])->toBe('test_only')
            ->and($output['release_provenance_verified'])->toBeFalse()
            ->and($artifacts)->toHaveCount(1);

        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, 32, JSON_THROW_ON_ERROR);
        expect($artifact['source_sha256'])->toBe(hash_file('sha256', $evidencePath))
            ->and($artifact['source_contract_status'])->toBe('contract_valid')
            ->and($artifact['platform_attestation_verified'])->toBeTrue()
            ->and($artifact['v09_release_evidence'])->toBeFalse()
            ->and($artifact['local_fixture_can_close_v09'])->toBeFalse()
            ->and($artifact['test_authority_can_close_v09'])->toBeFalse()
            ->and($artifact['output_storage_semantics'])->toBe('collision_safe_exclusive_create')
            ->and($artifact['worm_receipt_verified'])->toBeFalse()
            ->and($artifact)->not->toHaveKeys(['target', 'hostname', 'credential', 'payload']);
    } finally {
        foreach (glob($temporary.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($temporary);
    }
});
