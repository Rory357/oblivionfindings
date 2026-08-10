<?php

it('cannot report a normal production release before the configured Inertia SSR runtime is built and proven healthy', function (): void {
    $root = dirname(__DIR__, 2);
    $inertiaConfig = (string) file_get_contents($root.'/config/inertia.php');
    $package = json_decode(
        (string) file_get_contents($root.'/package.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $deploy = (string) file_get_contents($root.'/scripts/deploy-server.sh');
    $installer = (string) file_get_contents($root.'/scripts/inertia/install-supervisor.sh');

    expect($inertiaConfig)->toContain(
        "'enabled' => true",
        "'url' => 'http://127.0.0.1:13714'",
    )->and($package['scripts']['build:ssr'] ?? null)->toBe('vite build && vite build --ssr')
        ->and($deploy)->toContain(
            'npm run build:ssr',
            'scripts/inertia/install-supervisor.sh',
            '--skip-inertia-ssr',
            'run_app php artisan inertia:check-ssr',
        )
        ->and($installer)->toContain(
            'inertia:start-ssr --runtime=node',
            'status "$PROGRAM"',
            'inertia:check-ssr',
        );

    $build = strpos($deploy, 'run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr');
    $installerGate = strpos($deploy, 'bash scripts/inertia/install-supervisor.sh', $build);
    $supervisorBranchEnd = strpos($deploy, "\nfi\n\n", $installerGate);
    $healthGate = strpos($deploy, 'run_app php artisan inertia:check-ssr', $supervisorBranchEnd);
    $monitoringGate = strpos($deploy, 'bash scripts/monitoring/install-supervisor.sh', $healthGate);
    $queclinkGate = strpos($deploy, 'php artisan queclink:install', $monitoringGate);
    $success = strpos($deploy, 'Server provisioning complete', $queclinkGate);

    expect($build)
        ->not->toBeFalse()
        ->and($installerGate)->toBeGreaterThan($build)
        ->and($supervisorBranchEnd)->toBeGreaterThan($installerGate)
        ->and($healthGate)->toBeGreaterThan($supervisorBranchEnd)
        ->and(substr_count($deploy, 'run_app php artisan inertia:check-ssr'))->toBe(1)
        ->and($monitoringGate)->toBeGreaterThan($healthGate)
        ->and($queclinkGate)->toBeGreaterThan($monitoringGate)
        ->and($success)->toBeGreaterThan($queclinkGate);
});
