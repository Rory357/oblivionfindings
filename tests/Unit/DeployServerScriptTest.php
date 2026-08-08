<?php

it('updates the checkout from origin main before building', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)
        ->toContain('git fetch --prune origin')
        ->toContain('git pull --ff-only origin main');
});

it('refuses a dirty or non-exact release before dependencies and assets are built', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)->toContain(
        'if [ -e .git ]; then',
        'elif [ ! -e .git ]; then',
        'git status --porcelain=v1 --untracked-files=all',
        'git rev-parse --verify HEAD',
        'git rev-parse --verify refs/remotes/origin/main',
        'the release checkout contains tracked or untracked changes',
        'do not mix source with runtime or browser evidence artifacts',
        'the checked-out release does not exactly match origin/main',
    )->not->toContain('[ -d .git ]', '[ ! -d .git ]');

    $firstCleanCheck = strpos($script, "\n    assert_clean_release_checkout\n");
    $fetch = strpos($script, 'run_app git fetch --prune origin');
    $pull = strpos($script, 'run_app git pull --ff-only origin main');
    $exactReleaseCheck = strpos($script, "\n    assert_origin_main_release\n", $pull);
    $secondCleanCheck = strpos($script, "\n    assert_clean_release_checkout\n", $exactReleaseCheck);
    $composer = strpos($script, 'run_app composer install');
    $npm = strpos($script, 'run_app npm ci');

    expect($firstCleanCheck)
        ->not->toBeFalse()
        ->toBeLessThan($fetch)
        ->and($exactReleaseCheck)->toBeGreaterThan($pull)
        ->and($secondCleanCheck)->toBeGreaterThan($exactReleaseCheck)
        ->and($secondCleanCheck)->toBeLessThan($composer)
        ->and($composer)->toBeLessThan($npm);
});

it('builds and fails closed into the supervised Inertia SSR runtime unless explicitly skipped', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');
    $package = json_decode((string) file_get_contents(__DIR__.'/../../package.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($package['scripts']['build:ssr'])->toBe('vite build && vite build --ssr')
        ->and($script)->toContain(
            'run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr',
            '--skip-inertia-ssr',
            'scripts/inertia/install-supervisor.sh',
            'INERTIA_SSR_RUNTIME_USER',
            'INERTIA_SSR_LOG_DIRECTORY',
            'INERTIA_SSR_SUPERVISOR_INCLUDE_DIR',
            'INERTIA_SSR_SUPERVISORD_CONFIG',
            'sudo bash scripts/inertia/install-supervisor.sh',
            'Inertia SSR Supervisor install requires root or sudo',
            'Re-run with privilege or explicitly pass --skip-inertia-ssr',
            'skipping Inertia SSR Supervisor install (--skip-inertia-ssr)',
        )->not->toContain('sudo -E bash scripts/inertia/install-supervisor.sh');

    $build = strpos($script, 'run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr');
    $optimise = strpos($script, 'run_app php artisan optimize:clear', $build);
    $installer = strpos($script, 'bash scripts/inertia/install-supervisor.sh', $optimise);
    $monitoringInstaller = strpos($script, 'bash scripts/monitoring/install-supervisor.sh', $installer);
    $queueRestart = strpos($script, 'run_app php artisan queue:restart', $monitoringInstaller);
    $success = strpos($script, 'Server provisioning complete', $queueRestart);

    expect($build)
        ->not->toBeFalse()
        ->and($optimise)->toBeGreaterThan($build)
        ->and($installer)->toBeGreaterThan($optimise)
        ->and($monitoringInstaller)->toBeGreaterThan($installer)
        ->and($queueRestart)->toBeGreaterThan($monitoringInstaller)
        ->and($success)->toBeGreaterThan($queueRestart);
});

it('fails closed into the monitoring Supervisor installer unless explicitly skipped', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)
        ->toContain(
            '--skip-monitoring-supervisor',
            'scripts/monitoring/install-supervisor.sh',
            '--application-path=$(pwd -P)',
            'MONITORING_SUPERVISOR_INCLUDE_DIR',
            'MONITORING_SUPERVISORD_CONFIG',
            'sudo bash scripts/monitoring/install-supervisor.sh',
            'monitoring Supervisor install requires root or sudo',
        )
        ->not->toContain('sudo -E bash scripts/monitoring/install-supervisor.sh');

    expect(strpos($script, 'scripts/monitoring/install-supervisor.sh'))
        ->toBeLessThan(strpos($script, 'run_app php artisan queue:restart'));
});

it('fails closed into the native Queclink listener install unless explicitly skipped', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)->toContain(
        '--skip-queclink',
        'queclink:install requires root or sudo',
        'Re-run with privilege or explicitly pass --skip-queclink',
        'sudo -E php artisan queclink:install',
        'php artisan queclink:install',
        'systemctl is-active --quiet oblivion-queclink.service',
        'Queclink listener did not reach active systemd state',
        'skipping queclink:install (--skip-queclink)',
    )->not->toContain('Skipping — re-run with sudo');

    $defaultInstall = strpos($script, 'if [ "$SKIP_QUECLINK" -eq 0 ]; then');
    $privilegeFailure = strpos($script, 'queclink:install requires root or sudo', $defaultInstall);
    $fatalExit = strpos($script, "\n        exit 1\n", $privilegeFailure);
    $sudoInstall = strpos($script, 'sudo -E php artisan queclink:install', $fatalExit);
    $activeStateProbe = strpos($script, 'systemctl is-active --quiet oblivion-queclink.service', $sudoInstall);
    $runtimeFailure = strpos($script, 'Queclink listener did not reach active systemd state', $activeStateProbe);
    $runtimeFatalExit = strpos($script, "\n        exit 1\n", $runtimeFailure);
    $explicitSkip = strpos($script, 'skipping queclink:install (--skip-queclink)', $runtimeFatalExit);
    $success = strpos($script, 'Server provisioning complete', $explicitSkip);

    expect($defaultInstall)
        ->not->toBeFalse()
        ->and($privilegeFailure)->toBeGreaterThan($defaultInstall)
        ->and($fatalExit)->toBeGreaterThan($privilegeFailure)
        ->and($sudoInstall)->toBeGreaterThan($fatalExit)
        ->and($activeStateProbe)->toBeGreaterThan($sudoInstall)
        ->and($runtimeFailure)->toBeGreaterThan($activeStateProbe)
        ->and($runtimeFatalExit)->toBeGreaterThan($runtimeFailure)
        ->and($explicitSkip)->toBeGreaterThan($runtimeFatalExit)
        ->and($success)->toBeGreaterThan($explicitSkip);
});

it('creates deploy artifacts with web-readable permissions even under a restrictive login umask', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    $umaskPosition = strpos($script, 'umask 002');
    $composerPosition = strpos($script, 'run_app composer install');
    $npmPosition = strpos($script, 'run_app npm ci');

    expect($umaskPosition)
        ->not->toBeFalse()
        ->toBeLessThan($composerPosition)
        ->toBeLessThan($npmPosition);
});
