<?php

use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Http\HttpsCentralApi;

/** @param list<string> $headers @return array<string, string> */
function collectorTransportHeaders(array $headers): array
{
    $mapped = [];
    foreach ($headers as $header) {
        [$name, $value] = explode(': ', $header, 2);
        $mapped[$name] = $value;
    }

    return $mapped;
}

it('reuses one signed request for each active replay sample and emits conservative evidence', function () {
    $directory = collectorTempDirectory('transport-active');
    $certificate = $directory.'/collector.crt.pem';
    $privateKey = $directory.'/collector.key.pem';
    file_put_contents($certificate, 'test-certificate');
    file_put_contents($privateKey, 'test-private-key');
    $calls = [];
    $responses = [
        ['transport_ok' => true, 'status' => 422, 'body' => '{"message":"Collector request is invalid."}'],
        ['transport_ok' => true, 'status' => 401, 'body' => '{"message":"Collector authentication failed."}'],
        ['transport_ok' => true, 'status' => 422, 'body' => '{"message":"Collector request is invalid."}'],
        ['transport_ok' => true, 'status' => 401, 'body' => '{"message":"Collector authentication failed."}'],
    ];

    try {
        $api = new HttpsCentralApi(
            'https://central.example.test',
            'sha256//'.base64_encode(str_repeat("\x21", 32)),
            collectorIdentitySecretKey(),
            $certificate,
            $privateKey,
            function (string $method, string $path, string $body, array $headers, bool $useMtls) use (&$calls, &$responses): array {
                $calls[] = compact('method', 'path', 'body', 'headers', 'useMtls');

                return array_shift($responses);
            },
        );

        $evidence = $api->verifyTransport('2df1d87c-2d04-4e57-80ab-8a15f39c944d', 'active', 2);

        expect($evidence)->toBe([
            'state' => 'response_contract_matched',
            'expected_identity_state' => 'active',
            'pinned_https_contract' => 'matched',
            'initial_response' => 'validation_rejected',
            'replay_attempt' => 'authentication_denied',
            'samples' => 2,
        ])->and($calls)->toHaveCount(4)
            ->and($calls[0]['method'])->toBe('POST')
            ->and($calls[0]['path'])->toBe('/api/monitoring/collectors/configuration')
            ->and(json_decode($calls[0]['body'], true, 8, JSON_THROW_ON_ERROR))->toBe([
                'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
                'after_sequence' => 'transport-evidence-only',
            ])
            ->and($calls[0]['useMtls'])->toBeTrue()
            ->and($calls[0]['headers'])->toBe($calls[1]['headers'])
            ->and($calls[0]['body'])->toBe($calls[1]['body'])
            ->and($calls[2]['headers'])->toBe($calls[3]['headers'])
            ->and($calls[2]['headers'])->not->toBe($calls[0]['headers']);

        foreach ([0, 2] as $callIndex) {
            $headers = collectorTransportHeaders($calls[$callIndex]['headers']);
            $material = "POST\n/api/monitoring/collectors/configuration\n"
                .$headers['X-Oblivion-Collector-Timestamp']."\n"
                .$headers['X-Oblivion-Collector-Nonce']."\n"
                .hash('sha256', $calls[$callIndex]['body']);

            expect(sodium_crypto_sign_verify_detached(
                base64_decode($headers['X-Oblivion-Collector-Signature'], true),
                $material,
                sodium_crypto_sign_publickey(collectorIdentityKeyPair()),
            ))->toBeTrue();
        }

        expect(json_encode($evidence, JSON_THROW_ON_ERROR))->not->toContain(
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            'X-Oblivion-Collector-Nonce',
            'test-private-key',
        );
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('reports only the revoked response contract and rejects non-exact bodies', function () {
    $directory = collectorTempDirectory('transport-revoked');
    $certificate = $directory.'/collector.crt.pem';
    $privateKey = $directory.'/collector.key.pem';
    file_put_contents($certificate, 'test-certificate');
    file_put_contents($privateKey, 'test-private-key');
    $calls = [];
    $response = ['transport_ok' => true, 'status' => 401, 'body' => '{"message":"Collector authentication failed."}'];

    try {
        $transport = function (string $method, string $path, string $body, array $headers, bool $useMtls) use (&$calls, &$response): array {
            $calls[] = compact('method', 'path', 'body', 'headers', 'useMtls');

            return $response;
        };
        $api = new HttpsCentralApi(
            'https://central.example.test',
            'sha256//'.base64_encode(str_repeat("\x22", 32)),
            collectorIdentitySecretKey(),
            $certificate,
            $privateKey,
            $transport,
        );

        expect($api->verifyTransport('2df1d87c-2d04-4e57-80ab-8a15f39c944d', 'revoked', 1))->toBe([
            'state' => 'response_contract_matched',
            'expected_identity_state' => 'revoked',
            'pinned_https_contract' => 'matched',
            'initial_response' => 'authentication_denied',
            'replay_attempt' => 'not_exercised',
            'samples' => 1,
        ])->and($calls)->toHaveCount(1)
            ->and($calls[0]['useMtls'])->toBeTrue();

        $response['body'] = '{"message":"Collector authentication failed.","origin":"proxy"}';

        expect(fn () => $api->verifyTransport(
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            'revoked',
            1,
        ))->toThrow(CentralApiFailure::class, 'expected response contract');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('keeps token enrolment and signed runtime requests on separate transport modes', function () {
    $directory = collectorTempDirectory('transport-request-modes');
    $certificate = $directory.'/collector.crt.pem';
    $privateKey = $directory.'/collector.key.pem';
    file_put_contents($certificate, 'test-certificate');
    file_put_contents($privateKey, 'test-private-key');
    $runtimeCalls = [];
    $enrolmentCalls = [];

    try {
        $runtime = new HttpsCentralApi(
            'https://central.example.test',
            'sha256//'.base64_encode(str_repeat("\x23", 32)),
            collectorIdentitySecretKey(),
            $certificate,
            $privateKey,
            function (string $method, string $path, string $body, array $headers, bool $useMtls) use (&$runtimeCalls): array {
                $runtimeCalls[] = compact('method', 'path', 'body', 'headers', 'useMtls');

                return ['transport_ok' => true, 'status' => 200, 'body' => '{"envelope":"signed-config"}'];
            },
        );
        $enrolment = new HttpsCentralApi(
            'https://central.example.test',
            'sha256//'.base64_encode(str_repeat("\x24", 32)),
            testTransport: function (string $method, string $path, string $body, array $headers, bool $useMtls) use (&$enrolmentCalls): array {
                $enrolmentCalls[] = compact('method', 'path', 'body', 'headers', 'useMtls');

                return ['transport_ok' => true, 'status' => 201, 'body' => '{"state":"enrolled"}'];
            },
        );

        expect($runtime->configuration('2df1d87c-2d04-4e57-80ab-8a15f39c944d', 7))->toBe('signed-config')
            ->and($runtimeCalls)->toHaveCount(1)
            ->and($runtimeCalls[0]['useMtls'])->toBeTrue()
            ->and($runtimeCalls[0]['path'])->toBe('/api/monitoring/collectors/configuration')
            ->and(json_decode($runtimeCalls[0]['body'], true, 8, JSON_THROW_ON_ERROR))->toBe([
                'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
                'after_sequence' => 7,
            ]);
        $runtimeHeaders = collectorTransportHeaders($runtimeCalls[0]['headers']);
        expect($runtimeHeaders)->toHaveKeys([
            'X-Oblivion-Collector-Timestamp',
            'X-Oblivion-Collector-Nonce',
            'X-Oblivion-Collector-Signature',
        ])->not->toHaveKey('Authorization');

        expect($enrolment->enrol('one-time-token', '2df1d87c-2d04-4e57-80ab-8a15f39c944d', collectorPublicKey()))->toBe([
            'state' => 'enrolled',
        ])->and($enrolmentCalls)->toHaveCount(1)
            ->and($enrolmentCalls[0]['useMtls'])->toBeFalse()
            ->and($enrolmentCalls[0]['path'])->toBe('/api/monitoring/collectors/enrol');
        $enrolmentHeaders = collectorTransportHeaders($enrolmentCalls[0]['headers']);
        expect($enrolmentHeaders['Authorization'])->toBe('Bearer one-time-token')
            ->and($enrolmentHeaders)->not->toHaveKeys([
                'X-Oblivion-Collector-Timestamp',
                'X-Oblivion-Collector-Nonce',
                'X-Oblivion-Collector-Signature',
            ]);
    } finally {
        removeCollectorDirectory($directory);
    }
});
