#!/usr/bin/env php
<?php

use App\Support\Monitoring\S10NativeProcessRunner;
use App\Support\Monitoring\S10PinnedChildSource;
use App\Support\Monitoring\S10ProcessEnvironment;
use App\Support\Monitoring\S10ProtectedRuntimeEnvironment;
use App\Support\Monitoring\S10ReleaseAuthorityVerifier;
use App\Support\Monitoring\StrictJsonObjectDecoder;

$root = dirname(__DIR__, 2);
$bootstrapFiles = [
    '/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    '/app/Support/Monitoring/S10ProcessEnvironment.php',
    '/app/Support/Monitoring/S10ProtectedRuntimeEnvironment.php',
    '/app/Support/Monitoring/S10ReleaseAuthorityVerifier.php',
    '/app/Support/Monitoring/S10NativeProcessRunner.php',
    '/app/Support/Monitoring/S10PinnedChildSource.php',
];

foreach ($bootstrapFiles as $relativePath) {
    $path = $root.$relativePath;
    if (is_link($path) || ! is_file($path)) {
        fwrite(STDOUT, '{"status":"failed","reason":"bootstrap","s10_release_evidence":false}'.PHP_EOL);
        exit(1);
    }

    require_once $path;
}

const S10_GIT_BINARY = '/usr/bin/git';
const S10_BASH_BINARY = '/usr/bin/bash';
const S10_PHP_BINARY = '/usr/bin/php8.4';
const S10_CHILD_BOOTSTRAP = <<<'BASH'
readonly S10_CHILD_PHP_BINARY="$OBLIVION_S10_PHP_BINARY"
php() {
    command "$S10_CHILD_PHP_BINARY" "$@"
}
readonly -f php
source /dev/stdin "$@"
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

$applicationPath = realpath($root);
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
$outputDirectoryBefore = @lstat($outputDirectory);
$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
if (! is_array($outputDirectoryBefore)
    || ! is_int($effectiveUid)
    || (($outputDirectoryBefore['mode'] ?? 0) & 0170000) !== 0040000
    || (($outputDirectoryBefore['mode'] ?? 0) & 0777) !== 0700
    || ($outputDirectoryBefore['uid'] ?? null) !== $effectiveUid) {
    $fail('output_directory_protection');
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
$gitEnvironment = [];
foreach (S10ProcessEnvironment::sanitized([], $resolvedPhpBinary) as $key => $value) {
    if (is_string($key) && is_string($value)) {
        $gitEnvironment[$key] = $value;
    }
}
$processRunner = new S10NativeProcessRunner;
$normalizedApplicationPath = rtrim(str_replace('\\', '/', $applicationPath), '/');
$normalizedOutputDirectory = rtrim(str_replace('\\', '/', $outputDirectory), '/');
if ($normalizedOutputDirectory === $normalizedApplicationPath
    || str_starts_with($normalizedOutputDirectory.'/', $normalizedApplicationPath.'/')) {
    $fail('paths');
}

$authorityVerifier = new S10ReleaseAuthorityVerifier;
$git = static function (array $arguments, bool $preserveOutput = false) use ($applicationPath, $gitEnvironment, $processRunner): ?string {
    try {
        $result = $processRunner->run(
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
            $gitEnvironment,
            10,
        );
        if (! $result['successful'] || trim($result['stderr']) !== '') {
            return null;
        }

        return $preserveOutput ? $result['stdout'] : trim($result['stdout']);
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

$runChild = static function (array $command, array $environment, int $timeoutSeconds, string $failure, string $source) use (
    $applicationPath,
    $fail,
    $processRunner,
): string {
    $result = $processRunner->run(
        $command,
        $applicationPath,
        $environment,
        $timeoutSeconds,
        65_536,
        $source,
    );
    $output = $result['stdout'];
    if (! $result['successful']
        || strlen($output) < 2
        || strlen($output) > 65_536
        || trim($result['stderr']) !== '') {
        $fail($failure);
    }

    return $output;
};
$childSourceReader = new S10PinnedChildSource;
$pinnedChildSource = static function (string $relativePath) use (
    $applicationPath,
    $childSourceReader,
    $fail,
    $git,
): string {
    $committed = $git(['cat-file', 'blob', 'HEAD:'.$relativePath], true);
    $source = is_string($committed)
        ? $childSourceReader->read($applicationPath.'/'.$relativePath, $committed)
        : null;
    if (! is_string($source)) {
        $fail('child_source');
    }

    return $source;
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
$protocolSource = $pinnedChildSource('scripts/monitoring/verify-protocol-policy-evidence.sh');
$protocolEnvironment = (new S10ProtectedRuntimeEnvironment)->loadInstalled(
    (string) $protocolBefore['runtime_environment_sha256'],
    $resolvedPhpBinary,
);
$protocolRaw = $runChild([
    S10_BASH_BINARY,
    '--noprofile',
    '--norc',
    '-p',
    '-c',
    S10_CHILD_BOOTSTRAP,
    'oblivion-s10-child',
    '--application-path='.$applicationPath,
    '--samples='.$protocolSamples,
    '--interval-seconds='.$intervalSeconds,
    '--window-minutes='.$windowMinutes,
], $protocolEnvironment, (($protocolSamples - 1) * $intervalSeconds) + 300, 'protocol_policy_evidence', $protocolSource);
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
$queclinkSource = $pinnedChildSource('scripts/monitoring/verify-queclink-native-listener-evidence.sh');
$queclinkEnvironment = (new S10ProtectedRuntimeEnvironment)->loadInstalled(
    (string) $queclinkBefore['runtime_environment_sha256'],
    $resolvedPhpBinary,
);
$queclinkRaw = $runChild([
    S10_BASH_BINARY,
    '--noprofile',
    '--norc',
    '-p',
    '-c',
    S10_CHILD_BOOTSTRAP,
    'oblivion-s10-child',
    '--application-path='.$applicationPath,
    '--samples='.$queclinkSamples,
    '--interval-seconds='.$intervalSeconds,
    '--max-frame-age='.$maxFrameAge,
], $queclinkEnvironment, (($queclinkSamples - 1) * $intervalSeconds) + 300, 'queclink_native_listener_evidence', $queclinkSource);
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
$publicationBefore = $identitySnapshot();
$snapshots[] = $publicationBefore;
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
    'runtime_environment_sha256' => $protocolBefore['runtime_environment_sha256'],
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
    $outputDirectoryAfter = @lstat($outputDirectory);
    foreach (['dev', 'ino', 'mode', 'uid'] as $key) {
        if (! is_array($outputDirectoryAfter)
            || ! array_key_exists($key, $outputDirectoryBefore)
            || ! array_key_exists($key, $outputDirectoryAfter)
            || $outputDirectoryBefore[$key] !== $outputDirectoryAfter[$key]) {
            throw new RuntimeException('output_directory_changed');
        }
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

$publicationAfter = $authorityVerifier->verifyInstalled(
    new DateTimeImmutable('now', new DateTimeZone('UTC')),
);
$snapshots[] = $publicationAfter;
$finalHead = $git(['rev-parse', '--verify', 'HEAD']);
$finalOriginMain = $git(['rev-parse', '--verify', 'refs/remotes/origin/main']);
$finalStatus = $git(['status', '--porcelain=v1', '--untracked-files=all']);
if (! $authorityVerifier->identitiesRemainPinned($snapshots)
    || ! is_string($finalHead)
    || ! hash_equals((string) $artifact['release_revision'], $finalHead)
    || ! is_string($finalOriginMain)
    || ! hash_equals($finalHead, $finalOriginMain)
    || $finalStatus !== '') {
    @unlink($artifactPath);
    @unlink($checksumPath);
    $fail('release_identity_changed');
}
if (! $publishedRemainsExact($artifactPath, $encoded)
    || ! $publishedRemainsExact($checksumPath, $checksumEncoded)) {
    @unlink($artifactPath);
    @unlink($checksumPath);
    $fail('published_artifact_changed');
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'artifact_id' => $artifactId,
    'artifact_file' => $artifactFile,
    'artifact_sha256' => $artifactSha256,
    'checksum_file' => $checksumFile,
    'release_revision' => $artifact['release_revision'],
    'environment_reference_sha256' => $artifact['environment_reference_sha256'],
    'release_provenance_verified' => true,
    's10_release_evidence' => true,
], JSON_UNESCAPED_SLASHES).PHP_EOL);

exit(0);
