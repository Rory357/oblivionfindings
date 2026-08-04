<?php

use App\Domain\Monitoring\Services\MonitoringRuntimeHeartbeatService;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('supervises each monitoring runtime and governed command workload separately', function () {
    $components = app(MonitoringRuntimeHeartbeatService::class)->components();
    $config = (string) file_get_contents(base_path('ops/supervisor/oblivion-monitoring-workers.conf'));

    expect(array_keys($components))->toBe([
        'events', 'checks', 'discovery', 'provider', 'topology', 'maintenance', 'orchestration', 'commands',
    ])->and(array_values($components))->toHaveCount(count(array_unique($components)))
        ->and($config)->not->toMatch('/--queue=[^\s]*,/');

    foreach ($components as $component => $queue) {
        expect(preg_match_all('/--queue='.preg_quote($queue, '/').'(?=\s|$)/m', $config))
            ->toBe(1, "The {$component} runtime queue must have exactly one Supervisor program.");
    }

    preg_match('/\[program:oblivion-monitoring-commands\](.*?)(?=\n\[program:|\z)/s', $config, $matches);
    expect($matches[1] ?? '')
        ->toContain('--queue=monitoring-commands', '--tries=1', 'autorestart=true', 'stopasgroup=true', 'killasgroup=true');
});

it('verifies and reports every supervised monitoring runtime queue', function () {
    $script = (string) file_get_contents(base_path('scripts/monitoring/verify-runtime.ps1'));
    $workers = [
        'oblivion-monitoring-events' => 'monitoring-events',
        'oblivion-monitoring-checks' => 'monitoring-checks',
        'oblivion-monitoring-discovery' => 'monitoring-discovery',
        'oblivion-monitoring-provider' => 'monitoring-provider',
        'oblivion-monitoring-topology' => 'monitoring-topology',
        'oblivion-monitoring-maintenance' => 'monitoring-maintenance',
        'oblivion-monitoring-orchestration' => 'monitoring',
        'oblivion-monitoring-commands' => 'monitoring-commands',
    ];

    foreach ($workers as $program => $queue) {
        expect($script)
            ->toContain("'{$program}' = '{$queue}'")
            ->toContain("'{$queue}'");
    }

    expect($script)
        ->toContain("worker_queues = \$requiredWorkers.Count")
        ->toContain('worker_queue_names = @($requiredWorkers.Values)')
        ->toContain("Invoke-CheckedCommand -FilePath 'php' -Arguments @('artisan', 'queue:monitor', (\$monitoredQueues -join ','), '--max=1000', '--json')");
});

it('keeps protocol execution out of web controllers and database access out of the remote collector', function () {
    $forbiddenControllerSymbols = [
        'ProbeAdapterRegistry',
        'MonitorCheckRunner',
        'RunMonitorCheck',
        'RunDiscoveryScope',
        'NativeIcmpTransport',
        'NativeTcpTransport',
        'NativeDnsTransport',
        'NativeHttpTransport',
        'NativeTlsTransport',
        'NativeSnmpTransport',
        'socket_create(',
        'stream_socket_client(',
        'snmp2_get(',
        'snmp3_get(',
    ];
    $controllerRoots = [
        base_path('app/Http/Controllers'),
        base_path('app/Domain/SecurityDevices/Http/Controllers'),
        base_path('app/Domain/SecurityDevices/Management/Http/Controllers'),
    ];

    foreach ($controllerRoots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbiddenControllerSymbols as $symbol) {
                expect($source)->not->toContain($symbol);
            }
        }
    }

    $collectorComposer = json_decode(
        (string) file_get_contents(base_path('collector/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $collectorRequirements = array_keys($collectorComposer['require'] ?? []);
    expect($collectorRequirements)
        ->not->toContain('ext-pdo', 'laravel/framework', 'illuminate/database');

    $collectorFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('collector/src')));
    foreach ($collectorFiles as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        expect((string) file_get_contents($file->getPathname()))
            ->not->toContain('PDO(', 'mysqli_', 'Illuminate\\Database', 'DB::', 'Schema::', 'database_path(');
    }
});
