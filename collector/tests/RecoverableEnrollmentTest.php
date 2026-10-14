<?php

use Oblivion\Collector\CollectorApplication;
use Oblivion\Collector\Enrollment\RecoverableEnrollment;
use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Spool\CheckpointFile;

function recoveryClientArguments(string $root): array
{
    return [
        $root.'/collector.identity.json', '2df1d87c-2d04-4e57-80ab-8a15f39c944d', 9,
        'https://central.example.test', 'sha256//'.base64_encode(str_repeat('P', 32)),
        $root, 'ofc_enrol_'.str_repeat('A', 64),
    ];
}

function recoveryClientPayload(string $uuid, array $changes = []): array
{
    static $certificates = [];
    $certificate = $certificates[$uuid] ??= collectorCertificatePair('oblivion-collector-'.$uuid);
    $parsed = openssl_x509_parse($certificate['certificate']);

    return array_replace([
        'version' => 2, 'collector_id' => $uuid, 'site_id' => 9,
        'central_signing_public_key' => base64_encode(collectorPublicKey()),
        'client_certificate' => $certificate['certificate'], 'client_private_key' => $certificate['private_key'],
        'client_certificate_fingerprint' => strtolower(openssl_x509_fingerprint($certificate['certificate'], 'sha256')),
        'client_certificate_expires_at' => gmdate(DATE_ATOM, $parsed['validTo_time_t']),
        'acknowledged_source_sequence' => 0, 'enrolment_expires_at' => gmdate(DATE_ATOM, time() + 900),
    ], $changes);
}

function recoveryClientResponse(string $uuid, string $public, array $changes = []): array
{
    $plaintext = json_encode(recoveryClientPayload($uuid, $changes), JSON_THROW_ON_ERROR);

    return [
        'version' => 2, 'collector_id' => $uuid, 'recipient_key_fingerprint' => hash('sha256', $public),
        'sealed_identity' => base64_encode(sodium_crypto_box_seal($plaintext, sodium_crypto_sign_ed25519_pk_to_curve25519($public))),
    ];
}

it('keeps the same durable key after a lost reply and commits one locally valid identity', function () {
    $root = collectorTempDirectory('recoverable-reply');
    $args = recoveryClientArguments($root);
    $seen = [];
    $response = null;
    $transport = function (string $origin, string $pin, string $token, string $uuid, string $public, string $proof) use ($root, &$seen, &$response): array {
        $pendingBytes = file_get_contents($root.'/enrolment.pending.json');
        $pending = json_decode($pendingBytes, true, 16, JSON_THROW_ON_ERROR);
        expect($pendingBytes)->not->toContain($token)
            ->and(sodium_crypto_sign_publickey_from_secretkey(base64_decode($pending['request_signing_secret_key'], true)))->toBe($public)
            ->and(sodium_crypto_sign_verify_detached($proof, RecoverableEnrollment::DOMAIN.$uuid."\n".hash('sha256', $token)."\n".base64_encode($public), $public))->toBeTrue();
        $seen[] = [$origin, $pin, $public, $proof];
        $response ??= recoveryClientResponse($uuid, $public);
        if (count($seen) === 1) {
            throw new CentralApiFailure('Simulated reply lost after server acceptance.');
        }

        return $response;
    };
    try {
        $client = new RecoverableEnrollment($transport);
        expect(fn () => $client->enrol(...$args))->toThrow(CentralApiFailure::class)
            ->and($args[0])->not->toBeFile()
            ->and($root.'/enrolment.pending.json')->toBeFile();
        $client->enrol(...$args);
        expect($seen[1])->toBe($seen[0])->and($args[0])->toBeFile()
            ->and($root.'/enrolment.pending.json')->not->toBeFile();
        $identity = json_decode(file_get_contents($args[0]), true, 16, JSON_THROW_ON_ERROR);
        expect($identity['site_id'])->toBe(9)
            ->and($identity['enrolment_token_hash'])->toBe(hash('sha256', $args[6]))
            ->and(file_get_contents($args[0]))->not->toContain($args[6]);
        $client->enrol(...$args);
        expect($seen)->toHaveCount(2);
    } finally {
        removeCollectorDirectory($root);
    }
});

it('refuses to repoint a pending or committed enrolment without consuming new authority', function (bool $complete) {
    $root = collectorTempDirectory('recoverable-binding');
    $args = recoveryClientArguments($root);
    $calls = 0;
    $transport = function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use (&$calls, $complete): array {
        $calls++;
        if (! $complete) {
            throw new CentralApiFailure('Reply unavailable.');
        }

        return recoveryClientResponse($uuid, $public);
    };
    try {
        $client = new RecoverableEnrollment($transport);
        try {
            $client->enrol(...$args);
        } catch (CentralApiFailure) {
        }
        foreach ([1 => '2df1d87c-2d04-4e57-80ab-8a15f39c944e', 2 => 10, 3 => 'https://other.example.test',
            4 => 'sha256//'.base64_encode(str_repeat('Q', 32)), 6 => 'ofc_enrol_'.str_repeat('B', 64)] as $index => $changed) {
            $attempt = $args;
            $attempt[$index] = $changed;
            expect(fn () => $client->enrol(...$attempt))->toThrow(RuntimeException::class);
        }
        expect($calls)->toBe(1);
    } finally {
        removeCollectorDirectory($root);
    }
})->with([false, true]);

it('rejects misbound expired or invalid encrypted identities before saving certificate material', function (string $problem) {
    $root = collectorTempDirectory('recoverable-response');
    $args = recoveryClientArguments($root);
    try {
        $client = new RecoverableEnrollment(function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use ($problem): array {
            $changes = match ($problem) {
                'Site' => ['site_id' => 10],
                'UUID' => ['collector_id' => 'another-collector'],
                'expired' => ['enrolment_expires_at' => gmdate(DATE_ATOM, time() - 1)],
                'central key' => ['central_signing_public_key' => base64_encode('short')],
                'fingerprint' => ['client_certificate_fingerprint' => str_repeat('0', 64)],
                'certificate key' => ['client_private_key' => recoveryClientPayload('2df1d87c-2d04-4e57-80ab-8a15f39c944e')['client_private_key']],
                default => [],
            };
            $response = recoveryClientResponse($uuid, $public, $changes);
            if ($problem === 'recipient') {
                $response['recipient_key_fingerprint'] = str_repeat('0', 64);
            }
            if ($problem === 'ciphertext') {
                $ciphertext = base64_decode($response['sealed_identity'], true);
                $ciphertext[70] = chr(ord($ciphertext[70]) ^ 1);
                $response['sealed_identity'] = base64_encode($ciphertext);
            }

            return $response;
        });
        expect(fn () => $client->enrol(...$args))->toThrow(RuntimeException::class)
            ->and($args[0])->not->toBeFile()
            ->and($root.'/collector.crt.pem')->not->toBeFile()
            ->and($root.'/collector.key.pem')->not->toBeFile()
            ->and($root.'/checkpoint.json')->not->toBeFile()
            ->and($root.'/enrolment.pending.json')->toBeFile();
    } finally {
        removeCollectorDirectory($root);
    }
})->with(['Site', 'UUID', 'expired', 'central key', 'fingerprint', 'certificate key', 'recipient', 'ciphertext']);

it('retains foreign private state and prevents concurrent enrolment', function () {
    $root = collectorTempDirectory('recoverable-exclusive');
    $args = recoveryClientArguments($root);
    $calls = 0;
    $client = new RecoverableEnrollment(function () use (&$calls): array {
        $calls++;

        return [];
    });
    try {
        file_put_contents($root.'/unrelated.txt', 'preserve');
        chmod($root.'/unrelated.txt', 0600);
        expect(fn () => $client->enrol(...$args))->toThrow(RuntimeException::class)
            ->and(file_get_contents($root.'/unrelated.txt'))->toBe('preserve')
            ->and($calls)->toBe(0);
        unlink($root.'/unrelated.txt');
        $lock = fopen($root.'/enrolment.lock', 'r+b');
        flock($lock, LOCK_EX);
        try {
            expect(fn () => $client->enrol(...$args))->toThrow(RuntimeException::class, 'Another collector enrolment');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        expect($calls)->toBe(0);
    } finally {
        removeCollectorDirectory($root);
    }
});

it('reuses a completed local commit after interruption without overwriting certificates', function () {
    $root = collectorTempDirectory('recoverable-commit');
    $args = recoveryClientArguments($root);
    $pendingBytes = null;
    $transport = function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use ($root, &$pendingBytes): array {
        $pendingBytes = file_get_contents($root.'/enrolment.pending.json');

        return recoveryClientResponse($uuid, $public);
    };
    try {
        (new RecoverableEnrollment($transport))->enrol(...$args);
        $before = file_get_contents($args[0]);
        file_put_contents($root.'/enrolment.pending.json', $pendingBytes);
        chmod($root.'/enrolment.pending.json', 0600);
        (new RecoverableEnrollment(fn () => throw new RuntimeException('Must not re-enrol a completed identity.')))->enrol(...$args);
        expect(file_get_contents($args[0]))->toBe($before)->and($root.'/enrolment.pending.json')->not->toBeFile();
    } finally {
        removeCollectorDirectory($root);
    }
});

it('sends proof over the pinned enrolment transport without runtime identity or secret command values', function () {
    $pair = collectorIdentityKeyPair();
    $public = sodium_crypto_sign_publickey($pair);
    $uuid = '2df1d87c-2d04-4e57-80ab-8a15f39c944d';
    $token = 'ofc_enrol_'.str_repeat('A', 64);
    $proof = sodium_crypto_sign_detached(RecoverableEnrollment::DOMAIN.$uuid."\n".hash('sha256', $token)."\n".base64_encode($public), sodium_crypto_sign_secretkey($pair));
    $calls = [];
    $api = new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat('P', 32)), testTransport: function ($method, $path, $body, $headers, $mtls) use (&$calls): array {
        $calls[] = compact('method', 'path', 'body', 'headers', 'mtls');

        return ['transport_ok' => true, 'status' => 201, 'body' => '{"accepted":true}'];
    });
    expect($api->enrolRecoverable($token, $uuid, $public, $proof))->toBe(['accepted' => true])
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['path'])->toBe('/api/monitoring/collectors/enrol/recoverable')
        ->and($calls[0]['mtls'])->toBeFalse()
        ->and($calls[0]['body'])->not->toContain($token, base64_encode(sodium_crypto_sign_secretkey($pair)))
        ->and(json_decode($calls[0]['body'], true)['proof'])->toBe(base64_encode($proof));
});

it('exposes recoverable CLI enrolment with explicit Site and bounded non-secret output', function () {
    $root = collectorTempDirectory('recoverable-cli');
    $args = recoveryClientArguments($root);
    $previous = getenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN');
    putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN='.$args[6]);
    $output = '';
    try {
        $application = new CollectorApplication(output: function (string $message) use (&$output): void {
            $output .= $message;
        },
            recoverableEnrollmentTransport: fn ($_origin, $_pin, $_token, $uuid, $public, $_proof): array => recoveryClientResponse($uuid, $public));
        $exit = $application->run(['oblivion-collector', 'enrol-recoverable', '--identity='.$args[0],
            '--collector-id='.$args[1], '--site-id=9', '--central-url='.$args[3], '--tls-public-key-pin='.$args[4], '--state-directory='.$root]);
        expect($exit)->toBe(0)->and($output)->toContain('identity saved; runtime connectivity still requires verification')
            ->and($output)->not->toContain($args[6], 'PRIVATE KEY')->and($args[0])->toBeFile();
    } finally {
        $previous === false ? putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN') : putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN='.$previous);
        removeCollectorDirectory($root);
    }
});

it('recovers exact partial certificate writes but never overwrites conflicting bytes', function (bool $conflict) {
    $root = collectorTempDirectory('recoverable-partial');
    $args = recoveryClientArguments($root);
    $calls = 0;
    $response = null;
    $client = new RecoverableEnrollment(function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use (&$calls, &$response): array {
        $response ??= recoveryClientResponse($uuid, $public);
        if (++$calls === 1) {
            throw new CentralApiFailure('Reply interrupted.');
        }

        return $response;
    });
    try {
        expect(fn () => $client->enrol(...$args))->toThrow(CentralApiFailure::class);
        $partial = $conflict ? 'existing certificate must be preserved' : recoveryClientPayload($args[1])['client_certificate'];
        file_put_contents($root.'/collector.crt.pem', $partial);
        chmod($root.'/collector.crt.pem', 0600);
        if ($conflict) {
            expect(fn () => $client->enrol(...$args))->toThrow(RuntimeException::class, 'cannot replace existing')
                ->and($args[0])->not->toBeFile();
        } else {
            $client->enrol(...$args);
            expect($args[0])->toBeFile()->and($root.'/enrolment.pending.json')->not->toBeFile();
        }
        expect(file_get_contents($root.'/collector.crt.pem'))->toBe($partial);
    } finally {
        removeCollectorDirectory($root);
    }
})->with([false, true]);

it('refuses corrupted committed authority or a different pending key during local completion', function (string $problem) {
    $root = collectorTempDirectory('recoverable-local-integrity');
    $args = recoveryClientArguments($root);
    $pending = null;
    try {
        (new RecoverableEnrollment(function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use ($root, &$pending): array {
            $pending = json_decode(file_get_contents($root.'/enrolment.pending.json'), true);

            return recoveryClientResponse($uuid, $public);
        }))->enrol(...$args);
        if ($problem === 'central authority') {
            $identity = json_decode(file_get_contents($args[0]), true);
            $identity['central_signing_public_key'] = base64_encode('short');
            file_put_contents($args[0], json_encode($identity));
        } else {
            $pending['request_signing_secret_key'] = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            file_put_contents($root.'/enrolment.pending.json', json_encode($pending));
            chmod($root.'/enrolment.pending.json', 0600);
        }
        $before = file_get_contents($args[0]);
        $client = new RecoverableEnrollment(fn () => throw new LogicException('Must not contact server.'));
        expect(fn () => $client->enrol(...$args))->toThrow(RuntimeException::class)
            ->and(file_get_contents($args[0]))->toBe($before);
        if ($problem === 'pending key') {
            expect($root.'/enrolment.pending.json')->toBeFile();
        }
    } finally {
        removeCollectorDirectory($root);
    }
})->with(['central authority', 'pending key']);

it('checks installed runtime locally after the setup grant expires and refuses a changed destination or identity', function (string $change) {
    $root = collectorTempDirectory('installed-runtime-check');
    $args = recoveryClientArguments($root);
    try {
        (new RecoverableEnrollment(fn ($_origin, $_pin, $_token, $uuid, $public, $_proof): array => recoveryClientResponse($uuid, $public)))->enrol(...$args);
        $identity = json_decode(file_get_contents($args[0]), true);
        $identity['enrolment_expires_at'] = gmdate(DATE_ATOM, time() - 3600);
        match ($change) {
            'none' => null,
            'server' => $identity['central_url'] = 'http://central.example.test',
            'pin' => $identity['tls_public_key_pin'] = 'sha256//short',
            'certificate path' => $identity['client_certificate_file'] = $root.'/elsewhere.pem',
            'collector' => $identity['collector_id'] = '2df1d87c-2d04-4e57-80ab-8a15f39c944e',
        };
        file_put_contents($args[0], json_encode($identity));
        $output = '';
        $application = new CollectorApplication(output: function (string $message) use (&$output): void {
            $output .= $message;
        });
        $exit = $application->run(['oblivion-collector', 'runtime-check', '--identity='.$args[0]]);
        expect($exit)->toBe($change === 'none' ? 0 : 1)
            ->and($output)->not->toContain($args[6], $identity['request_signing_secret_key'], 'PRIVATE KEY');
        if ($change === 'none') {
            expect($output)->toContain('locally ready; central monitoring health is not confirmed');
        }
    } finally {
        removeCollectorDirectory($root);
    }
})->with(['none', 'server', 'pin', 'certificate path', 'collector']);

it('confirms a saved identity with the current server grant without changing local progress', function () {
    $root = collectorTempDirectory('recoverable-confirm');
    $args = recoveryClientArguments($root);
    $calls = 0;
    $response = null;
    $revoked = false;
    $client = new RecoverableEnrollment(function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use (&$calls, &$response, &$revoked): array {
        $calls++;
        if ($revoked) {
            throw new CentralApiFailure('Grant revoked.');
        }

        return $response ??= recoveryClientResponse($uuid, $public);
    });
    try {
        $client->enrol(...[...$args, true, base64_encode(collectorPublicKey())]);
        $before = file_get_contents($args[0]);
        // A real runtime checkpoint must never be reset by setup confirmation.
        $checkpoint = new CheckpointFile($root.'/checkpoint.json');
        $checkpoint->merge(['acknowledged_source_sequence' => 12]);
        $progress = file_get_contents($root.'/checkpoint.json');
        $client->enrol(...[...$args, true, base64_encode(collectorPublicKey())]);
        expect($calls)->toBe(2)->and(file_get_contents($args[0]))->toBe($before)
            ->and(file_get_contents($root.'/checkpoint.json'))->toBe($progress);
        $revoked = true;
        expect(fn () => $client->enrol(...[...$args, true, base64_encode(collectorPublicKey())]))->toThrow(CentralApiFailure::class);
        expect($calls)->toBe(3)->and(file_get_contents($args[0]))->toBe($before)
            ->and(file_get_contents($root.'/checkpoint.json'))->toBe($progress);
    } finally {
        removeCollectorDirectory($root);
    }
});

it('rejects unexpected installation authority before committing and on completed retries', function (bool $completed) {
    $root = collectorTempDirectory('recoverable-authority');
    $args = recoveryClientArguments($root);
    $calls = 0;
    $client = new RecoverableEnrollment(function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use (&$calls): array {
        $calls++;

        return recoveryClientResponse($uuid, $public);
    });
    try {
        if ($completed) {
            $client->enrol(...$args);
        }
        expect(fn () => $client->enrol(...[...$args, true, base64_encode(str_repeat('X', 32))]))->toThrow(RuntimeException::class, 'authority differs');
        expect($calls)->toBe(1);
        if (! $completed) {
            expect($args[0])->not->toBeFile()->and($root.'/collector.crt.pem')->not->toBeFile();
        }
    } finally {
        removeCollectorDirectory($root);
    }
})->with([false, true]);

it('refuses a changed saved grant response even when its certificate and scope are valid', function () {
    $root = collectorTempDirectory('recoverable-changed-grant');
    $args = recoveryClientArguments($root);
    $calls = 0;
    $client = new RecoverableEnrollment(function ($_origin, $_pin, $_token, $uuid, $public, $_proof) use (&$calls): array {
        $calls++;

        return recoveryClientResponse($uuid, $public, ['enrolment_expires_at' => gmdate(DATE_ATOM, time() + ($calls === 1 ? 900 : 600))]);
    });
    try {
        $client->enrol(...$args);
        $before = file_get_contents($args[0]);
        expect(fn () => $client->enrol(...[...$args, true]))->toThrow(RuntimeException::class, 'differs from the saved identity');
        expect(file_get_contents($args[0]))->toBe($before);
    } finally {
        removeCollectorDirectory($root);
    }
});
