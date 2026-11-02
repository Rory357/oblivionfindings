#!/usr/bin/env php
<?php

/*
 * composer test:changed [-- --base=<ref>] [--dry-run] [--serial] [<pest arguments>]
 *
 * Runs the tests your changes touch, instead of the whole suite:
 *  - test files you changed or added;
 *  - tests that name a class you changed (under app/ or database/);
 *  - every test of an app/Domain/<Module> you touched, from tests/Feature/<Module>
 *    and tests/Unit/<Module>.
 * "Changes" are everything since the branch left origin/main (else main),
 * committed or not, plus new files. More than 15 test files run in parallel
 * through scripts/test/fast.php; --serial keeps them in one process.
 */

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/fast.php';

const OBLIVION_CHANGED_TEST_ROOTS = ['tests/Feature', 'tests/Unit', 'tests/Integration', 'tests/Architecture'];

/** @param list<string> $command */
function oblivionChangedGit(string $root, array $command): ?string
{
    $process = new Process(['git', ...$command], $root);
    $process->run();

    return $process->isSuccessful() ? $process->getOutput() : null;
}

function oblivionChangedBase(string $root, ?string $base): string
{
    foreach ($base !== null ? [$base] : ['origin/main', 'main'] as $candidate) {
        $mergeBase = oblivionChangedGit($root, ['merge-base', 'HEAD', $candidate]);

        if ($mergeBase !== null && trim($mergeBase) !== '') {
            return trim($mergeBase);
        }
    }

    throw new RuntimeException('Could not find where this branch left '.($base ?? 'origin/main or main').'; pass --base=<ref>.');
}

/** @return list<string> Changed and new files, repository-relative, deleted ones left out. */
function oblivionChangedFiles(string $root, string $base): array
{
    $files = [
        ...preg_split('/\R/', (string) oblivionChangedGit($root, ['diff', '--name-only', '--diff-filter=ACMRT', $base])),
        ...preg_split('/\R/', (string) oblivionChangedGit($root, ['ls-files', '--others', '--exclude-standard'])),
    ];
    $files = array_values(array_unique(array_filter(array_map('trim', $files), static fn (string $file): bool => $file !== '')));
    sort($files, SORT_STRING);

    return $files;
}

/**
 * @param  list<string>  $changed
 * @param  Closure(string): list<string>  $testsNaming  Test files that mention a class name.
 * @return array{tests: array<string, list<string>>, notes: list<string>} Test path => why it was picked.
 */
function oblivionChangedSelection(array $changed, Closure $testsNaming, Closure $isDirectory): array
{
    $tests = [];
    $notes = [];
    $pick = static function (string $path, string $reason) use (&$tests): void {
        $tests[$path] ??= [];

        if (! in_array($reason, $tests[$path], true)) {
            $tests[$path][] = $reason;
        }
    };

    foreach ($changed as $file) {
        if (preg_match('#\Atests/(?:Feature|Unit|Integration|Architecture)/.+Test\.php\z#', $file) === 1) {
            $pick($file, 'changed');

            continue;
        }

        if (preg_match('#\A(?:tests/(?:TestCase|Pest)\.php|tests/Support/|phpunit\.xml\z)#', $file) === 1) {
            $notes['setup'] = 'Test setup changed ('.$file.'): composer test:fast runs everything.';

            continue;
        }

        if (str_starts_with($file, 'database/migrations/')) {
            $notes['migrations'] = 'Migrations changed: the kept test databases apply them on the next run.';

            continue;
        }

        if (preg_match('#\Aapp/Domain/([^/]+)/#', $file, $module) === 1) {
            foreach (['tests/Feature/', 'tests/Unit/'] as $directory) {
                if ($isDirectory($directory.$module[1])) {
                    $pick($directory.$module[1], 'module '.$module[1]);
                }
            }
        }

        if (preg_match('#\A(?:app|database)/.+/([A-Za-z_][A-Za-z0-9_]*)\.php\z#', $file, $class) === 1) {
            foreach ($testsNaming($class[1]) as $test) {
                $pick($test, 'uses '.$class[1]);
            }
        }
    }

    // A file inside a picked module folder runs with that folder.
    foreach (array_keys($tests) as $path) {
        foreach (array_keys($tests) as $directory) {
            if ($path !== $directory && ! str_ends_with($directory, '.php') && str_starts_with($path, $directory.'/')) {
                unset($tests[$path]);

                break;
            }
        }
    }

    ksort($tests, SORT_STRING);

    return ['tests' => $tests, 'notes' => array_values($notes)];
}

/** @param list<string> $arguments */
function oblivionChangedMain(array $arguments): int
{
    $root = dirname(__DIR__, 2);
    $base = null;
    $dryRun = false;
    $serial = false;
    $pest = [];

    foreach ($arguments as $argument) {
        match (true) {
            str_starts_with($argument, '--base=') => $base = substr($argument, 7),
            $argument === '--dry-run' => $dryRun = true,
            $argument === '--serial' => $serial = true,
            default => $pest[] = $argument,
        };
    }

    try {
        $baseCommit = oblivionChangedBase($root, $base);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);

        return 2;
    }

    $changed = oblivionChangedFiles($root, $baseCommit);
    $testsNaming = static function (string $class) use ($root): array {
        $output = oblivionChangedGit($root, ['grep', '--untracked', '-l', '-w', '-F', '-e', $class, '--', ...OBLIVION_CHANGED_TEST_ROOTS]);

        return array_values(array_filter(
            preg_split('/\R/', (string) $output),
            static fn (string $file): bool => str_ends_with($file, 'Test.php'),
        ));
    };
    $selection = oblivionChangedSelection(
        $changed,
        $testsNaming,
        static fn (string $directory): bool => is_dir($root.'/'.$directory),
    );

    fwrite(STDOUT, sprintf("%d changed file(s) since %s.\n", count($changed), substr($baseCommit, 0, 9)));

    foreach ($selection['notes'] as $note) {
        fwrite(STDOUT, '  Note: '.$note.PHP_EOL);
    }

    if ($selection['tests'] === []) {
        fwrite(STDOUT, "No tests match these changes.\n");

        return 0;
    }

    foreach ($selection['tests'] as $path => $reasons) {
        fwrite(STDOUT, sprintf("  %s  (%s)\n", $path, implode(', ', array_slice($reasons, 0, 3)).(count($reasons) > 3 ? ', ...' : '')));
    }

    if ($dryRun) {
        return 0;
    }

    $paths = array_keys($selection['tests']);
    $files = count(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '.php')));
    $parallel = ! $serial && ($files > 15 || count($paths) > $files);

    // The parallel runner takes one path, and Windows caps a command line
    // near 32,000 characters: several paths run through one configuration.
    $targets = count($paths) > 1 && ($parallel || strlen(implode(' ', $paths)) > 20000)
        ? ['--configuration='.oblivionTestConfiguration($root, $paths)]
        : $paths;

    $command = $parallel
        ? [PHP_BINARY, 'scripts/test/fast.php', ...$targets, ...$pest]
        : [PHP_BINARY, 'artisan', 'test', ...$targets, ...$pest];

    $process = new Process($command, $root);
    $process->setTimeout(null);

    return $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(oblivionChangedMain(array_slice($argv, 1)));
}
