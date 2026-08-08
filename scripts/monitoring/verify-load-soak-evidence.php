#!/usr/bin/env php
<?php

use App\Support\Monitoring\LoadSoakEvidenceVerifier;
use App\Support\Monitoring\LoadSoakPlatformAttestationVerifier;
use App\Support\Monitoring\StrictJsonObjectDecoder;

require dirname(__DIR__, 2).'/vendor/autoload.php';

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

$expectedPublicKeySha256 = getenv('MONITORING_LOAD_SOAK_ATTESTATION_PUBLIC_KEY_SHA256');
if (! is_string($expectedPublicKeySha256)
    || preg_match('/\A[0-9a-f]{64}\z/', $expectedPublicKeySha256) !== 1) {
    $fail('public_key_pin');
}

$resolveInput = static function (string $path, int $maximumBytes) use ($fail): string {
    if (is_link($path)) {
        $fail('paths');
    }
    $resolved = realpath($path);
    $size = is_string($resolved) && is_file($resolved) ? filesize($resolved) : false;
    if (! is_string($resolved) || ! is_int($size) || $size < 1 || $size > $maximumBytes) {
        $fail('paths');
    }

    return $resolved;
};

$evidencePath = $resolveInput($arguments['evidence'], 2_097_152);
$attestationPath = $resolveInput($arguments['attestation'], 65_536);
$publicKeyPath = $resolveInput($arguments['public-key'], 4_096);
$outputDirectory = realpath($arguments['output-directory']);
if (! is_string($outputDirectory) || ! is_dir($outputDirectory) || ! is_writable($outputDirectory)) {
    $fail('paths');
}

$rawEvidence = file_get_contents($evidencePath);
$rawAttestation = file_get_contents($attestationPath);
$rawPublicKey = file_get_contents($publicKeyPath);
if (! is_string($rawEvidence) || ! is_string($rawAttestation) || ! is_string($rawPublicKey)) {
    $fail('read');
}

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
$releaseProvenance = $contractValid && $attestationValid && ! $testAuthority;
$testContractValid = $contractValid && $attestationValid && $testAuthority;
$status = match (true) {
    $releaseProvenance => 'passed',
    $testContractValid => 'contract_valid_test_authority',
    default => 'failed',
};

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
    'authority_scope' => $testAuthority ? 'test_only' : 'release_platform',
    'status' => $status,
    'source_contract_status' => $verification['status'],
    'platform_attestation_verified' => $attestationValid,
    'release_provenance_verified' => $releaseProvenance,
    'v09_release_evidence' => $releaseProvenance,
    'checks' => $verification['checks'],
    'violations_count' => $verification['violations_count'] + ($attestationValid ? 0 : 1),
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

$handle = @fopen($artifactPath, 'x+b');
if ($handle === false) {
    $fail('artifact_create');
}

$committed = false;
try {
    $offset = 0;
    $length = strlen($encoded);
    while ($offset < $length) {
        $written = fwrite($handle, substr($encoded, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('artifact_write');
        }
        $offset += $written;
    }

    if (! fflush($handle)) {
        throw new RuntimeException('artifact_flush');
    }
    if (function_exists('fsync') && ! fsync($handle)) {
        throw new RuntimeException('artifact_sync');
    }
    $committed = true;
} catch (Throwable) {
    fclose($handle);
    @unlink($artifactPath);
    $fail('artifact_write');
} finally {
    if (is_resource($handle)) {
        fclose($handle);
    }
    if (! $committed && is_file($artifactPath)) {
        @unlink($artifactPath);
    }
}

fwrite(STDOUT, json_encode([
    'status' => $artifact['status'],
    'authority_scope' => $artifact['authority_scope'],
    'artifact_id' => $artifactId,
    'artifact_file' => $artifactFile,
    'release_provenance_verified' => $releaseProvenance,
    'violations_count' => $artifact['violations_count'],
], JSON_UNESCAPED_SLASHES).PHP_EOL);

exit(in_array($status, ['passed', 'contract_valid_test_authority'], true) ? 0 : 1);
