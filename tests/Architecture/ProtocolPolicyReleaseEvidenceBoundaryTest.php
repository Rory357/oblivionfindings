<?php

use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('requires protected signed A07 and A08 evidence linked to the S10 sustained run', function (): void {
    $authority = (string) file_get_contents(base_path('app/Support/Monitoring/ProtocolPolicyReleaseAuthorityVerifier.php'));
    $verifier = (string) file_get_contents(base_path('app/Support/Monitoring/ProtocolPolicyReleaseEvidenceVerifier.php'));
    $script = (string) file_get_contents(base_path('scripts/monitoring/verify-protocol-policy-release-evidence.php'));
    $runbook = (string) file_get_contents(base_path('docs/runbooks/monitoring/protocol-policy-release-acceptance.md'));

    expect($authority)->toContain(
        "'/etc/oblivion/monitoring-protocol-policy-release-authority.json'",
        'StrictJsonObjectDecoder',
        "'attestation_public_key_sha256'",
        "'owner_uid'",
        '($metadata[\'mode\'] & 0022) === 0',
        'MAXIMUM_AUTHORITY_SECONDS = 86_400',
        'identitiesRemainPinned',
    );

    expect($verifier)->toContain(
        "'security_devices_s10_release_evidence_v1'",
        "'collision_safe_exclusive_create'",
        "'canonical_paired_trackers'",
        "'fresh_trackers_observed'",
        "'monitoring_protocol_policy_release_evidence_v1'",
        'sodium_crypto_sign_verify_detached',
        "'provider_milesight'",
        "'provider_unifi'",
        "'snmp_traps'",
        "'ssh_read_only'",
        "'winrm_read_only'",
        "'maintenance'",
        "'stale_unknown'",
        "'transition_drills'",
        '$seenReferences',
        '$started >= $during',
        '$during >= $recovered',
        "'notification_storm_count'",
        "'ticket_storm_count'",
        "'supervision_reference_sha256'",
        "'provider_audit_reference_sha256'",
        "'target_side_logs_reference_sha256'",
        'count(array_unique([',
        '], SORT_STRING)) !== 4',
        "(string) \$row['runtime_reference_sha256']",
        "(string) \$row['target_side_reference_sha256']",
        "'signed_review_sha256' => hash('sha256', \$rawEvidence)",
        "'detached_signature_sha256' => hash('sha256', \$encodedSignature)",
        "'a07_release_evidence' => true",
        "'a08_release_evidence' => true",
    );

    expect($script)->toContain(
        "new LoadSoakReleaseCheckoutVerifier('/usr/bin/git')",
        'ProtocolPolicyReleaseAuthorityVerifier',
        'ProtocolPolicyReleaseEvidenceVerifier',
        'S10ReleaseAuthorityVerifier',
        's10Authority: $s10AuthorityBefore',
        's10_release_authority',
        's10_release_authority_changed',
        "'s10-release-evidence'",
        "'output-directory'",
        "'output_directory_protection'",
        'identitiesRemainPinned([$authorityBefore, $authorityAfter])',
        'identitiesRemainPinned([$authorityBefore, $authorityAfter, $authorityFinal])',
        "'release_identity_changed'",
        '$inputsRemainPinned($inputs)',
        "'evidence_changed'",
        "'collision_safe_exclusive_create'",
        "'worm_receipt_verified' => false",
        "@fopen(\$path, 'x+b')",
        '@chmod($path, 0600)',
        "function_exists('fsync')",
        '$artifactPublished = false',
        '$checksumPublished = false',
        '$publishedRemainsExact = static function',
        'hash_equals($expected, $contents)',
        '$publishedRemainsExact($artifactPath, $encoded)',
        '$publishedRemainsExact($checksumPath, $checksumEncoded)',
        '...$report,',
        "'artifact_sha256'",
        "'checksum_file'",
    )->not->toContain('--authority=', 'getenv(', 'vendor/autoload.php');

    expect($runbook)->toContain(
        'The six-field child result is not A07/A08 release evidence',
        '/etc/oblivion/monitoring-protocol-policy-release-authority.json',
        'monitoring_protocol_policy_release_evidence_v1',
        'verify-protocol-policy-release-evidence.php',
        'exact S10',
        'combined artifact.',
        'All 18 transition references must be globally',
        'four top-level evidence-class references are pairwise distinct',
        'runtime reference must differ from its target-side reference',
        'timestamps must strictly advance',
        '--output-directory=',
        'exact mode `0700`',
        'mode-`0600` value-free',
        'matching `.sha256` sidecar',
        '`worm_receipt_verified=false`',
        'reopens both outputs',
        'rechecks the S10 artifact',
        'exact raw signed-review and',
        'detached-signature SHA-256 values',
        'separately installed protected S10',
        'stable S10-shaped JSON file is not sufficient',
        'The verifier does not',
        'itself close A07 or A08',
    );
});
