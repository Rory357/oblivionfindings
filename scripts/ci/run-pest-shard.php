#!/usr/bin/env php
<?php

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/test-results.php';

/** @return list<string> */
function oblivionCiPestFiles(string $root, string $suite): array
{
    $directories = match ($suite) {
        'feature' => ['tests/Feature'],
        'foundation' => ['tests/Unit', 'tests/Integration', 'tests/Architecture'],
        default => throw new InvalidArgumentException('Unsupported test suite.'),
    };
    $files = [];

    foreach ($directories as $directory) {
        $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (! is_dir($absolute)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo
                || ! $file->isFile()
                || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
    }

    sort($files, SORT_STRING);

    return array_values(array_unique($files));
}

/** @param list<string> $files @return list<string> */
function oblivionCiPestShard(array $files, int $index, int $count): array
{
    if ($count < 1 || $index < 0 || $index >= $count) {
        throw new InvalidArgumentException('Invalid shard coordinates.');
    }

    return array_values(array_filter(
        $files,
        static fn (string $file, int $position): bool => $position % $count === $index,
        ARRAY_FILTER_USE_BOTH,
    ));
}

/** @param list<string> $files @return list<list<string>> */
function oblivionCiPestBatches(array $files, int $batchSize): array
{
    if ($batchSize < 1 || $batchSize > 50) {
        throw new InvalidArgumentException('Invalid batch size.');
    }

    return array_values(array_chunk($files, $batchSize));
}

/**
 * Run every batch as its own fresh Pest process, up to $workers at once. Each
 * process gets its own test database (Tests\TestCase), so batches never share one.
 *
 * @param  list<list<string>>  $batches
 * @return array<int, array{exit: int, seconds: float, junit: string, output: string}>
 */
function oblivionCiRunBatches(string $root, array $batches, int $workers, string $junitDirectory, string $label): array
{
    $queue = array_keys($batches);
    $running = [];
    $finished = [];

    while ($queue !== [] || $running !== []) {
        while (count($running) < $workers && $queue !== []) {
            $batchIndex = array_shift($queue);
            $junit = sprintf('%s/%s-batch-%03d.xml', $junitDirectory, $label, $batchIndex + 1);
            @unlink($junit);
            $process = new Process([
                PHP_BINARY,
                'artisan',
                'test',
                ...$batches[$batchIndex],
                '--colors=never',
                '--log-junit='.$junit,
            ], $root);
            $process->setTimeout(null);
            $process->start();
            $running[$batchIndex] = [$process, microtime(true), $junit];
        }

        foreach ($running as $batchIndex => [$process, $started, $junit]) {
            if ($process->isRunning()) {
                continue;
            }

            unset($running[$batchIndex]);
            $seconds = microtime(true) - $started;
            $output = $process->getOutput().$process->getErrorOutput();
            $exit = $process->getExitCode() ?? 1;
            file_put_contents(sprintf('%s/%s-batch-%03d.log', $junitDirectory, $label, $batchIndex + 1), $output);
            $finished[$batchIndex] = ['exit' => $exit, 'seconds' => $seconds, 'junit' => $junit, 'output' => $output];

            // GitHub folds each batch's complete output into one group.
            fwrite(STDOUT, sprintf(
                "::group::Batch %d/%d (%d files) exit %d in %ds\n%s\n::endgroup::\n",
                $batchIndex + 1,
                count($batches),
                count($batches[$batchIndex]),
                $exit,
                (int) round($seconds),
                rtrim($output),
            ));
        }

        if ($running !== []) {
            usleep(200_000);
        }
    }

    ksort($finished);

    return $finished;
}

/** @param list<string> $arguments */
function oblivionCiPestMain(array $arguments): int
{
    $options = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (preg_match('/\A--(suite|shard-index|shard-count|batch-size|workers)=([A-Za-z0-9_-]+)\z/', $argument, $match) !== 1
            || array_key_exists($match[1], $options)) {
            fwrite(STDERR, "Invalid CI test-shard argument.\n");

            return 2;
        }
        $options[$match[1]] = $match[2];
    }

    $required = ['suite', 'shard-index', 'shard-count', 'batch-size'];
    if (array_slice(array_keys($options), 0, 4) !== $required || array_diff(array_keys($options), [...$required, 'workers']) !== []) {
        fwrite(STDERR, "All CI test-shard arguments are required in canonical order.\n");

        return 2;
    }

    $suite = $options['suite'];
    $index = filter_var($options['shard-index'], FILTER_VALIDATE_INT);
    $count = filter_var($options['shard-count'], FILTER_VALIDATE_INT);
    $batchSize = filter_var($options['batch-size'], FILTER_VALIDATE_INT);
    $workers = filter_var($options['workers'] ?? '1', FILTER_VALIDATE_INT);
    if (! is_int($index) || ! is_int($count) || ! is_int($batchSize) || ! is_int($workers) || $workers < 1 || $workers > 32) {
        fwrite(STDERR, "CI test-shard coordinates must be integers.\n");

        return 2;
    }

    $root = dirname(__DIR__, 2);
    try {
        $inventory = oblivionCiPestFiles($root, $suite);
        $files = oblivionCiPestShard($inventory, $index, $count);
        $batches = oblivionCiPestBatches($files, $batchSize);
    } catch (Throwable) {
        fwrite(STDERR, "CI test-shard plan is invalid.\n");

        return 2;
    }

    if ($inventory === [] || $files === [] || $batches === []) {
        fwrite(STDERR, "CI test-shard inventory is empty.\n");

        return 2;
    }

    $rosterHash = hash('sha256', implode("\n", $files));
    fwrite(STDOUT, sprintf(
        "CI Pest suite=%s shard=%d/%d files=%d batches=%d workers=%d roster_sha256=%s\n",
        $suite,
        $index + 1,
        $count,
        count($files),
        count($batches),
        $workers,
        $rosterHash,
    ));

    $junitDirectory = $root.'/storage/logs/ci';
    if (! is_dir($junitDirectory) && ! mkdir($junitDirectory, 0777, true) && ! is_dir($junitDirectory)) {
        fwrite(STDERR, "Cannot create storage/logs/ci.\n");

        return 2;
    }

    // A failing batch must not hide the rest of the shard: run every batch,
    // then judge the shard on what failed.
    $label = sprintf('%s-%02d-of-%02d', $suite, $index + 1, $count);
    $started = microtime(true);
    $batchResults = oblivionCiRunBatches($root, $batches, $workers, $junitDirectory, $label);

    $results = [];
    $crashed = [];
    $firstTestSeconds = [];
    foreach ($batchResults as $batchIndex => $batch) {
        $batchTests = [];
        if (is_file($batch['junit'])) {
            try {
                $batchTests = oblivionCiReadJunit($batch['junit'], $root);
            } catch (Throwable) {
                $batchTests = [];
            }
        }

        $failed = array_filter($batchTests, static fn (array $test): bool => $test['status'] === 'failed');
        // A process that ended badly without reporting a failing test crashed (fatal error, memory, signal).
        if ($batchTests === [] || ($batch['exit'] !== 0 && $failed === [])) {
            $crashed[$batchIndex + 1] = $batches[$batchIndex];
        }

        if ($batchTests !== []) {
            $firstTestSeconds[] = reset($batchTests)['time'];
        }

        $results += $batchTests;
    }

    $known = oblivionCiKnownFailures($root.'/tests/ci/known-failures.php');
    $comparison = oblivionCiCompare($results, $known);
    sort($firstTestSeconds);
    $medianStart = $firstTestSeconds === [] ? 0.0 : $firstTestSeconds[intdiv(count($firstTestSeconds), 2)];

    file_put_contents($junitDirectory.'/'.$label.'-summary.json', json_encode([
        'suite' => $suite,
        'shard' => $index + 1,
        'shards' => $count,
        'workers' => $workers,
        'files' => count($files),
        'batches' => count($batches),
        'seconds' => round(microtime(true) - $started, 1),
        'tests' => count($results),
        'failed' => count($comparison['new']) + count($comparison['known']),
        'new' => $comparison['new'],
        'known' => $comparison['known'],
        'crashed' => $crashed,
        // A batch's first test also pays for its process's test database build.
        'median_first_test_seconds' => round($medianStart, 1),
        'batch_seconds' => array_map(static fn (array $batch): float => round($batch['seconds'], 1), $batchResults),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    fwrite(STDOUT, sprintf(
        "\nCI Pest shard done in %ds: %d tests, %d known failures, %d new failures, %d crashed batches; a batch's first test (database build included) took %.1fs (median).\n",
        (int) round(microtime(true) - $started),
        count($results),
        count($comparison['known']),
        count($comparison['new']),
        count($crashed),
        $medianStart,
    ));

    foreach ($comparison['new'] as $id) {
        fwrite(STDOUT, sprintf("::error title=New test failure::%s — %s\n", $id, $results[$id]['message']));
    }
    foreach ($crashed as $batchNumber => $batchFiles) {
        fwrite(STDOUT, sprintf("::error title=Crashed Pest batch %d::%s\n", $batchNumber, implode(' ', $batchFiles)));
    }

    return $comparison['new'] === [] && $crashed === [] ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(oblivionCiPestMain($argv));
}
