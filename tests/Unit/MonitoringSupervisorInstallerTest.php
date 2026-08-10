<?php

it('validates every isolated monitoring program before a scoped Supervisor update', function () {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/install-supervisor.sh');
    $workerConfig = (string) file_get_contents($root.'/ops/supervisor/oblivion-monitoring-workers.conf');
    $listenerConfig = (string) file_get_contents($root.'/ops/supervisor/oblivion-monitoring-listeners.conf');
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
    $listeners = [
        'oblivion-monitoring-snmp-traps' => 'monitoring:listen-snmp-traps',
        'oblivion-monitoring-syslog' => 'monitoring:listen-syslog',
        'oblivion-monitoring-flow' => 'monitoring:listen-flow',
    ];

    expect(preg_match_all('/^\[program:/m', $workerConfig.$listenerConfig))->toBe(11);
    foreach ($workers as $program => $queue) {
        expect($script)->toContain($program)
            ->and(substr_count($workerConfig.$listenerConfig, "[program:{$program}]"))->toBe(1)
            ->and($workerConfig)->toMatch('/--queue='.preg_quote($queue, '/').'(?=\s|$)/');
    }
    foreach ($listeners as $program => $command) {
        expect($script)->toContain($program)
            ->and(substr_count($workerConfig.$listenerConfig, "[program:{$program}]"))->toBe(1)
            ->and($listenerConfig)->toContain("artisan {$command}");
    }

    expect($script)->toContain(
        '[[ "$EUID" -eq 0 ]]',
        'supervisorctl -c "$SUPERVISORD_CONFIG" pid',
        'directory=$APPLICATION_PATH',
        'user=$RUN_USER',
        'stdout_logfile=$LOG_DIRECTORY/',
        'combined_program_count',
        'program_declaration_count',
        'END { print count + 0 }',
        'PROBE_PROGRAM="oblivion-monitoring-install-probe-$$"',
        "'autostart=false'",
        'Supervisor does not discover .conf files from $INCLUDE_DIRECTORY.',
        'Supervisor did not discover $program from $INCLUDE_DIRECTORY.',
        'supervisorctl -c "$SUPERVISORD_CONFIG" update "${EXPECTED_PROGRAMS[@]}"',
        'status "$program:*"',
        'INSTALL_COMMITTED=false',
        'the running Supervisor daemon rejected the include path probe',
        'the running Supervisor daemon rejected the complete monitoring configuration',
    )->not->toContain(
        'PASSWORD=',
        'TOKEN=',
        'sudo -E',
        'queue:work redis --queue=default',
        'grep -h -Fxc',
        'supervisord -c "$SUPERVISORD_CONFIG" -t',
    );

    $reread = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" reread');
    $available = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" avail', $reread);
    $update = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" update', $available);

    expect($reread)->not->toBeFalse()
        ->and($available)->toBeGreaterThan($reread)
        ->and($update)->toBeGreaterThan($available);
});

it('supports an explicit include path while retaining the Ubuntu default', function () {
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/install-supervisor.sh');

    expect($script)->toContain(
        'MONITORING_SUPERVISOR_INCLUDE_DIR:-/etc/supervisor/conf.d',
        '--include-directory=*)',
        'Supervisor include directory does not exist;',
        'supervisord configuration is unavailable; supply --supervisord-config.',
    );
});
