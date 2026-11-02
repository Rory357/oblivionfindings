<?php

use Oblivion\Collector\CollectorApplication;

/**
 * A hub identity and state folder whose hub-instructions.json names one release file
 * (S&D Phase B, D3). The certificate files only need to exist: the test transport stands in
 * for curl.
 *
 * @param  array<string, mixed>  $instructions
 * @return array{state: string, identity: string, output: string}
 */
function hubUpdateFixture(string $published, array $instructions = []): array
{
    $state = collectorTempDirectory('hub-update');
    mkdir($state.DIRECTORY_SEPARATOR.'updates', 0700);
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
    file_put_contents($state.DIRECTORY_SEPARATOR.'hub-instructions.json', json_encode(array_replace_recursive([
        'approval' => 'approved',
        'update' => [
            'held' => false,
            'ring' => 'early',
            'window' => ['start' => '02:00', 'end' => '05:00', 'timezone' => 'Pacific/Auckland'],
            'rollback_after_minutes' => 5,
            'target' => ['version' => '1.3.1', 'release_revision' => 41, 'artifacts' => [[
                'id' => 'windows-site-package-amd64',
                'architecture' => 'amd64',
                'filename' => 'oblivion-site-windows-amd64.zip',
                'size' => strlen($published),
                'sha256' => hash('sha256', $published),
            ]]],
        ],
        'received_at' => gmdate(DATE_ATOM),
        'running_version' => '1.3.0',
    ], $instructions), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return ['state' => $state, 'identity' => $identity, 'output' => $state.DIRECTORY_SEPARATOR.'updates'.DIRECTORY_SEPARATOR.'release.zip'];
}

/**
 * The hub application with a central server that serves $served for every call.
 *
 * @param  list<array<string, mixed>>  $calls
 */
function hubUpdateApplication(string $served, array &$calls, string &$output): CollectorApplication
{
    return new CollectorApplication(
        output: function (string $message) use (&$output): void {
            $output .= $message;
        },
        updateTransport: function (string $method, string $path, string $body, array $headers, bool $useMtls) use ($served, &$calls): array {
            $calls[] = compact('method', 'path', 'body', 'headers', 'useMtls');

            return ['transport_ok' => true, 'status' => 200, 'body' => $served];
        },
    );
}

/** @param array{state: string, identity: string, output: string} $fixture */
function hubUpdateFetch(CollectorApplication $application, array $fixture, string $artifact = 'windows-site-package-amd64', ?string $output = null): int
{
    return $application->run(['oblivion-collector', 'update-fetch', '--identity='.$fixture['identity'], '--artifact='.$artifact, '--output='.($output ?? $fixture['output'])]);
}

/** @return list<string> */
function hubUpdateFolder(string $state): array
{
    return array_values(array_diff(scandir($state.DIRECTORY_SEPARATOR.'updates') ?: [], ['.', '..']));
}

it('downloads the instructed release file on the signed mTLS path and saves it whole', function () {
    $published = random_bytes(200_000); // several chunks
    $fixture = hubUpdateFixture($published);
    $calls = [];
    $output = '';

    try {
        expect(hubUpdateFetch(hubUpdateApplication($published, $calls, $output), $fixture))->toBe(0)
            ->and(file_get_contents($fixture['output']))->toBe($published)
            ->and(hubUpdateFolder($fixture['state']))->toBe(['release.zip'])
            ->and($calls)->toHaveCount(1)
            ->and($calls[0]['method'])->toBe('POST')
            ->and($calls[0]['path'])->toBe('/api/monitoring/collectors/update/download')
            ->and($calls[0]['useMtls'])->toBeTrue()
            ->and(json_decode($calls[0]['body'], true))->toBe([
                'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
                'artifact_id' => 'windows-site-package-amd64',
                'sha256' => hash('sha256', $published),
            ])
            ->and(implode("\n", $calls[0]['headers']))->toContain('X-Oblivion-Collector-Signature: ')
            ->and($output)->toContain('update-fetch: downloaded and checked');
    } finally {
        removeCollectorDirectory($fixture['state']);
    }
});

it('refuses a download whose SHA-256 does not match and keeps the earlier file untouched', function () {
    $fixture = hubUpdateFixture('the published release');
    file_put_contents($fixture['output'], 'an earlier download');
    $calls = [];
    $output = '';

    try {
        // Same length, different bytes: only the SHA-256 tells them apart.
        expect(hubUpdateFetch(hubUpdateApplication('the tampered release!', $calls, $output), $fixture))->toBe(1)
            ->and($output)->toContain('does not match its size and SHA-256')
            ->and(file_get_contents($fixture['output']))->toBe('an earlier download')
            ->and(hubUpdateFolder($fixture['state']))->toBe(['release.zip']);

        $output = '';
        expect(hubUpdateFetch(hubUpdateApplication('the published release', $calls, $output), $fixture))->toBe(0)
            ->and(file_get_contents($fixture['output']))->toBe('the published release');
    } finally {
        removeCollectorDirectory($fixture['state']);
    }
});

it('stops a download that is larger than instructed and leaves nothing behind', function () {
    $fixture = hubUpdateFixture('the published release');
    $calls = [];
    $output = '';

    try {
        expect(hubUpdateFetch(hubUpdateApplication('the published release, and more', $calls, $output), $fixture))->toBe(1)
            ->and(hubUpdateFolder($fixture['state']))->toBe([]);
    } finally {
        removeCollectorDirectory($fixture['state']);
    }
});

it('refuses anything that is not named in the current update instructions', function () {
    $published = 'the published release';
    $cases = [
        'another file' => [[], 'windows-package-amd64', null],
        'updates held' => [['update' => ['held' => true]], 'windows-site-package-amd64', null],
        'no target' => [['update' => ['target' => null]], 'windows-site-package-amd64', null],
        'bad digest' => [['update' => ['target' => ['artifacts' => [['sha256' => 'not-a-digest']]]]], 'windows-site-package-amd64', null],
        'outside the state folder' => [[], 'windows-site-package-amd64', sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-release-'.bin2hex(random_bytes(4)).'.zip'],
    ];
    foreach ($cases as $name => [$instructions, $artifact, $elsewhere]) {
        $fixture = hubUpdateFixture($published, $instructions);
        $calls = [];
        $output = '';
        try {
            expect(hubUpdateFetch(hubUpdateApplication($published, $calls, $output), $fixture, $artifact, $elsewhere))->toBe(1, $name)
                ->and($calls)->toBe([], $name)
                ->and(hubUpdateFolder($fixture['state']))->toBe([], $name);
            if ($elsewhere !== null) {
                expect(file_exists($elsewhere))->toBeFalse();
            }
        } finally {
            removeCollectorDirectory($fixture['state']);
        }
    }
});

it('reports how an update went in plain text', function () {
    $fixture = hubUpdateFixture('the published release');
    $calls = [];
    $output = '';

    try {
        $application = hubUpdateApplication('{"recorded":true}', $calls, $output);
        expect($application->run(['oblivion-collector', 'update-report', '--identity='.$fixture['identity'], '--outcome=rolled_back',
            '--from=1.3.1', '--to=1.3.2', "--detail=Version 1.3.2 didn't check in.\nGone back."]))->toBe(0)
            ->and($calls)->toHaveCount(1)
            ->and($calls[0]['path'])->toBe('/api/monitoring/collectors/update/report')
            ->and(json_decode($calls[0]['body'], true))->toBe([
                'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
                'report' => ['outcome' => 'rolled_back', 'from_version' => '1.3.1', 'to_version' => '1.3.2', 'detail' => "Version 1.3.2 didn't check in. Gone back."],
            ]);

        $calls = [];
        expect($application->run(['oblivion-collector', 'update-report', '--identity='.$fixture['identity'], '--outcome=maybe', '--to=1.3.2']))->toBe(1)
            ->and($application->run(['oblivion-collector', 'update-report', '--identity='.$fixture['identity'], '--outcome=installed', '--to=latest']))->toBe(1)
            ->and($calls)->toBe([]);
    } finally {
        removeCollectorDirectory($fixture['state']);
    }
});
