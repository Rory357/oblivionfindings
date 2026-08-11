<?php

namespace App\Support\Release;

use App\Support\Monitoring\StrictJsonObjectDecoder;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ItSecurityDesktopReleaseEvidenceVerifier
{
    public const string AUTHORITY_PATH = '/etc/oblivion/it-security-desktop-release-authority.json';

    private const int MAXIMUM_AUTHORITY_BYTES = 32_768;

    private const int MAXIMUM_CAPTURE_OR_NETWORK_BYTES = 536_870_912;

    private const int MAXIMUM_CONSOLE_ACCESSIBILITY_OR_COMPANION_BYTES = 67_108_864;

    private const int MAXIMUM_ROUTE_OR_FIXTURE_BYTES = 16_777_216;

    private const int MAXIMUM_SESSION_BINDING_BYTES = 65_536;

    private const int MAXIMUM_BROWSER_DESCRIPTOR_BYTES = 65_536;

    private const int RETAINED_ARTIFACT_COUNT = 274;

    private const int MAXIMUM_AUTHORITY_SECONDS = 604_800;

    private const array AUTHORITY_KEYS = [
        'authority_reference',
        'environment_reference_sha256',
        'evidence_class',
        'manifest_public_key_base64',
        'manifest_public_key_reference',
        'not_after_utc',
        'not_before_utc',
        'release_revision',
        'restored_environment_reference_sha256',
        'schema_version',
    ];

    private const array PAYLOAD_KEYS = [
        'browser_version_descriptor',
        'browser_version_descriptor_sha256',
        'browser_version_reference',
        'companions',
        'deployed_at_utc',
        'environment_reference_sha256',
        'evidence_class',
        'release_identifier_reference',
        'release_revision',
        'restored_environment_reference_sha256',
        'restored_rows',
        'reviewed_at_utc',
        'reviewer_reference',
        'rows',
        'schema_version',
    ];

    private const array COMPANIONS = [
        'central_runtime' => 'primary',
        'collector' => 'primary',
        'configuration_history' => 'restored',
        'deployment_runtime' => 'primary',
        'load_soak' => 'primary',
        'local_automated' => 'primary',
        'protocol_provider' => 'primary',
        'retention' => 'primary',
        'storage_restore' => 'restored',
    ];

    private const array ROW_ACTORS = [
        'D01' => ['release-requester'],
        'D02' => ['release-it-manager'],
        'D03' => ['release-it-manager'],
        'D04' => ['release-it-manager'],
        'D05' => ['release-it-manager'],
        'D06' => ['release-it-manager'],
        'D07' => ['release-it-manager'],
        'D08' => ['release-it-manager', 'release-auditor'],
        'D09' => ['release-it-manager'],
        'D10' => ['release-control-room'],
        'D11' => ['release-it-manager'],
        'D12' => ['release-it-manager'],
        'D13' => ['release-it-manager'],
        'D14' => ['release-it-manager'],
        'D15' => ['release-it-manager'],
        'D16' => ['release-it-manager', 'release-it-reviewer'],
        'D17' => ['release-it-manager'],
        'D18' => ['release-denied', 'release-source-denied'],
    ];

    private const array RESTORED_ROWS = ['D07', 'D12', 'D15', 'D18'];

    /** @return array<string, mixed> */
    public function verifyInstalledAuthority(DateTimeImmutable $verifiedAt): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || is_link(self::AUTHORITY_PATH)) {
            return $this->invalidAuthority();
        }

        $before = @lstat(self::AUTHORITY_PATH);
        $handle = @fopen(self::AUTHORITY_PATH, 'rb');
        if (! is_array($before) || $handle === false) {
            return $this->invalidAuthority();
        }

        try {
            $opened = @fstat($handle);
            $after = @lstat(self::AUTHORITY_PATH);
            if (! is_array($opened) || ! is_array($after)) {
                return $this->invalidAuthority();
            }
            $size = $opened['size'] ?? null;
            if (! is_int($size) || $size < 1 || $size > self::MAXIMUM_AUTHORITY_BYTES) {
                return $this->invalidAuthority();
            }
            $raw = stream_get_contents($handle, self::MAXIMUM_AUTHORITY_BYTES + 1);
            if (! is_string($raw) || strlen($raw) !== $size) {
                return $this->invalidAuthority();
            }
            $read = @fstat($handle);
            $final = @lstat(self::AUTHORITY_PATH);
            if (! is_array($read) || ! is_array($final)) {
                return $this->invalidAuthority();
            }

            return $this->verifyAuthorityRecord($raw, [
                'is_regular_file' => (($opened['mode'] ?? 0) & 0170000) === 0100000,
                'is_symlink' => (($before['mode'] ?? 0) & 0170000) === 0120000
                    || (($after['mode'] ?? 0) & 0170000) === 0120000,
                'mode' => $opened['mode'] ?? null,
                'owner_uid' => $opened['uid'] ?? null,
                'stable_identity' => $this->sameFile($before, $opened)
                    && $this->sameFile($opened, $after)
                    && $this->sameFile($after, $read)
                    && $this->sameFile($read, $final),
            ], $verifiedAt);
        } catch (Throwable) {
            return $this->invalidAuthority();
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public function verifyAuthorityRecord(
        string $rawAuthority,
        array $metadata,
        DateTimeImmutable $verifiedAt,
    ): array {
        try {
            if (! $this->protectedMetadata($metadata, requireRoot: true)) {
                return $this->invalidAuthority();
            }
            $authority = (new StrictJsonObjectDecoder)->decode($rawAuthority, 16);
            if (! $this->exactKeys($authority, self::AUTHORITY_KEYS)) {
                return $this->invalidAuthority();
            }

            $notBefore = $this->utc($authority['not_before_utc'] ?? null);
            $notAfter = $this->utc($authority['not_after_utc'] ?? null);
            $publicKey = base64_decode((string) ($authority['manifest_public_key_base64'] ?? ''), true);
            $verifiedAt = $verifiedAt->setTimezone(new DateTimeZone('UTC'));
            $valid = ($authority['schema_version'] ?? null) === 1
                && ($authority['evidence_class'] ?? null) === 'it_security_desktop_release_authority_v1'
                && $this->matches($authority['authority_reference'] ?? null, '/\AAUTHORITY-[a-f0-9]{32}\z/')
                && $this->matches($authority['manifest_public_key_reference'] ?? null, '/\AKEY-[a-f0-9]{32}\z/')
                && $this->sha($authority['release_revision'] ?? null, 40)
                && $this->sha($authority['environment_reference_sha256'] ?? null)
                && $this->sha($authority['restored_environment_reference_sha256'] ?? null)
                && is_string($publicKey)
                && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && $notBefore !== null
                && $notAfter !== null
                && $notBefore < $notAfter
                && $notAfter->getTimestamp() - $notBefore->getTimestamp() <= self::MAXIMUM_AUTHORITY_SECONDS
                && $verifiedAt >= $notBefore
                && $verifiedAt <= $notAfter;

            return $valid ? [
                'valid' => true,
                'authority_reference' => $authority['authority_reference'],
                'authority_sha256' => hash('sha256', $rawAuthority),
                'environment_reference_sha256' => $authority['environment_reference_sha256'],
                'manifest_public_key' => $publicKey,
                'manifest_public_key_reference' => $authority['manifest_public_key_reference'],
                'release_revision' => $authority['release_revision'],
                'restored_environment_reference_sha256' => $authority['restored_environment_reference_sha256'],
            ] : $this->invalidAuthority();
        } catch (Throwable) {
            return $this->invalidAuthority();
        }
    }

    /** @param array<string, mixed> $authority @return array<string, mixed> */
    public function verifyManifest(
        string $rawManifest,
        array $authority,
        DateTimeImmutable $verifiedAt,
    ): array {
        try {
            if (($authority['valid'] ?? null) !== true
                || ! is_string($authority['manifest_public_key'] ?? null)) {
                return $this->invalidManifest();
            }
            $manifest = (new StrictJsonObjectDecoder)->decode($rawManifest, 64);
            if (! $this->exactKeys($manifest, ['payload', 'signature_base64'])
                || ! is_array($manifest['payload'])
                || array_is_list($manifest['payload'])
                || ! $this->exactKeys($manifest['payload'], self::PAYLOAD_KEYS)) {
                return $this->invalidManifest();
            }
            $signature = base64_decode((string) $manifest['signature_base64'], true);
            if (! is_string($signature)
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || ! sodium_crypto_sign_verify_detached(
                    $signature,
                    $this->canonicalPayload($manifest['payload']),
                    $authority['manifest_public_key'],
                )) {
                return $this->invalidManifest();
            }

            $payload = $manifest['payload'];
            $deployedAt = $this->utc($payload['deployed_at_utc'] ?? null);
            $reviewedAt = $this->utc($payload['reviewed_at_utc'] ?? null);
            $verifiedAt = $verifiedAt->setTimezone(new DateTimeZone('UTC'));
            if (($payload['schema_version'] ?? null) !== 3
                || ($payload['evidence_class'] ?? null) !== 'it_security_desktop_release_evidence_v3'
                || ! hash_equals((string) $authority['release_revision'], (string) ($payload['release_revision'] ?? ''))
                || ! hash_equals((string) $authority['environment_reference_sha256'], (string) ($payload['environment_reference_sha256'] ?? ''))
                || ! hash_equals((string) $authority['restored_environment_reference_sha256'], (string) ($payload['restored_environment_reference_sha256'] ?? ''))
                || ! $this->matches($payload['release_identifier_reference'] ?? null, '/\ARELEASE-[a-f0-9]{32}\z/')
                || ! $this->matches($payload['reviewer_reference'] ?? null, '/\AREVIEWER-[a-f0-9]{32}\z/')
                || ! $this->matches($payload['browser_version_reference'] ?? null, '/\ABROWSER-[a-f0-9]{32}\z/')
                || ! $this->sha($payload['browser_version_descriptor_sha256'] ?? null)
                || ! $this->validBrowserDescriptor($payload['browser_version_descriptor'] ?? null)
                || $deployedAt === null
                || $reviewedAt === null
                || $deployedAt > $reviewedAt
                || $reviewedAt > $verifiedAt->modify('+60 seconds')
                || $reviewedAt->getTimestamp() - $deployedAt->getTimestamp() > self::MAXIMUM_AUTHORITY_SECONDS
                || ! $this->validCompanions(
                    $payload['companions'] ?? null,
                    $authority,
                    $deployedAt,
                    $reviewedAt,
                )
                || ! $this->uniqueCompanionEvidence($payload['companions'] ?? null)
                || ! $this->validRows(
                    $payload['rows'] ?? null,
                    self::ROW_ACTORS,
                    (string) $authority['environment_reference_sha256'],
                    (string) $payload['browser_version_descriptor_sha256'],
                    $deployedAt,
                    $reviewedAt,
                )
                || ! $this->validRows(
                    $payload['restored_rows'] ?? null,
                    array_intersect_key(self::ROW_ACTORS, array_flip(self::RESTORED_ROWS)),
                    (string) $authority['restored_environment_reference_sha256'],
                    (string) $payload['browser_version_descriptor_sha256'],
                    $deployedAt,
                    $reviewedAt,
                )
                || ! $this->restoredRowsFollowCompanions(
                    $payload['restored_rows'] ?? null,
                    $payload['companions'] ?? null,
                )
                || ! $this->uniqueEvidenceReferences(
                    $payload['rows'] ?? null,
                    $payload['restored_rows'] ?? null,
                )
                || ! $this->artifactClassesAreDistinct(
                    $payload['rows'] ?? null,
                    $payload['restored_rows'] ?? null,
                    $payload['companions'] ?? null,
                    $payload['browser_version_descriptor_sha256'] ?? null,
                )
                || ! $this->actorSessionReferencesAreRoleBound(
                    $payload['rows'] ?? null,
                    $payload['restored_rows'] ?? null,
                )) {
                return $this->invalidManifest();
            }

            return [
                'valid' => true,
                'authority_reference' => $authority['authority_reference'],
                'retained_artifacts' => $this->retainedArtifacts($payload),
                'environment_reference_sha256' => $authority['environment_reference_sha256'],
                'manifest_sha256' => hash('sha256', $rawManifest),
                'primary_rows' => count(self::ROW_ACTORS),
                'primary_viewports' => count(self::ROW_ACTORS) * 2,
                'release_revision' => $authority['release_revision'],
                'restored_environment_reference_sha256' => $authority['restored_environment_reference_sha256'],
                'restored_rows' => count(self::RESTORED_ROWS),
                'restored_viewports' => count(self::RESTORED_ROWS) * 2,
            ];
        } catch (Throwable) {
            return $this->invalidManifest();
        }
    }

    public function retainedPackageArtifactsAreValid(
        mixed $artifacts,
        string $directory,
        string $repositoryRoot,
    ): bool {
        try {
            if (! is_array($artifacts)
                || ! array_is_list($artifacts)
                || count($artifacts) !== self::RETAINED_ARTIFACT_COUNT
                || is_link($directory)) {
                return false;
            }

            $resolvedDirectory = realpath($directory);
            $resolvedRepository = realpath($repositoryRoot);
            if (! is_string($resolvedDirectory) || ! is_string($resolvedRepository)) {
                return false;
            }

            $normalise = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
            $evidenceDirectory = $normalise($resolvedDirectory);
            $repository = $normalise($resolvedRepository);
            if ($evidenceDirectory === $repository
                || str_starts_with($evidenceDirectory.'/', $repository.'/')) {
                return false;
            }

            $directoryBefore = @lstat($resolvedDirectory);
            $effectiveUid = PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid')
                ? null
                : posix_geteuid();
            if (! is_array($directoryBefore)
                || (($directoryBefore['mode'] ?? 0) & 0170000) !== 0040000
                || (PHP_OS_FAMILY !== 'Windows' && (
                    ! is_int($effectiveUid)
                    || (($directoryBefore['mode'] ?? 0) & 0777) !== 0700
                    || ($directoryBefore['uid'] ?? null) !== $effectiveUid
                ))) {
                return false;
            }

            $paths = [];
            foreach ($artifacts as $artifact) {
                $suffix = is_array($artifact) && is_string($artifact['suffix'] ?? null)
                    ? $artifact['suffix']
                    : '';
                $maximumBytes = $this->maximumArtifactBytes($suffix);
                $expectedKeys = in_array($suffix, ['browser', 'sessions'], true)
                    ? ['document', 'reference', 'sha256', 'suffix']
                    : ['reference', 'sha256', 'suffix'];
                if (! is_array($artifact)
                    || array_is_list($artifact)
                    || ! $this->exactKeys($artifact, $expectedKeys)
                    || ! $this->validArtifactReference($artifact['reference'] ?? null, $suffix)
                    || ! $this->sha($artifact['sha256'] ?? null)
                    || $maximumBytes === null
                    || ! (in_array($suffix, ['browser', 'sessions'], true)
                        ? $this->jsonArtifactMatches(
                            $resolvedDirectory.DIRECTORY_SEPARATOR.$artifact['reference'].'.'.$suffix,
                            $resolvedDirectory,
                            (string) $artifact['sha256'],
                            $artifact['document'] ?? null,
                            $maximumBytes,
                            $effectiveUid,
                        )
                        : $this->artifactHashMatches(
                            $resolvedDirectory.DIRECTORY_SEPARATOR.$artifact['reference'].'.'.$suffix,
                            $resolvedDirectory,
                            (string) $artifact['sha256'],
                            $maximumBytes,
                            $effectiveUid,
                        ))) {
                    return false;
                }

                $paths[] = $artifact['reference'].'.'.$suffix;
            }

            $directoryAfter = @lstat($resolvedDirectory);

            return count($paths) === count(array_unique($paths, SORT_STRING))
                && is_array($directoryAfter)
                && $this->sameFile($directoryBefore, $directoryAfter);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function protectedManifestRemainsPinned(array $before, array $after): bool
    {
        foreach ([$before, $after] as $snapshot) {
            if (! $this->exactKeys($snapshot, ['identity', 'raw'])
                || ! is_string($snapshot['raw'] ?? null)
                || ! is_array($snapshot['identity'] ?? null)
                || ! $this->exactKeys($snapshot['identity'], ['dev', 'ino', 'mode', 'mtime', 'size', 'uid'])) {
                return false;
            }
        }

        return hash_equals(hash('sha256', $before['raw']), hash('sha256', $after['raw']))
            && $this->sameFile($before['identity'], $after['identity']);
    }

    /** @param array<string, mixed> $payload */
    public function canonicalPayload(array $payload): string
    {
        $normalise = function (mixed $value) use (&$normalise): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($normalise, $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $normalise($item);
            }

            return $value;
        };

        return json_encode(
            $normalise($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param mixed $companions @param array<string, mixed> $authority */
    private function validCompanions(
        mixed $companions,
        array $authority,
        DateTimeImmutable $deployedAt,
        DateTimeImmutable $reviewedAt,
    ): bool {
        if (! is_array($companions) || array_is_list($companions)
            || ! $this->exactKeys($companions, array_keys(self::COMPANIONS))) {
            return false;
        }
        foreach (self::COMPANIONS as $name => $environment) {
            $companion = $companions[$name] ?? null;
            $companionVerifiedAt = is_array($companion)
                ? $this->utc($companion['verified_at_utc'] ?? null)
                : null;
            $expectedEnvironment = $environment === 'restored'
                ? $authority['restored_environment_reference_sha256']
                : $authority['environment_reference_sha256'];
            if (! is_array($companion)
                || array_is_list($companion)
                || ! $this->exactKeys($companion, [
                    'environment_reference_sha256',
                    'evidence_reference',
                    'evidence_sha256',
                    'release_revision',
                    'status',
                    'verified_at_utc',
                ])
                || ($companion['status'] ?? null) !== 'verified'
                || ! $this->matches($companion['evidence_reference'] ?? null, '/\AEVIDENCE-[a-f0-9]{32}\z/')
                || ! $this->sha($companion['evidence_sha256'] ?? null)
                || ! hash_equals((string) $authority['release_revision'], (string) ($companion['release_revision'] ?? ''))
                || ! hash_equals((string) $expectedEnvironment, (string) ($companion['environment_reference_sha256'] ?? ''))
                || $companionVerifiedAt === null
                || $companionVerifiedAt > $reviewedAt
                || ($name !== 'local_automated' && $companionVerifiedAt < $deployedAt)) {
                return false;
            }
        }

        return true;
    }

    private function restoredRowsFollowCompanions(mixed $rows, mixed $companions): bool
    {
        if (! is_array($rows) || ! array_is_list($rows)
            || ! is_array($companions) || array_is_list($companions)) {
            return false;
        }

        $configurationHistoryAt = $this->utc(
            is_array($companions['configuration_history'] ?? null)
                ? ($companions['configuration_history']['verified_at_utc'] ?? null)
                : null,
        );
        $storageRestoreAt = $this->utc(
            is_array($companions['storage_restore'] ?? null)
                ? ($companions['storage_restore']['verified_at_utc'] ?? null)
                : null,
        );
        if ($configurationHistoryAt === null || $storageRestoreAt === null) {
            return false;
        }

        $restoredEvidenceReadyAt = $configurationHistoryAt > $storageRestoreAt
            ? $configurationHistoryAt
            : $storageRestoreAt;
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_array($row['viewports'] ?? null)) {
                return false;
            }
            foreach ($row['viewports'] as $viewport) {
                $verifiedAt = is_array($viewport)
                    ? $this->utc($viewport['verified_at_utc'] ?? null)
                    : null;
                if ($verifiedAt === null || $verifiedAt < $restoredEvidenceReadyAt) {
                    return false;
                }
            }
        }

        return true;
    }

    private function uniqueCompanionEvidence(mixed $companions): bool
    {
        if (! is_array($companions) || array_is_list($companions)) {
            return false;
        }

        $evidenceReferences = [];
        $evidenceHashes = [];
        foreach ($companions as $companion) {
            if (! is_array($companion)
                || ! is_string($companion['evidence_reference'] ?? null)
                || ! is_string($companion['evidence_sha256'] ?? null)) {
                return false;
            }

            $evidenceReferences[] = $companion['evidence_reference'];
            $evidenceHashes[] = $companion['evidence_sha256'];
        }

        return count($evidenceReferences) === count(array_unique($evidenceReferences, SORT_STRING))
            && count($evidenceHashes) === count(array_unique($evidenceHashes, SORT_STRING));
    }

    /** @param mixed $rows @param array<string, list<string>> $expectedRows */
    private function validRows(
        mixed $rows,
        array $expectedRows,
        string $expectedEnvironmentReference,
        string $browserDescriptorHash,
        DateTimeImmutable $deployedAt,
        DateTimeImmutable $reviewedAt,
    ): bool {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($expectedRows)) {
            return false;
        }
        foreach (array_values($expectedRows) as $index => $actors) {
            $id = array_keys($expectedRows)[$index];
            $row = $rows[$index] ?? null;
            if (! is_array($row)
                || array_is_list($row)
                || ! $this->exactKeys($row, [
                    'actors',
                    'denial_contract_verified',
                    'environment_reference_sha256',
                    'fixture_manifest_sha256',
                    'id',
                    'interaction_contract_verified',
                    'privacy_contract_verified',
                    'result_reference',
                    'route_manifest_sha256',
                    'viewports',
                ])
                || ($row['id'] ?? null) !== $id
                || ($row['actors'] ?? null) !== $actors
                || ($row['denial_contract_verified'] ?? null) !== ($id === 'D18')
                || ! hash_equals(
                    $expectedEnvironmentReference,
                    (string) ($row['environment_reference_sha256'] ?? ''),
                )
                || ($row['interaction_contract_verified'] ?? null) !== true
                || ($row['privacy_contract_verified'] ?? null) !== true
                || ! $this->sha($row['fixture_manifest_sha256'] ?? null)
                || ! $this->sha($row['route_manifest_sha256'] ?? null)
                || ! $this->matches($row['result_reference'] ?? null, '/\ARESULT-[a-f0-9]{32}\z/')
                || ! $this->validViewports(
                    $row['viewports'] ?? null,
                    $actors,
                    $browserDescriptorHash,
                    $deployedAt,
                    $reviewedAt,
                )) {
                return false;
            }
        }

        return true;
    }

    private function uniqueEvidenceReferences(mixed ...$rowSets): bool
    {
        $resultReferences = [];
        $captureReferences = [];
        $captureHashes = [];
        foreach ($rowSets as $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['result_reference'] ?? null)) {
                    return false;
                }
                $resultReferences[] = $row['result_reference'];
                foreach (($row['viewports'] ?? []) as $viewport) {
                    if (! is_array($viewport)
                        || ! is_string($viewport['capture_archive_reference'] ?? null)
                        || ! is_string($viewport['capture_archive_sha256'] ?? null)) {
                        return false;
                    }
                    $captureReferences[] = $viewport['capture_archive_reference'];
                    $captureHashes[] = $viewport['capture_archive_sha256'];
                }
            }
        }

        return count($resultReferences) === count(array_unique($resultReferences, SORT_STRING))
            && count($captureReferences) === count(array_unique($captureReferences, SORT_STRING))
            && count($captureHashes) === count(array_unique($captureHashes, SORT_STRING));
    }

    private function artifactClassesAreDistinct(
        mixed $rows,
        mixed $restoredRows,
        mixed $companions,
        mixed $browserDescriptorHash,
    ): bool {
        $artifactClasses = [];
        $record = static function (mixed $hash, string $class) use (&$artifactClasses): bool {
            if (! is_string($hash)) {
                return false;
            }
            if (isset($artifactClasses[$hash]) && $artifactClasses[$hash] !== $class) {
                return false;
            }

            $artifactClasses[$hash] = $class;

            return true;
        };
        if (! $record($browserDescriptorHash, 'browser')) {
            return false;
        }

        foreach ([$rows, $restoredRows] as $rowSet) {
            if (! is_array($rowSet) || ! array_is_list($rowSet)) {
                return false;
            }
            foreach ($rowSet as $row) {
                if (! is_array($row)
                    || ! $record($row['route_manifest_sha256'] ?? null, 'routes')
                    || ! $record($row['fixture_manifest_sha256'] ?? null, 'fixtures')) {
                    return false;
                }
                foreach (($row['viewports'] ?? []) as $viewport) {
                    if (! is_array($viewport)) {
                        return false;
                    }
                    foreach ([
                        'capture_archive_sha256' => 'capture',
                        'network_trace_sha256' => 'network',
                        'console_log_sha256' => 'console',
                        'accessibility_report_sha256' => 'accessibility',
                        'actor_session_binding_sha256' => 'sessions',
                    ] as $field => $class) {
                        if (! $record($viewport[$field] ?? null, $class)) {
                            return false;
                        }
                    }
                }
            }
        }

        if (! is_array($companions) || array_is_list($companions)) {
            return false;
        }
        foreach ($companions as $companion) {
            if (! is_array($companion)
                || ! $record($companion['evidence_sha256'] ?? null, 'companion')) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload @return list<array{reference: string, sha256: string, suffix: string}> */
    private function retainedArtifacts(array $payload): array
    {
        $archives = [[
            'document' => $payload['browser_version_descriptor'],
            'reference' => $payload['browser_version_reference'],
            'sha256' => $payload['browser_version_descriptor_sha256'],
            'suffix' => 'browser',
        ]];
        foreach ([$payload['rows'], $payload['restored_rows']] as $rows) {
            foreach ($rows as $row) {
                foreach ($row['viewports'] as $viewport) {
                    foreach ([
                        'capture' => 'capture_archive_sha256',
                        'network' => 'network_trace_sha256',
                        'console' => 'console_log_sha256',
                        'accessibility' => 'accessibility_report_sha256',
                    ] as $suffix => $hashField) {
                        $archives[] = [
                            'reference' => $viewport['capture_archive_reference'],
                            'sha256' => $viewport[$hashField],
                            'suffix' => $suffix,
                        ];
                    }
                    $archives[] = [
                        'document' => $viewport['actor_session_references'],
                        'reference' => $viewport['actor_session_binding_reference'],
                        'sha256' => $viewport['actor_session_binding_sha256'],
                        'suffix' => 'sessions',
                    ];
                }
                foreach ([
                    'routes' => 'route_manifest_sha256',
                    'fixtures' => 'fixture_manifest_sha256',
                ] as $suffix => $hashField) {
                    $archives[] = [
                        'reference' => $row['result_reference'],
                        'sha256' => $row[$hashField],
                        'suffix' => $suffix,
                    ];
                }
            }
        }
        foreach ($payload['companions'] as $companion) {
            $archives[] = [
                'reference' => $companion['evidence_reference'],
                'sha256' => $companion['evidence_sha256'],
                'suffix' => 'companion',
            ];
        }

        return $archives;
    }

    private function validArtifactReference(mixed $reference, string $suffix): bool
    {
        return match ($suffix) {
            'accessibility', 'capture', 'console', 'network' => $this->matches(
                $reference,
                '/\ACAPTURE-[a-f0-9]{32}\z/',
            ),
            'sessions' => $this->matches($reference, '/\ASESSION-[a-f0-9]{32}\z/'),
            'browser' => $this->matches($reference, '/\ABROWSER-[a-f0-9]{32}\z/'),
            'fixtures', 'routes' => $this->matches($reference, '/\ARESULT-[a-f0-9]{32}\z/'),
            'companion' => $this->matches($reference, '/\AEVIDENCE-[a-f0-9]{32}\z/'),
            default => false,
        };
    }

    private function maximumArtifactBytes(string $suffix): ?int
    {
        return match ($suffix) {
            'capture', 'network' => self::MAXIMUM_CAPTURE_OR_NETWORK_BYTES,
            'accessibility', 'console', 'companion' => self::MAXIMUM_CONSOLE_ACCESSIBILITY_OR_COMPANION_BYTES,
            'fixtures', 'routes' => self::MAXIMUM_ROUTE_OR_FIXTURE_BYTES,
            'sessions' => self::MAXIMUM_SESSION_BINDING_BYTES,
            'browser' => self::MAXIMUM_BROWSER_DESCRIPTOR_BYTES,
            default => null,
        };
    }

    private function artifactHashMatches(
        string $path,
        string $directory,
        string $expectedHash,
        int $maximumBytes,
        ?int $effectiveUid,
    ): bool {
        if (is_link($path)) {
            return false;
        }

        $resolved = realpath($path);
        if (! is_string($resolved)
            || dirname($resolved) !== $directory
            || basename($resolved) !== basename($path)) {
            return false;
        }

        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (! is_array($before) || $handle === false) {
            return false;
        }

        try {
            $opened = @fstat($handle);
            if (! is_array($opened)
                || (($opened['mode'] ?? 0) & 0170000) !== 0100000
                || ! is_int($opened['size'] ?? null)
                || $opened['size'] < 1
                || $opened['size'] > $maximumBytes
                || (PHP_OS_FAMILY !== 'Windows' && (
                    ! is_int($effectiveUid)
                    || (($opened['mode'] ?? 0) & 0077) !== 0
                    || ($opened['uid'] ?? null) !== $effectiveUid
                ))) {
                return false;
            }

            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $handle, $maximumBytes + 1);
            $read = @fstat($handle);
            $final = @lstat($path);

            return is_int($bytes)
                && $bytes === $opened['size']
                && is_array($read)
                && is_array($final)
                && $this->sameFile($before, $opened)
                && $this->sameFile($opened, $read)
                && $this->sameFile($read, $final)
                && hash_equals($expectedHash, hash_final($hash));
        } finally {
            fclose($handle);
        }
    }

    private function jsonArtifactMatches(
        string $path,
        string $directory,
        string $expectedHash,
        mixed $expectedDocument,
        int $maximumBytes,
        ?int $effectiveUid,
    ): bool {
        if (! is_array($expectedDocument) || array_is_list($expectedDocument) || is_link($path)) {
            return false;
        }
        $resolved = realpath($path);
        if (! is_string($resolved) || dirname($resolved) !== $directory || basename($resolved) !== basename($path)) {
            return false;
        }
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (! is_array($before) || $handle === false) {
            return false;
        }
        try {
            $opened = @fstat($handle);
            if (! is_array($opened)
                || (($opened['mode'] ?? 0) & 0170000) !== 0100000
                || ! is_int($opened['size'] ?? null)
                || $opened['size'] < 1
                || $opened['size'] > $maximumBytes
                || (PHP_OS_FAMILY !== 'Windows' && (
                    ! is_int($effectiveUid)
                    || (($opened['mode'] ?? 0) & 0077) !== 0
                    || ($opened['uid'] ?? null) !== $effectiveUid
                ))) {
                return false;
            }
            $raw = stream_get_contents($handle, $maximumBytes + 1);
            $read = @fstat($handle);
            $final = @lstat($path);
            if (! is_string($raw)
                || strlen($raw) !== $opened['size']
                || ! hash_equals($expectedHash, hash('sha256', $raw))
                || ! is_array($read)
                || ! is_array($final)
                || ! $this->sameFile($before, $opened)
                || ! $this->sameFile($opened, $read)
                || ! $this->sameFile($read, $final)) {
                return false;
            }

            $document = (new StrictJsonObjectDecoder)->decode($raw, 16);

            return ! array_is_list($document) && $document === $expectedDocument;
        } catch (Throwable) {
            return false;
        } finally {
            fclose($handle);
        }
    }

    private function validViewports(
        mixed $viewports,
        array $expectedActors,
        string $browserDescriptorHash,
        DateTimeImmutable $deployedAt,
        DateTimeImmutable $reviewedAt,
    ): bool {
        if (! is_array($viewports) || ! array_is_list($viewports) || count($viewports) !== 2) {
            return false;
        }
        foreach ([[1440, 900], [1280, 800]] as $index => [$width, $height]) {
            $viewport = $viewports[$index] ?? null;
            $verifiedAt = is_array($viewport) ? $this->utc($viewport['verified_at_utc'] ?? null) : null;
            if (! is_array($viewport)
                || array_is_list($viewport)
                || ! $this->exactKeys($viewport, [
                    'accessibility_report_sha256',
                    'actor_session_binding_reference',
                    'actor_session_binding_sha256',
                    'actor_session_references',
                    'browser_version_descriptor_sha256',
                    'capture_archive_reference',
                    'capture_archive_sha256',
                    'console_clean',
                    'console_log_sha256',
                    'height',
                    'keyboard_accessible',
                    'network_clean',
                    'network_trace_sha256',
                    'overflow_free',
                    'privacy_scan_passed',
                    'route_evidence_count',
                    'status',
                    'verified_at_utc',
                    'width',
                ])
                || ($viewport['width'] ?? null) !== $width
                || ($viewport['height'] ?? null) !== $height
                || ($viewport['status'] ?? null) !== 'passed'
                || ($viewport['overflow_free'] ?? null) !== true
                || ($viewport['console_clean'] ?? null) !== true
                || ($viewport['network_clean'] ?? null) !== true
                || ($viewport['privacy_scan_passed'] ?? null) !== true
                || ($viewport['keyboard_accessible'] ?? null) !== true
                || ! is_int($viewport['route_evidence_count'] ?? null)
                || $viewport['route_evidence_count'] < 1
                || $viewport['route_evidence_count'] > 64
                || ! $this->matches($viewport['capture_archive_reference'] ?? null, '/\ACAPTURE-[a-f0-9]{32}\z/')
                || ! $this->matches($viewport['actor_session_binding_reference'] ?? null, '/\ASESSION-[a-f0-9]{32}\z/')
                || ! $this->validActorSessionReferences(
                    $viewport['actor_session_references'] ?? null,
                    $expectedActors,
                )
                || ! hash_equals($browserDescriptorHash, (string) ($viewport['browser_version_descriptor_sha256'] ?? ''))
                || ! $this->sha($viewport['actor_session_binding_sha256'] ?? null)
                || ! $this->sha($viewport['capture_archive_sha256'] ?? null)
                || ! $this->sha($viewport['network_trace_sha256'] ?? null)
                || ! $this->sha($viewport['console_log_sha256'] ?? null)
                || ! $this->sha($viewport['accessibility_report_sha256'] ?? null)
                || $verifiedAt === null
                || $verifiedAt < $deployedAt
                || $verifiedAt > $reviewedAt) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $expectedActors */
    private function validActorSessionReferences(mixed $references, array $expectedActors): bool
    {
        if (! is_array($references)
            || array_is_list($references)
            || ! $this->exactKeys($references, $expectedActors)) {
            return false;
        }

        foreach ($expectedActors as $actor) {
            if (! $this->matches($references[$actor] ?? null, '/\ASESSION-[a-f0-9]{32}\z/')) {
                return false;
            }
        }

        return count($references) === count(array_unique(array_values($references), SORT_STRING));
    }

    private function validBrowserDescriptor(mixed $descriptor): bool
    {
        return is_array($descriptor)
            && ! array_is_list($descriptor)
            && $this->exactKeys($descriptor, ['browser', 'version'])
            && ($descriptor['browser'] ?? null) === 'chromium'
            && is_string($descriptor['version'] ?? null)
            && preg_match('/\A\d+(?:\.\d+){1,3}\z/', $descriptor['version']) === 1;
    }

    private function actorSessionReferencesAreRoleBound(mixed ...$rowSets): bool
    {
        $actorBySessionReference = [];
        foreach ($rowSets as $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_array($row['viewports'] ?? null)) {
                    return false;
                }
                foreach ($row['viewports'] as $viewport) {
                    $references = is_array($viewport)
                        ? ($viewport['actor_session_references'] ?? null)
                        : null;
                    if (! is_array($references) || array_is_list($references)) {
                        return false;
                    }
                    foreach ($references as $actor => $reference) {
                        if (! is_string($actor) || ! is_string($reference)
                            || (isset($actorBySessionReference[$reference])
                                && $actorBySessionReference[$reference] !== $actor)) {
                            return false;
                        }
                        $actorBySessionReference[$reference] = $actor;
                    }
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function protectedMetadata(array $metadata, bool $requireRoot): bool
    {
        return $this->exactKeys($metadata, [
            'is_regular_file',
            'is_symlink',
            'mode',
            'owner_uid',
            'stable_identity',
        ])
            && ($metadata['is_regular_file'] ?? null) === true
            && ($metadata['is_symlink'] ?? null) === false
            && ($metadata['stable_identity'] ?? null) === true
            && is_int($metadata['mode'] ?? null)
            && ($metadata['mode'] & 0022) === 0
            && (! $requireRoot || ($metadata['owner_uid'] ?? null) === 0);
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function sameFile(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! array_key_exists($key, $left)
                || ! array_key_exists($key, $right)
                || $left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }

    private function utc(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $parsed
            : null;
    }

    private function sha(mixed $value, int $length = 64): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    /** @return array<string, mixed> */
    private function invalidAuthority(): array
    {
        return [
            'valid' => false,
            'authority_reference' => null,
            'authority_sha256' => null,
            'environment_reference_sha256' => null,
            'manifest_public_key' => null,
            'manifest_public_key_reference' => null,
            'release_revision' => null,
            'restored_environment_reference_sha256' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function invalidManifest(): array
    {
        return [
            'valid' => false,
            'authority_reference' => null,
            'environment_reference_sha256' => null,
            'manifest_sha256' => null,
            'primary_rows' => 0,
            'primary_viewports' => 0,
            'release_revision' => null,
            'restored_environment_reference_sha256' => null,
            'restored_rows' => 0,
            'restored_viewports' => 0,
        ];
    }
}
