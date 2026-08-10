<?php

use App\Support\Release\ItSecurityDesktopReleaseEvidenceVerifier;
use Symfony\Component\Process\Process;

/** @return array<string, list<string>> */
function desktopEvidenceActors(): array
{
    return [
        'D01' => ['release-requester'],
        'D02' => ['release-it-manager'],
        'D03' => ['release-it-manager'],
        'D04' => ['release-it-manager'],
        'D05' => ['release-it-manager'],
        'D06' => ['release-it-manager'],
        'D07' => ['release-it-manager'],
        'D08' => ['release-it-manager', 'release-auditor'],
        'D09' => ['release-it-manager'],
        'D10' => ['release-control-room'],
        'D11' => ['release-it-manager'],
        'D12' => ['release-it-manager'],
        'D13' => ['release-it-manager'],
        'D14' => ['release-it-manager'],
        'D15' => ['release-it-manager'],
        'D16' => ['release-it-manager', 'release-it-reviewer'],
        'D17' => ['release-it-manager'],
        'D18' => ['release-denied', 'release-source-denied'],
    ];
}

/** @return array<string, mixed> */
function desktopEvidenceViewport(int $width, int $height, string $verifiedAt, string $seed): array
{
    return [
        'accessibility_report_sha256' => hash('sha256', 'accessibility-'.$seed),
        'actor_session_reference_sha256' => hash('sha256', 'session-'.$seed),
        'capture_archive_reference' => 'CAPTURE-'.substr(hash('sha256', 'capture-reference-'.$seed), 0, 32),
        'capture_archive_sha256' => hash('sha256', 'capture-'.$seed),
        'console_clean' => true,
        'console_log_sha256' => hash('sha256', 'console-'.$seed),
        'height' => $height,
        'keyboard_accessible' => true,
        'network_clean' => true,
        'network_trace_sha256' => hash('sha256', 'network-'.$seed),
        'overflow_free' => true,
        'privacy_scan_passed' => true,
        'route_evidence_count' => 2,
        'status' => 'passed',
        'verified_at_utc' => $verifiedAt,
        'width' => $width,
    ];
}

/** @param list<string> $actors @return array<string, mixed> */
function desktopEvidenceRow(
    string $id,
    array $actors,
    string $verifiedAt,
    string $environmentReference,
    string $scope,
): array {
    return [
        'actors' => $actors,
        'denial_contract_verified' => $id === 'D18',
        'environment_reference_sha256' => $environmentReference,
        'fixture_manifest_sha256' => hash('sha256', 'fixture-'.$scope.'-'.$id),
        'id' => $id,
        'interaction_contract_verified' => true,
        'privacy_contract_verified' => true,
        'result_reference' => 'RESULT-'.substr(hash('sha256', 'result-'.$scope.'-'.$id), 0, 32),
        'route_manifest_sha256' => hash('sha256', 'route-'.$scope.'-'.$id),
        'viewports' => [
            desktopEvidenceViewport(1440, 900, $verifiedAt, $scope.'-'.$id.'-1440x900'),
            desktopEvidenceViewport(1280, 800, $verifiedAt, $scope.'-'.$id.'-1280x800'),
        ],
    ];
}

/** @return array<string, mixed> */
function desktopEvidencePayload(string $releaseRevision, string $primaryEnvironment, string $restoredEnvironment): array
{
    $actors = desktopEvidenceActors();
    $companionEnvironments = [
        'central_runtime' => $primaryEnvironment,
        'collector' => $primaryEnvironment,
        'configuration_history' => $restoredEnvironment,
        'deployment_runtime' => $primaryEnvironment,
        'load_soak' => $primaryEnvironment,
        'local_automated' => $primaryEnvironment,
        'protocol_provider' => $primaryEnvironment,
        'retention' => $primaryEnvironment,
        'storage_restore' => $restoredEnvironment,
    ];
    $companions = [];
    foreach ($companionEnvironments as $name => $environment) {
        $companions[$name] = [
            'environment_reference_sha256' => $environment,
            'evidence_reference' => 'EVIDENCE-'.substr(hash('sha256', $name), 0, 32),
            'evidence_sha256' => hash('sha256', 'evidence-'.$name),
            'release_revision' => $releaseRevision,
            'status' => 'verified',
        ];
    }
    $rows = [];
    foreach ($actors as $id => $rowActors) {
        $rows[] = desktopEvidenceRow(
            $id,
            $rowActors,
            '2026-08-09T02:00:00Z',
            $primaryEnvironment,
            'primary',
        );
    }
    $restoredRows = [];
    foreach (['D07', 'D12', 'D15', 'D18'] as $id) {
        $restoredRows[] = desktopEvidenceRow(
            $id,
            $actors[$id],
            '2026-08-09T02:30:00Z',
            $restoredEnvironment,
            'restored',
        );
    }

    return [
        'browser_version_reference_sha256' => str_repeat('a', 64),
        'companions' => $companions,
        'deployed_at_utc' => '2026-08-09T01:00:00Z',
        'environment_reference_sha256' => $primaryEnvironment,
        'evidence_class' => 'it_security_desktop_release_evidence_v1',
        'release_identifier_reference' => 'RELEASE-'.str_repeat('b', 32),
        'release_revision' => $releaseRevision,
        'restored_environment_reference_sha256' => $restoredEnvironment,
        'restored_rows' => $restoredRows,
        'reviewed_at_utc' => '2026-08-09T03:00:00Z',
        'reviewer_reference' => 'REVIEWER-'.str_repeat('c', 32),
        'rows' => $rows,
        'schema_version' => 1,
    ];
}

/** @param array<string, mixed> $payload */
function signedDesktopEvidence(
    ItSecurityDesktopReleaseEvidenceVerifier $verifier,
    array $payload,
    string $secretKey,
): string {
    return json_encode([
        'payload' => $payload,
        'signature_base64' => base64_encode(sodium_crypto_sign_detached(
            $verifier->canonicalPayload($payload),
            $secretKey,
        )),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('verifies one signed revision-bound complete primary and restored desktop matrix', function (): void {
    $verifier = new ItSecurityDesktopReleaseEvidenceVerifier;
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $releaseRevision = str_repeat('d', 40);
    $primaryEnvironment = str_repeat('e', 64);
    $restoredEnvironment = str_repeat('f', 64);
    $rawAuthority = json_encode([
        'authority_reference' => 'AUTHORITY-'.str_repeat('1', 32),
        'environment_reference_sha256' => $primaryEnvironment,
        'evidence_class' => 'it_security_desktop_release_authority_v1',
        'manifest_public_key_base64' => base64_encode($publicKey),
        'manifest_public_key_reference' => 'KEY-'.str_repeat('2', 32),
        'not_after_utc' => '2026-08-10T00:00:00Z',
        'not_before_utc' => '2026-08-09T00:00:00Z',
        'release_revision' => $releaseRevision,
        'restored_environment_reference_sha256' => $restoredEnvironment,
        'schema_version' => 1,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $authority = $verifier->verifyAuthorityRecord($rawAuthority, [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100600,
        'owner_uid' => 0,
        'stable_identity' => true,
    ], new DateTimeImmutable('2026-08-09T00:30:00Z'));
    $payload = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $rawManifest = signedDesktopEvidence($verifier, $payload, $secretKey);
    $result = $verifier->verifyManifest(
        $rawManifest,
        $authority,
        new DateTimeImmutable('2026-08-09T03:00:30Z'),
    );

    expect($authority['valid'])->toBeTrue()
        ->and($result)->toMatchArray([
            'valid' => true,
            'environment_reference_sha256' => $primaryEnvironment,
            'primary_rows' => 18,
            'primary_viewports' => 36,
            'release_revision' => $releaseRevision,
            'restored_environment_reference_sha256' => $restoredEnvironment,
            'restored_rows' => 4,
            'restored_viewports' => 8,
        ])
        ->and($result['manifest_sha256'])->toBe(hash('sha256', $rawManifest));
});

it('rejects incomplete mixed failed tampered or ambiguous desktop evidence', function (): void {
    $verifier = new ItSecurityDesktopReleaseEvidenceVerifier;
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $releaseRevision = str_repeat('d', 40);
    $primaryEnvironment = str_repeat('e', 64);
    $restoredEnvironment = str_repeat('f', 64);
    $rawAuthority = json_encode([
        'authority_reference' => 'AUTHORITY-'.str_repeat('1', 32),
        'environment_reference_sha256' => $primaryEnvironment,
        'evidence_class' => 'it_security_desktop_release_authority_v1',
        'manifest_public_key_base64' => base64_encode($publicKey),
        'manifest_public_key_reference' => 'KEY-'.str_repeat('2', 32),
        'not_after_utc' => '2026-08-10T00:00:00Z',
        'not_before_utc' => '2026-08-09T00:00:00Z',
        'release_revision' => $releaseRevision,
        'restored_environment_reference_sha256' => $restoredEnvironment,
        'schema_version' => 1,
    ], JSON_THROW_ON_ERROR);
    $authority = $verifier->verifyAuthorityRecord($rawAuthority, [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100600,
        'owner_uid' => 0,
        'stable_identity' => true,
    ], new DateTimeImmutable('2026-08-09T00:30:00Z'));
    $verify = fn (array $payload): bool => ($verifier->verifyManifest(
        signedDesktopEvidence($verifier, $payload, $secretKey),
        $authority,
        new DateTimeImmutable('2026-08-09T03:00:30Z'),
    )['valid'] ?? null) === true;

    $missingRow = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    array_pop($missingRow['rows']);
    $missingViewport = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    array_pop($missingViewport['rows'][0]['viewports']);
    $wrongActors = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $wrongActors['rows'][0]['actors'] = ['release-it-manager'];
    $mixedRevision = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $mixedRevision['companions']['central_runtime']['release_revision'] = str_repeat('0', 40);
    $replayedCompanionReference = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $replayedCompanionReference['companions']['collector']['evidence_reference'] =
        $replayedCompanionReference['companions']['central_runtime']['evidence_reference'];
    $replayedCompanionHash = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $replayedCompanionHash['companions']['collector']['evidence_sha256'] =
        $replayedCompanionHash['companions']['central_runtime']['evidence_sha256'];
    $failedViewport = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $failedViewport['rows'][0]['viewports'][0]['status'] = 'failed';
    $missingRestored = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    array_pop($missingRestored['restored_rows']);
    $primaryRowRelabelledAsRestored = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $primaryRowRelabelledAsRestored['restored_rows'][0] = $primaryRowRelabelledAsRestored['rows'][6];
    $replayedResult = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $replayedResult['rows'][1]['result_reference'] = $replayedResult['rows'][0]['result_reference'];
    $replayedCapture = desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment);
    $replayedCapture['rows'][1]['viewports'][0] = $replayedCapture['rows'][0]['viewports'][0];
    $validRaw = signedDesktopEvidence(
        $verifier,
        desktopEvidencePayload($releaseRevision, $primaryEnvironment, $restoredEnvironment),
        $secretKey,
    );
    $tampered = str_replace(str_repeat('a', 64), str_repeat('0', 64), $validRaw);
    $duplicate = preg_replace('/\A\{"payload":/', '{"payload":{},"payload":', $validRaw, 1);

    expect($verify($missingRow))->toBeFalse()
        ->and($verify($missingViewport))->toBeFalse()
        ->and($verify($wrongActors))->toBeFalse()
        ->and($verify($mixedRevision))->toBeFalse()
        ->and($verify($replayedCompanionReference))->toBeFalse()
        ->and($verify($replayedCompanionHash))->toBeFalse()
        ->and($verify($failedViewport))->toBeFalse()
        ->and($verify($missingRestored))->toBeFalse()
        ->and($verify($primaryRowRelabelledAsRestored))->toBeFalse()
        ->and($verify($replayedResult))->toBeFalse()
        ->and($verify($replayedCapture))->toBeFalse()
        ->and($verifier->verifyManifest($tampered, $authority, new DateTimeImmutable('2026-08-09T03:00:30Z'))['valid'])->toBeFalse()
        ->and($verifier->verifyManifest((string) $duplicate, $authority, new DateTimeImmutable('2026-08-09T03:00:30Z'))['valid'])->toBeFalse();
});

it('does not execute ignored Composer autoload code before release checkout verification', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-desktop-evidence-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($temporary.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'release', 0700, true);
    mkdir($temporary.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'Monitoring', 0700, true);
    mkdir($temporary.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'Release', 0700, true);
    mkdir($temporary.DIRECTORY_SEPARATOR.'vendor', 0700, true);

    copy(
        $root.'/scripts/release/verify-it-security-desktop-evidence.php',
        $temporary.'/scripts/release/verify-it-security-desktop-evidence.php',
    );
    copy(
        $root.'/app/Support/Monitoring/StrictJsonObjectDecoder.php',
        $temporary.'/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    );
    copy(
        $root.'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
        $temporary.'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
    );
    copy(
        $root.'/app/Support/Release/ItSecurityDesktopReleaseEvidenceVerifier.php',
        $temporary.'/app/Support/Release/ItSecurityDesktopReleaseEvidenceVerifier.php',
    );
    file_put_contents($temporary.'/artisan', "<?php\n");
    file_put_contents($temporary.'/manifest.json', "{}\n");
    file_put_contents(
        $temporary.'/vendor/autoload.php',
        "<?php throw new RuntimeException('ignored Composer autoload executed');\n",
    );

    try {
        $process = new Process([
            PHP_BINARY,
            $temporary.'/scripts/release/verify-it-security-desktop-evidence.php',
            '--manifest='.$temporary.'/manifest.json',
        ], $temporary);
        $process->run();
        $output = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe('')
            ->and($output)->toBe([
                'status' => 'failed',
                'reason' => 'paths',
                'v10_release_evidence' => false,
            ]);
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && ! is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path.DIRECTORY_SEPARATOR.$entry);
                    }
                }
                rmdir($path);

                return;
            }

            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        };
        $remove($temporary);
    }
});
