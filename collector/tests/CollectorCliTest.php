<?php

use Oblivion\Collector\CollectorApplication;
use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;
use Symfony\Component\Process\Process;

/** @return array{certificate: string, private_key: string} */
function collectorCertificatePair(string $commonName): array
{
    $configPath = tempnam(sys_get_temp_dir(), 'oblivion-collector-openssl-');
    if ($configPath === false || file_put_contents($configPath, <<<'CONFIG'
[req]
distinguished_name = subject
prompt = no

[subject]
CN = oblivion-collector-test
CONFIG
    ) === false) {
        throw new RuntimeException('Collector test OpenSSL configuration failed.');
    }

    try {
        $options = [
            'config' => $configPath,
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $privateKey = openssl_pkey_new($options);
        $request = $privateKey === false ? false : openssl_csr_new(
            ['commonName' => $commonName],
            $privateKey,
            $options,
        );
        $certificate = $request === false || $privateKey === false
            ? false
            : openssl_csr_sign($request, null, $privateKey, 1, $options);
        if ($privateKey === false || $certificate === false
            || ! openssl_x509_export($certificate, $certificatePem)
            || ! openssl_pkey_export($privateKey, $privateKeyPem, null, $options)) {
            throw new RuntimeException('Collector test certificate generation failed.');
        }

        return ['certificate' => $certificatePem, 'private_key' => $privateKeyPem];
    } finally {
        @unlink($configPath);
    }
}

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

it('rejects a fingerprint-valid certificate paired with a different private key before persisting enrolment', function () {
    $directory = collectorTempDirectory('enrol-mismatched-mtls');
    $identityPath = $directory.'/collector.identity.json';
    $certificatePair = collectorCertificatePair('oblivion-collector-test');
    $differentPair = collectorCertificatePair('oblivion-collector-other');
    $certificate = openssl_x509_read($certificatePair['certificate']);
    $fingerprint = $certificate === false ? false : openssl_x509_fingerprint($certificate, 'sha256');
    $output = '';
    $previousToken = getenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN');
    putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN=one-time-enrolment-token');

    try {
        if (! is_string($fingerprint)) {
            throw new RuntimeException('Collector test certificate fingerprint generation failed.');
        }
        $application = new CollectorApplication(
            enrolmentTransport: fn (
                string $_centralUrl,
                string $_tlsPin,
                string $_token,
                string $_collectorId,
                string $_publicKey,
            ): array => [
                'site_id' => 9,
                'central_signing_public_key' => base64_encode(collectorPublicKey()),
                'client_certificate' => $certificatePair['certificate'],
                'client_private_key' => $differentPair['private_key'],
                'client_certificate_fingerprint' => strtolower($fingerprint),
                'acknowledged_source_sequence' => 0,
            ],
            output: function (string $message, mixed $_stream) use (&$output): void {
                $output .= $message;
            },
        );

        $exitCode = $application->run([
            'oblivion-collector',
            'enrol',
            "--identity={$identityPath}",
            '--collector-id=2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            '--central-url=https://central.example.test',
            '--tls-public-key-pin=sha256//'.base64_encode(str_repeat("\x21", 32)),
            "--state-directory={$directory}",
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('collector_error: Collector mTLS enrolment identity is invalid.')
            ->and($output)->not->toContain('enrolment: complete')
            ->and($identityPath)->not->toBeFile()
            ->and($directory.'/collector.crt.pem')->not->toBeFile()
            ->and($directory.'/collector.key.pem')->not->toBeFile()
            ->and($directory.'/checkpoint.json')->not->toBeFile();
    } finally {
        $previousToken === false
            ? putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN')
            : putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN='.$previousToken);
        removeCollectorDirectory($directory);
    }
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
