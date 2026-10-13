<?php

use Oblivion\Collector\CollectorApplication;
use Oblivion\Collector\Enrollment\InstallationProfile;
use Oblivion\Collector\Enrollment\RecoverableEnrollment;

function siteInstallationProfile(string $platform): array
{
    return [
        'version' => 1, 'component' => 'site', 'platform' => $platform,
        'deployment_url' => 'https://care.example.test', 'server_url' => 'https://collector.example.test',
        'server_public_key' => base64_encode(collectorPublicKey()),
        'tls_public_key_pin' => 'sha256//'.base64_encode(str_repeat('P', 32)),
        'organisation' => 'Example Care', 'site_id' => 9, 'site_label' => 'Main office',
        'collector_uuid' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'enrolment_token' => 'ofc_enrol_'.str_repeat('A', 64), 'expires_at' => gmdate(DATE_ATOM, time() + 900),
    ];
}

it('reads the exact exported profile on its intended Windows or Linux platform', function (string $platform) {
    $profile = siteInstallationProfile($platform);
    $bytes = json_encode($profile, JSON_THROW_ON_ERROR);
    expect(InstallationProfile::decode($bytes, $platform))->toBe($profile);
    $other = $platform === 'windows' ? 'linux' : 'windows';
    expect(fn () => InstallationProfile::decode($bytes, $other))->toThrow(RuntimeException::class);
})->with(['windows', 'linux']);

it('rejects ambiguous nested expired or misdirected profiles before consuming authority', function () {
    $profile = siteInstallationProfile('linux');
    $raw = json_encode($profile, JSON_THROW_ON_ERROR);
    foreach ([
        $raw.$raw, substr($raw, 0, -1).',"site_id":9}', str_replace('"site_id":9', '"site_id":null', $raw),
        str_replace('"site_id":9', '"site_id":9.0', $raw), str_replace('"site_id":9', '"site_id":{"id":9}', $raw),
        str_replace('"site_id":9', '"site_id":[9]', $raw), str_replace('"version"', '"Version"', $raw),
        str_replace('"site_id":9', '"site_id":9,"\u0073ite_id":9', $raw), str_repeat(' ', 16385),
    ] as $bad) {
        expect(fn () => InstallationProfile::decode($bad, 'linux'))->toThrow(Exception::class);
    }
    foreach ([
        ['expires_at' => gmdate(DATE_ATOM, time() - 1)], ['expires_at' => gmdate(DATE_ATOM, time() + 3600)],
        ['deployment_url' => 'https://user:password@care.example.test'], ['server_url' => 'http://collector.example.test'],
        ['server_url' => 'https://collector.example.test/path'], ['server_public_key' => 'invalid'],
        ['tls_public_key_pin' => 'sha256//invalid'], ['site_id' => '9'], ['tenant_id' => 1],
        ['site_label' => "Main\u{202e}office"], ['collector_uuid' => 'not-an-identity'], ['component' => 'computer'],
    ] as $change) {
        expect(fn () => InstallationProfile::decode(json_encode(array_replace($profile, $change)), 'linux'))->toThrow(RuntimeException::class);
    }
    // Escaped quotes, slashes and property-like text within a legitimate label are data.
    $profile['site_label'] = 'Office "site_id":9, backslash \\ and ā';
    expect(InstallationProfile::decode(json_encode($profile), 'linux'))->toBe($profile);
});

it('enrols from the profile with current confirmation and never uses an environment token or replacement identity', function () {
    $root = collectorTempDirectory('installation-profile-state');
    $files = collectorTempDirectory('installation-profile-files');
    $platform = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
    $profile = siteInstallationProfile($platform);
    $path = $files.'/profile.json';
    file_put_contents($path, json_encode($profile, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    $calls = 0;
    $response = null;
    $output = '';
    $client = new CollectorApplication(
        output: function ($message) use (&$output) {
            $output .= $message;
        },
        recoverableEnrollmentTransport: function ($origin, $pin, $token, $uuid, $public, $proof) use ($profile, &$calls, &$response): array {
            $calls++;
            expect($origin)->toBe($profile['server_url'])->and($pin)->toBe($profile['tls_public_key_pin'])
                ->and($token)->toBe($profile['enrolment_token'])->and($uuid)->toBe($profile['collector_uuid'])
                ->and(sodium_crypto_sign_verify_detached($proof, RecoverableEnrollment::DOMAIN.$uuid."\n".hash('sha256', $token)."\n".base64_encode($public), $public))->toBeTrue();
            $cert = collectorCertificatePair('oblivion-collector-'.$uuid);
            $payload = [
                'version' => 2, 'collector_id' => $uuid, 'site_id' => 9,
                'central_signing_public_key' => $profile['server_public_key'],
                'client_certificate' => $cert['certificate'], 'client_private_key' => $cert['private_key'],
                'client_certificate_fingerprint' => strtolower(openssl_x509_fingerprint($cert['certificate'], 'sha256')),
                'client_certificate_expires_at' => gmdate(DATE_ATOM, time() + 3600),
                'acknowledged_source_sequence' => 0, 'enrolment_expires_at' => $profile['expires_at'],
            ];

            return $response ??= [
                'version' => 2, 'collector_id' => $uuid, 'recipient_key_fingerprint' => hash('sha256', $public),
                'sealed_identity' => base64_encode(sodium_crypto_box_seal(json_encode($payload), sodium_crypto_sign_ed25519_pk_to_curve25519($public))),
            ];
        },
    );
    $args = ['collector', 'enrol-profile', '--profile='.$path, '--state-directory='.$root, '--confirm-server='.$profile['deployment_url']];
    putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN=must-not-be-used');
    try {
        expect($client->run($args))->toBe(1)->and($calls)->toBe(0);
        $args[] = '--confirm-collector-server='.$profile['server_url'];
        expect($client->run($args))->toBe(0)->and($calls)->toBe(1);
        $saved = file_get_contents($root.'/collector.identity.json');
        expect($client->run($args))->toBe(0)->and($calls)->toBe(2)
            ->and(file_get_contents($root.'/collector.identity.json'))->toBe($saved)
            ->and($saved)->not->toContain($profile['enrolment_token'])
            ->and($output)->not->toContain($profile['enrolment_token'], 'must-not-be-used');
        expect($client->run([...$args, '--central-url=https://attacker.test']))->toBe(1)->and($calls)->toBe(2);
        file_put_contents($path, str_repeat('a', 16385));
        expect(fn () => InstallationProfile::read($path, $platform))->toThrow(RuntimeException::class);
    } finally {
        putenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN');
        removeCollectorDirectory($root);
        removeCollectorDirectory($files);
    }
});
