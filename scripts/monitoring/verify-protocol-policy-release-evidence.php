#!/usr/bin/php8.4
<?php

declare(strict_types=1);

use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use App\Support\Monitoring\ProtocolPolicyReleaseAuthorityVerifier;
use App\Support\Monitoring\ProtocolPolicyReleaseEvidenceVerifier;

$root = dirname(__DIR__, 2);
$sources = [
    $root.'/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    $root.'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
    $root.'/app/Support/Monitoring/ProtocolPolicyReleaseAuthorityVerifier.php',
    $root.'/app/Support/Monitoring/ProtocolPolicyReleaseEvidenceVerifier.php',
];

$fail = static function (string $reason): never {
    fwrite(STDOUT, json_encode([
        'status' => 'failed',
        'reason' => $reason,
        'a07_release_evidence' => false,
        'a08_release_evidence' => false,
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
        || preg_match('/\A--(evidence|public-key|s10-release-evidence|signature)=(.+)\z/', $argument, $matches) !== 1
        || array_key_exists($matches[1], $arguments)) {
        $fail('arguments');
    }
    $arguments[$matches[1]] = $matches[2];
}
$expectedArguments = ['evidence', 'public-key', 's10-release-evidence', 'signature'];
$actualArguments = array_keys($arguments);
sort($expectedArguments, SORT_STRING);
sort($actualArguments, SORT_STRING);
if ($actualArguments !== $expectedArguments) {
    $fail('arguments');
}

$applicationPath = realpath($root);
if (PHP_OS_FAMILY !== 'Linux' || ! is_string($applicationPath)) {
    $fail('runtime');
}

$readStable = static function (string $path, int $maximumBytes) use ($applicationPath, $fail): string {
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

        return $raw;
    } finally {
        fclose($handle);
    }
};

try {
    $authorityVerifier = new ProtocolPolicyReleaseAuthorityVerifier;
    $authorityBefore = $authorityVerifier->loadInstalled();
    $checkoutVerifier = new LoadSoakReleaseCheckoutVerifier('/usr/bin/git');
    if (! $checkoutVerifier->verify($applicationPath, (string) $authorityBefore['release_revision'])) {
        $fail('release_checkout');
    }

    $report = (new ProtocolPolicyReleaseEvidenceVerifier)->verify(
        rawS10Evidence: $readStable($arguments['s10-release-evidence'], 131_072),
        rawEvidence: $readStable($arguments['evidence'], 131_072),
        encodedSignature: $readStable($arguments['signature'], 512),
        encodedPublicKey: $readStable($arguments['public-key'], 512),
        authority: $authorityBefore,
    );

    $authorityAfter = $authorityVerifier->loadInstalled();
    if (! $authorityVerifier->identitiesRemainPinned([$authorityBefore, $authorityAfter])
        || ! $checkoutVerifier->verify($applicationPath, (string) $authorityAfter['release_revision'])) {
        $fail('release_identity_changed');
    }

    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable) {
    $fail('verification');
}
