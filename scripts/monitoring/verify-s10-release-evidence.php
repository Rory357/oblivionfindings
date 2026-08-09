#!/usr/bin/env php
<?php

use App\Support\Monitoring\S10ProcessEnvironment;
use App\Support\Monitoring\S10ReleaseAuthorityVerifier;
use App\Support\Monitoring\StrictJsonObjectDecoder;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const S10_GIT_BINARY = '/usr/bin/git';
const S10_BASH_BINARY = '/usr/bin/bash';
const S10_PHP_BINARY = '/usr/bin/php8.4';
const S10_CHILD_BOOTSTRAP = <<<'BASH'
readonly S10_CHILD_PHP_BINARY="$OBLIVION_S10_PHP_BINARY"
readonly S10_CHILD_SCRIPT="$1"
shift
php() {
    command "$S10_CHILD_PHP_BINARY" "$@"
}
readonly -f php
source "$S10_CHILD_SCRIPT" "$@"
BASH;

$fail = static function (string $reason): never {
    fwrite(STDOUT, json_encode([
        'status' => 'failed',
        'reason' => $reason,
        's10_release_evidence' => false,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
};

$arguments = [];
foreach (array_slice($argv, 1) as $argument) {
    if (! is_string($argument)
        || preg_match(
            '/\A--(interval-seconds|max-frame-age|output-directory|protocol-samples|queclink-samples|window-minutes)=(.+)\z/',
            $argument,
            $matches,
        ) !== 1
        || array_key_exists($matches[1], $arguments)) {
        $fail('arguments');
    }
    $arguments[$matches[1]] = $matches[2];
}

if (! isset($arguments['output-directory']) || count($arguments) > 6) {
    $fail('arguments');
}

$integerArgument = static function (
    string $name,
    int $default,
    int $minimum,
    int $maximum,
) use ($arguments, $fail): int {
    $raw = $arguments[$name] ?? (string) $default;
    if (! is_string($raw) || preg_match('/\A[0-9]+\z/', $raw) !== 1) {
        $fail('arguments');
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if (! is_int($value) || $value < $minimum || $value > $maximum) {
        $fail('arguments');
    }

    return $value;
};

$protocolSamples = $integerArgument('protocol-samples', 15, 15, 120);
$queclinkSamples = $integerArgument('queclink-samples', 5, 5, 120);
$intervalSeconds = $integerArgument('interval-seconds', 60, 60, 60);
$windowMinutes = $integerArgument('window-minutes', 60, 60, 60);
$maxFrameAge = $integerArgument('max-frame-age', 900, 60, 900);

$applicationPath = realpath(dirname(__DIR__, 2));
$outputArgument = $arguments['output-directory'];
$outputDirectory = is_string($outputArgument) && ! is_link($outputArgument)
    ? realpath($outputArgument)
    : false;
if (PHP_OS_FAMILY !== 'Linux'
    || ! is_string($applicationPath)
    || ! is_file($applicationPath.DIRECTORY_SEPARATOR.'artisan')
    || ! is_string($outputDirectory)
    || ! str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)
    || ! is_dir($outputDirectory)
    || ! is_writable($outputDirectory)) {
    $fail('paths');
}
$protectedExecutable = static function (string $path): bool {
    if (is_link($path) || ! is_file($path) || ! is_executable($path)) {
        return false;
    }
    $metadata = @lstat($path);
    $mode = is_array($metadata) ? ($metadata['mode'] ?? null) : null;

    return is_int($mode)
        && ($mode & 0170000) === 0100000
        && ($mode & 0022) === 0
        && ($metadata['uid'] ?? null) === 0;
};
if (! $protectedExecutable(S10_GIT_BINARY) || ! $protectedExecutable(S10_BASH_BINARY)) {
    $fail('runtime_binaries');
}
$resolvedPhpBinary = realpath(S10_PHP_BINARY);
if (! is_string($resolvedPhpBinary) || ! $protectedExecutable($resolvedPhpBinary)) {
    $fail('runtime_binaries');
}
$processEnvironment = S10ProcessEnvironment::processOverrides($resolvedPhpBinary);
$normalizedApplicationPath = rtrim(str_replace('\\', '/', $applicationPath), '/');
$normalizedOutputDirectory = rtrim(str_replace('\\', '/', $outputDirectory), '/');
if ($normalizedOutputDirectory === $normalizedApplicationPath
    || str_starts_with($normalizedOutputDirectory.'/', $normalizedApplicationPath.'/')) {
    $fail('paths');
}

$authorityVerifier = new S10ReleaseAuthorityVerifier;
$git = static function (array $arguments) use ($applicationPath, $processEnvironment): ?string {
    try {
        $process = new Process(
            [
                S10_GIT_BINARY,
                '--no-optional-locks',
                '-c',
                'core.fsmonitor=false',
                '-c',
                'core.untrackedCache=false',
                '-C',
                $applicationPath,
                ...$arguments,
            ],
            null,
            $processEnvironment,
        );
        $process->setTimeout(10);
        $process->run();
        if (! $process->isSuccessful() || trim($process->getErrorOutput()) !== '') {
            return null;
        }

        return trim($process->getOutput());
    } catch (Throwable) {
        return null;
    }
};
$identitySnapshot = static function () use ($authorityVerifier, $fail, $git, $normalizedApplicationPath): array {
    $verifiedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $authority = $authorityVerifier->verifyInstalled($verifiedAt);
    if (($authority['valid'] ?? null) !== true) {
        $fail('release_authority');
    }

    $inside = $git(['rev-parse', '--is-inside-work-tree']);
    $topLevel = $git(['rev-parse', '--show-toplevel']);
    $releaseRevision = $git(['rev-parse', '--verify', 'HEAD']);
    $originMain = $git(['rev-parse', '--verify', 'refs/remotes/origin/main']);
    $status = $git(['status', '--porcelain=v1', '--untracked-files=all']);
    $resolvedTopLevel = is_string($topLevel) ? realpath($topLevel) : false;
    if ($inside !== 'true'
        || ! is_string($resolvedTopLevel)
        || $normalizedApplicationPath !== rtrim(str_replace('\\', '/', $resolvedTopLevel), '/')
        || ! is_string($releaseRevision)
        || preg_match('/\A[0-9a-f]{40}\z/', $releaseRevision) !== 1
        || ! hash_equals((string) $authority['release_revision'], $releaseRevision)) {
        $fail('release_revision');
    }
    if (! is_string($originMain)
        || ! hash_equals($releaseRevision, $originMain)
        || $status !== '') {
        $fail('release_checkout');
    }

    return [...$authority, 'verified_at' => $verifiedAt];
};

$runChild = static function (array $command, int $timeoutSeconds, string $failure) use (
    $applicationPath,
    $fail,
    $processEnvironment,
): string {
    $process = new Process($command, $applicationPath, $processEnvironment);
    $process->setTimeout($timeoutSeconds);
    $process->run();
    $output = $process->getOutput();
    if (! $process->isSuccessful()
        || ! is_string($output)
        || strlen($output) < 2
        || strlen($output) > 65_536
        || trim($process->getErrorOutput()) !== '') {
        $fail($failure);
    }

    return $output;
};

$hasExactKeys = static function (array $value, array $expected): bool {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);

    return $actual === $expected;
};
$utc = static function (mixed $value): ?DateTimeImmutable {
    if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
        return null;
    }
    try {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d\TH:i:s\Z') === $value
                ? $parsed
                : null;
    } catch (Throwable) {
        return null;
    }
};
$validateWindow = static function (
    mixed $startedValue,
    mixed $completedValue,
    int $observationSeconds,
    DateTimeImmutable $boundaryBefore,
    DateTimeImmutable $boundaryAfter,
) use ($utc): ?array {
    $startedAt = $utc($startedValue);
    $completedAt = $utc($completedValue);
    if ($startedAt === null
        || $completedAt === null
        || $startedAt > $completedAt
        || ($completedAt->getTimestamp() - $startedAt->getTimestamp()) < $observationSeconds
        || $startedAt < $boundaryBefore->modify('-5 seconds')
        || $completedAt > $boundaryAfter->modify('+5 seconds')) {
        return null;
    }

    return [
        'started_at' => $startedAt->format('Y-m-d\TH:i:s\Z'),
        'completed_at' => $completedAt->format('Y-m-d\TH:i:s\Z'),
    ];
};

$protocolBefore = $identitySnapshot();
$protocolRaw = $runChild([
    S10_BASH_BINARY,
    '--noprofile',
    '--norc',
    '-p',
    '-c',
    S10_CHILD_BOOTSTRAP,
    'oblivion-s10-child',
    $applicationPath.'/scripts/monitoring/verify-protocol-policy-evidence.sh',
    '--application-path='.$applicationPath,
    '--samples='.$protocolSamples,
    '--interval-seconds='.$intervalSeconds,
    '--window-minutes='.$windowMinutes,
], (($protocolSamples - 1) * $intervalSeconds) + 300, 'protocol_policy_evidence');
$protocolAfter = $identitySnapshot();

try {
    $protocol = (new StrictJsonObjectDecoder)->decode($protocolRaw);
} catch (Throwable) {
    $fail('protocol_policy_contract');
}
$protocolObservationSeconds = ($protocolSamples - 1) * $intervalSeconds;
$protocolWindow = $validateWindow(
    $protocol['started_at'] ?? null,
    $protocol['completed_at'] ?? null,
    $protocolObservationSeconds,
    $protocolBefore['verified_at'],
    $protocolAfter['verified_at'],
);
if (! $hasExactKeys($protocol, [
    'completed_at',
    'observation_seconds',
    'samples',
    'started_at',
    'state',
    'window_minutes',
])
    || ($protocol['state'] ?? null) !== 'verified'
    || ($protocol['samples'] ?? null) !== $protocolSamples
    || ($protocol['observation_seconds'] ?? null) !== $protocolObservationSeconds
    || ($protocol['window_minutes'] ?? null) !== $windowMinutes
    || $protocolWindow === null) {
    $fail('protocol_policy_contract');
}

$queclinkBefore = $identitySnapshot();
$queclinkRaw = $runChild([
    S10_BASH_BINARY,
    '--noprofile',
    '--norc',
    '-p',
    '-c',
    S10_CHILD_BOOTSTRAP,
    'oblivion-s10-child',
    $applicationPath.'/scripts/monitoring/verify-queclink-native-listener-evidence.sh',
    '--application-path='.$applicationPath,
    '--samples='.$queclinkSamples,
    '--interval-seconds='.$intervalSeconds,
    '--max-frame-age='.$maxFrameAge,
], (($queclinkSamples - 1) * $intervalSeconds) + 300, 'queclink_native_listener_evidence');
$queclinkAfter = $identitySnapshot();

try {
    $queclink = (new StrictJsonObjectDecoder)->decode($queclinkRaw);
} catch (Throwable) {
    $fail('queclink_native_listener_contract');
}
$queclinkObservationSeconds = ($queclinkSamples - 1) * $intervalSeconds;
$queclinkWindow = $validateWindow(
    $queclink['started_at'] ?? null,
    $queclink['completed_at'] ?? null,
    $queclinkObservationSeconds,
    $queclinkBefore['verified_at'],
    $queclinkAfter['verified_at'],
);
$canonicalTrackers = $queclink['canonical_paired_trackers'] ?? null;
if (! $hasExactKeys($queclink, [
    'canonical_paired_trackers',
    'completed_at',
    'fresh_trackers_observed',
    'max_frame_age_seconds',
    'observation_seconds',
    'samples',
    'started_at',
    'state',
])
    || ($queclink['state'] ?? null) !== 'verified'
    || ($queclink['samples'] ?? null) !== $queclinkSamples
    || ($queclink['observation_seconds'] ?? null) !== $queclinkObservationSeconds
    || ($queclink['max_frame_age_seconds'] ?? null) !== $maxFrameAge
    || ! is_int($canonicalTrackers)
    || $canonicalTrackers < 1
    || ($queclink['fresh_trackers_observed'] ?? null) !== $canonicalTrackers
    || $queclinkWindow === null) {
    $fail('queclink_native_listener_contract');
}

$snapshots = [$protocolBefore, $protocolAfter, $queclinkBefore, $queclinkAfter];
if (! $authorityVerifier->identitiesRemainPinned($snapshots)) {
    $fail('release_identity_changed');
}

$createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$artifactId = bin2hex(random_bytes(16));
$artifactFile = 'security-devices-s10-release-evidence-'
    .$createdAt->format('Ymd\THis.u\Z').'-'.$artifactId.'.json';
$artifactPath = $outputDirectory.DIRECTORY_SEPARATOR.$artifactFile;
$artifact = [
    'schema_version' => 1,
    'artifact_id' => $artifactId,
    'evidence_class' => 'security_devices_s10_release_evidence_v1',
    'created_at' => $createdAt->format('Y-m-d\TH:i:s.u\Z'),
    'authority_reference' => $protocolBefore['authority_reference'],
    'authority_sha256' => $protocolBefore['authority_sha256'],
    'release_revision' => $protocolBefore['release_revision'],
    'environment_reference_sha256' => $protocolBefore['environment_reference_sha256'],
    'provider_api_contracts' => ['unifi', 'milesight'],
    'queclink_transport' => 'native_tcp',
    'protocol_policy_evidence' => [
        'sha256' => hash('sha256', $protocolRaw),
        'samples' => $protocolSamples,
        'observation_seconds' => $protocolObservationSeconds,
        'window_minutes' => $windowMinutes,
        ...$protocolWindow,
    ],
    'queclink_native_listener_evidence' => [
        'sha256' => hash('sha256', $queclinkRaw),
        'samples' => $queclinkSamples,
        'observation_seconds' => $queclinkObservationSeconds,
        'max_frame_age_seconds' => $maxFrameAge,
        'canonical_paired_trackers' => $canonicalTrackers,
        'fresh_trackers_observed' => $canonicalTrackers,
        ...$queclinkWindow,
    ],
    'release_provenance_verified' => true,
    's10_release_evidence' => true,
    'verification_artifact_contains_targets_credentials_or_payloads' => false,
    'output_storage_semantics' => 'collision_safe_exclusive_create',
    'worm_receipt_verified' => false,
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
    if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
        throw new RuntimeException('artifact_flush');
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
    'status' => 'passed',
    'artifact_id' => $artifactId,
    'artifact_file' => $artifactFile,
    'release_revision' => $artifact['release_revision'],
    'environment_reference_sha256' => $artifact['environment_reference_sha256'],
    'release_provenance_verified' => true,
    's10_release_evidence' => true,
], JSON_UNESCAPED_SLASHES).PHP_EOL);

exit(0);
