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
        ->and(substr_count($testsWorkflow, 'shard_index:'))->toBe(10);

    $tests = Yaml::parse($testsWorkflow);
    $phpJob = $tests['jobs']['ci'] ?? [];
    $phpSteps = collect($phpJob['steps'] ?? []);
    $shards = $phpSteps->firstWhere('name', 'Crash-safe tests (${{ matrix.suite }} ${{ matrix.shard_index }}/${{ matrix.shard_count }})');
    $release = $phpSteps->firstWhere('name', 'Medication and connected-care release gates');
    $expectedMatrix = [
        ['suite' => 'foundation', 'shard_index' => 0, 'shard_count' => 1, 'batch_size' => 30],
        ['suite' => 'emar-release', 'shard_index' => 0, 'shard_count' => 1, 'batch_size' => 1],
    ];
    foreach (range(0, 7) as $index) {
        $expectedMatrix[] = ['suite' => 'feature', 'shard_index' => $index, 'shard_count' => 8, 'batch_size' => 12];
    }

    $phpunit = simplexml_load_file($root.'/phpunit.xml');
    expect($phpJob['timeout-minutes'] ?? null)->toBe(180)
        ->and($phpJob['strategy']['fail-fast'] ?? null)->toBeFalse()
        ->and($phpJob['strategy']['matrix']['include'] ?? null)->toBe($expectedMatrix)
        ->and($shards['if'] ?? null)->toBe("matrix.suite != 'emar-release'")
        ->and($release['if'] ?? null)->toBe("matrix.suite == 'emar-release'")
        ->and($release['run'] ?? '')->toStartWith('php vendor/bin/pest ')
        ->toContain('tests/Feature/Emar/PackLedgerIntegrationTest.php')
        ->toContain('tests/Feature/Emar/MedicationHistoricalRecoveryTest.php')
        ->toContain('tests/Feature/Emar/ConnectedMedicationPermissionSeedingTest.php')
        ->toContain('tests/Feature/Auth/EmailVerificationTest.php')
        ->toContain('--log-junit storage/logs/emar-release-gates.xml')
        ->and((string) $phpunit->xpath('/phpunit/php/ini[@name="memory_limit"]')[0]['value'])->toBe('1024M');

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
