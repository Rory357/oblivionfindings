<?php

use Oblivion\Collector\CollectorApplication;

// Uninstalling a hub tells Oblivion (S&D Phase B, windows tab E). The test transport stands in
// for curl; the certificate files only need to exist.

/** @return array{state: string, identity: string} */
function hubDeregisterFixture(): array
{
    $state = collectorTempDirectory('hub-deregister');
    file_put_contents($state.DIRECTORY_SEPARATOR.'collector.crt.pem', 'test certificate');
    file_put_contents($state.DIRECTORY_SEPARATOR.'collector.key.pem', 'test key');
    $identity = $state.DIRECTORY_SEPARATOR.'collector.identity.json';
    file_put_contents($identity, json_encode([
        'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'site_id' => 9,
        'central_url' => 'https://central.example.test',
        'tls_public_key_pin' => 'sha256//'.base64_encode(str_repeat("\x21", 32)),
        'central_signing_public_key' => base64_encode(collectorPublicKey()),
        'request_signing_secret_key' => base64_encode(collectorIdentitySecretKey()),
        'state_directory' => $state,
        'client_certificate_file' => $state.DIRECTORY_SEPARATOR.'collector.crt.pem',
        'client_private_key_file' => $state.DIRECTORY_SEPARATOR.'collector.key.pem',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return ['state' => $state, 'identity' => $identity];
}

/** @param list<array<string, mixed>> $calls */
function hubDeregisterApplication(int $status, array &$calls, string &$output): CollectorApplication
{
    return new CollectorApplication(
        output: function (string $message) use (&$output): void {
            $output .= $message;
        },
        updateTransport: function (string $method, string $path, string $body, array $headers, bool $useMtls) use ($status, &$calls): array {
            $calls[] = compact('method', 'path', 'body', 'headers', 'useMtls');

            return ['transport_ok' => true, 'status' => $status, 'body' => $status === 200 ? '{"state":"removed"}' : '{"state":"removed","message":"gone"}'];
        },
    );
}

it('asks Oblivion to remove the hub on the signed mTLS path, and treats already removed as done', function () {
    $fixture = hubDeregisterFixture();
    try {
        foreach ([200, 410] as $status) {
            $calls = [];
            $output = '';
            expect(hubDeregisterApplication($status, $calls, $output)->run(['oblivion-collector', 'deregister', '--identity='.$fixture['identity']]))->toBe(0)
                ->and($calls)->toHaveCount(1)
                ->and($calls[0]['path'])->toBe('/api/monitoring/collectors/deregister')
                ->and($calls[0]['useMtls'])->toBeTrue()
                ->and(json_decode($calls[0]['body'], true))->toBe(['collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d'])
                ->and(implode("\n", $calls[0]['headers']))->toContain('X-Oblivion-Collector-Signature: ')
                ->and($output)->toBe("deregister: removed\n");
        }
    } finally {
        removeCollectorDirectory($fixture['state']);
    }
});

it('says plainly when Oblivion could not be told, and takes no other options', function () {
    $fixture = hubDeregisterFixture();
    try {
        $calls = [];
        $output = '';
        $application = hubDeregisterApplication(503, $calls, $output);
        expect($application->run(['oblivion-collector', 'deregister', '--identity='.$fixture['identity']]))->toBe(1)
            ->and($output)->toBe("deregister: not confirmed\n");

        $calls = [];
        expect($application->run(['oblivion-collector', 'deregister', '--identity='.$fixture['identity'], '--force']))->toBe(1)
            ->and($calls)->toBe([]);
    } finally {
        removeCollectorDirectory($fixture['state']);
    }
});
