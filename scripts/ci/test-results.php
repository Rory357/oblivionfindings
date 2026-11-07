<?php

/*
 * Reading PHPUnit/Pest JUnit files and comparing failures with the reviewed
 * known-failures list (tests/ci/known-failures.php). Plain PHP with no Composer
 * dependencies, so the verdict job can run it without installing vendor/.
 *
 * A test is identified as "<path from the repo root>::<test name>", for
 * example "tests/Feature/Auth/AuthenticationTest.php::test_users_can_logout".
 */

/**
 * @return array<string, array{status: 'passed'|'failed'|'skipped', file: string, message: string, time: float}>
 */
function oblivionCiReadJunit(string $junitFile, string $root): array
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($junitFile);
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        throw new RuntimeException('Unreadable JUnit file: '.$junitFile);
    }

    $results = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $file = oblivionCiRelativePath((string) ($case['file'] ?? ''), $root);
        if ($file === '') {
            $class = (string) ($case['class'] ?? $case['classname'] ?? '');
            $file = str_replace(['\\', '.'], '/', preg_replace('/^P\\\\/', '', $class)).'.php';
            $file = lcfirst($file);
        }

        $status = 'passed';
        $message = '';
        if (isset($case->failure) || isset($case->error)) {
            $status = 'failed';
            $node = isset($case->failure) ? $case->failure : $case->error;
            $message = trim((string) ($node['message'] ?? '')) ?: trim((string) $node);
        } elseif (isset($case->skipped)) {
            $status = 'skipped';
        }

        $id = $file.'::'.(string) $case['name'];
        if (isset($results[$id]) && $results[$id]['status'] === 'failed') {
            continue;
        }

        $results[$id] = [
            'status' => $status,
            'file' => $file,
            'message' => mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 400),
            'time' => (float) ($case['time'] ?? 0),
        ];
    }

    return $results;
}

function oblivionCiRelativePath(string $path, string $root): string
{
    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/').'/';
    if (str_starts_with(strtolower($path), strtolower($root))) {
        $path = substr($path, strlen($root));
    }

    // Pest can report "file.php::line" style locations.
    return preg_replace('/::\d+$/', '', $path) ?? $path;
}

/**
 * @return array<string, array{test: string, owner: string, reason: string, since: string}>
 */
function oblivionCiKnownFailures(string $listFile): array
{
    $entries = require $listFile;
    if (! is_array($entries)) {
        throw new RuntimeException('The known-failures list must return an array.');
    }

    $known = [];
    foreach ($entries as $entry) {
        foreach (['test', 'owner', 'reason', 'since'] as $field) {
            if (! is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                throw new RuntimeException('Every known failure needs test, owner, reason and since: '.json_encode($entry));
            }
        }
        $known[$entry['test']] = $entry;
    }

    return $known;
}

/**
 * @param  array<string, array{status: string, file: string, message: string, time: float}>  $results
 * @param  array<string, array{test: string, owner: string, reason: string, since: string}>  $known
 * @return array{new: list<string>, known: list<string>, fixed: list<string>, notRun: list<string>}
 */
function oblivionCiCompare(array $results, array $known): array
{
    $new = [];
    $knownFailed = [];
    foreach ($results as $id => $result) {
        if ($result['status'] !== 'failed') {
            continue;
        }

        if (isset($known[$id])) {
            $knownFailed[] = $id;
        } else {
            $new[] = $id;
        }
    }

    $fixed = [];
    $notRun = [];
    foreach (array_keys($known) as $id) {
        if (! isset($results[$id])) {
            $notRun[] = $id;
        } elseif ($results[$id]['status'] === 'passed') {
            $fixed[] = $id;
        }
    }

    sort($new);
    sort($knownFailed);
    sort($fixed);
    sort($notRun);

    return ['new' => $new, 'known' => $knownFailed, 'fixed' => $fixed, 'notRun' => $notRun];
}
