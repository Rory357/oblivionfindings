<?php

it('keeps PHP and browser CI in bounded crash safe shards', function () {
    $root = dirname(__DIR__, 2);
    $testsWorkflow = (string) file_get_contents($root.'/.github/workflows/tests.yml');
    $visualWorkflow = (string) file_get_contents($root.'/.github/workflows/visual.yml');
    $runner = (string) file_get_contents($root.'/scripts/ci/run-pest-shard.php');

    expect($testsWorkflow)
        ->toContain('cancel-in-progress: true')
        ->toContain('coverage: none')
        ->toContain('fail-fast: false')
        ->toContain('suite: foundation')
        ->toContain('shard_count: 8')
        ->toContain('batch_size: 12')
        ->toContain('php scripts/ci/run-pest-shard.php')
        ->not->toContain('run: ./vendor/bin/pest', 'coverage: xdebug');

    expect(substr_count($testsWorkflow, 'suite: feature'))->toBe(8)
        ->and(substr_count($testsWorkflow, 'shard_index:'))->toBe(9);

    expect($runner)
        ->toContain("'tests/Unit', 'tests/Integration', 'tests/Architecture'")
        ->toContain("'feature' => ['tests/Feature']")
        ->toContain('$position % $count === $index')
        ->toContain('array_chunk($files, $batchSize)')
        ->toContain("'artisan',\n            'test'")
        ->toContain('$process->setTimeout(null)')
        ->not->toContain('--parallel');

    foreach ([
        'chromium-desktop',
        'chromium-mobile',
        'it-security-desktop-1440',
        'it-security-desktop-1280',
    ] as $project) {
        expect($visualWorkflow)->toContain("- {$project}");
    }

    expect($visualWorkflow)
        ->toContain('cancel-in-progress: true')
        ->toContain('fail-fast: false')
        ->toContain('timeout-minutes: 70')
        ->toContain('timeout-minutes: 50')
        ->toContain('npx playwright test --project=${{ matrix.project }}')
        ->toContain('playwright-artifacts-${{ matrix.project }}')
        ->not->toContain('run: npm run visual:test');
});
