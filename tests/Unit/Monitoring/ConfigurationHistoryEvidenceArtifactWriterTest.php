<?php

use App\Domain\Monitoring\Services\ConfigurationHistoryEvidenceArtifactWriter;

function verifiedConfigurationHistoryArtifactReport(): array
{
    return [
        'checked_at' => '2026-08-11T00:00:00+00:00',
        'evidence_fingerprint' => str_repeat('a', 64),
        'all_verified' => true,
        'checks' => [
            'isolated_restored_runtime' => 'verified',
            'verified_restore_artifact' => 'verified',
        ],
        'verified_mysql_rows' => 4,
        'verified_snapshot_payloads' => 2,
        'verified_capacity_boundary_points' => 2,
    ];
}

function configurationHistoryArtifactDirectory(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-a10-artifact-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($directory, 0700);
    }

    return $directory;
}

function configurationHistoryReleaseIdentity(): array
{
    return [
        'backup_manifest_sha256' => str_repeat('b', 64),
        'release_revision' => str_repeat('c', 40),
        'restore_artifact_sha256' => str_repeat('d', 64),
        'restore_authority_reference' => 'AUTHORITY-'.str_repeat('e', 32),
        'restore_authority_sha256' => str_repeat('f', 64),
        'restored_environment_reference_sha256' => str_repeat('1', 64),
    ];
}

function removeConfigurationHistoryArtifactDirectory(string $directory): void
{
    foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}

it('publishes one private collision safe A10 artifact and checksum after the final identity check', function (): void {
    $directory = configurationHistoryArtifactDirectory();
    try {
        $checked = false;
        $result = (new ConfigurationHistoryEvidenceArtifactWriter)->write(
            $directory,
            verifiedConfigurationHistoryArtifactReport(),
            configurationHistoryReleaseIdentity(),
            function () use (&$checked): void {
                $checked = true;
            },
        );

        expect($checked)->toBeTrue()
            ->and(array_map('basename', glob($directory.DIRECTORY_SEPARATOR.'*') ?: []))->toHaveCount(2)
            ->and($result['filename'])->toEndWith('.json')
            ->and($result['sha256_filename'])->toBe($result['filename'].'.sha256');

        $payload = file_get_contents($directory.DIRECTORY_SEPARATOR.$result['filename']);
        $checksum = file_get_contents($directory.DIRECTORY_SEPARATOR.$result['sha256_filename']);
        $document = json_decode((string) $payload, true, 32, JSON_THROW_ON_ERROR);
        expect($document)->toMatchArray([
            'schema_version' => 1,
            'evidence_class' => 'monitoring-configuration-history-release-evidence-v1',
            'a10_release_evidence' => true,
            'publication' => 'collision_safe_exclusive_create',
            'worm_receipt_verified' => false,
            'all_verified' => true,
            'release_revision' => str_repeat('c', 40),
            'restored_environment_reference_sha256' => str_repeat('1', 64),
        ])->and($checksum)->toBe(hash('sha256', (string) $payload).'  '.$result['filename'].PHP_EOL);

        if (DIRECTORY_SEPARATOR !== '\\') {
            expect(fileperms($directory.DIRECTORY_SEPARATOR.$result['filename']) & 0777)->toBe(0600)
                ->and(fileperms($directory.DIRECTORY_SEPARATOR.$result['sha256_filename']) & 0777)->toBe(0600);
        }
    } finally {
        removeConfigurationHistoryArtifactDirectory($directory);
    }
});

it('removes both newly created files when the final identity check refuses publication', function (): void {
    $directory = configurationHistoryArtifactDirectory();
    try {
        expect(fn () => (new ConfigurationHistoryEvidenceArtifactWriter)->write(
            $directory,
            verifiedConfigurationHistoryArtifactReport(),
            configurationHistoryReleaseIdentity(),
            fn () => throw new RuntimeException('input changed'),
        ))->toThrow(RuntimeException::class, 'could not be created')
            ->and(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
    } finally {
        removeConfigurationHistoryArtifactDirectory($directory);
    }
});

it('removes both files when an artifact is replaced during the final identity check', function (): void {
    $directory = configurationHistoryArtifactDirectory();
    try {
        expect(fn () => (new ConfigurationHistoryEvidenceArtifactWriter)->write(
            $directory,
            verifiedConfigurationHistoryArtifactReport(),
            configurationHistoryReleaseIdentity(),
            function () use ($directory): void {
                $artifact = collect(glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [])->first();
                if (! is_string($artifact)) {
                    throw new RuntimeException('artifact missing');
                }
                file_put_contents($artifact, str_repeat('x', (int) filesize($artifact)));
            },
        ))->toThrow(RuntimeException::class, 'could not be created')
            ->and(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
    } finally {
        removeConfigurationHistoryArtifactDirectory($directory);
    }
});

it('never publishes an incomplete report as A10 release evidence', function (): void {
    $directory = configurationHistoryArtifactDirectory();
    try {
        $report = verifiedConfigurationHistoryArtifactReport();
        $report['all_verified'] = false;
        $report['checks']['verified_restore_artifact'] = 'not_verified';

        expect(fn () => (new ConfigurationHistoryEvidenceArtifactWriter)->write(
            $directory,
            $report,
            configurationHistoryReleaseIdentity(),
            fn () => null,
        ))->toThrow(RuntimeException::class, 'Only a complete value-free A10 report may be published')
            ->and(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
    } finally {
        removeConfigurationHistoryArtifactDirectory($directory);
    }
});
