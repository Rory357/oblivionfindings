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
        "'notification_storm_count'",
        "'ticket_storm_count'",
        "'supervision_reference_sha256'",
        "'provider_audit_reference_sha256'",
        "'target_side_logs_reference_sha256'",
        "'a07_release_evidence' => true",
        "'a08_release_evidence' => true",
    );

    expect($script)->toContain(
        "new LoadSoakReleaseCheckoutVerifier('/usr/bin/git')",
        'ProtocolPolicyReleaseAuthorityVerifier',
        'ProtocolPolicyReleaseEvidenceVerifier',
        "'s10-release-evidence'",
        'identitiesRemainPinned([$authorityBefore, $authorityAfter])',
        "'release_identity_changed'",
    )->not->toContain('--authority=', 'getenv(', 'vendor/autoload.php');

    expect($runbook)->toContain(
        'The six-field child result is not A07/A08 release evidence',
        '/etc/oblivion/monitoring-protocol-policy-release-authority.json',
        'monitoring_protocol_policy_release_evidence_v1',
        'verify-protocol-policy-release-evidence.php',
        'exact S10',
        'combined artifact.',
        'does not itself close A07 or A08',
    );
});
