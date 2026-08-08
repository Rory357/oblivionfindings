<?php

it('provisions trusted trigger creation before the unprivileged database bootstrap migration', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $workflow = file_get_contents($root.'/.github/workflows/database-bootstrap.yml');
    $trustCommand = 'SET GLOBAL log_bin_trust_function_creators = ON;';
    $migrationCommand = 'php artisan migrate:fresh --seed --force';
    $trustPosition = strpos($workflow, $trustCommand);
    $migrationPosition = strpos($workflow, $migrationCommand);

    expect($trustPosition)->not->toBeFalse()
        ->and($migrationPosition)->not->toBeFalse()
        ->and($trustPosition)->toBeLessThan($migrationPosition)
        ->and($workflow)
        ->toContain('DB_USERNAME: testUser')
        ->not->toContain('GRANT SUPER', 'GRANT ALL PRIVILEGES');
});

it('keeps every fail-closed lifecycle trigger installed by the adjacent goal migrations', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $relationshipMigration = file_get_contents(
        $root.'/database/migrations/2026_08_06_000041_retain_device_relationship_history.php',
    );
    $monitoringMigration = file_get_contents(
        $root.'/database/migrations/2026_08_06_000047_enforce_monitoring_evidence_lifecycle.php',
    );

    expect(substr_count($relationshipMigration, 'CREATE TRIGGER '))->toBe(3)
        ->and(substr_count($monitoringMigration, 'CREATE TRIGGER '))->toBe(13)
        ->and($relationshipMigration)
        ->toContain('Device relationship history cannot be deleted.')
        ->and($monitoringMigration)
        ->toContain('Configuration snapshot evidence cannot be deleted.')
        ->toContain('Monitoring retention tombstone evidence cannot be deleted.')
        ->toContain('Metric series business-record pointers cannot be deleted.');
});
