<?php

use App\Support\Database\LifecycleTriggerDeploymentGuard;

function lifecycleTriggerMigrationSources(): array
{
    return [
        LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION => (string) file_get_contents(
            __DIR__.'/../../database/migrations/'.LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION.'.php',
        ),
        LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION => (string) file_get_contents(
            __DIR__.'/../../database/migrations/'.LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION.'.php',
        ),
    ];
}

it('pins the exact sixteen lifecycle trigger definitions to their migrations and event surfaces', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;
    $result = $guard->sourceRosterResult(lifecycleTriggerMigrationSources());
    $roster = $result['roster'];

    expect($roster)->toHaveCount(16)
        ->and($result['raw_count'])->toBe(16)
        ->and($result['duplicate_names'])->toBe([])
        ->and($guard->sourceRosterErrors(
            $roster,
            $result['raw_count'],
            $result['duplicate_names'],
        ))->toBe([])
        ->and($roster['device_relationships_before_insert_guard'])->toMatchArray([
            'migration' => LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION,
            'table' => 'device_relationships',
            'timing' => 'BEFORE',
            'event' => 'INSERT',
        ])
        ->and($roster['monitoring_snapshots_after_update_audit'])->toMatchArray([
            'migration' => LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION,
            'table' => 'monitoring_configuration_snapshots',
            'timing' => 'AFTER',
            'event' => 'UPDATE',
        ]);
});

it('applies the binary log trust requirement only while binary logging is enabled', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;

    expect($guard->binaryLogAllowsTriggerCreation(false, false))->toBeTrue()
        ->and($guard->binaryLogAllowsTriggerCreation(false, true))->toBeTrue()
        ->and($guard->binaryLogAllowsTriggerCreation(true, true))->toBeTrue()
        ->and($guard->binaryLogAllowsTriggerCreation(true, false))->toBeFalse();
});

it('accepts only the reviewed MySQL 8 server family and rejects MariaDB aliases', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;

    expect($guard->supportsMySqlVersion('8.0.44', 'MySQL Community Server - GPL'))->toBeTrue()
        ->and($guard->supportsMySqlVersion('8.4.7-commercial', 'MySQL Enterprise Server'))->toBeTrue()
        ->and($guard->supportsMySqlVersion('8.0.18', 'MySQL Community Server - GPL'))->toBeFalse()
        ->and($guard->supportsMySqlVersion('5.7.44', 'MySQL Community Server - GPL'))->toBeFalse()
        ->and($guard->supportsMySqlVersion('9.0.1', 'MySQL Community Server - GPL'))->toBeFalse()
        ->and($guard->supportsMySqlVersion('8.0.44-MariaDB', 'MariaDB Server'))->toBeFalse();
});

it('requires effective schema level migration and trigger privileges without accepting table grants', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;
    $database = 'oblivion_findings';
    $schemaGrant = 'GRANT SELECT, INSERT, CREATE, REFERENCES, INDEX, ALTER, TRIGGER '
        .'ON `oblivion_findings`.* TO `migration`@`10.%`';
    $tableGrant = 'GRANT ALL PRIVILEGES ON `oblivion_findings`.`device_relationships` '
        .'TO `migration`@`10.%`';

    expect($guard->schemaPrivileges([$schemaGrant, $tableGrant], $database))
        ->toEqualCanonicalizing(LifecycleTriggerDeploymentGuard::REQUIRED_SCHEMA_PRIVILEGES)
        ->and($guard->schemaPrivileges([$tableGrant], $database))->toBe([])
        ->and($guard->schemaPrivileges([
            'GRANT ALL PRIVILEGES ON *.* TO `migration`@`10.%`',
        ], $database))->toBe(LifecycleTriggerDeploymentGuard::REQUIRED_SCHEMA_PRIVILEGES);
});

it('matches safely escaped schema identifiers and subtracts applicable partial revokes', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;
    $grants = [
        'GRANT SELECT, INSERT, CREATE, REFERENCES, INDEX, ALTER, TRIGGER '
        .'ON `oblivion\_findings`.* TO `migration`@`10.%`',
        'REVOKE TRIGGER ON `oblivion\_findings`.* FROM `migration`@`10.%`',
    ];

    expect($guard->schemaPrivileges($grants, 'oblivion_findings'))
        ->toEqualCanonicalizing([
            'SELECT',
            'INSERT',
            'CREATE',
            'REFERENCES',
            'INDEX',
            'ALTER',
        ])
        ->and($guard->schemaPrivileges($grants, 'different_database'))->toBe([]);
});

it('rejects root-equivalent global grants that the deployment never needs', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;

    expect($guard->prohibitedGlobalPrivileges([
        'GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION',
    ]))->toBe(['GLOBAL_ALL'])
        ->and($guard->prohibitedGlobalPrivileges([
            'GRANT SELECT, SUPER, SYSTEM_VARIABLES_ADMIN, BINLOG_ADMIN ON *.* TO `migration`@`10.%`',
        ]))->toEqualCanonicalizing(['SUPER', 'SYSTEM_VARIABLES_ADMIN', 'BINLOG_ADMIN'])
        ->and($guard->prohibitedGlobalPrivileges([
            'GRANT SELECT, INSERT, CREATE, REFERENCES, INDEX, ALTER, TRIGGER '
            .'ON `oblivion_findings`.* TO `migration`@`10.%`',
        ]))->toBe([]);
});

it('rejects a changed body definer or extra trigger from the live roster', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;
    $sources = lifecycleTriggerMigrationSources();
    $expected = $guard->expectedRoster($sources);
    $actions = [];

    foreach ($sources as $source) {
        preg_match_all(
            '/CREATE TRIGGER\s+([a-z0-9_]+)\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+[a-z0-9_]+\s+FOR EACH ROW\s+(.*?)\R\s+SQL\);/si',
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $actions[strtolower($match[1])] = $match[2];
        }
    }

    $rows = [];
    foreach ($expected as $name => $trigger) {
        $rows[] = [
            'name' => $name,
            'table' => $trigger['table'],
            'timing' => $trigger['timing'],
            'event' => $trigger['event'],
            'action' => $actions[$name],
            'definer' => 'migration@10.%',
        ];
    }

    expect($guard->liveRosterErrors(
        $rows,
        $expected,
        [
            LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION,
            LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION,
        ],
        'migration@10.%',
    ))->toBe([]);

    $changedBodyName = $rows[0]['name'];
    $changedDefinerName = $rows[1]['name'];
    $rows[0]['action'] .= ' SET @unexpected = 1;';
    $rows[1]['definer'] = 'other@10.%';
    $rows[] = [
        'name' => 'unreviewed_target_trigger',
        'table' => 'device_relationships',
        'timing' => 'BEFORE',
        'event' => 'INSERT',
        'action' => 'BEGIN END',
        'definer' => 'migration@10.%',
    ];

    expect($guard->liveRosterErrors(
        $rows,
        $expected,
        [
            LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION,
            LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION,
        ],
        'migration@10.%',
    ))->toContain(
        'live_trigger_roster_mismatch',
        "live_trigger_{$changedBodyName}_body_mismatch",
        "live_trigger_{$changedDefinerName}_definer_mismatch",
    );
});

it('rejects malformed raw trigger declarations and duplicate names before deriving hashes', function (): void {
    $guard = new LifecycleTriggerDeploymentGuard;
    $sources = lifecycleTriggerMigrationSources();
    preg_match(
        '/DB::unprepared\(<<<\'SQL\'\R\s+CREATE TRIGGER device_relationships_before_insert_guard.*?\R\s+SQL\);/si',
        $sources[LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION],
        $duplicate,
    );
    $sources[LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION] .= "\n".$duplicate[0]
        ."\nCREATE TRIGGER malformed_without_a_body";

    $result = $guard->sourceRosterResult($sources);

    expect($result['raw_count'])->toBe(18)
        ->and($result['duplicate_names'])->toBe(['device_relationships_before_insert_guard'])
        ->and($guard->sourceRosterErrors(
            $result['roster'],
            $result['raw_count'],
            $result['duplicate_names'],
        ))->toContain(
            'source_trigger_raw_count_mismatch',
            'source_trigger_duplicate_name',
        );
});
