<?php

it('updates the checkout from origin main before building', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)
        ->toContain('git fetch --prune origin')
        ->toContain('git pull --ff-only origin main');
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
