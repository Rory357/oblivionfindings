#!/usr/bin/php8.4
<?php

declare(strict_types=1);

use App\Support\Monitoring\CentralRuntimeReleaseAuthorityVerifier;
use App\Support\Monitoring\ExternalWatchdogEvidenceVerifier;
use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;

$root = dirname(__DIR__, 2);
$sources = [
    $root.'/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    $root.'/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
    $root.'/app/Support/Monitoring/CentralRuntimeReleaseAuthorityVerifier.php',
    $root.'/app/Support/Monitoring/ExternalWatchdogEvidenceVerifier.php',
];

$fail = static function (string $reason): never {
    fwrite(STDOUT, json_encode([
        'status' => 'failed',
        'reason' => $reason,
        'external_watchdog_release_evidence' => false,
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
        || preg_match('/\A--(central-runtime-evidence|evidence|public-key|signature)=(.+)\z/', $argument, $matches) !== 1
        || isset($arguments[$matches[1]])) {
        $fail('arguments');
    }
    $arguments[$matches[1]] = $matches[2];
}
$expectedArguments = ['central-runtime-evidence', 'evidence', 'public-key', 'signature'];
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

$authority = (new CentralRuntimeReleaseAuthorityVerifier)->loadInstalled();
if (! (new LoadSoakReleaseCheckoutVerifier('/usr/bin/git'))->verify(
    $applicationPath,
    (string) $authority['release_revision'],
)) {
    $fail('release_checkout');
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
        $fail('evidence_paths');
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
    $report = (new ExternalWatchdogEvidenceVerifier)->verify(
        rawEvidence: $readStable($arguments['evidence'], 65_536),
        encodedSignature: $readStable($arguments['signature'], 512),
        encodedPublicKey: $readStable($arguments['public-key'], 512),
        rawCentralRuntimeEvidence: $readStable($arguments['central-runtime-evidence'], 65_536),
        authority: $authority,
    );
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable) {
    $fail('verification');
}
