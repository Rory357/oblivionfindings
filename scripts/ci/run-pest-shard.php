#!/usr/bin/env php
<?php

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

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

/** @param list<string> $arguments */
function oblivionCiPestMain(array $arguments): int
{
    $options = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (preg_match('/\A--(suite|shard-index|shard-count|batch-size)=([A-Za-z0-9_-]+)\z/', $argument, $match) !== 1
            || array_key_exists($match[1], $options)) {
            fwrite(STDERR, "Invalid CI test-shard argument.\n");

            return 2;
        }
        $options[$match[1]] = $match[2];
    }

    if (array_keys($options) !== ['suite', 'shard-index', 'shard-count', 'batch-size']) {
        fwrite(STDERR, "All CI test-shard arguments are required in canonical order.\n");

        return 2;
    }

    $suite = $options['suite'];
    $index = filter_var($options['shard-index'], FILTER_VALIDATE_INT);
    $count = filter_var($options['shard-count'], FILTER_VALIDATE_INT);
    $batchSize = filter_var($options['batch-size'], FILTER_VALIDATE_INT);
    if (! is_int($index) || ! is_int($count) || ! is_int($batchSize)) {
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
        "CI Pest suite=%s shard=%d/%d files=%d batches=%d roster_sha256=%s\n",
        $suite,
        $index + 1,
        $count,
        count($files),
        count($batches),
        $rosterHash,
    ));

    foreach ($batches as $batchIndex => $batch) {
        fwrite(STDOUT, sprintf(
            "Starting fresh Pest process %d/%d with %d files.\n",
            $batchIndex + 1,
            count($batches),
            count($batch),
        ));

        $process = new Process([
            PHP_BINARY,
            'artisan',
            'test',
            ...$batch,
            '--colors=never',
        ], $root);
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });

        if (! $process->isSuccessful()) {
            fwrite(STDERR, sprintf("CI Pest batch %d failed.\n", $batchIndex + 1));

            return $process->getExitCode() ?? 1;
        }
    }

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(oblivionCiPestMain($argv));
}
