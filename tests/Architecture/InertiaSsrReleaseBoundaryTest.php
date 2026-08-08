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
        )
        ->and($installer)->toContain(
            'inertia:start-ssr --runtime=node',
            'status "$PROGRAM"',
            'inertia:check-ssr',
        );

    $build = strpos($deploy, 'run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr');
    $installerGate = strpos($deploy, 'bash scripts/inertia/install-supervisor.sh', $build);
    $monitoringGate = strpos($deploy, 'bash scripts/monitoring/install-supervisor.sh', $installerGate);
    $queclinkGate = strpos($deploy, 'php artisan queclink:install', $monitoringGate);
    $success = strpos($deploy, 'Server provisioning complete', $queclinkGate);

    expect($build)
        ->not->toBeFalse()
        ->and($installerGate)->toBeGreaterThan($build)
        ->and($monitoringGate)->toBeGreaterThan($installerGate)
        ->and($queclinkGate)->toBeGreaterThan($monitoringGate)
        ->and($success)->toBeGreaterThan($queclinkGate);
});
