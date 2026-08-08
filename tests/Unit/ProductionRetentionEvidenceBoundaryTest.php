<?php

use App\Domain\Monitoring\Services\ProductionRetentionEndpointAttestation;
use App\Domain\Monitoring\Services\ProductionRetentionEndpointGuard;
use App\Domain\Monitoring\Services\ProductionRetentionEvidenceArtifactWriter;
use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;

function productionRetentionSettings(array $overrides = []): array
{
    return [
        'driver' => 'influxdb',
        'url' => 'https://influx.production.internal',
        'token' => 'configured-secret',
        'organisation' => 'configured-org',
        'bucket' => 'configured-bucket',
        ...$overrides,
    ];
}

function productionRetentionReport(string $artifactId): array
{
    $attestation = productionRetentionAttestation($artifactId);

    return [
        'schema' => 'monitoring-production-retention-v1',
        'artifact_id' => $artifactId,
        'classification' => 'production_real_endpoints',
        'a05_release_evidence' => true,
        'status' => 'verified',
        'started_at_utc' => now('UTC')->subMinute()->toIso8601ZuluString(),
        'completed_at_utc' => now('UTC')->toIso8601ZuluString(),
        'endpoints' => [
            'business_store' => 'mysql',
            'time_series_store' => 'influxdb',
            'health' => 'verified',
        ],
        'endpoint_attestation' => $attestation,
        'execution' => [
            'raw_to_hourly_chain_count' => 1,
            'hourly_to_daily_chain_count' => 1,
            'tombstone_count' => 2,
            'raw_tombstone_count' => 1,
            'hourly_tombstone_count' => 1,
            'privacy_tombstone_count' => 1,
            'held_record_count' => 1,
            'coverage_verified_count' => 2,
            'occupied_buckets_verified_count' => 2,
            'coverage_blocked_count' => 0,
            'reconciled_deletion_intent_count' => 0,
            'unresolved_deletion_intent_count' => 0,
        ],
        'integrity' => [
            'tombstone_lineage_gap_count' => 0,
            'deleted_range_gap_count' => 0,
            'legal_hold_gap_count' => 0,
            'business_reference_gap_count' => 0,
            'pointer_gap_count' => 0,
            'timeseries_reference_gap_count' => 0,
        ],
        'errors' => [],
    ];
}

function productionRetentionAttestation(string $runId): array
{
    static $secretKey;
    if (! is_string($secretKey)) {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x51", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $secretKey = sodium_crypto_sign_secretkey($pair);
    }
    $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
    $fingerprints = [
        'mysql_endpoint_sha256' => str_repeat('a', 64),
        'influx_scope_sha256' => str_repeat('b', 64),
        'influx_tls_certificate_sha256' => str_repeat('c', 64),
    ];
    $document = [
        'schema' => 'monitoring-production-retention-endpoint-attestation-v1',
        'run_id' => $runId,
        'release_revision' => str_repeat('d', 40),
        'valid_from_utc' => now('UTC')->subHour()->toIso8601ZuluString(),
        'valid_until_utc' => now('UTC')->addHour()->toIso8601ZuluString(),
        ...$fingerprints,
        'key_reference' => 'ATTEST-'.substr(hash('sha256', $publicKey), 0, 32),
    ];
    $document['signature_base64'] = base64_encode(sodium_crypto_sign_detached(
        "oblivion-a05-production-endpoints-v1\n".(new ProductionRetentionEndpointAttestation)->canonicalJson($document),
        $secretKey,
    ));

    return $document;
}

function productionRetentionPublicKey(): string
{
    $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x51", SODIUM_CRYPTO_SIGN_SEEDBYTES));

    return sodium_crypto_sign_publickey($pair);
}

function productionRetentionWriter(): ProductionRetentionEvidenceArtifactWriter
{
    return new ProductionRetentionEvidenceArtifactWriter(productionRetentionPublicKey());
}

it('rejects every local fixture and incomplete endpoint combination as A05 production evidence', function (): void {
    $guard = new ProductionRetentionEndpointGuard;

    expect($guard->errors(
        'testing',
        true,
        'sqlite',
        stdClass::class,
        productionRetentionSettings(['token' => null, 'url' => 'http://localhost:8086']),
        ['host' => 'localhost', 'database' => 'oblivion_test'],
    ))->toEqualCanonicalizing([
        'production_environment_required',
        'unit_test_runtime_ineligible',
        'mysql_endpoint_required',
        'pinned_mysql_endpoint_required',
        'influxdb_endpoint_required',
        'influxdb_configuration_incomplete',
        'secure_influxdb_url_required',
    ]);
});

it('admits only the concrete production MySQL and secure InfluxDB endpoint contract', function (): void {
    $guard = new ProductionRetentionEndpointGuard;

    expect($guard->errors(
        'production',
        false,
        'mysql',
        InfluxDbTimeSeriesStore::class,
        productionRetentionSettings(),
        ['host' => 'mysql.production.internal', 'port' => 3306, 'database' => 'oblivion'],
    ))->toBe([]);
});

it('rejects production relabelling over reserved local or documentation endpoints', function (): void {
    $guard = new ProductionRetentionEndpointGuard;

    expect($guard->errors(
        'production',
        false,
        'mysql',
        InfluxDbTimeSeriesStore::class,
        productionRetentionSettings(['url' => 'https://localhost:8086']),
        ['host' => '127.0.0.1', 'database' => 'oblivion'],
    ))->toContain('pinned_mysql_endpoint_required', 'secure_influxdb_url_required');
    expect($guard->errors(
        'production',
        false,
        'mysql',
        InfluxDbTimeSeriesStore::class,
        productionRetentionSettings(['url' => 'https://influx.invalid']),
        ['host' => 'mysql.production.internal', 'database' => 'oblivion'],
    ))->toContain('secure_influxdb_url_required');
});

it('creates a value-free report and checksum exclusively without overwriting a collision', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retention-evidence-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($directory, 0700);
    }

    $writer = productionRetentionWriter();
    $report = productionRetentionReport('018f47a8-674f-7d2c-9f1c-9d5f82f7d124');

    try {
        $artifact = $writer->write($directory, $report);
        $path = $directory.DIRECTORY_SEPARATOR.$artifact['filename'];
        $checksumPath = $directory.DIRECTORY_SEPARATOR.$artifact['sha256_filename'];
        $original = file_get_contents($path);

        expect($original)->not->toBeFalse()
            ->and(json_decode((string) $original, true, flags: JSON_THROW_ON_ERROR))->toBe($report)
            ->and(hash_file('sha256', $path))->toBe($artifact['sha256'])
            ->and(file_get_contents($checksumPath))->toBe(
                $artifact['sha256'].'  '.$artifact['filename'].PHP_EOL,
            );

        expect(fn () => $writer->write($directory, $report))
            ->toThrow(RuntimeException::class, 'could not be created');
        expect(file_get_contents($path))->toBe($original);
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('rejects a directly constructed verified report with empty execution semantics', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retention-evidence-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($directory, 0700);
    }
    $report = productionRetentionReport('018f47a8-674f-7d2c-9f1c-9d5f82f7d126');
    foreach ($report['execution'] as $key => $value) {
        $report['execution'][$key] = 0;
    }

    try {
        expect(fn () => productionRetentionWriter()->write($directory, $report))
            ->toThrow(RuntimeException::class, 'does not prove');
        expect(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
    } finally {
        rmdir($directory);
    }
});

it('cleans the completed artifact when checksum publication collides', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retention-evidence-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($directory, 0700);
    }
    $runId = '018f47a8-674f-7d2c-9f1c-9d5f82f7d127';
    $report = productionRetentionReport($runId);
    $stamp = (new DateTimeImmutable($report['started_at_utc']))->format('Ymd\THis\Z');
    $filename = "monitoring-retention-{$stamp}-{$runId}.json";
    $checksum = $directory.DIRECTORY_SEPARATOR.$filename.'.sha256';
    file_put_contents($checksum, 'existing');

    try {
        expect(fn () => productionRetentionWriter()->write($directory, $report))
            ->toThrow(RuntimeException::class, 'could not be created');
        expect(is_file($directory.DIRECTORY_SEPARATOR.$filename))->toBeFalse()
            ->and(file_get_contents($checksum))->toBe('existing');
    } finally {
        unlink($checksum);
        rmdir($directory);
    }
});

it('refuses identifier-bearing fields even when the top-level schema is otherwise valid', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retention-evidence-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($directory, 0700);
    }
    $report = productionRetentionReport('018f47a8-674f-7d2c-9f1c-9d5f82f7d125');
    $report['execution']['site_id'] = 123;

    try {
        expect(fn () => productionRetentionWriter()->write($directory, $report))
            ->toThrow(RuntimeException::class, 'prohibited key');
        expect(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
    } finally {
        rmdir($directory);
    }
});
