<?php

it('keeps the native Queclink operations runbook executable bounded and cloud free', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $runbook = file_get_contents($root.'/docs/runbooks/monitoring/queclink-native-listener.md');

    expect($runbook)->toContain(
        '`gv500cg`',
        '`gl30m`',
        'php artisan queclink:install --check',
        'php artisan queclink:status --json',
        'sudo -E php artisan queclink:install',
        'systemctl is-active oblivion-queclink.service',
        '/security-devices/integrations/queclink/provisioning?family=gv500cg',
        'ready_for_secure_provisioning',
        '/security-devices/devices/<device-id>?section=management',
        'Failed or uncertain Device command',
        'Credential compromise containment and rotation',
        'no cloud API was used',
        'no raw command was replayed',
    )->not->toContain(
        'ims.queclink.com',
        'AT+GT',
        'queue:retry',
        'password=',
        'Bearer ',
    );
});
