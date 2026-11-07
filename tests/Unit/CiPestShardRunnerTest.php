<?php

require_once dirname(__DIR__, 2).'/scripts/ci/run-pest-shard.php';

it('partitions the complete Feature inventory exactly once across crash safe shards', function () {
    $root = dirname(__DIR__, 2);
    $inventory = oblivionCiPestFiles($root, 'feature');
    $combined = [];

    for ($index = 0; $index < 8; $index++) {
        $shard = oblivionCiPestShard($inventory, $index, 8);
        expect($shard)->not->toBeEmpty()
            ->and(oblivionCiPestBatches($shard, 20))->not->toBeEmpty();
        array_push($combined, ...$shard);
    }

    sort($combined, SORT_STRING);

    expect($inventory)->not->toBeEmpty()
        ->and($combined)->toBe($inventory)
        ->and(count(array_unique($combined)))->toBe(count($inventory));
});

it('keeps Unit Integration and Architecture in the fresh-process foundation plan', function () {
    $root = dirname(__DIR__, 2);
    $files = oblivionCiPestFiles($root, 'foundation');

    expect($files)->not->toBeEmpty()
        ->and($files)->toContain('tests/Unit/CiPestShardRunnerTest.php')
        ->and(array_filter($files, fn (string $file): bool => str_starts_with($file, 'tests/Unit/')))->not->toBeEmpty()
        ->and(array_filter($files, fn (string $file): bool => str_starts_with($file, 'tests/Architecture/')))->not->toBeEmpty();

    if (is_dir($root.'/tests/Integration')) {
        expect(array_filter($files, fn (string $file): bool => str_starts_with($file, 'tests/Integration/')))->not->toBeEmpty();
    }
});

it('rejects invalid shard coordinates and oversized batches', function () {
    expect(fn () => oblivionCiPestShard(['a'], 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => oblivionCiPestBatches(['a'], 51))->toThrow(InvalidArgumentException::class);
});

it('reads JUnit results as repo-relative test ids', function () {
    $root = dirname(__DIR__, 2);
    $junit = tempnam(sys_get_temp_dir(), 'junit');
    file_put_contents($junit, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Tests\Feature\ExampleTest" file="%1$s/tests/Feature/ExampleTest.php">
    <testcase name="it passes" file="%1$s/tests/Feature/ExampleTest.php" class="Tests\Feature\ExampleTest" time="0.5"/>
    <testcase name="it fails" file="%1$s/tests/Feature/ExampleTest.php" class="Tests\Feature\ExampleTest" time="0.2">
      <failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that false is true.</failure>
    </testcase>
    <testcase name="test_errors" file="%1$s/tests/Feature/ExampleTest.php::12" class="Tests\Feature\ExampleTest">
      <error type="RuntimeException">Boom</error>
    </testcase>
    <testcase name="it is skipped" file="%1$s/tests/Feature/ExampleTest.php" class="Tests\Feature\ExampleTest">
      <skipped/>
    </testcase>
  </testsuite>
</testsuites>
XML, str_replace('\\', '/', $root)));

    try {
        $results = oblivionCiReadJunit($junit, $root);
    } finally {
        unlink($junit);
    }

    expect(array_map(fn (array $result): string => $result['status'], $results))->toBe([
        'tests/Feature/ExampleTest.php::it passes' => 'passed',
        'tests/Feature/ExampleTest.php::it fails' => 'failed',
        'tests/Feature/ExampleTest.php::test_errors' => 'failed',
        'tests/Feature/ExampleTest.php::it is skipped' => 'skipped',
    ])->and($results['tests/Feature/ExampleTest.php::it fails']['message'])->toBe('Failed asserting that false is true.');
});

it('turns only failures missing from the known list into new failures', function () {
    $results = [
        'tests/Feature/A.php::known' => ['status' => 'failed', 'file' => 'tests/Feature/A.php', 'message' => '', 'time' => 0.0],
        'tests/Feature/A.php::fresh' => ['status' => 'failed', 'file' => 'tests/Feature/A.php', 'message' => '', 'time' => 0.0],
        'tests/Feature/A.php::fixed' => ['status' => 'passed', 'file' => 'tests/Feature/A.php', 'message' => '', 'time' => 0.0],
    ];
    $entry = fn (string $test): array => ['test' => $test, 'owner' => 'MASTER', 'reason' => 'r', 'since' => '2026-10-31'];
    $known = [
        'tests/Feature/A.php::known' => $entry('tests/Feature/A.php::known'),
        'tests/Feature/A.php::fixed' => $entry('tests/Feature/A.php::fixed'),
        'tests/Feature/B.php::gone' => $entry('tests/Feature/B.php::gone'),
    ];

    expect(oblivionCiCompare($results, $known))->toBe([
        'new' => ['tests/Feature/A.php::fresh'],
        'known' => ['tests/Feature/A.php::known'],
        'fixed' => ['tests/Feature/A.php::fixed'],
        'notRun' => ['tests/Feature/B.php::gone'],
    ]);
});

it('keeps every known failure reviewed with an owner, a reason and a date', function () {
    $root = dirname(__DIR__, 2);
    $known = oblivionCiKnownFailures($root.'/tests/ci/known-failures.php');

    foreach ($known as $id => $entry) {
        expect($id)->toMatch('#^tests/(Feature|Unit|Integration|Architecture)/.+\.php::.+$#')
            ->and($entry['since'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and(is_file($root.'/'.explode('::', $id, 2)[0]))->toBeTrue();
    }
});
