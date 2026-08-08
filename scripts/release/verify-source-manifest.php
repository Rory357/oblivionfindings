#!/usr/bin/env php
<?php

declare(strict_types=1);

const MAX_MANIFEST_BYTES = 5_242_880;
const MAX_MANIFEST_FILES = 10_000;
const MAX_SOURCE_BYTES = 52_428_800;

final class ManifestRefused extends RuntimeException {}

/** @return never */
function refuse(string $message): void
{
    throw new ManifestRefused($message);
}

/** @return array{exit_code: int, stdout: string, stderr: string} */
function runGit(string $checkout, array $arguments): array
{
    $command = array_merge(['git', '-C', $checkout], $arguments);
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        refuse('git could not be started.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

function requireGit(string $checkout, array $arguments, string $failure): string
{
    $result = runGit($checkout, $arguments);
    if ($result['exit_code'] !== 0) {
        refuse($failure);
    }

    return $result['stdout'];
}

function requireExactKeys(array $value, array $required, array $optional, string $context): void
{
    $keys = array_keys($value);
    $allowed = array_merge($required, $optional);
    $unknown = array_values(array_diff($keys, $allowed));
    $missing = array_values(array_diff($required, $keys));

    if ($unknown !== []) {
        refuse($context.' contains unsupported fields.');
    }
    if ($missing !== []) {
        refuse($context.' is missing required fields.');
    }
}

function requireSafeText(mixed $value, string $field, int $maximum = 500): string
{
    if (! is_string($value) || $value === '' || trim($value) !== $value) {
        refuse($field.' must be one non-empty trimmed string.');
    }
    if (strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        refuse($field.' contains unsupported content.');
    }

    return $value;
}

function requireUtcEvidenceTime(mixed $value, string $field): string
{
    $timestamp = requireSafeText($value, $field, 40);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $timestamp) !== 1) {
        refuse($field.' must be one explicit UTC timestamp.');
    }

    try {
        $instant = new DateTimeImmutable($timestamp);
    } catch (Throwable) {
        refuse($field.' must be one valid UTC timestamp.');
    }
    if ($instant->getOffset() !== 0 || $instant > new DateTimeImmutable('+5 seconds')) {
        refuse($field.' cannot be in the future.');
    }

    return $timestamp;
}

function requireApprovedReview(mixed $value, string $field): void
{
    if (! is_array($value) || array_is_list($value)) {
        refuse($field.' must be one structured review object.');
    }
    requireExactKeys($value, ['decision', 'reviewer', 'reviewed_at_utc'], [], $field);
    if (($value['decision'] ?? null) !== 'approved') {
        refuse($field.'.decision must be approved.');
    }

    $reviewer = requireSafeText($value['reviewer'] ?? null, $field.'.reviewer', 100);
    if (strlen($reviewer) < 3 || preg_match('/\A(?:pending|unknown|none|n\/a|self|reviewer|approved reviewer)\z/i', $reviewer) === 1) {
        refuse($field.'.reviewer must identify the approving reviewer.');
    }
    requireUtcEvidenceTime($value['reviewed_at_utc'] ?? null, $field.'.reviewed_at_utc');
}

function requirePassedVerification(mixed $value, string $field): void
{
    if (! is_array($value) || array_is_list($value)) {
        refuse($field.' must be one structured verification object.');
    }
    requireExactKeys($value, ['result', 'evidence', 'observed_at_utc'], [], $field);
    if (($value['result'] ?? null) !== 'passed') {
        refuse($field.'.result must be passed.');
    }

    $evidence = requireSafeText($value['evidence'] ?? null, $field.'.evidence', 1000);
    if (strlen($evidence) < 8 || preg_match('/\A(?:pending|unknown|none|n\/a|not run|unverified|todo)\z/i', $evidence) === 1) {
        refuse($field.'.evidence must identify the completed verification.');
    }
    requireUtcEvidenceTime($value['observed_at_utc'] ?? null, $field.'.observed_at_utc');
}

function requireRevision(mixed $value, string $field): string
{
    if (! is_string($value) || preg_match('/^[0-9a-f]{40}$/', $value) !== 1) {
        refuse($field.' must be one exact lowercase 40-character Git revision.');
    }

    return $value;
}

function requireHash(mixed $value, string $field): string
{
    if (! is_string($value) || preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
        refuse($field.' must be one exact lowercase SHA-256 hash.');
    }

    return $value;
}

function requireRepositoryPath(mixed $value, string $field): string
{
    $path = requireSafeText($value, $field, 512);
    if (
        str_contains($path, '\\')
        || str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:/', $path) === 1
        || preg_match('/[*?\[\]{}]/', $path) === 1
    ) {
        refuse($field.' must be one exact relative path without a glob.');
    }

    $segments = explode('/', $path);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        refuse($field.' must be one normalised repository path.');
    }

    return $path;
}

function pathIsInside(string $path, string $directory): bool
{
    $normalisedPath = rtrim(str_replace('\\', '/', $path), '/');
    $normalisedDirectory = rtrim(str_replace('\\', '/', $directory), '/');

    if (DIRECTORY_SEPARATOR === '\\') {
        $normalisedPath = strtolower($normalisedPath);
        $normalisedDirectory = strtolower($normalisedDirectory);
    }

    return $normalisedPath === $normalisedDirectory
        || str_starts_with($normalisedPath, $normalisedDirectory.'/');
}

function isExcludedPath(string $path): bool
{
    $lower = strtolower($path);
    $basename = basename($lower);
    $rootCommandOutput = [
        'count()])',
        "pluck('migration'))",
        'tosql())',
        "value('migration')])",
    ];

    if (in_array($lower, $rootCommandOutput, true)) {
        return true;
    }
    if ($lower === '.env' || (str_starts_with($lower, '.env.') && $lower !== '.env.example')) {
        return true;
    }
    if ($lower === '.phpunit.result.cache' || $lower === 'public/hot') {
        return true;
    }
    if (preg_match('~(^|/)(\.playwright-cli|output|playwright-report|test-results|vendor|node_modules)(/|$)~', $lower) === 1) {
        return true;
    }
    if (str_starts_with($lower, 'public/build/') || str_starts_with($lower, 'bootstrap/ssr/')) {
        return true;
    }
    if (preg_match('~^resources/js/(actions|routes|wayfinder)(/|$)~', $lower) === 1) {
        return true;
    }
    if (str_starts_with($lower, 'storage/logs/') || str_starts_with($lower, 'storage/framework/testing/')) {
        return true;
    }
    if (
        (str_starts_with($lower, 'tests/browser/screenshots/') || str_starts_with($lower, 'tests/browser/console/'))
        && $basename !== '.gitignore'
    ) {
        return true;
    }
    if ($lower === 'database/database.sqlite' || preg_match('/\.sqlite(?:-journal|-wal|-shm)?$/', $lower) === 1) {
        return true;
    }
    if (preg_match('/\.(?:out|err|trace)$/', $lower) === 1) {
        return true;
    }
    if (preg_match('/\.(?:png|jpe?g|gif|webp|mp4|webm|mov|har)$/', $lower) === 1) {
        return true;
    }
    if (str_ends_with($lower, '.log') && ! str_starts_with($lower, 'tests/fixtures/')) {
        return true;
    }

    return false;
}

function isAllowedSourcePath(string $path): bool
{
    $prefixes = [
        'app/',
        'collector/',
        'config/',
        'database/factories/',
        'database/migrations/',
        'database/seeders/',
        'docs/runbooks/',
        'ops/',
        'resources/css/',
        'resources/js/',
        'routes/',
        'scripts/',
        'tests/',
    ];
    $rootFiles = [
        '.env.example',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'tsconfig.json',
        'vite.config.ts',
        'vitest.config.ts',
        'docs/it-support-security-devices-completion-goal.md',
        'docs/hero-unification-v3-handoff.md',
    ];

    if (in_array($path, $rootFiles, true)) {
        return true;
    }

    foreach ($prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

function requireSanitisedCredentialRemediation(string $path, string $candidatePath): void
{
    if ($path !== 'docs/hero-unification-v3-handoff.md') {
        return;
    }

    $contents = file_get_contents($candidatePath);
    if ($contents === false
        || ! str_contains($contents, '- SSH: [removed; use approved secure deployment access]')
        || ! str_contains($contents, '- Login: use an approved dev/demo application account from the secure credential channel.')
        || preg_match('/^\- (?:SSH|Login): .*\s\/\s.+$/m', $contents) === 1) {
        refuse('the credential-remediation handoff is not sanitised.');
    }
}

function gitObjectExists(string $checkout, string $revision, string $path): bool
{
    return runGit($checkout, ['cat-file', '-e', $revision.':'.$path])['exit_code'] === 0;
}

function hashGitObject(string $checkout, string $revision, string $path): string
{
    $object = $revision.':'.$path;
    $size = trim(requireGit($checkout, ['cat-file', '-s', $object], 'a source object could not be sized.'));
    if (preg_match('/^\d+$/', $size) !== 1 || (int) $size > MAX_SOURCE_BYTES) {
        refuse('a source object exceeds the verifier size limit.');
    }

    $contents = requireGit($checkout, ['show', $object], 'a source object could not be read.');

    return hash('sha256', $contents);
}

/** @return array<string, array{change: string, previous_path: ?string}> */
function changedPaths(string $checkout, string $baseRevision, string $candidateRevision): array
{
    $output = requireGit(
        $checkout,
        ['-c', 'core.quotePath=false', 'diff', '--name-status', '--find-renames=50%', $baseRevision, $candidateRevision, '--'],
        'the exact revision diff could not be read.',
    );
    $changes = [];

    foreach (preg_split('/\r?\n/', rtrim($output)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        $status = array_shift($parts);
        $code = is_string($status) && $status !== '' ? $status[0] : '';

        if (in_array($code, ['A', 'M', 'D'], true) && count($parts) === 1) {
            $path = requireRepositoryPath($parts[0], 'changed path');
            $change = match ($code) {
                'A' => 'added',
                'M' => 'modified',
                'D' => 'deleted',
            };
            $previousPath = null;
        } elseif ($code === 'R' && count($parts) === 2) {
            $previousPath = requireRepositoryPath($parts[0], 'renamed previous path');
            $path = requireRepositoryPath($parts[1], 'renamed path');
            $change = 'renamed';
        } else {
            refuse('the revision diff contains an unsupported change or path.');
        }

        if (isset($changes[$path])) {
            refuse('the revision diff contains a duplicate destination path.');
        }
        if (isExcludedPath($path) || ($previousPath !== null && isExcludedPath($previousPath))) {
            refuse('the candidate revision contains an excluded path.');
        }
        if (! isAllowedSourcePath($path) || ($previousPath !== null && ! isAllowedSourcePath($previousPath))) {
            refuse('the candidate revision contains a path outside the approved source families.');
        }

        $changes[$path] = ['change' => $change, 'previous_path' => $previousPath];
    }

    return $changes;
}

/** @return array<string, array{change: string, previous_path: ?string}> */
function verifyRows(array $rows, string $checkout, string $baseRevision, string $candidateRevision): array
{
    if (! array_is_list($rows) || $rows === [] || count($rows) > MAX_MANIFEST_FILES) {
        refuse('files must be one non-empty bounded list.');
    }

    $manifestChanges = [];
    foreach ($rows as $index => $row) {
        if (! is_array($row) || array_is_list($row)) {
            refuse("files[$index] must be one object.");
        }
        requireExactKeys(
            $row,
            ['path', 'change', 'sha256', 'owner', 'requirement', 'source_or_generated', 'review', 'verification'],
            ['previous_path'],
            "files[$index]",
        );

        $path = requireRepositoryPath($row['path'], "files[$index].path");
        if (isset($manifestChanges[$path])) {
            refuse('the manifest contains a duplicate path.');
        }
        if (isExcludedPath($path)) {
            refuse('the manifest contains an excluded path.');
        }
        if (! isAllowedSourcePath($path)) {
            refuse('the manifest path is outside the approved source families.');
        }

        $change = requireSafeText($row['change'], "files[$index].change", 16);
        if (! in_array($change, ['added', 'modified', 'renamed', 'deleted'], true)) {
            refuse("files[$index].change is unsupported.");
        }
        $hash = requireHash($row['sha256'], "files[$index].sha256");
        requireSafeText($row['owner'], "files[$index].owner", 100);
        requireSafeText($row['requirement'], "files[$index].requirement", 100);
        requireApprovedReview($row['review'], "files[$index].review");
        requirePassedVerification($row['verification'], "files[$index].verification");
        if ($row['source_or_generated'] !== 'source') {
            refuse("files[$index].source_or_generated must be source.");
        }

        $previousPath = null;
        if ($change === 'renamed') {
            if (! array_key_exists('previous_path', $row)) {
                refuse("files[$index].previous_path is required for a rename.");
            }
            $previousPath = requireRepositoryPath($row['previous_path'], "files[$index].previous_path");
            if ($previousPath === $path || isExcludedPath($previousPath) || ! isAllowedSourcePath($previousPath)) {
                refuse("files[$index].previous_path is invalid.");
            }
        } elseif (array_key_exists('previous_path', $row)) {
            refuse("files[$index].previous_path is allowed only for a rename.");
        }

        $baseExists = gitObjectExists($checkout, $baseRevision, $previousPath ?? $path);
        $candidateExists = gitObjectExists($checkout, $candidateRevision, $path);
        if ($change === 'added' && ($baseExists || ! $candidateExists)) {
            refuse('an added manifest path does not match the revisions.');
        }
        if ($change === 'modified' && (! $baseExists || ! $candidateExists)) {
            refuse('a modified manifest path does not match the revisions.');
        }
        if ($change === 'deleted' && (! $baseExists || $candidateExists)) {
            refuse('a deleted manifest path does not match the revisions.');
        }
        if ($change === 'renamed' && (! $baseExists || ! $candidateExists || gitObjectExists($checkout, $candidateRevision, $previousPath))) {
            refuse('a renamed manifest path does not match the revisions.');
        }

        if ($change === 'deleted') {
            $actualHash = hashGitObject($checkout, $baseRevision, $path);
        } else {
            $candidatePath = $checkout.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (! is_file($candidatePath) || is_link($candidatePath)) {
                refuse('a candidate source path is missing, unreadable, or symbolic.');
            }
            $size = filesize($candidatePath);
            if ($size === false || $size > MAX_SOURCE_BYTES) {
                refuse('a candidate source path exceeds the verifier size limit.');
            }
            $actualHash = hash_file('sha256', $candidatePath);
            if ($actualHash === false) {
                refuse('a candidate source path could not be hashed.');
            }
            requireSanitisedCredentialRemediation($path, $candidatePath);
        }
        if (! hash_equals($hash, $actualHash)) {
            refuse('a manifest SHA-256 hash does not match the reviewed source.');
        }

        $manifestChanges[$path] = ['change' => $change, 'previous_path' => $previousPath];
    }

    return $manifestChanges;
}

function verifyManifest(string $manifestPath, string $checkout): int
{
    $manifestSize = filesize($manifestPath);
    if ($manifestSize === false || $manifestSize < 2 || $manifestSize > MAX_MANIFEST_BYTES) {
        refuse('the manifest size is invalid.');
    }
    $json = file_get_contents($manifestPath);
    if ($json === false) {
        refuse('the manifest could not be read.');
    }

    try {
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        refuse('the manifest is not valid JSON.');
    }
    if (! is_array($manifest) || array_is_list($manifest)) {
        refuse('the manifest root must be one object.');
    }
    requireExactKeys(
        $manifest,
        ['schema_version', 'base_revision', 'candidate_revision', 'origin_main_revision', 'files'],
        [],
        'manifest',
    );
    if ($manifest['schema_version'] !== 2) {
        refuse('schema_version must be 2.');
    }

    $baseRevision = requireRevision($manifest['base_revision'], 'base_revision');
    $candidateRevision = requireRevision($manifest['candidate_revision'], 'candidate_revision');
    $originMainRevision = requireRevision($manifest['origin_main_revision'], 'origin_main_revision');
    if ($candidateRevision !== $originMainRevision) {
        refuse('candidate_revision must equal origin_main_revision.');
    }

    $inside = trim(requireGit($checkout, ['rev-parse', '--is-inside-work-tree'], 'the candidate checkout is not a Git worktree.'));
    if ($inside !== 'true') {
        refuse('the candidate checkout is not a Git worktree.');
    }
    $head = trim(requireGit($checkout, ['rev-parse', '--verify', 'HEAD'], 'candidate HEAD is unavailable.'));
    $originMain = trim(requireGit($checkout, ['rev-parse', '--verify', 'refs/remotes/origin/main'], 'origin/main is unavailable.'));
    if ($head !== $candidateRevision || $originMain !== $originMainRevision) {
        refuse('the candidate checkout revision does not match the manifest.');
    }
    if (runGit($checkout, ['cat-file', '-e', $baseRevision.'^{commit}'])['exit_code'] !== 0) {
        refuse('base_revision is unavailable.');
    }
    if (runGit($checkout, ['merge-base', '--is-ancestor', $baseRevision, $candidateRevision])['exit_code'] !== 0) {
        refuse('base_revision is not an ancestor of candidate_revision.');
    }

    $status = requireGit(
        $checkout,
        ['status', '--porcelain=v1', '--untracked-files=all'],
        'candidate checkout cleanliness could not be verified.',
    );
    if ($status !== '') {
        refuse('the candidate checkout contains tracked or untracked changes.');
    }

    $actualChanges = changedPaths($checkout, $baseRevision, $candidateRevision);
    $manifestChanges = verifyRows($manifest['files'], $checkout, $baseRevision, $candidateRevision);
    ksort($actualChanges);
    ksort($manifestChanges);
    if ($manifestChanges !== $actualChanges) {
        refuse('the manifest does not exactly match the revision diff.');
    }

    fwrite(STDOUT, sprintf(
        "Release source manifest verified: %d exact source entries at %s.\n",
        count($manifestChanges),
        $candidateRevision,
    ));

    return 0;
}

try {
    $restIndex = null;
    $options = getopt('', ['manifest:', 'checkout:'], $restIndex);
    if (! is_array($options) || ! isset($options['manifest'], $options['checkout'])) {
        refuse('usage: verify-source-manifest.php --manifest=/absolute/manifest.json --checkout=/absolute/clean-checkout');
    }
    requireExactKeys($options, ['manifest', 'checkout'], [], 'arguments');
    if ($restIndex !== count($_SERVER['argv'])) {
        refuse('unsupported command-line arguments were supplied.');
    }
    if (! is_string($options['manifest']) || ! is_string($options['checkout'])) {
        refuse('manifest and checkout must each be supplied exactly once.');
    }

    $manifestPath = realpath($options['manifest']);
    $checkout = realpath($options['checkout']);
    if ($manifestPath === false || ! is_file($manifestPath)) {
        refuse('the prepared manifest file is unavailable.');
    }
    if ($checkout === false || ! is_dir($checkout)) {
        refuse('the candidate checkout directory is unavailable.');
    }
    if (pathIsInside($manifestPath, $checkout)) {
        refuse('the prepared manifest must remain outside the candidate checkout.');
    }

    exit(verifyManifest($manifestPath, $checkout));
} catch (ManifestRefused $exception) {
    fwrite(STDERR, 'Release source manifest verification refused: '.$exception->getMessage().PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Release source manifest verification refused: unexpected verifier failure.\n");
    exit(1);
}
