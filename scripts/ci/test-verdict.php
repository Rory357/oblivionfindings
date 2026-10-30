#!/usr/bin/env php
<?php

/*
 * One verdict for a whole CI run: reads every JUnit file and shard summary
 * under the given directory and compares the failures with the reviewed list
 * in tests/ci/known-failures.php.
 *
 *   php scripts/ci/test-verdict.php <results-directory> [--expect-shards=N]
 *
 * Green: every failing test is on the known list, no batch crashed and every
 * expected shard reported. Red otherwise. Known failures that now pass are
 * listed so the list can be trimmed; they do not turn the run red.
 *
 * Writes verdict.json and verdict.md next to the results (and the Markdown to
 * $GITHUB_STEP_SUMMARY when set). Plain PHP: no vendor/ needed.
 */

require_once __DIR__.'/test-results.php';

$root = dirname(__DIR__, 2);
$directory = $argv[1] ?? '';
$expectShards = 0;
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/\A--expect-shards=(\d+)\z/', $argument, $match) === 1) {
        $expectShards = (int) $match[1];
    }
}

if ($directory === '' || ! is_dir($directory)) {
    fwrite(STDERR, "Usage: php scripts/ci/test-verdict.php <results-directory> [--expect-shards=N]\n");
    exit(2);
}

$results = [];
$unreadable = [];
$summaries = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (str_ends_with($path, '.xml')) {
        try {
            foreach (oblivionCiReadJunit($path, $root) as $id => $result) {
                if (! isset($results[$id]) || $result['status'] === 'failed') {
                    $results[$id] = $result;
                }
            }
        } catch (Throwable) {
            $unreadable[] = basename($path);
        }
    } elseif (str_ends_with($path, '-summary.json')) {
        $summary = json_decode((string) file_get_contents($path), true);
        if (is_array($summary)) {
            $summaries[] = $summary;
        }
    }
}

$known = oblivionCiKnownFailures($root.'/tests/ci/known-failures.php');
$comparison = oblivionCiCompare($results, $known);
$crashed = [];
$firstTests = [];
$slowestShard = 0.0;
foreach ($summaries as $summary) {
    foreach ($summary['crashed'] ?? [] as $batch => $files) {
        $crashed[] = sprintf('%s shard %d batch %d: %s', $summary['suite'], $summary['shard'], $batch, implode(' ', $files));
    }
    if (($summary['suite'] ?? '') === 'feature' && isset($summary['median_first_test_seconds'])) {
        $firstTests[] = (float) $summary['median_first_test_seconds'];
    }
    $slowestShard = max($slowestShard, (float) ($summary['seconds'] ?? 0));
}
sort($firstTests);
$firstTest = $firstTests === [] ? 0.0 : $firstTests[intdiv(count($firstTests), 2)];

$missingShards = $expectShards > 0 && count($summaries) < $expectShards
    ? $expectShards - count($summaries)
    : 0;
$green = $comparison['new'] === [] && $crashed === [] && $unreadable === [] && $missingShards === 0 && $results !== [];

$counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
foreach ($results as $result) {
    $counts[$result['status']]++;
}

$verdict = [
    'green' => $green,
    'tests' => count($results),
    'passed' => $counts['passed'],
    'failed' => $counts['failed'],
    'skipped' => $counts['skipped'],
    'new_failures' => array_map(static fn (string $id): array => ['test' => $id, 'message' => $results[$id]['message']], $comparison['new']),
    'known_failures' => array_map(static fn (string $id): array => ['test' => $id, 'owner' => $known[$id]['owner']], $comparison['known']),
    'known_now_passing' => $comparison['fixed'],
    'known_not_run' => $comparison['notRun'],
    'crashed_batches' => $crashed,
    'unreadable_results' => $unreadable,
    'shards_reported' => count($summaries),
    'shards_missing' => $missingShards,
    'slowest_shard_seconds' => round($slowestShard),
    // A feature batch's first test also pays for its process's test database build.
    'median_first_feature_test_seconds' => $firstTest,
];
file_put_contents($directory.'/verdict.json', json_encode($verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$lines = [];
$lines[] = $green
    ? '## ✅ Tests green: no new failures'
    : '## ❌ Tests red: new failures or missing results';
$lines[] = '';
$lines[] = sprintf(
    '%d tests: %d passed, %d failed (%d known, %d new), %d skipped. Shards reported: %d%s. Slowest shard %d min; a feature batch\'s first test (database build included) %.0f s.',
    count($results),
    $counts['passed'],
    $counts['failed'],
    count($comparison['known']),
    count($comparison['new']),
    $counts['skipped'],
    count($summaries),
    $missingShards > 0 ? sprintf(' (%d missing)', $missingShards) : '',
    (int) round($slowestShard / 60),
    $firstTest,
);

$section = static function (string $title, array $items) use (&$lines): void {
    if ($items === []) {
        return;
    }
    $lines[] = '';
    $lines[] = '### '.$title;
    foreach ($items as $item) {
        $lines[] = '- '.$item;
    }
};

$section('New failures (not on tests/ci/known-failures.php)', array_map(
    static fn (string $id): string => sprintf('`%s` — %s', $id, $results[$id]['message']),
    $comparison['new'],
));
$section('Crashed batches', $crashed);
$section('Unreadable result files', $unreadable);
$section('Known failures that now pass (remove them from the list)', array_map(static fn (string $id): string => '`'.$id.'`', $comparison['fixed']));
$section('Known failures that did not run (renamed or removed?)', array_map(static fn (string $id): string => '`'.$id.'`', $comparison['notRun']));

$owners = [];
foreach ($comparison['known'] as $id) {
    $owners[$known[$id]['owner']] = ($owners[$known[$id]['owner']] ?? 0) + 1;
}
arsort($owners);
$section('Known failures by owner', array_map(
    static fn (string $owner, int $count): string => sprintf('%s: %d', $owner, $count),
    array_keys($owners),
    array_values($owners),
));

$markdown = implode("\n", $lines)."\n";
file_put_contents($directory.'/verdict.md', $markdown);
if (($summaryFile = getenv('GITHUB_STEP_SUMMARY')) !== false && $summaryFile !== '') {
    file_put_contents($summaryFile, $markdown, FILE_APPEND);
}

fwrite(STDOUT, $markdown);
exit($green ? 0 : 1);
