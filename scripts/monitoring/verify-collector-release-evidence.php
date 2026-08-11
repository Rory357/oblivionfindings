#!/usr/bin/php8.4
<?php

declare(strict_types=1);

use App\Support\Monitoring\CollectorReleaseAuthorityVerifier;
use App\Support\Monitoring\CollectorReleaseEvidenceVerifier;
use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;

$root = dirname(__DIR__, 2);
$sources = [
    $root.'/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    $root.'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
    $root.'/app/Support/Monitoring/CollectorReleaseAuthorityVerifier.php',
    $root.'/app/Support/Monitoring/CollectorReleaseEvidenceVerifier.php',
];

$fail = static function (string $reason): never {
    fwrite(STDOUT, json_encode([
        'status' => 'failed',
        'reason' => $reason,
        'collector_release_evidence' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
};

foreach ($sources as $source) {
    if (! is_file($source) || is_link($source)) {
        $fail('bootstrap');
    }
    require_once $source;
}

$arguments = [];
foreach (array_slice($argv, 1) as $argument) {
    if (! is_string($argument)
        || preg_match('/\A--(active-transport|evidence|output-directory|public-key|replacement-transport|revoked-transport|signature)=(.+)\z/', $argument, $matches) !== 1
        || array_key_exists($matches[1], $arguments)) {
        $fail('arguments');
    }
    $arguments[$matches[1]] = $matches[2];
}
$expectedArguments = [
    'active-transport',
    'evidence',
    'output-directory',
    'public-key',
    'replacement-transport',
    'revoked-transport',
    'signature',
];
$actualArguments = array_keys($arguments);
sort($expectedArguments, SORT_STRING);
sort($actualArguments, SORT_STRING);
if ($actualArguments !== $expectedArguments) {
    $fail('arguments');
}

$applicationPath = realpath($root);
$outputArgument = $arguments['output-directory'];
$outputDirectory = is_string($outputArgument) && ! is_link($outputArgument)
    ? realpath($outputArgument)
    : false;
if (PHP_OS_FAMILY !== 'Linux'
    || ! is_string($applicationPath)
    || ! is_string($outputDirectory)
    || ! str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)
    || ! is_dir($outputDirectory)
    || ! is_writable($outputDirectory)) {
    $fail('runtime');
}
$outputDirectoryBefore = @lstat($outputDirectory);
$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
if (! is_array($outputDirectoryBefore)
    || ! is_int($effectiveUid)
    || (($outputDirectoryBefore['mode'] ?? 0) & 0170000) !== 0040000
    || (($outputDirectoryBefore['mode'] ?? 0) & 0777) !== 0700
    || ($outputDirectoryBefore['uid'] ?? null) !== $effectiveUid) {
    $fail('output_directory_protection');
}

$readStable = static function (string $path, int $maximumBytes) use ($applicationPath, $fail): array {
    if (! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path)) {
        $fail('evidence_paths');
    }
    $resolved = realpath($path);
    if (! is_string($resolved)
        || $resolved === $applicationPath
        || str_starts_with($resolved.DIRECTORY_SEPARATOR, rtrim($applicationPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
        $fail('evidence_paths');
    }
    $before = @lstat($resolved);
    $handle = @fopen($resolved, 'rb');
    if (! is_array($before) || $handle === false) {
        $fail('evidence_files');
    }

    try {
        $opened = @fstat($handle);
        $size = is_array($opened) ? ($opened['size'] ?? null) : null;
        $mode = is_array($opened) ? ($opened['mode'] ?? null) : null;
        if (! is_array($opened)
            || ! is_int($size)
            || $size < 1
            || $size > $maximumBytes
            || ! is_int($mode)
            || ($mode & 0170000) !== 0100000
            || ($mode & 0022) !== 0) {
            $fail('evidence_files');
        }
        $raw = stream_get_contents($handle, $maximumBytes + 1);
        $read = @fstat($handle);
        $after = @lstat($resolved);
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! is_array($read)
                || ! is_array($after)
                || ! array_key_exists($key, $before)
                || ! array_key_exists($key, $opened)
                || ! array_key_exists($key, $read)
                || ! array_key_exists($key, $after)
                || $before[$key] !== $opened[$key]
                || $opened[$key] !== $read[$key]
                || $read[$key] !== $after[$key]) {
                $fail('evidence_files');
            }
        }
        if (! is_string($raw) || strlen($raw) !== $size) {
            $fail('evidence_files');
        }

        return [
            'path' => $resolved,
            'raw' => $raw,
            'identity' => array_intersect_key($after, array_flip(['dev', 'ino', 'mode', 'uid', 'size', 'mtime'])),
        ];
    } finally {
        fclose($handle);
    }
};

$inputsRemainPinned = static function (array $inputs): bool {
    foreach ($inputs as $input) {
        $path = $input['path'] ?? null;
        $identity = $input['identity'] ?? null;
        if (! is_string($path) || ! is_array($identity) || is_link($path)) {
            return false;
        }
        $after = @lstat($path);
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! is_array($after)
                || ! array_key_exists($key, $identity)
                || ! array_key_exists($key, $after)
                || $identity[$key] !== $after[$key]) {
                return false;
            }
        }
    }

    return true;
};

try {
    $authorityVerifier = new CollectorReleaseAuthorityVerifier;
    $authorityBefore = $authorityVerifier->loadInstalled();
    $checkoutVerifier = new LoadSoakReleaseCheckoutVerifier('/usr/bin/git');
    if (! $checkoutVerifier->verify($applicationPath, (string) $authorityBefore['release_revision'])) {
        $fail('release_checkout');
    }

    $inputs = [
        'active_transport' => $readStable($arguments['active-transport'], 32_768),
        'revoked_transport' => $readStable($arguments['revoked-transport'], 32_768),
        'replacement_transport' => $readStable($arguments['replacement-transport'], 32_768),
        'evidence' => $readStable($arguments['evidence'], 131_072),
        'signature' => $readStable($arguments['signature'], 512),
        'public_key' => $readStable($arguments['public-key'], 512),
    ];
    $report = (new CollectorReleaseEvidenceVerifier)->verify(
        rawActiveTransport: $inputs['active_transport']['raw'],
        rawRevokedTransport: $inputs['revoked_transport']['raw'],
        rawReplacementTransport: $inputs['replacement_transport']['raw'],
        rawEvidence: $inputs['evidence']['raw'],
        encodedSignature: $inputs['signature']['raw'],
        encodedPublicKey: $inputs['public_key']['raw'],
        authority: $authorityBefore,
    );

    $authorityAfter = $authorityVerifier->loadInstalled();
    if (! $authorityVerifier->identitiesRemainPinned([$authorityBefore, $authorityAfter])
        || ! $checkoutVerifier->verify($applicationPath, (string) $authorityAfter['release_revision'])) {
        $fail('release_identity_changed');
    }
    if (! $inputsRemainPinned($inputs)) {
        $fail('evidence_changed');
    }

    $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $artifactId = bin2hex(random_bytes(16));
    $artifactFile = 'monitoring-collector-release-verification-'
        .$createdAt->format('Ymd\THis.u\Z').'-'.$artifactId.'.json';
    $artifactPath = $outputDirectory.DIRECTORY_SEPARATOR.$artifactFile;
    $artifact = [
        ...$report,
        'artifact_id' => $artifactId,
        'created_at' => $createdAt->format('Y-m-d\TH:i:s.u\Z'),
        'output_storage_semantics' => 'collision_safe_exclusive_create',
        'worm_receipt_verified' => false,
    ];
    $encoded = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    $artifactSha256 = hash('sha256', $encoded);
    $checksumFile = $artifactFile.'.sha256';
    $checksumPath = $outputDirectory.DIRECTORY_SEPARATOR.$checksumFile;
    $checksumEncoded = $artifactSha256.'  '.$artifactFile.PHP_EOL;
    $writeExclusivePrivate = static function (string $path, string $contents) use ($effectiveUid): void {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('artifact_create');
        }
        $created = true;
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
                if ($written === false || $written === 0) {
                    throw new RuntimeException('artifact_write');
                }
                $offset += $written;
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('artifact_flush');
            }
            $written = @fstat($handle);
            $published = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
                if (! is_array($written)
                    || ! is_array($published)
                    || ! array_key_exists($key, $opened)
                    || ! array_key_exists($key, $written)
                    || ! array_key_exists($key, $published)
                    || $opened[$key] !== $written[$key]
                    || $written[$key] !== $published[$key]) {
                    throw new RuntimeException('artifact_identity');
                }
            }
            foreach (['size', 'mtime'] as $key) {
                if (! is_array($written)
                    || ! is_array($published)
                    || ! array_key_exists($key, $written)
                    || ! array_key_exists($key, $published)
                    || $written[$key] !== $published[$key]) {
                    throw new RuntimeException('artifact_identity');
                }
            }
            if (($written['size'] ?? null) !== $length) {
                throw new RuntimeException('artifact_size');
            }
            $created = false;
        } finally {
            fclose($handle);
            if ($created && is_file($path)) {
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
        $writeExclusivePrivate($artifactPath, $encoded);
        $artifactPublished = true;
        $writeExclusivePrivate($checksumPath, $checksumEncoded);
        $checksumPublished = true;
        $authorityFinal = $authorityVerifier->loadInstalled();
        if (! $authorityVerifier->identitiesRemainPinned([$authorityBefore, $authorityAfter, $authorityFinal])
            || ! $checkoutVerifier->verify($applicationPath, (string) $authorityFinal['release_revision'])) {
            throw new RuntimeException('release_identity_changed');
        }
        if (! $inputsRemainPinned($inputs)) {
            throw new RuntimeException('evidence_changed');
        }
        $outputDirectoryAfter = @lstat($outputDirectory);
        foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
            if (! is_array($outputDirectoryAfter)
                || ! array_key_exists($key, $outputDirectoryBefore)
                || ! array_key_exists($key, $outputDirectoryAfter)
                || $outputDirectoryBefore[$key] !== $outputDirectoryAfter[$key]) {
                throw new RuntimeException('output_directory_changed');
            }
        }
        if (! $publishedRemainsExact($artifactPath, $encoded)
            || ! $publishedRemainsExact($checksumPath, $checksumEncoded)) {
            throw new RuntimeException('published_artifact_changed');
        }
    } catch (Throwable) {
        if ($artifactPublished) {
            @unlink($artifactPath);
        }
        if ($checksumPublished) {
            @unlink($checksumPath);
        }
        $fail('artifact_write');
    }

    fwrite(STDOUT, json_encode([
        'status' => 'passed',
        'artifact_id' => $artifactId,
        'artifact_file' => $artifactFile,
        'artifact_sha256' => $artifactSha256,
        'checksum_file' => $checksumFile,
        'release_revision' => $report['release_revision'],
        'environment_reference_sha256' => $report['environment_reference_sha256'],
        'collector_release_evidence' => true,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable) {
    $fail('verification');
}
