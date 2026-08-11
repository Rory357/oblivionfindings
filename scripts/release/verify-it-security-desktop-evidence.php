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

$arguments = [];
foreach (array_slice($argv, 1) as $argument) {
    if (! is_string($argument)
        || preg_match('/\A--(manifest|output-directory)=(.+)\z/', $argument, $matches) !== 1
        || array_key_exists($matches[1], $arguments)) {
        $fail('arguments');
    }
    $arguments[$matches[1]] = $matches[2];
}
$argumentNames = array_keys($arguments);
sort($argumentNames, SORT_STRING);
if ($argumentNames !== ['manifest', 'output-directory']) {
    $fail('arguments');
}

$applicationPath = realpath($root);
$manifestArgument = $arguments['manifest'];
$manifestPath = is_link($manifestArgument) ? false : realpath($manifestArgument);
$outputArgument = $arguments['output-directory'];
$outputDirectory = is_link($outputArgument) ? false : realpath($outputArgument);
if (PHP_OS_FAMILY !== 'Linux'
    || ! is_string($applicationPath)
    || ! is_string($manifestPath)
    || ! is_string($outputDirectory)
    || ! is_file($applicationPath.DIRECTORY_SEPARATOR.'artisan')
    || ! is_file($manifestPath)
    || is_link($manifestPath)
    || ! is_dir($outputDirectory)
    || ! is_writable($outputDirectory)) {
    $fail('paths');
}
$normalise = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
$applicationRoot = $normalise($applicationPath);
$manifestFile = $normalise($manifestPath);
if ($manifestFile === $applicationRoot || str_starts_with($manifestFile.'/', $applicationRoot.'/')) {
    $fail('paths');
}
$outputRoot = $normalise($outputDirectory);
if ($outputRoot === $applicationRoot || str_starts_with($outputRoot.'/', $applicationRoot.'/')) {
    $fail('paths');
}
$outputDirectoryBefore = @lstat($outputDirectory);
$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
if (! is_array($outputDirectoryBefore)
    || ! is_int($effectiveUid)
    || (($outputDirectoryBefore['mode'] ?? 0) & 0170000) !== 0040000
    || (($outputDirectoryBefore['mode'] ?? 0) & 0777) !== 0700
    || ($outputDirectoryBefore['uid'] ?? null) !== $effectiveUid) {
    $fail('output_directory');
}

$readProtectedManifest = static function (string $path) use ($fail): array {
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

        return [
            'identity' => array_intersect_key($opened, array_flip(['dev', 'ino', 'mode', 'mtime', 'size', 'uid'])),
            'raw' => $raw,
        ];
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

$manifestBefore = $readProtectedManifest($manifestPath);
$rawManifest = $manifestBefore['raw'];
$result = $verifier->verifyManifest($rawManifest, $authorityBefore, $verifiedAt);
if (($result['valid'] ?? null) !== true) {
    $fail('manifest');
}
if (! $verifier->retainedPackageArtifactsAreValid(
    $result['retained_artifacts'] ?? null,
    dirname($manifestPath),
    $applicationPath,
)) {
    $fail('retained_artifacts');
}
$manifestAfter = $readProtectedManifest($manifestPath);
if (! $verifier->protectedManifestRemainsPinned($manifestBefore, $manifestAfter)) {
    $fail('manifest_changed');
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

$createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$artifactId = bin2hex(random_bytes(16));
$artifactFile = 'it-security-desktop-release-verification-'
    .$createdAt->format('Ymd\THis.u\Z').'-'.$artifactId.'.json';
$artifactPath = $outputDirectory.DIRECTORY_SEPARATOR.$artifactFile;
$artifact = [
    'status' => 'verified',
    'artifact_id' => $artifactId,
    'created_at_utc' => $createdAt->format('Y-m-d\TH:i:s.u\Z'),
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
    'publication' => 'collision_safe_exclusive_create',
    'worm_receipt_verified' => false,
];
$encodedArtifact = json_encode(
    $artifact,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$artifactSha256 = hash('sha256', $encodedArtifact);
$checksumFile = $artifactFile.'.sha256';
$checksumPath = $outputDirectory.DIRECTORY_SEPARATOR.$checksumFile;
$writeExclusive = static function (string $path, string $contents) use ($effectiveUid): void {
    $handle = @fopen($path, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('artifact_create');
    }
    $complete = false;
    try {
        if (! @chmod($path, 0600)) {
            throw new RuntimeException('artifact_permissions');
        }
        $opened = @fstat($handle);
        if (! is_array($opened)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || (($opened['mode'] ?? 0) & 0777) !== 0600
            || ($opened['uid'] ?? null) !== $effectiveUid) {
            throw new RuntimeException('artifact_permissions');
        }
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('artifact_write');
            }
            $offset += $written;
        }
        if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
            throw new RuntimeException('artifact_flush');
        }
        $writtenIdentity = @fstat($handle);
        $publishedIdentity = @lstat($path);
        if (! is_array($writtenIdentity)
            || ! is_array($publishedIdentity)
            || ($writtenIdentity['size'] ?? null) !== $length) {
            throw new RuntimeException('artifact_size');
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (($writtenIdentity[$key] ?? null) !== ($publishedIdentity[$key] ?? null)) {
                throw new RuntimeException('artifact_identity');
            }
        }
        $complete = true;
    } finally {
        fclose($handle);
        if (! $complete && is_file($path)) {
            @unlink($path);
        }
    }
};
$publishedRemainsExact = static function (string $path, string $expected) use ($effectiveUid): bool {
    if (is_link($path)) {
        return false;
    }
    $before = @lstat($path);
    $handle = @fopen($path, 'rb');
    if (! is_array($before) || $handle === false) {
        return false;
    }
    try {
        $opened = @fstat($handle);
        $contents = stream_get_contents($handle, strlen($expected) + 1);
        $read = @fstat($handle);
        $final = @lstat($path);
        if (! is_array($opened)
            || ! is_array($read)
            || ! is_array($final)
            || ! is_string($contents)
            || ! hash_equals($expected, $contents)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || (($opened['mode'] ?? 0) & 0777) !== 0600
            || ($opened['uid'] ?? null) !== $effectiveUid) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (($before[$key] ?? null) !== ($opened[$key] ?? null)
                || ($opened[$key] ?? null) !== ($read[$key] ?? null)
                || ($read[$key] ?? null) !== ($final[$key] ?? null)) {
                return false;
            }
        }

        return true;
    } finally {
        fclose($handle);
    }
};

$artifactPublished = false;
$checksumPublished = false;
try {
    $writeExclusive($artifactPath, $encodedArtifact);
    $artifactPublished = true;
    $writeExclusive($checksumPath, $artifactSha256.'  '.$artifactFile.PHP_EOL);
    $checksumPublished = true;

    $manifestFinal = $readProtectedManifest($manifestPath);
    if (! $verifier->protectedManifestRemainsPinned($manifestBefore, $manifestFinal)
        || ! $verifier->retainedPackageArtifactsAreValid(
            $result['retained_artifacts'] ?? null,
            dirname($manifestPath),
            $applicationPath,
        )) {
        throw new RuntimeException('package_changed');
    }
    $authorityFinal = $verifier->verifyInstalledAuthority(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    foreach ([
        'authority_reference',
        'authority_sha256',
        'environment_reference_sha256',
        'manifest_public_key_reference',
        'release_revision',
        'restored_environment_reference_sha256',
    ] as $identityKey) {
        if (($authorityFinal['valid'] ?? null) !== true
            || ! is_string($authorityBefore[$identityKey] ?? null)
            || ! is_string($authorityFinal[$identityKey] ?? null)
            || ! hash_equals($authorityBefore[$identityKey], $authorityFinal[$identityKey])) {
            throw new RuntimeException('release_authority_changed');
        }
    }
    if (! $checkoutVerifier->verify($applicationPath, $releaseRevision)) {
        throw new RuntimeException('release_checkout_changed');
    }
    $outputDirectoryAfter = @lstat($outputDirectory);
    foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
        if (! is_array($outputDirectoryAfter)
            || ($outputDirectoryBefore[$key] ?? null) !== ($outputDirectoryAfter[$key] ?? null)) {
            throw new RuntimeException('output_directory_changed');
        }
    }
    if (! $publishedRemainsExact($artifactPath, $encodedArtifact)
        || ! $publishedRemainsExact($checksumPath, $artifactSha256.'  '.$artifactFile.PHP_EOL)) {
        throw new RuntimeException('published_artifact_changed');
    }
} catch (Throwable) {
    if ($checksumPublished && is_file($checksumPath)) {
        @unlink($checksumPath);
    }
    if ($artifactPublished && is_file($artifactPath)) {
        @unlink($artifactPath);
    }
    $fail('publication');
}

fwrite(STDOUT, json_encode([
    'status' => 'verified',
    'artifact_id' => $artifactId,
    'artifact_file' => $artifactFile,
    'artifact_sha256' => $artifactSha256,
    'checksum_file' => $checksumFile,
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
    'publication' => 'collision_safe_exclusive_create',
    'worm_receipt_verified' => false,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
