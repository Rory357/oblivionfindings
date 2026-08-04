<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;
use Symfony\Component\Process\Process;

it('provides version and database-free doctor commands', function () {
    $state = __DIR__.'/tmp/doctor-state';
    removeCollectorDirectory($state);

    $version = new Process([PHP_BINARY, __DIR__.'/../bin/oblivion-collector', 'version']);
    $version->mustRun();
    $doctor = new Process([
        PHP_BINARY,
        __DIR__.'/../bin/oblivion-collector',
        'doctor',
        '--config='.__DIR__.'/Fixtures/collector.json',
    ]);
    $doctor->mustRun();

    expect($version->getOutput())->toContain('Oblivion Collector')
        ->and($doctor->getOutput())->toContain(
            'database: absent',
            'signature: valid',
            'scope: valid',
            'spool: writable',
        );

    removeCollectorDirectory($state);
});

it('allows an exact accepted configuration replay but rejects a changed same-sequence envelope', function () {
    $directory = collectorTempDirectory('config-replay');
    try {
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );
        $envelope = signedCollectorConfig(['sequence' => 8]);
        expect($loader->load($envelope, collectorNow())->sequence)->toBe(8)
            ->and($loader->load($envelope, collectorNow())->sequence)->toBe(8)
            ->and(fn () => $loader->load(signedCollectorConfig([
                'sequence' => 8,
                'checks' => [[
                    'id' => 'changed-check',
                    'device_id' => 'edge-1',
                    'protocol' => 'icmp',
                    'target' => '10.44.0.10',
                ]],
            ]), collectorNow()))->toThrow(
                ConfigurationRejected::class,
                'sequence',
            );
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('treats an over-age retained frame as buffer full without deleting it', function () {
    $directory = collectorTempDirectory('spool-age');
    try {
        $spool = new EncryptedSpool($directory, new CheckpointFile($directory.'/checkpoint.json'), 65536, 10, 60);
        $spool->append('old-item', 1, ['value' => 1], collectorNow());

        expect($spool->status(collectorNow()->modify('+61 seconds'))['state'])->toBe('buffer_full')
            ->and($spool->readBatch(10, collectorNow()->modify('+61 seconds')))->toHaveCount(1);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('requires a clean HTTPS origin and a TLS public-key pin', function () {
    expect(fn () => new HttpsCentralApi('http://central.example.test', 'sha256//'.str_repeat('a', 44)))
        ->toThrow(CentralApiFailure::class, 'HTTPS')
        ->and(fn () => new HttpsCentralApi('https://central.example.test', 'invalid'))
        ->toThrow(CentralApiFailure::class, 'pin');
});

it('binds signed runtime requests to a nonce and mTLS identity files', function () {
    $source = file_get_contents(__DIR__.'/../src/Http/HttpsCentralApi.php');

    expect($source)->toContain(
        'X-Oblivion-Collector-Nonce',
        'CURLOPT_SSLCERT',
        'CURLOPT_SSLKEY',
        '$nonce."\\n".hash',
    );
});
