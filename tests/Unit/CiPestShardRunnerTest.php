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
