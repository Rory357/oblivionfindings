<?php

use App\Support\Database\LifecycleTriggerDeploymentGuard;

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
    $guard = new LifecycleTriggerDeploymentGuard;
    $result = $guard->sourceRosterResult([
        LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION => file_get_contents(
            $root.'/database/migrations/'.LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION.'.php',
        ),
        LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION => file_get_contents(
            $root.'/database/migrations/'.LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION.'.php',
        ),
    ]);
    $roster = $result['roster'];

    expect($roster)->toHaveCount(16)
        ->and($result['raw_count'])->toBe(16)
        ->and($result['duplicate_names'])->toBe([])
        ->and($guard->sourceRosterErrors(
            $roster,
            $result['raw_count'],
            $result['duplicate_names'],
        ))->toBe([])
        ->and(collect($roster)->where(
            'migration',
            LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION,
        ))->toHaveCount(3)
        ->and(collect($roster)->where(
            'migration',
            LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION,
        ))->toHaveCount(13);
});

it('gates production migration with value-free rootless preflight isolation and exact postflight', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $deploy = file_get_contents($root.'/scripts/deploy-server.sh');
    $command = file_get_contents($root.'/app/Console/Commands/VerifyLifecycleTriggerDeployment.php');
    $guardSource = file_get_contents($root.'/app/Support/Database/LifecycleTriggerDeploymentGuard.php');

    $preflight = strpos($deploy, 'database:verify-lifecycle-triggers preflight --json');
    $maintenance = strpos($deploy, 'run_app php artisan down --retry=60', $preflight);
    $writerRestart = strpos($deploy, 'run_app php artisan queue:restart', $maintenance);
    $writerDrain = strpos($deploy, 'wait_for_queue_writer_exit', $writerRestart);
    $migration = strpos($deploy, 'php artisan migrate --force --isolated=75');
    $postflight = strpos($deploy, 'database:verify-lifecycle-triggers postflight --json');
    $runtimeRestart = strpos($deploy, 'run_app php artisan queue:restart', $postflight);
    $runtimeValidation = strpos($deploy, 'final application and lifecycle runtime validation', $runtimeRestart);
    $finalPostflight = strrpos($deploy, 'database:verify-lifecycle-triggers postflight --json');
    $leaveMaintenance = strpos($deploy, 'run_app php artisan up', $finalPostflight);
    $databaseTry = strpos($command, 'try {');
    $databaseConnection = strpos($command, 'DB::connection($connectionName)', $databaseTry);
    $valueFreeCatch = strpos($command, 'catch (Throwable) {', $databaseConnection);

    expect($preflight)->not->toBeFalse()
        ->and($maintenance)->toBeGreaterThan($preflight)
        ->and($writerRestart)->toBeGreaterThan($maintenance)
        ->and($writerDrain)->toBeGreaterThan($writerRestart)
        ->and($migration)->toBeGreaterThan($writerDrain)
        ->and($migration)->toBeGreaterThan($preflight)
        ->and($postflight)->toBeGreaterThan($migration)
        ->and($runtimeRestart)->toBeGreaterThan($postflight)
        ->and($runtimeValidation)->toBeGreaterThan($runtimeRestart)
        ->and($finalPostflight)->toBeGreaterThan($runtimeValidation)
        ->and($leaveMaintenance)->toBeGreaterThan($finalPostflight)
        ->and(substr_count($deploy, 'run_app php artisan up'))->toBe(1)
        ->and($databaseTry)->not->toBeFalse()
        ->and($databaseConnection)->toBeGreaterThan($databaseTry)
        ->and($valueFreeCatch)->toBeGreaterThan($databaseConnection)
        ->and($deploy)->toContain(
            'MAINTENANCE_ACTIVE=1',
            'trap report_maintenance_on_failure EXIT',
            'the application remains in maintenance mode',
            'pre_migration_queue_writer_pids',
            'DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS',
        )
        ->and($deploy)->not->toContain('SET GLOBAL', 'GRANT SUPER', 'DB_PASSWORD=')
        ->and($command)->toContain(
            '@@GLOBAL.log_bin AS log_bin',
            '@@GLOBAL.log_bin_trust_function_creators',
            'SHOW GRANTS FOR CURRENT_USER()',
            'SHOW GRANTS FOR CURRENT_USER() USING',
            'information_schema.ENABLED_ROLES',
            'information_schema.TRIGGERS',
            'ACTION_STATEMENT AS action',
            'DEFINER AS definer',
            "['mysql_connection_required']",
            "'unsupported_mysql_version'",
            "'binary_log_trigger_trust_disabled'",
            "'prohibited_global_privileges:'",
            "['relationship_migration_partial_schema']",
            "['monitoring_migration_partial_schema']",
            "'relationship_migration_obsolete_unique_present'",
            "'relationship_migration_generated_guard_mismatch'",
            "'relationship_migration_unique_guard_missing'",
            '?bool $binaryLogging = null',
            '?int $observedTriggers = null',
            "'unknown'",
        )
        ->and($command)->not->toContain(
            'SHOW GRANTS FOR {$quotedName}',
            'SET GLOBAL',
            'SUPER privilege',
            'root password',
        )
        ->and($guardSource)->toContain(
            "version_compare(\$matches[1], '8.0.19', '>=')",
            '(GRANT|REVOKE)',
            'ACTIVE_RELATIONSHIP_INDEX_COLUMNS',
            'LEGACY_RELATIONSHIP_INDEX_COLUMNS',
            'ACTIVE_RELATIONSHIP_GENERATION_EXPRESSION',
            'source_trigger_raw_count_mismatch',
            'source_trigger_duplicate_name',
        );
});
