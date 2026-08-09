<?php

use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('packages the database-free collector as one hardened non-overlapping systemd cycle', function () {
    $service = (string) file_get_contents(base_path('ops/systemd/oblivion-monitoring-collector.service'));
    $timer = (string) file_get_contents(base_path('ops/systemd/oblivion-monitoring-collector.timer'));
    $runner = (string) file_get_contents(base_path('ops/systemd/oblivion-monitoring-collector-run'));
    $environment = (string) file_get_contents(base_path('ops/systemd/monitoring-collector.env.example'));

    expect($service)->toContain(
        'Type=oneshot',
        'User=oblivion-monitoring-collector',
        'Group=oblivion-monitoring-collector',
        'EnvironmentFile=/etc/oblivion/monitoring-collector.env',
        'ExecStart=/usr/local/libexec/oblivion-monitoring-collector-run',
        'StateDirectory=oblivion-monitoring-collector',
        'Restart=on-failure',
        'NoNewPrivileges=true',
        'ProtectSystem=strict',
        'ProtectHome=true',
        'CapabilityBoundingSet=',
    )->not->toContain('DB_', 'mysql', 'Restart=always');

    expect($timer)->toContain(
        'OnUnitInactiveSec=60s',
        'Persistent=false',
        'Unit=oblivion-monitoring-collector.service',
        'WantedBy=timers.target',
    );

    expect($runner)->toContain(
        'flock -n 9',
        'run \\',
        '"--identity=$OBLIVION_COLLECTOR_IDENTITY_FILE"',
        '"--config=$OBLIVION_COLLECTOR_CONFIG_FILE"',
    )->not->toContain(' enrol ', 'ENROLMENT_TOKEN', 'DB_');

    expect($environment)->toContain(
        'OBLIVION_COLLECTOR_PHP_BINARY=',
        'OBLIVION_COLLECTOR_ARTIFACT_DIR=',
        'OBLIVION_COLLECTOR_IDENTITY_FILE=',
        'OBLIVION_COLLECTOR_CONFIG_FILE=',
    )->not->toContain('ENROLMENT_TOKEN', 'DB_', 'PASSWORD=', 'PRIVATE_KEY=');
});

it('installs the collector timer only after fail-closed runtime and identity checks', function () {
    $installer = (string) file_get_contents(base_path('scripts/monitoring/install-collector-systemd.sh'));

    expect($installer)->toContain(
        '[[ "$EUID" -eq 0 ]]',
        'PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4',
        '["curl", "json", "openssl", "sockets", "sodium"]',
        'vendor/autoload.php',
        '--shell /usr/sbin/nologin',
        'collector identity is missing;',
        '"client_certificate_fingerprint"',
        'openssl_x509_fingerprint($certificate, "sha256")',
        'openssl_pkey_get_private($privateKeyPem)',
        'openssl_x509_check_private_key($certificate, $privateKey)',
        '"oblivion-collector-".$identity["collector_id"]',
        '$now < $parsed["validFrom_time_t"]',
        '$now >= $parsed["validTo_time_t"]',
        'chmod 0600 "$IDENTITY_FILE"',
        'systemctl daemon-reload',
        'systemctl enable --now oblivion-monitoring-collector.timer',
    )->not->toContain(
        'oblivion-collector enrol',
        'OBLIVION_COLLECTOR_ENROLMENT_TOKEN',
        'composer install',
        'DB_',
    );
});

it('ships a value-free pinned HTTPS response-contract probe', function () {
    $application = (string) file_get_contents(base_path('collector/src/CollectorApplication.php'));
    $https = (string) file_get_contents(base_path('collector/src/Http/HttpsCentralApi.php'));
    $readme = (string) file_get_contents(base_path('collector/README.md'));
    $runbook = (string) file_get_contents(base_path('docs/runbooks/monitoring/collector-outage-and-revocation.md'));

    expect($application)->toContain(
        "'verify-transport' => \$this->verifyTransport(\$options)",
        "['active', 'revoked']",
        "['samples'] ?? '5'",
        'json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)',
        'openssl_x509_check_private_key($certificate, $privateKey)',
        'sodium_memzero($secretKey)',
    );

    expect($https)->toContain(
        "'after_sequence' => 'transport-evidence-only'",
        '$replay = $this->send($method, $path, $body, $headers, true)',
        "assertEvidenceResponse(\$first, 422, 'Collector request is invalid.')",
        "assertEvidenceResponse(\$replay, 401, 'Collector authentication failed.')",
        "assertEvidenceResponse(\$first, 401, 'Collector authentication failed.')",
        "'state' => 'response_contract_matched'",
        "'expected_identity_state' => \$expectedIdentityState",
        "'pinned_https_contract' => 'matched'",
        "'initial_response' => \$expectedIdentityState === 'active'",
        "'replay_attempt' => \$expectedIdentityState === 'active'",
        'CURLOPT_SSL_VERIFYPEER => true',
        'CURLOPT_SSL_VERIFYHOST => 2',
        'CURLOPT_PINNEDPUBLICKEY => $this->tlsPublicKeyPin',
        'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS',
        '$options[CURLOPT_SSLCERT] = $this->clientCertificateFile',
        '$options[CURLOPT_SSLKEY] = $this->clientPrivateKeyFile',
    );

    expect($readme)->toContain(
        'bounded deployment-evidence probe',
        'short-lived nonce',
        'reservations in the configured replay store',
        'does not issue configuration',
        'run checks, upload observations',
        'does not by itself prove',
        'shared Redis',
    );

    expect($runbook)->toContain(
        '## Deployed acceptance sequence',
        '--expect=active',
        '--expect=revoked',
        '--samples=5',
        'same shared Redis',
        'accepted/replayed pair crossed different',
        'proves replay rejection only',
        'does not prove why authentication was denied',
        'credentialed remote protocol',
        'exact revoked collector UUID',
    );
});

it('binds the complete collector exercise to one protected release authority', function () {
    $authority = (string) file_get_contents(base_path('app/Support/Monitoring/CollectorReleaseAuthorityVerifier.php'));
    $verifier = (string) file_get_contents(base_path('app/Support/Monitoring/CollectorReleaseEvidenceVerifier.php'));
    $script = (string) file_get_contents(base_path('scripts/monitoring/verify-collector-release-evidence.php'));
    $runbook = (string) file_get_contents(base_path('docs/runbooks/monitoring/collector-outage-and-revocation.md'));

    expect($authority)->toContain(
        "'/etc/oblivion/monitoring-collector-release-authority.json'",
        'StrictJsonObjectDecoder',
        "'owner_uid'",
        '($metadata[\'mode\'] & 0022) === 0',
        "'attestation_public_key_sha256'",
        "'remote_site_reference_sha256'",
        "'load_balancer_reference_sha256'",
        'MAXIMUM_AUTHORITY_SECONDS = 86_400',
        'identitiesRemainPinned',
    );

    expect($verifier)->toContain(
        "'oblivion-collector-transport-evidence-v2'",
        "'monitoring_collector_release_evidence_v1'",
        'sodium_crypto_sign_verify_detached',
        "'samples'] ?? null) !== 5",
        "'same_shared_redis_verified'",
        "'mtls_header_replacement_verified'",
        "'legacy_fingerprint_header_disabled'",
        "'cross_instance_replay_reference_sha256'",
        "'reviewed_at'",
        "'exactly_one_root_correlation'",
        "'pinned_monitor_roster_sha256'",
        "'post_boundary_observations'",
        "'roster_drift_negative_reference_sha256'",
        "['snmpv3', 'ssh_read_only', 'winrm_approved']",
        "'observed_at'",
        "'old_identity_forwarded_and_denied'",
        "'revoked_at'",
        "'replacement_issued_at'",
        "'replacement_consumed_at'",
        "'service_restored_at'",
        "'replacement_token_reuse_denial_reference_sha256'",
        "'general_site_token_denial_reference_sha256'",
        "'collector_release_evidence' => true",
    );

    expect($script)->toContain(
        "new LoadSoakReleaseCheckoutVerifier('/usr/bin/git')",
        'CollectorReleaseAuthorityVerifier',
        'CollectorReleaseEvidenceVerifier',
        "'active-transport'",
        "'revoked-transport'",
        "'replacement-transport'",
        'identitiesRemainPinned([$authorityBefore, $authorityAfter])',
        "'release_identity_changed'",
    )->not->toContain('--authority=', 'getenv(');

    expect($runbook)->toContain(
        '/etc/oblivion/monitoring-collector-release-authority.json',
        'monitoring_collector_release_evidence_v1',
        'verify-collector-release-evidence.php',
        'protected authority, environment, revision, remote Site and load balancer',
        'close A03.',
    );
});
