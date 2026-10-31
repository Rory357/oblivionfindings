#!/usr/bin/env php
<?php

/*
 * composer test:fast [-- <pest arguments>]
 *
 * The suite in parallel: one worker per two CPU cores, at most 8, and no more
 * than MySQL's open-table cache holds, each on its own kept test database
 * (Tests\TestCase). TEST_PROCESSES or --processes=<n> picks the count; any
 * other argument (a folder, --filter, --stop-on-failure) goes to Pest as
 * given. The parallel runner takes one path, so several run through a
 * generated PHPUnit configuration.
 */

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

const OBLIVION_FAST_TEST_BATCH_FILES = 20;

/**
 * Replace several test paths with one generated configuration that runs them.
 *
 * @param  list<string>  $arguments
 * @return list<string>
 */
function oblivionFastTestTargets(array $arguments, string $root): array
{
    $paths = array_values(array_filter(
        $arguments,
        static fn (string $argument): bool => ! str_starts_with($argument, '-') && file_exists($root.'/'.$argument),
    ));

    if (count($paths) < 2) {
        return $arguments;
    }

    return [
        '--configuration='.oblivionTestConfiguration($root, $paths),
        ...array_values(array_diff($arguments, $paths)),
    ];
}

/**
 * phpunit.xml with its suites replaced by one suite of $paths, every path
 * absolute, saved under storage/framework/testing (git-ignored).
 *
 * @param  list<string>  $paths  Repository-relative test files and folders.
 * @return string The generated configuration's path.
 */
function oblivionTestConfiguration(string $root, array $paths): string
{
    $document = new DOMDocument;
    $document->preserveWhiteSpace = false;
    $document->formatOutput = true;
    $document->load($root.'/phpunit.xml');

    $phpunit = $document->documentElement;
    $absolute = static fn (string $path): string => str_replace('\\', '/', $root).'/'.ltrim(str_replace('\\', '/', $path), '/');
    $phpunit->setAttribute('bootstrap', $absolute('vendor/autoload.php'));
    $phpunit->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:noNamespaceSchemaLocation', $absolute('vendor/phpunit/phpunit/phpunit.xsd'));

    foreach (iterator_to_array($document->getElementsByTagName('directory')) as $directory) {
        $directory->nodeValue = $absolute(trim($directory->nodeValue));
    }

    $suites = $document->getElementsByTagName('testsuites')->item(0);
    while ($suites->firstChild !== null) {
        $suites->removeChild($suites->firstChild);
    }

    $suite = $suites->appendChild($document->createElement('testsuite'));
    $suite->setAttribute('name', 'Selected');

    foreach ($paths as $path) {
        $suite->appendChild($document->createElement(is_dir($root.'/'.$path) ? 'directory' : 'file', $absolute($path)));
    }

    $directory = $root.'/storage/framework/testing';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $file = $directory.'/phpunit.selected-'.substr(sha1(implode("\n", $paths)), 0, 12).'.xml';
    $document->save($file);

    return $file;
}

/**
 * @param  list<string>  $arguments
 * @param  int|null  $fitTableCache  How many workers' databases MySQL's open-table cache holds at once.
 */
function oblivionFastTestCommand(array $arguments, int $cpus, ?string $configured, ?int $fitTableCache = null): array
{
    $processes = null;
    $batches = true;

    foreach ($arguments as $argument) {
        if (preg_match('/\A(?:--processes|-p)(?:=|\z)/', $argument) === 1) {
            $processes = false;
        }

        if (preg_match('/\A(?:--max-batch-size|-m)(?:=|\z)/', $argument) === 1) {
            $batches = false;
        }
    }

    if ($processes === null) {
        $processes = $configured !== null && ctype_digit($configured) && (int) $configured > 0
            ? (int) $configured
            : min(max(2, min(8, intdiv($cpus, 2))), max(1, $fitTableCache ?? PHP_INT_MAX));
    }

    return [
        PHP_BINARY,
        'artisan',
        'test',
        '--parallel',
        // Tests\TestCase gives each worker its own database.
        '--without-databases',
        ...($processes === false ? [] : ['--processes='.$processes]),
        // Each test boots a new application and a little memory stays behind:
        // a worker ran out of its 1 GB after ~2,000 tests on 31 Oct 2026. A
        // fresh worker every 20 files keeps it far below that, on the same slot.
        ...($batches ? ['--max-batch-size='.OBLIVION_FAST_TEST_BATCH_FILES] : []),
        ...$arguments,
    ];
}

/**
 * Every worker has its own copy of all ~1,200 tables. Beyond what MySQL's
 * table_open_cache holds, each statement reopens table files: on 31 Oct 2026
 * eight workers on the default cache (4,000) ran ~20 times slower than three.
 */
function oblivionWorkersFittingTableCache(string $root): ?int
{
    $settings = [];
    $phpunit = @simplexml_load_file($root.'/phpunit.xml');

    foreach ($phpunit === false ? [] : $phpunit->xpath('//php/env') as $env) {
        $settings[(string) $env['name']] = (string) $env['value'];
    }

    $setting = static fn (string $key, string $default): string => (string) (getenv($key) ?: ($settings[$key] ?? $default));
    $tables = substr_count((string) @file_get_contents($root.'/database/schema/mysql-schema.sql'), "\nCREATE TABLE ");

    try {
        $server = new PDO(
            sprintf('mysql:host=%s;port=%s', $setting('DB_HOST', '127.0.0.1'), $setting('DB_PORT', '3306')),
            $setting('DB_USERNAME', 'root'),
            $setting('DB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
        );
        $cache = (int) $server->query('SELECT @@table_open_cache')->fetchColumn();
    } catch (Throwable) {
        return null;
    }

    return $tables > 0 ? max(1, intdiv($cache, $tables)) : null;
}

function oblivionCpuCount(): int
{
    $count = getenv('NUMBER_OF_PROCESSORS');

    if ($count === false && is_readable('/proc/cpuinfo')) {
        $count = substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1;
    }

    return max(1, (int) $count);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__, 2);

    // A cached config would pin every worker to whatever database it names.
    (new Process([PHP_BINARY, 'artisan', 'config:clear', '--ansi'], $root))->run();

    $configured = getenv('TEST_PROCESSES');
    $configured = $configured === false ? null : $configured;
    $targets = oblivionFastTestTargets(array_slice($argv, 1), $root);
    $fit = oblivionWorkersFittingTableCache($root);
    $command = oblivionFastTestCommand($targets, oblivionCpuCount(), $configured, $fit);

    if ($command !== oblivionFastTestCommand($targets, oblivionCpuCount(), $configured)) {
        fwrite(STDOUT, sprintf(
            "MySQL's table_open_cache holds %d worker database(s) at once, so %d run. Raising it lets more run: see docs/TEST_SPEED_PLAN.md.\n",
            $fit,
            $fit,
        ));
    }

    // Workers log database repairs and rebuilds here instead of to STDERR,
    // which the parallel runner treats as a crash; show this run's lines after.
    $log = $root.'/storage/logs/test-database.log';
    clearstatcache();
    $logStart = is_file($log) ? (int) filesize($log) : 0;

    $process = new Process($command, $root);
    $process->setTimeout(null);

    if (Process::isTtySupported()) {
        $process->setTty(true);
    }

    $exitCode = $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });

    clearstatcache();
    $logged = is_file($log) && filesize($log) > $logStart
        ? (string) file_get_contents($log, offset: $logStart)
        : '';

    if (trim($logged) !== '') {
        fwrite(STDOUT, PHP_EOL.'Test databases (storage/logs/test-database.log):'.PHP_EOL.$logged);
    }

    exit($exitCode);
}
