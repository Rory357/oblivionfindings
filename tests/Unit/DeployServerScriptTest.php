<?php

it('updates the checkout from origin main before building', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)
        ->toContain('git fetch --prune origin')
        ->toContain('git pull --ff-only origin main');
});

it('refuses a dirty, unbound, or non-exact release before dependencies and assets are built', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)->toContain(
        'elif [ ! -e .git ]; then',
        'git status --porcelain=v1 --untracked-files=all',
        'git rev-parse --verify HEAD',
        'git rev-parse --verify refs/remotes/origin/main',
        'the release checkout contains tracked or untracked changes',
        'do not mix source with runtime or browser evidence artifacts',
        'the checked-out release does not exactly match origin/main',
        '--skip-git-update still requires the reviewed Git checkout',
        'The flag skips the network update only; it cannot bypass exact source-revision verification',
        'Re-run from the reviewed Git checkout',
    )->not->toContain('[ -d .git ]', '[ ! -d .git ]');

    $skipBranch = strpos($script, 'if [ "$SKIP_GIT_UPDATE" -eq 1 ]; then');
    $skipRequiresGit = strpos($script, 'if [ ! -e .git ]; then', $skipBranch);
    $skipFailure = strpos($script, '--skip-git-update still requires the reviewed Git checkout', $skipRequiresGit);
    $skipExactReleaseCheck = strpos($script, "\n    assert_origin_main_release\n", $skipFailure);
    $skipCleanCheck = strpos($script, "\n    assert_clean_release_checkout\n", $skipExactReleaseCheck);
    $normalBranch = strpos($script, 'elif [ ! -e .git ]; then', $skipCleanCheck);
    $normalFirstCleanCheck = strpos($script, "\n    assert_clean_release_checkout\n", $normalBranch);
    $fetch = strpos($script, 'run_app git fetch --prune origin');
    $pull = strpos($script, 'run_app git pull --ff-only origin main');
    $exactReleaseCheck = strpos($script, "\n    assert_origin_main_release\n", $pull);
    $secondCleanCheck = strpos($script, "\n    assert_clean_release_checkout\n", $exactReleaseCheck);
    $composer = strpos($script, 'run_app composer install');
    $npm = strpos($script, 'run_app npm ci');

    expect($skipBranch)
        ->not->toBeFalse()
        ->and($skipRequiresGit)->toBeGreaterThan($skipBranch)
        ->and($skipFailure)->toBeGreaterThan($skipRequiresGit)
        ->and($skipExactReleaseCheck)->toBeGreaterThan($skipFailure)
        ->and($skipCleanCheck)->toBeGreaterThan($skipExactReleaseCheck)
        ->and($normalBranch)->toBeGreaterThan($skipCleanCheck)
        ->and($normalFirstCleanCheck)
        ->not->toBeFalse()
        ->toBeLessThan($fetch)
        ->and($exactReleaseCheck)->toBeGreaterThan($pull)
        ->and($secondCleanCheck)->toBeGreaterThan($exactReleaseCheck)
        ->and($secondCleanCheck)->toBeLessThan($composer)
        ->and($composer)->toBeLessThan($npm);
});

it('builds and proves the Inertia SSR runtime even when Supervisor installation is explicitly skipped', function () {
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
            'run_app php artisan inertia:check-ssr',
        )->not->toContain('sudo -E bash scripts/inertia/install-supervisor.sh');

    $build = strpos($script, 'run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr');
    $optimise = strpos($script, 'run_app php artisan optimize:clear', $build);
    $skipBranch = strpos($script, 'if [ "$SKIP_INERTIA_SSR" -eq 1 ]; then', $optimise);
    $installer = strpos($script, 'bash scripts/inertia/install-supervisor.sh', $optimise);
    $supervisorBranchEnd = strpos($script, "\nfi\n\n", $installer);
    $health = strpos($script, 'run_app php artisan inertia:check-ssr', $supervisorBranchEnd);
    $monitoringInstaller = strpos($script, 'bash scripts/monitoring/install-supervisor.sh', $health);
    $queueRestart = strpos($script, 'run_app php artisan queue:restart', $monitoringInstaller);
    $success = strpos($script, 'Server provisioning complete', $queueRestart);

    expect($build)
        ->not->toBeFalse()
        ->and($optimise)->toBeGreaterThan($build)
        ->and($skipBranch)->toBeGreaterThan($optimise)
        ->and($installer)->toBeGreaterThan($skipBranch)
        ->and($supervisorBranchEnd)->toBeGreaterThan($installer)
        ->and($health)->toBeGreaterThan($supervisorBranchEnd)
        ->and(substr_count($script, 'run_app php artisan inertia:check-ssr'))->toBe(1)
        ->and($monitoringInstaller)->toBeGreaterThan($health)
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

    $installer = strpos($script, 'scripts/monitoring/install-supervisor.sh');
    expect($installer)
        ->toBeLessThan(strpos($script, 'run_app php artisan queue:restart', $installer));
});

it('fails closed through stable Queclink readiness even when installation is externally managed', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)->toContain(
        '--skip-queclink',
        'queclink:install requires root or sudo',
        'Re-run with privilege or explicitly pass --skip-queclink',
        'sudo -E php artisan queclink:install',
        'php artisan queclink:install',
        'skipping application-owned Queclink install (--skip-queclink; externally managed runtime)',
        'verifying consecutive Queclink listener readiness',
        'systemctl is-active --quiet oblivion-queclink.service',
        '"$queclink_consecutive_active" -ge 3',
        'Queclink listener did not remain active for three consecutive readiness samples',
        'run_app php artisan queclink:install --check',
        'final Queclink listener readiness check',
    )->not->toContain(
        'Skipping — re-run with sudo',
        'QUECLINK_RELEASE_REQUIRED',
        'Queclink listener is not required for this approved release',
    );

    $defaultInstall = strpos($script, 'if [ "$SKIP_QUECLINK" -eq 0 ]; then');
    $privilegeFailure = strpos($script, 'queclink:install requires root or sudo', $defaultInstall);
    $fatalExit = strpos($script, "\n        exit 1\n", $privilegeFailure);
    $sudoInstall = strpos($script, 'sudo -E php artisan queclink:install', $fatalExit);
    $explicitSkip = strpos($script, 'skipping application-owned Queclink install', $sudoInstall);
    $readinessStart = strpos($script, 'verifying consecutive Queclink listener readiness', $explicitSkip);
    $activeStateProbe = strpos($script, 'systemctl is-active --quiet oblivion-queclink.service', $readinessStart);
    $consecutiveGate = strpos($script, '"$queclink_consecutive_active" -ge 3', $activeStateProbe);
    $runtimeFailure = strpos($script, 'Queclink listener did not remain active for three consecutive readiness samples', $consecutiveGate);
    $readinessCheck = strpos($script, 'run_app php artisan queclink:install --check', $runtimeFailure);
    $queueRestart = strpos($script, 'run_app php artisan queue:restart', $readinessCheck);
    $finalReadinessCheck = strpos($script, 'run_app php artisan queclink:install --check', $queueRestart);
    $applicationUp = strpos($script, 'run_app php artisan up', $finalReadinessCheck);
    $success = strpos($script, 'Server provisioning complete', $applicationUp);

    expect($defaultInstall)
        ->not->toBeFalse()
        ->and($privilegeFailure)->toBeGreaterThan($defaultInstall)
        ->and($fatalExit)->toBeGreaterThan($privilegeFailure)
        ->and($sudoInstall)->toBeGreaterThan($fatalExit)
        ->and($explicitSkip)->toBeGreaterThan($sudoInstall)
        ->and($readinessStart)->toBeGreaterThan($explicitSkip)
        ->and($activeStateProbe)->toBeGreaterThan($readinessStart)
        ->and($consecutiveGate)->toBeGreaterThan($activeStateProbe)
        ->and($runtimeFailure)->toBeGreaterThan($consecutiveGate)
        ->and($readinessCheck)->toBeGreaterThan($runtimeFailure)
        ->and($queueRestart)->toBeGreaterThan($readinessCheck)
        ->and($finalReadinessCheck)->toBeGreaterThan($queueRestart)
        ->and($applicationUp)->toBeGreaterThan($finalReadinessCheck)
        ->and($success)->toBeGreaterThan($applicationUp)
        ->and(substr_count($script, 'run_app php artisan queclink:install --check'))->toBe(2);
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
