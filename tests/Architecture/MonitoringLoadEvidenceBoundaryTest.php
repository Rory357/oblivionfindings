<?php

it('keeps local synthetic monitoring performance artifacts ineligible for V09 closure', function (): void {
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root.'/tests/Performance/Monitoring/MonitoringLoadTest.php');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/monitoring/load-and-soak-evidence.md');
    $script = (string) file_get_contents($root.'/scripts/monitoring/verify-load-soak-evidence.php');
    $verifier = (string) file_get_contents($root.'/app/Support/Monitoring/LoadSoakEvidenceVerifier.php');
    $attestation = (string) file_get_contents($root.'/app/Support/Monitoring/LoadSoakPlatformAttestationVerifier.php');
    $releaseAuthority = (string) file_get_contents($root.'/app/Support/Monitoring/LoadSoakReleaseAuthorityVerifier.php');
    $releaseCheckout = (string) file_get_contents($root.'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php');
    $strictJson = (string) file_get_contents($root.'/app/Support/Monitoring/StrictJsonObjectDecoder.php');

    expect($source)->toContain(
        "'scale_profile' => \$writeEvidence ? 'full_scale' : 'smoke'",
        "'evidence_contract' => 'monitoring-local-synthetic-v1'",
        "'artifact_id' => \$artifactId",
        "'evidence_classification' => 'local_synthetic_fixture'",
        "'execution_scope' => 'test_process_only'",
        "'deployed_runtime_observed' => false",
        "'soak_duration_proven' => false",
        "'v09_release_evidence' => false",
        "fopen(\$path, 'xb')",
    );

    expect($script)->toContain(
        "'/app/Support/Monitoring/StrictJsonObjectDecoder.php'",
        "'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php'",
        'is_link($path) || ! is_file($path)',
        'require_once $path',
        "getenv('MONITORING_LOAD_SOAK_ATTESTATION_PUBLIC_KEY_SHA256')",
        'if ($testAuthority)',
        "'--test-authority'",
        "'contract_valid_test_authority'",
        '(new LoadSoakReleaseAuthorityVerifier)->verifyInstalled(',
        "\$releaseAuthorityValid => 'release_platform'",
        "default => 'unverified'",
        "'release_authority_verified' => \$releaseAuthorityValid",
        "'release_authority_reference' => \$releaseAuthority['authority_reference']",
        '&& $releaseAuthorityValid',
        '(new LoadSoakReleaseCheckoutVerifier)->verify(',
        '&& $releaseCheckoutValid',
        "'release_checkout_verified' => \$releaseCheckoutValid",
        "'release_provenance_verified' => \$releaseProvenance",
        "'v09_release_evidence' => \$releaseProvenance",
        "fopen(\$artifactPath, 'x+b')",
        "'output_storage_semantics' => 'collision_safe_exclusive_create'",
        "'worm_receipt_verified' => false",
        "'local_fixture_can_close_v09' => false",
        "'test_authority_can_close_v09' => false",
    )->not->toContain(
        'vendor/autoload.php',
        'file_put_contents',
        'MONITORING_LOAD_SOAK_AUTHORITY_PATH',
        '--authority=',
        "'contains_targets_credentials_or_payloads' => false",
        "'output_storage_semantics' => 'immutable'",
    );

    expect($releaseAuthority)->toContain(
        "AUTHORITY_PATH = '/etc/oblivion/monitoring-load-soak-authority.json'",
        "PHP_OS_FAMILY !== 'Linux'",
        'is_link(self::AUTHORITY_PATH)',
        '@lstat(self::AUTHORITY_PATH)',
        "@fopen(self::AUTHORITY_PATH, 'rb')",
        '($mode & 0170000) === 0100000',
        '($mode & 0022) === 0',
        "(\$metadata['owner_uid'] ?? null) === 0",
        '(new StrictJsonObjectDecoder)->decode($rawAuthority)',
        "'monitoring_load_soak_release_authority_v1'",
        "'attestation_public_key_sha256'",
        "'release_revision'",
        "'environment_reference_sha256'",
        "'source_sha256'",
        "'attestation_sha256'",
        "'not_before'",
        "'not_after'",
        "'authority_reference'",
        'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES',
        'hash_equals($expectedPublicKeySha256, $publicKeySha256)',
        '$verifiedAt >= $notBefore',
        '$verifiedAt <= $notAfter',
    )->not->toContain(
        'getenv(',
        '$_ENV',
        '$_SERVER',
    );

    expect($attestation)->toContain(
        'sodium_crypto_sign_verify_detached',
        "hash('sha256', \$publicKey)",
        'hash_equals($expectedPublicKeySha256, $publicKeyHash)',
        "'monitoring_load_soak_platform_attestation_v1'",
        "'source_sha256'",
        "'load_profile_sha256'",
        "'measurement_contract_sha256'",
        "'supervisor_observation_generation'",
        '$issuedAt >= $createdAt',
        '$verifiedAt <= $expiresAt',
    );

    expect($releaseCheckout)->toContain(
        'proc_open(',
        "'bypass_shell' => true",
        "'suppress_errors' => true",
        'proc_get_status($process)',
        'microtime(true) + 10',
        '@proc_terminate($process)',
        "['rev-parse', '--is-inside-work-tree']",
        "['rev-parse', '--show-toplevel']",
        "['rev-parse', '--verify', 'HEAD']",
        "['rev-parse', '--verify', 'refs/remotes/origin/main']",
        "['status', '--porcelain=v1', '--untracked-files=all']",
        '$this->gitProcessEnvironment()',
        "! str_starts_with(strtoupper(\$key), 'GIT_')",
        "'GIT_DIR'",
        "'GIT_INDEX_FILE'",
        "'GIT_WORK_TREE'",
        "'GIT_CONFIG_COUNT'",
        "\$environment['GIT_OPTIONAL_LOCKS'] = '0'",
        "'core.fsmonitor=false'",
        "'core.untrackedCache=false'",
        "private readonly string \$gitBinary = '/usr/bin/git'",
        'private function protectedGitBinary(): bool',
        "\$this->gitBinary !== '/usr/bin/git'",
        '($mode & 0022) === 0',
        "(\$metadata['uid'] ?? null) === 0",
        '$head === $expectedRevision',
        '$originMain === $expectedRevision',
        "\$status === ''",
    )->not->toContain(
        'Symfony\\Component\\Process',
        'git reset',
        'git clean',
        'git checkout',
        'git pull',
        'git fetch',
    );

    expect($strictJson)->toContain(
        'JSON evidence contains a duplicate object key.',
        'array_key_exists($key, $keys)',
        'json_decode($token, true, 2, JSON_THROW_ON_ERROR)',
    );

    foreach ([
        'checks', 'commands', 'discovery', 'events', 'maintenance', 'orchestration', 'provider', 'topology',
        'flow', 'snmp_traps', 'syslog',
    ] as $role) {
        expect($verifier)->toContain("'{$role}'");
    }

    expect($verifier)->toContain(
        'MINIMUM_DURATION_SECONDS = 3600',
        'MAXIMUM_SAMPLE_INTERVAL_SECONDS = 60',
        "'deployed_monitoring_load_soak_v2'",
        "'contract_valid'",
        "'release_provenance_verified' => false",
        "'approved_load_profile_sha256'",
        "'approved_measurement_contract_sha256'",
        "'baseline_processed_events'",
        "'counter_run_id'",
        '$baseline === 0',
        'abs($attempted - $scheduledEvents) <= $scheduleTolerance',
        'count(array_unique($references)) === 11',
        "'supervisor_observation_generation'",
        "'sample_count'",
        '$endedAt <= $recoveredAt',
        '$recoveredAt <= $createdAt',
        '$createdAt <= $verifiedAt->modify(\'+60 seconds\')',
        "preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z\\z/'",
    );

    expect($runbook)->toContain(
        'These artifacts are prerequisite regression evidence only and cannot',
        '`contract_valid_test_authority`',
        '`release_provenance_verified` and `v09_release_evidence` remain false',
        'independently pinned Ed25519 attestation',
        '`MONITORING_LOAD_SOAK_ATTESTATION_PUBLIC_KEY_SHA256`',
        'Duplicate object keys are forbidden at every depth',
        'Fractional seconds are rejected',
        'exactly these worker roles',
        'Each of the eleven roles maps to a distinct opaque SHA-256 runtime reference.',
        '`baseline_processed_events: 0`',
        'preapproved measurement-contract hash',
        'collision-safe publication, not proof of immutability',
        'must never be presented as V09 or overall release completion by itself',
    );
});
