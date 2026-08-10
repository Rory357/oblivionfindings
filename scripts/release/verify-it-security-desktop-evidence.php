#!/usr/bin/env php
<?php

use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use App\Support\Release\ItSecurityDesktopReleaseEvidenceVerifier;

$root = dirname(__DIR__, 2);
$bootstrapFiles = [
    '/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    '/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
    '/app/Support/Release/ItSecurityDesktopReleaseEvidenceVerifier.php',
];

foreach ($bootstrapFiles as $relativePath) {
    $path = $root.$relativePath;
    if (is_link($path) || ! is_file($path)) {
        fwrite(STDOUT, '{"status":"failed","reason":"bootstrap","v10_release_evidence":false}'.PHP_EOL);
        exit(1);
    }

    require_once $path;
}

const MAXIMUM_DESKTOP_MANIFEST_BYTES = 2_097_152;

$fail = static function (string $reason): never {
    fwrite(STDOUT, json_encode([
        'status' => 'failed',
        'reason' => $reason,
        'v10_release_evidence' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
};

$manifestArgument = null;
foreach (array_slice($argv, 1) as $argument) {
    if (! is_string($argument)
        || ! str_starts_with($argument, '--manifest=')
        || $manifestArgument !== null) {
        $fail('arguments');
    }
    $manifestArgument = substr($argument, strlen('--manifest='));
}
if (! is_string($manifestArgument) || $manifestArgument === '') {
    $fail('arguments');
}

$applicationPath = realpath($root);
$manifestPath = is_link($manifestArgument) ? false : realpath($manifestArgument);
if (PHP_OS_FAMILY !== 'Linux'
    || ! is_string($applicationPath)
    || ! is_string($manifestPath)
    || ! is_file($applicationPath.DIRECTORY_SEPARATOR.'artisan')
    || ! is_file($manifestPath)
    || is_link($manifestPath)) {
    $fail('paths');
}
$normalise = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
$applicationRoot = $normalise($applicationPath);
$manifestFile = $normalise($manifestPath);
if ($manifestFile === $applicationRoot || str_starts_with($manifestFile.'/', $applicationRoot.'/')) {
    $fail('paths');
}

$readProtectedManifest = static function (string $path) use ($fail): string {
    $before = @lstat($path);
    $handle = @fopen($path, 'rb');
    if (! is_array($before) || $handle === false) {
        $fail('manifest_file');
    }
    try {
        $opened = @fstat($handle);
        $after = @lstat($path);
        $mode = is_array($opened) ? ($opened['mode'] ?? null) : null;
        $size = is_array($opened) ? ($opened['size'] ?? null) : null;
        if (! is_array($opened)
            || ! is_array($after)
            || ! is_int($mode)
            || ($mode & 0170000) !== 0100000
            || ($mode & 0022) !== 0
            || ! is_int($size)
            || $size < 1
            || $size > MAXIMUM_DESKTOP_MANIFEST_BYTES) {
            $fail('manifest_file');
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! array_key_exists($key, $before)
                || ! array_key_exists($key, $opened)
                || ! array_key_exists($key, $after)
                || $before[$key] !== $opened[$key]
                || $opened[$key] !== $after[$key]) {
                $fail('manifest_file');
            }
        }
        $raw = stream_get_contents($handle, MAXIMUM_DESKTOP_MANIFEST_BYTES + 1);
        if (! is_string($raw) || strlen($raw) !== $size) {
            $fail('manifest_file');
        }
        $read = @fstat($handle);
        $final = @lstat($path);
        if (! is_array($read) || ! is_array($final)) {
            $fail('manifest_file');
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! array_key_exists($key, $read)
                || ! array_key_exists($key, $final)
                || $opened[$key] !== $read[$key]
                || $read[$key] !== $final[$key]) {
                $fail('manifest_file');
            }
        }

        return $raw;
    } finally {
        fclose($handle);
    }
};

$verifiedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$verifier = new ItSecurityDesktopReleaseEvidenceVerifier;
$authorityBefore = $verifier->verifyInstalledAuthority($verifiedAt);
if (($authorityBefore['valid'] ?? null) !== true) {
    $fail('release_authority');
}
$releaseRevision = $authorityBefore['release_revision'];
$checkoutVerifier = new LoadSoakReleaseCheckoutVerifier;
if (! is_string($releaseRevision)
    || ! $checkoutVerifier->verify($applicationPath, $releaseRevision)) {
    $fail('release_checkout');
}

$rawManifest = $readProtectedManifest($manifestPath);
$result = $verifier->verifyManifest($rawManifest, $authorityBefore, $verifiedAt);
if (($result['valid'] ?? null) !== true) {
    $fail('manifest');
}

$authorityAfter = $verifier->verifyInstalledAuthority(new DateTimeImmutable('now', new DateTimeZone('UTC')));
foreach ([
    'authority_reference',
    'authority_sha256',
    'environment_reference_sha256',
    'manifest_public_key_reference',
    'release_revision',
    'restored_environment_reference_sha256',
] as $identityKey) {
    if (($authorityAfter['valid'] ?? null) !== true
        || ! is_string($authorityBefore[$identityKey] ?? null)
        || ! is_string($authorityAfter[$identityKey] ?? null)
        || ! hash_equals($authorityBefore[$identityKey], $authorityAfter[$identityKey])) {
        $fail('release_authority_changed');
    }
}
if (! $checkoutVerifier->verify($applicationPath, $releaseRevision)) {
    $fail('release_checkout_changed');
}

fwrite(STDOUT, json_encode([
    'status' => 'verified',
    'release_revision' => $result['release_revision'],
    'environment_reference_sha256' => $result['environment_reference_sha256'],
    'restored_environment_reference_sha256' => $result['restored_environment_reference_sha256'],
    'authority_reference' => $result['authority_reference'],
    'manifest_sha256' => $result['manifest_sha256'],
    'primary_rows' => $result['primary_rows'],
    'primary_viewports' => $result['primary_viewports'],
    'restored_rows' => $result['restored_rows'],
    'restored_viewports' => $result['restored_viewports'],
    'v10_release_evidence' => true,
], JSON_UNESCAPED_SLASHES).PHP_EOL);
