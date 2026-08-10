<?php

function collectorKeyPair(): string
{
    static $pair;

    return $pair ??= sodium_crypto_sign_seed_keypair(str_repeat("\x5a", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

function collectorPublicKey(): string
{
    return sodium_crypto_sign_publickey(collectorKeyPair());
}

function collectorSecretKey(): string
{
    return sodium_crypto_sign_secretkey(collectorKeyPair());
}

function collectorIdentityKeyPair(): string
{
    static $pair;

    return $pair ??= sodium_crypto_sign_seed_keypair(str_repeat("\x6b", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

function collectorIdentitySecretKey(): string
{
    return sodium_crypto_sign_secretkey(collectorIdentityKeyPair());
}

/** @param array<string, scalar|null> $material @param array<string, mixed> $overrides */
function sealedCollectorCredentialLease(array $material, array $overrides = []): array
{
    $scope = array_replace([
        'version' => 1,
        'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'site_id' => 9,
        'device_id' => 'edge-1',
        'protocol' => 'snmp',
        'target' => '10.44.0.10',
        'expires_at' => '2026-07-23T12:30:00+00:00',
    ], $overrides);
    $plaintext = json_encode([
        ...$scope,
        'material' => $material,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signingPublicKey = sodium_crypto_sign_publickey(collectorIdentityKeyPair());
    $encryptionPublicKey = sodium_crypto_sign_ed25519_pk_to_curve25519($signingPublicKey);
    $sealed = sodium_crypto_box_seal($plaintext, $encryptionPublicKey);
    sodium_memzero($plaintext);

    return [
        ...$scope,
        'sealed_material' => base64_encode($sealed),
    ];
}

/** @param array<string, mixed> $overrides */
function signedCollectorConfig(array $overrides = []): string
{
    $payload = array_replace_recursive([
        'version' => 1,
        'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'site_id' => 9,
        'sequence' => 4,
        'issued_at' => '2026-07-23T11:55:00+00:00',
        'expires_at' => '2026-07-23T13:00:00+00:00',
        'revoked' => false,
        'scope' => [
            'cidrs' => ['10.44.0.0/24'],
            'devices' => [
                'edge-1' => ['10.44.0.10'],
                'server-1' => ['10.44.0.20'],
            ],
            'protocols' => ['icmp', 'tcp', 'dns', 'http', 'https', 'tls', 'snmp', 'ssh', 'winrm'],
        ],
        'checks' => [[
            'id' => 'check-edge-icmp',
            'device_id' => 'edge-1',
            'protocol' => 'icmp',
            'target' => '10.44.0.10',
        ]],
    ], $overrides);
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return json_encode([
        'payload' => base64_encode($json),
        'signature' => base64_encode(sodium_crypto_sign_detached($json, collectorSecretKey())),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function collectorTempDirectory(string $name): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-collector-'.$name.'-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);

    return $directory;
}

function removeCollectorDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

function collectorNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-07-23T12:00:00+00:00');
}
