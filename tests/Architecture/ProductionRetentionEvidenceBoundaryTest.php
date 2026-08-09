<?php

it('binds A05 release evidence to the fixed protected authority before any endpoint or retention work', function (): void {
    $root = dirname(__DIR__, 2);
    $command = (string) file_get_contents(
        $root.'/app/Console/Commands/RecordProductionMonitoringRetentionEvidence.php',
    );
    $authority = (string) file_get_contents(
        $root.'/app/Domain/Monitoring/Services/ProductionRetentionReleaseAuthority.php',
    );
    $attestation = (string) file_get_contents(
        $root.'/app/Domain/Monitoring/Services/ProductionRetentionEndpointAttestation.php',
    );
    $writer = (string) file_get_contents(
        $root.'/app/Domain/Monitoring/Services/ProductionRetentionEvidenceArtifactWriter.php',
    );
    $runbook = (string) file_get_contents(
        $root.'/docs/runbooks/monitoring/production-retention-acceptance.md',
    );

    $authorityReads = substr_count($command, '$releaseAuthority->loadInstalled()');
    $firstAuthorityRead = strpos($command, '$releaseAuthority->loadInstalled()');
    $finalAuthorityRead = strrpos($command, '$releaseAuthority->loadInstalled()');
    $checkoutChecks = substr_count(
        $command,
        '$releaseCheckout->verify(base_path(), $authority[\'release_revision\'])',
    );
    $firstCheckoutCheck = strpos(
        $command,
        '$releaseCheckout->verify(base_path(), $authority[\'release_revision\'])',
    );
    $endpointProbe = strpos($command, '$endpointProbe->fingerprints($settings)');
    $downsample = strpos($command, '(new DownsampleMetrics)->handle');
    $writerCall = strpos($command, '$writer->write($outputDirectory, $report, $authority[\'public_key\'])');

    expect($authority)
        ->toContain(
            "AUTHORITY_PATH = '/etc/oblivion/monitoring-retention-release-authority.json'",
            "PHP_OS_FAMILY !== 'Linux'",
            'new StrictJsonObjectDecoder',
            "(\$metadata['owner_uid'] ?? null) === 0",
            '($mode & 0022) === 0',
            "'stable_identity'",
        )
        ->and($command)->toContain(
            'return $this->finish([\'release_authority_invalid\'])',
            'return $this->finish([\'release_checkout_invalid\'])',
            "'release_authority_changed_or_expired'",
            "'release_checkout_changed'",
            'use App\\Support\\Monitoring\\LoadSoakReleaseCheckoutVerifier;',
            '$authority[\'release_revision\']',
            '$authority[\'public_key\']',
        )
        ->and($command)->not->toContain(
            "getenv('OBLIVION_RELEASE_REVISION')",
            'MONITORING_A05_ATTESTATION_PUBLIC_KEY',
        )
        ->and($attestation.$writer)->not->toContain(
            'production_evidence_attestation_public_key',
            'MONITORING_A05_ATTESTATION_PUBLIC_KEY',
        )
        ->and($authorityReads)->toBe(2)
        ->and($firstAuthorityRead)->toBeInt()
        ->and($finalAuthorityRead)->toBeInt()
        ->and($checkoutChecks)->toBe(2)
        ->and($firstCheckoutCheck)->toBeInt()
        ->and($endpointProbe)->toBeInt()
        ->and($downsample)->toBeInt()
        ->and($writerCall)->toBeInt()
        ->and($firstAuthorityRead)->toBeLessThan($endpointProbe)
        ->and($firstCheckoutCheck)->toBeLessThan($endpointProbe)
        ->and($endpointProbe)->toBeLessThan($downsample)
        ->and(strrpos(
            $command,
            '$releaseCheckout->verify(base_path(), $authority[\'release_revision\'])',
        ))->toBeLessThan($writerCall)
        ->and($finalAuthorityRead)->toBeGreaterThan($downsample)
        ->and($finalAuthorityRead)->toBeLessThan($writerCall)
        ->and($runbook)->toContain(
            '`/etc/oblivion/monitoring-retention-release-authority.json`',
            'There is no command-line or environment override for this path.',
            'process environment cannot substitute either trust input',
            'clean source checkout whose `HEAD` and `origin/main` both equal the protected authority revision',
            're-reads the fixed authority immediately before artifact publication',
        );
});
