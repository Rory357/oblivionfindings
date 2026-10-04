<?php

use Symfony\Component\Yaml\Yaml;

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

    $visual = Yaml::parse($visualWorkflow);
    $job = $visual['jobs']['visual'] ?? [];
    $steps = collect($job['steps'] ?? []);
    $browser = $steps->firstWhere('name', 'Visual Regression (${{ matrix.project }})');
    $artifacts = $steps->firstWhere('name', 'Upload Playwright Artifacts');

    expect($visual['concurrency']['cancel-in-progress'] ?? null)->toBeTrue()
        ->and($job['strategy']['fail-fast'] ?? null)->toBeFalse()
        ->and($job['timeout-minutes'] ?? null)->toBe(70)
        ->and($browser['timeout-minutes'] ?? null)->toBe(50)
        ->and($job['strategy']['matrix']['project'] ?? null)->toBe([
            'chromium-desktop',
            'chromium-desktop-visual',
            'it-security-desktop-1440',
            'it-security-desktop-1280',
        ])
        ->and($job['strategy']['matrix']['include'] ?? null)->toBe([
            ['project' => 'governance-desktop-1366', 'config' => 'playwright.governance.config.ts'],
            ['project' => 'governance-desktop-1920', 'config' => 'playwright.governance.config.ts'],
        ])
        ->and($artifacts['with']['name'] ?? null)->toBe('playwright-artifacts-${{ matrix.project }}')
        ->and($artifacts['with']['path'] ?? '')->toContain('playwright-report-governance');

    $commands = [];
    expect(preg_match_all(
        '/^\s*npx playwright test\s+([^\r\n]+)$/m',
        (string) ($browser['run'] ?? ''),
        $commands,
    ))->toBe(1)
        ->and($commands[1])->toBe([
            '--config=${{ matrix.config || \'playwright.config.ts\' }} --project=${{ matrix.project }}',
        ]);

    expect($visualWorkflow)
        ->not->toContain('- chromium-mobile')
        ->not->toContain('run: npm run visual:test');
});
