#!/usr/bin/env php
<?php

use App\Support\Monitoring\LoadSoakEvidenceVerifier;
use App\Support\Monitoring\LoadSoakPlatformAttestationVerifier;
use App\Support\Monitoring\LoadSoakReleaseAuthorityVerifier;
use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use App\Support\Monitoring\StrictJsonObjectDecoder;

$root = dirname(__DIR__, 2);
$bootstrapFiles = [
    '/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    '/app/Support/Monitoring/LoadSoakEvidenceVerifier.php',
    '/app/Support/Monitoring/LoadSoakPlatformAttestationVerifier.php',
    '/app/Support/Monitoring/LoadSoakReleaseAuthorityVerifier.php',
    '/app/Support/Monitoring/LoadSoakReleaseCheckoutVerifier.php',
];

foreach ($bootstrapFiles as $relativePath) {
    $path = $root.$relativePath;
    if (is_link($path) || ! is_file($path)) {
        fwrite(STDOUT, '{"status":"failed","reason":"bootstrap","v09_release_evidence":false}'.PHP_EOL);
        exit(1);
    }

    require_once $path;
}

$fail = static function (string $reason): never {
    fwrite(STDOUT, json_encode([
        'status' => 'failed',
        'reason' => $reason,
        'v09_release_evidence' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
};

if (count($argv) < 5 || count($argv) > 6) {
    $fail('usage');
}

$arguments = [];
$testAuthority = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--test-authority') {
        if ($testAuthority) {
            $fail('arguments');
        }
        $testAuthority = true;

        continue;
    }
    if (! is_string($argument)
        || preg_match('/\A--(attestation|evidence|output-directory|public-key)=(.+)\z/', $argument, $matches) !== 1
        || array_key_exists($matches[1], $arguments)) {
        $fail('arguments');
    }
    $arguments[$matches[1]] = $matches[2];
}

if (array_keys($arguments) === []
    || count($arguments) !== 4
    || ! isset($arguments['attestation'], $arguments['evidence'], $arguments['output-directory'], $arguments['public-key'])) {
    $fail('arguments');
}

$testPublicKeySha256 = null;
if ($testAuthority) {
    $testPublicKeySha256 = getenv('MONITORING_LOAD_SOAK_ATTESTATION_PUBLIC_KEY_SHA256');
    if (! is_string($testPublicKeySha256)
        || preg_match('/\A[0-9a-f]{64}\z/', $testPublicKeySha256) !== 1) {
        $fail('public_key_pin');
    }
}

$readStableInput = static function (string $path, int $maximumBytes) use ($fail): array {
    if (is_link($path)) {
        $fail('paths');
    }
    $resolved = realpath($path);
    $before = is_string($resolved) ? @lstat($resolved) : false;
    $handle = is_string($resolved) ? @fopen($resolved, 'rb') : false;
    if (! is_string($resolved) || ! is_array($before) || $handle === false) {
        $fail('paths');
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
            || ($mode & 0170000) !== 0100000) {
            $fail('paths');
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
                $fail('read');
            }
        }
        if (! is_string($raw) || strlen($raw) !== $size) {
            $fail('read');
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

$inputs = [
    'evidence' => $readStableInput($arguments['evidence'], 2_097_152),
    'attestation' => $readStableInput($arguments['attestation'], 65_536),
    'public_key' => $readStableInput($arguments['public-key'], 4_096),
];
$outputDirectory = realpath($arguments['output-directory']);
if (! is_string($outputDirectory) || ! is_dir($outputDirectory) || ! is_writable($outputDirectory)) {
    $fail('paths');
}

$rawEvidence = $inputs['evidence']['raw'];
$rawAttestation = $inputs['attestation']['raw'];
$rawPublicKey = $inputs['public_key']['raw'];
$inputsRemainPinned = static function (array $records): bool {
    foreach ($records as $record) {
        $path = $record['path'] ?? null;
        $identity = $record['identity'] ?? null;
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

$decoder = new StrictJsonObjectDecoder;
$evidence = null;
$attestation = null;
try {
    $evidence = $decoder->decode($rawEvidence);
    $attestation = $decoder->decode($rawAttestation);
} catch (Throwable) {
    // The collision-safe failed artifact below retains only source hashes.
}

$verifiedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$verification = $evidence === null
    ? [
        'status' => 'failed',
        'checks' => ['strict_unique_key_json_object' => false],
        'violations_count' => 1,
        'run_id' => null,
        'release_revision' => null,
        'environment_fingerprint' => null,
        'load_profile_sha256' => null,
        'measurement_contract_sha256' => null,
        'supervisor_observation_generation' => null,
        'observed_duration_seconds' => null,
        'achieved_throughput_per_second' => null,
        'aggregate_error_rate_percent' => null,
        'sample_count' => 0,
        'release_provenance_verified' => false,
    ]
    : (new LoadSoakEvidenceVerifier)->verify($evidence, $verifiedAt);

$releaseAuthorityVerifier = new LoadSoakReleaseAuthorityVerifier;
$releaseAuthority = ! $testAuthority && $evidence !== null && $attestation !== null
    ? $releaseAuthorityVerifier->verifyInstalled(
        hash('sha256', $rawEvidence),
        hash('sha256', $rawAttestation),
        $evidence,
        $attestation,
        $rawPublicKey,
        $verifiedAt,
    )
    : [
        'valid' => false,
        'authority_reference' => null,
        'public_key_sha256' => null,
    ];
$expectedPublicKeySha256 = $testAuthority
    ? $testPublicKeySha256
    : ($releaseAuthority['public_key_sha256'] ?? '');
$attestationResult = $evidence !== null && $attestation !== null
    ? (new LoadSoakPlatformAttestationVerifier)->verify(
        $attestation,
        hash('sha256', $rawEvidence),
        $evidence,
        $rawPublicKey,
        $expectedPublicKeySha256,
        $verifiedAt,
    )
    : ['valid' => false, 'public_key_sha256' => null];
$contractValid = $verification['status'] === 'contract_valid';
$attestationValid = $attestationResult['valid'] === true;
$releaseAuthorityValid = $releaseAuthority['valid'] === true;
$releaseCheckoutVerifier = new LoadSoakReleaseCheckoutVerifier;
$releaseCheckoutValid = ! $testAuthority
    && $releaseAuthorityValid
    && is_string($verification['release_revision'] ?? null)
    && $releaseCheckoutVerifier->verify(
        dirname(__DIR__, 2),
        $verification['release_revision'],
    );
$releaseProvenance = $contractValid
    && $attestationValid
    && $releaseAuthorityValid
    && $releaseCheckoutValid
    && ! $testAuthority;
$testContractValid = $contractValid && $attestationValid && $testAuthority;
$status = match (true) {
    $releaseProvenance => 'passed',
    $testContractValid => 'contract_valid_test_authority',
    default => 'failed',
};

$outputDirectoryBefore = @lstat($outputDirectory);
$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
if ($releaseProvenance) {
    $applicationPath = realpath($root);
    $relativeOutput = is_string($applicationPath)
        ? str_starts_with($outputDirectory.DIRECTORY_SEPARATOR, rtrim($applicationPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
        : true;
    if (PHP_OS_FAMILY !== 'Linux'
        || ! is_string($applicationPath)
        || is_link($arguments['output-directory'])
        || $relativeOutput
        || ! is_array($outputDirectoryBefore)
        || ! is_int($effectiveUid)
        || (($outputDirectoryBefore['mode'] ?? 0) & 0170000) !== 0040000
        || (($outputDirectoryBefore['mode'] ?? 0) & 0777) !== 0700
        || ($outputDirectoryBefore['uid'] ?? null) !== $effectiveUid) {
        $fail('output_directory_protection');
    }
    foreach ($inputs as $input) {
        $path = $input['path'];
        $identity = $input['identity'];
        if (str_starts_with($path.DIRECTORY_SEPARATOR, rtrim($applicationPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || (($identity['mode'] ?? 0) & 0022) !== 0
            || ($identity['uid'] ?? null) !== $effectiveUid) {
            $fail('input_protection');
        }
    }
}

$artifactId = bin2hex(random_bytes(16));
$timestamp = $verifiedAt->format('Ymd\THis.u\Z');
$artifactFile = "monitoring-load-soak-verification-{$timestamp}-{$artifactId}.json";
$artifactPath = $outputDirectory.DIRECTORY_SEPARATOR.$artifactFile;
$artifact = [
    'schema_version' => 2,
    'artifact_id' => $artifactId,
    'evidence_class' => 'monitoring_load_soak_verification_v2',
    'verified_at' => $verifiedAt->format('Y-m-d\TH:i:s.u\Z'),
    'source_sha256' => hash('sha256', $rawEvidence),
    'attestation_sha256' => hash('sha256', $rawAttestation),
    'public_key_sha256' => $attestationResult['public_key_sha256'],
    'authority_scope' => match (true) {
        $testAuthority => 'test_only',
        $releaseAuthorityValid => 'release_platform',
        default => 'unverified',
    },
    'release_authority_verified' => $releaseAuthorityValid,
    'release_authority_reference' => $releaseAuthority['authority_reference'],
    'release_checkout_verified' => $releaseCheckoutValid,
    'status' => $status,
    'source_contract_status' => $verification['status'],
    'platform_attestation_verified' => $attestationValid,
    'release_provenance_verified' => $releaseProvenance,
    'v09_release_evidence' => $releaseProvenance,
    'checks' => $verification['checks'],
    'violations_count' => $verification['violations_count']
        + ($attestationValid ? 0 : 1)
        + (! $testAuthority && ! $releaseAuthorityValid ? 1 : 0)
        + (! $testAuthority && ! $releaseCheckoutValid ? 1 : 0),
    'run_id' => $verification['run_id'],
    'release_revision' => $verification['release_revision'],
    'environment_fingerprint' => $verification['environment_fingerprint'],
    'load_profile_sha256' => $verification['load_profile_sha256'],
    'measurement_contract_sha256' => $verification['measurement_contract_sha256'],
    'supervisor_observation_generation' => $verification['supervisor_observation_generation'],
    'observed_duration_seconds' => $verification['observed_duration_seconds'],
    'achieved_throughput_per_second' => $verification['achieved_throughput_per_second'],
    'aggregate_error_rate_percent' => $verification['aggregate_error_rate_percent'],
    'sample_count' => $verification['sample_count'],
    'verification_artifact_contains_targets_credentials_or_payloads' => false,
    'output_storage_semantics' => 'collision_safe_exclusive_create',
    'worm_receipt_verified' => false,
    'local_fixture_can_close_v09' => false,
    'test_authority_can_close_v09' => false,
];
$encoded = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
$artifactSha256 = hash('sha256', $encoded);
$checksumFile = $artifactFile.'.sha256';
$checksumPath = $outputDirectory.DIRECTORY_SEPARATOR.$checksumFile;
$checksumEncoded = $artifactSha256.'  '.$artifactFile.PHP_EOL;
$writeExclusive = static function (
    string $path,
    string $contents,
    bool $privateReleaseOutput,
) use ($effectiveUid): void {
    $handle = @fopen($path, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('artifact_create');
    }
    $created = true;
    try {
        if ($privateReleaseOutput && ! @chmod($path, 0600)) {
            throw new RuntimeException('artifact_permissions');
        }
        $opened = @fstat($handle);
        if (! is_array($opened)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || ($privateReleaseOutput && (
                (($opened['mode'] ?? 0) & 0777) !== 0600
                || ($opened['uid'] ?? null) !== $effectiveUid
            ))) {
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

$publishedRemainsExact = static function (
    string $path,
    string $expected,
    bool $privateReleaseOutput,
) use ($effectiveUid): bool {
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
            || ($privateReleaseOutput && (
                (($opened['mode'] ?? 0) & 0777) !== 0600
                || ($opened['uid'] ?? null) !== $effectiveUid
            ))) {
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
    $writeExclusive($artifactPath, $encoded, $releaseProvenance);
    $artifactPublished = true;
    $writeExclusive($checksumPath, $checksumEncoded, $releaseProvenance);
    $checksumPublished = true;

    if (! $inputsRemainPinned($inputs)) {
        throw new RuntimeException('input_changed');
    }
    if ($releaseProvenance) {
        $publishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $releaseAuthorityFinal = $releaseAuthorityVerifier->verifyInstalled(
            hash('sha256', $rawEvidence),
            hash('sha256', $rawAttestation),
            $evidence,
            $attestation,
            $rawPublicKey,
            $publishedAt,
        );
        if (($releaseAuthorityFinal['valid'] ?? null) !== true
            || ! hash_equals((string) $releaseAuthority['authority_reference'], (string) $releaseAuthorityFinal['authority_reference'])
            || ! hash_equals((string) $releaseAuthority['public_key_sha256'], (string) $releaseAuthorityFinal['public_key_sha256'])
            || ! $releaseCheckoutVerifier->verify($root, (string) $verification['release_revision'])) {
            throw new RuntimeException('release_identity_changed');
        }
    }
    $outputDirectoryAfter = @lstat($outputDirectory);
    foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
        if (! is_array($outputDirectoryBefore)
            || ! is_array($outputDirectoryAfter)
            || ! array_key_exists($key, $outputDirectoryBefore)
            || ! array_key_exists($key, $outputDirectoryAfter)
            || $outputDirectoryBefore[$key] !== $outputDirectoryAfter[$key]) {
            throw new RuntimeException('output_directory_changed');
        }
    }
    if (! $publishedRemainsExact($artifactPath, $encoded, $releaseProvenance)
        || ! $publishedRemainsExact($checksumPath, $checksumEncoded, $releaseProvenance)) {
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
    'status' => $artifact['status'],
    'authority_scope' => $artifact['authority_scope'],
    'artifact_id' => $artifactId,
    'artifact_file' => $artifactFile,
    'artifact_sha256' => $artifactSha256,
    'checksum_file' => $checksumFile,
    'release_provenance_verified' => $releaseProvenance,
    'release_authority_verified' => $releaseAuthorityValid,
    'release_checkout_verified' => $releaseCheckoutValid,
    'violations_count' => $artifact['violations_count'],
], JSON_UNESCAPED_SLASHES).PHP_EOL);

exit(in_array($status, ['passed', 'contract_valid_test_authority'], true) ? 0 : 1);
