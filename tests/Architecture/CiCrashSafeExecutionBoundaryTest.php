<?php

use Symfony\Component\Yaml\Yaml;

it('keeps PHP and browser CI in bounded crash safe shards', function () {
    $root = dirname(__DIR__, 2);
    $testsWorkflow = (string) file_get_contents($root.'/.github/workflows/tests.yml');
    $machineAction = (string) file_get_contents($root.'/.github/actions/php-test-machine/action.yml');
    $visualWorkflow = (string) file_get_contents($root.'/.github/workflows/visual.yml');
    $runner = (string) file_get_contents($root.'/scripts/ci/run-pest-shard.php');

    expect($testsWorkflow)
        ->toContain('cancel-in-progress: ${{ github.ref != \'refs/heads/main\' }}')
        ->toContain('fail-fast: false')
        ->toContain('php scripts/ci/run-pest-shard.php')
        ->toContain('--suite=foundation')
        ->toContain('--suite=feature')
        ->toContain('--batch-size=12')
        ->not->toContain('run: ./vendor/bin/pest', 'coverage: xdebug', '--parallel', 'npm run build');

    expect($machineAction)
        ->toContain('coverage: none')
        ->toContain('--tmpfs /var/lib/mysql')
        ->not->toContain('coverage: xdebug');

    $tests = Yaml::parse($testsWorkflow);
    $jobs = $tests['jobs'] ?? [];
    $stepNamed = fn (string $job, string $name): array => collect($jobs[$job]['steps'] ?? [])->firstWhere('name', $name) ?? [];

    $foundation = $stepNamed('foundation', 'Crash-safe tests (foundation)');
    $feature = $stepNamed('feature', 'Crash-safe tests (feature ${{ matrix.shard }}/${{ strategy.job-total }})');
    $release = $stepNamed('emar-release', 'Medication and connected-care release gates');
    $verdict = $stepNamed('verdict', 'Verdict');

    // Every PHP test job is bounded, keeps going past a failing shard, and runs on the
    // shared machine setup (PHP without coverage, MySQL with its data in RAM).
    foreach (['foundation', 'emar-release', 'feature'] as $job) {
        expect($jobs[$job]['timeout-minutes'] ?? null)->toBe(90)
            ->and(collect($jobs[$job]['steps'] ?? [])->pluck('uses')->filter()->values()->all())
            ->toContain('./.github/actions/php-test-machine');
    }

    // Fork pull requests never reach a self-hosted runner: the repository is public.
    foreach (['foundation', 'emar-release', 'feature'] as $job) {
        expect($jobs[$job]['runs-on'] ?? '')->toStartWith('${{ github.event.pull_request.head.repo.fork && \'ubuntu-latest\' || ');
    }

    $phpunit = simplexml_load_file($root.'/phpunit.xml');
    expect($jobs['feature']['strategy']['fail-fast'] ?? null)->toBeFalse()
        ->and($jobs['feature']['strategy']['matrix']['shard'] ?? null)
        ->toBe('${{ fromJSON(vars.TESTS_FEATURE_SHARDS || \'[1,2,3,4,5,6,7,8,9,10,11,12]\') }}')
        ->and($foundation['run'] ?? '')->toContain('--shard-index=0')->toContain('--shard-count=1')->toContain('--batch-size=30')
        ->and($feature['run'] ?? '')->toContain('--shard-index=${{ strategy.job-index }}')
        ->toContain('--shard-count=${{ strategy.job-total }}')
        ->and($release['run'] ?? '')->toStartWith('php vendor/bin/pest ')
        ->toContain('tests/Feature/Emar/PackLedgerIntegrationTest.php')
        ->toContain('tests/Feature/Emar/MedicationHistoricalRecoveryTest.php')
        ->toContain('tests/Feature/Emar/ConnectedMedicationPermissionSeedingTest.php')
        ->toContain('tests/Feature/Auth/EmailVerificationTest.php')
        ->toContain('--log-junit storage/logs/emar-release-gates.xml')
        ->and($jobs['verdict']['needs'] ?? null)->toBe(['foundation', 'emar-release', 'feature'])
        ->and($jobs['verdict']['if'] ?? null)->toBe('always()')
        ->and($verdict['run'] ?? '')->toStartWith('php scripts/ci/test-verdict.php ')
        ->and((string) $phpunit->xpath('/phpunit/php/ini[@name="memory_limit"]')[0]['value'])->toBe('1024M');

    // Each batch is a fresh Pest process with no time limit of its own; workers run
    // separate processes (each with its own test database), never Pest's --parallel.
    expect($runner)
        ->toContain("'tests/Unit', 'tests/Integration', 'tests/Architecture'")
        ->toContain("'feature' => ['tests/Feature']")
        ->toContain('$position % $count === $index')
        ->toContain('array_chunk($files, $batchSize)')
        ->toContain("'artisan',\n                'test'")
        ->toContain('$process->setTimeout(null)')
        ->toContain("oblivionCiKnownFailures(\$root.'/tests/ci/known-failures.php')")
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
