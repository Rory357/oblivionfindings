<?php

use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('packages the database-free collector as one hardened non-overlapping systemd cycle', function () {
    $service = (string) file_get_contents(base_path('ops/systemd/oblivion-monitoring-collector.service'));
    $timer = (string) file_get_contents(base_path('ops/systemd/oblivion-monitoring-collector.timer'));
    $runner = (string) file_get_contents(base_path('ops/systemd/oblivion-monitoring-collector-run'));
    $environment = (string) file_get_contents(base_path('ops/systemd/monitoring-collector.env.example'));

    expect($service)->toContain(
        'Type=oneshot',
        'User=oblivion-monitoring-collector',
        'Group=oblivion-monitoring-collector',
        'EnvironmentFile=/etc/oblivion/monitoring-collector.env',
        'ExecStart=/usr/local/libexec/oblivion-monitoring-collector-run',
        'StateDirectory=oblivion-monitoring-collector',
        'Restart=on-failure',
        'NoNewPrivileges=true',
        'ProtectSystem=strict',
        'ProtectHome=true',
        'CapabilityBoundingSet=',
    )->not->toContain('DB_', 'mysql', 'Restart=always');

    expect($timer)->toContain(
        'OnUnitInactiveSec=60s',
        'Persistent=false',
        'Unit=oblivion-monitoring-collector.service',
        'WantedBy=timers.target',
    );

    expect($runner)->toContain(
        'flock -n 9',
        'run \\',
        '"--identity=$OBLIVION_COLLECTOR_IDENTITY_FILE"',
        '"--config=$OBLIVION_COLLECTOR_CONFIG_FILE"',
    )->not->toContain(' enrol ', 'ENROLMENT_TOKEN', 'DB_');

    expect($environment)->toContain(
        'OBLIVION_COLLECTOR_PHP_BINARY=',
        'OBLIVION_COLLECTOR_ARTIFACT_DIR=',
        'OBLIVION_COLLECTOR_IDENTITY_FILE=',
        'OBLIVION_COLLECTOR_CONFIG_FILE=',
    )->not->toContain('ENROLMENT_TOKEN', 'DB_', 'PASSWORD=', 'PRIVATE_KEY=');
});

it('installs the collector timer only after fail-closed runtime and identity checks', function () {
    $installer = (string) file_get_contents(base_path('scripts/monitoring/install-collector-systemd.sh'));

    expect($installer)->toContain(
        '[[ "$EUID" -eq 0 ]]',
        'PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4',
        '["curl", "json", "openssl", "sockets", "sodium"]',
        'vendor/autoload.php',
        "--shell /usr/sbin/nologin",
        'collector identity is missing;',
        'chmod 0600 "$IDENTITY_FILE"',
        'systemctl daemon-reload',
        'systemctl enable --now oblivion-monitoring-collector.timer',
    )->not->toContain(
        'oblivion-collector enrol',
        'OBLIVION_COLLECTOR_ENROLMENT_TOKEN',
        'composer install',
        'DB_',
    );
});
