<?php

it('installs only a built and healthy Inertia SSR runtime through a scoped Supervisor update', function () {
    $script = (string) file_get_contents(__DIR__.'/../../scripts/inertia/install-supervisor.sh');

    expect($script)->toContain(
        '[[ "$EUID" -eq 0 ]]',
        'PROGRAM=\'oblivion-inertia-ssr\'',
        'bootstrap/ssr/ssr.js',
        'the production SSR bundle is unavailable; run npm run build:ssr',
        'node is not executable by the configured runtime user',
        "grep -Eq '^inertia:start-ssr([[:space:]]|$)'",
        "grep -Eq '^inertia:check-ssr([[:space:]]|$)'",
        'inertia:start-ssr --runtime=node',
        'environment=NODE_ENV=\"production\",PATH=\"$RUNTIME_PATH\"',
        'supervisorctl -c "$SUPERVISORD_CONFIG" pid',
        'supervisord -c "$SUPERVISORD_CONFIG" -t',
        'supervisorctl -c "$SUPERVISORD_CONFIG" reread',
        'supervisorctl -c "$SUPERVISORD_CONFIG" avail',
        'supervisorctl -c "$SUPERVISORD_CONFIG" update "$PROGRAM"',
        'supervisorctl -c "$SUPERVISORD_CONFIG" restart "$PROGRAM"',
        'status "$PROGRAM"',
        'inertia:check-ssr',
        'inertia:check-ssr did not confirm a healthy SSR gateway',
        'INSTALL_COMMITTED=false',
    )->not->toContain('PASSWORD=', 'TOKEN=', 'sudo -E', 'autorestart=false');

    $bundle = strpos($script, 'bootstrap/ssr/ssr.js');
    $configValidation = strpos($script, 'supervisord -c "$SUPERVISORD_CONFIG" -t', $bundle);
    $reread = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" reread', $configValidation);
    $available = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" avail', $reread);
    $update = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" update "$PROGRAM"', $available);
    $restart = strpos($script, 'supervisorctl -c "$SUPERVISORD_CONFIG" restart "$PROGRAM"', $update);
    $running = strpos($script, 'status "$PROGRAM"', $restart);
    $health = strpos($script, 'inertia:check-ssr >/dev/null', $running);
    $success = strpos($script, 'runtime installed, running, and healthy', $health);

    expect($bundle)
        ->not->toBeFalse()
        ->and($configValidation)->toBeGreaterThan($bundle)
        ->and($reread)->toBeGreaterThan($configValidation)
        ->and($available)->toBeGreaterThan($reread)
        ->and($update)->toBeGreaterThan($available)
        ->and($restart)->toBeGreaterThan($update)
        ->and($running)->toBeGreaterThan($restart)
        ->and($health)->toBeGreaterThan($running)
        ->and($success)->toBeGreaterThan($health);
});

it('supports explicit daemon paths and preserves the previous definition until validation succeeds', function () {
    $script = (string) file_get_contents(__DIR__.'/../../scripts/inertia/install-supervisor.sh');

    expect($script)->toContain(
        'INERTIA_SSR_SUPERVISOR_INCLUDE_DIR:-/etc/supervisor/conf.d',
        'INERTIA_SSR_SUPERVISORD_CONFIG:-',
        '--include-directory=*)',
        '--supervisord-config=*)',
        'Supervisor include directory does not exist;',
        'supervisord configuration is unavailable; supply --supervisord-config',
        '[[ ! -L "$TARGET" ]]',
        'BACKUP_DIRECTORY="$(mktemp -d',
        'cp -p "$TARGET"',
        'if [[ "$FILES_INSTALLED" == true && "$INSTALL_COMMITTED" != true ]]',
        'install -o root -g root -m 0644 "$BACKUP_DIRECTORY/$PROGRAM.conf" "$TARGET"',
    );
});
