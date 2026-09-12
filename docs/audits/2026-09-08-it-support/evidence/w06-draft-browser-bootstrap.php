<?php

/** Fresh schema only. No reset/drop or existing browser database writes. */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/w06-draft-browser-runtime.php';
$phase = 'guard';
$migrationDiagnostics = [];
try {
    w06BrowserRequire(PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--create-owned-schema' && count($argv) === 2, 'Explicit guarded schema creation mode is required.');
    $context = w06BrowserGuard();
    w06BrowserSaveNew($context['root'].'/bootstrap-phase-guarded.json', ['phase' => 'guarded', 'pid' => getmypid(), 'at' => gmdate(DATE_ATOM)]);
    w06BrowserRequire(! is_file($context['root'].'/schema-created.json') && ! is_file($context['root'].'/ready.json'), 'Schema creation evidence exists; inspect rather than reset.');
    $schemaPath = W06_BROWSER_CHECKOUT.'/database/schema/mysql-schema.sql';
    w06BrowserRequire(hash_file('sha256', $schemaPath) === $context['schema_sha256'], 'Reviewed repository schema changed.');
    $migrations = [];
    foreach (glob(W06_BROWSER_CHECKOUT.'/database/migrations/*.php') as $path) {
        $migrations[basename($path)] = hash_file('sha256', $path);
    }
    w06BrowserRequire($migrations === $context['migration_hashes'], 'Reviewed migration source set changed.');
    // Validate every statement before CREATE DATABASE. The repository dump may
    // populate only its migration registry; organisation records are not copied.
    $sql = file_get_contents($schemaPath);
    w06BrowserRequire(preg_match('/(?:^|\n)\s*(?:USE\s|CREATE\s+DATABASE\s|DROP\s+DATABASE\s)/i', $sql) !== 1
        && preg_match('/(?:^|\n)\s*INSERT\s+INTO\s+(?!`migrations`\s)/i', $sql) !== 1,
        'Schema dump contains an unexpected database switch or content insert.');
    $server = w06BrowserPdo();
    $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$context['database']]);
    w06BrowserRequire((int) $exists->fetchColumn() === 0, 'Fresh random schema already exists; no overwrite or reset is permitted.');
    $server->exec('CREATE DATABASE `'.$context['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    w06BrowserSaveNew($context['root'].'/schema-created.json', ['database' => $context['database'], 'token' => $context['token'], 'created_at' => gmdate(DATE_ATOM)]);
    $pdo = w06BrowserPdo($context['database']);
    $phase = 'schema_import';
    $buffer = '';
    $inComment = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = ltrim($line);
        if ($inComment) {
            if (str_contains($line, '*/')) {
                $inComment = false;
            }
            continue;
        }
        if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
            continue;
        }
        if ($buffer === '' && str_starts_with($trimmed, '/*') && ! str_contains($line, '*/')) {
            $inComment = true;
            continue;
        }
        w06BrowserRequire(! str_starts_with(strtoupper($trimmed), 'DELIMITER '), 'Unexpected schema delimiter; do not partially guess the import.');
        $buffer .= $line."\n";
        if (preg_match('/;\s*$/', rtrim($line))) {
            $statement = trim($buffer);
            $buffer = '';
            if ($statement !== '' && $statement !== ';') {
                $pdo->exec($statement);
            }
        }
    }
    w06BrowserRequire(trim($buffer) === '', 'Schema import has an unterminated statement.');
    unset($sql, $pdo, $server);
    $phase = 'application_bootstrap';
    w06BrowserSaveNew($context['root'].'/bootstrap-phase-application.json', ['phase' => $phase, 'pid' => getmypid(), 'at' => gmdate(DATE_ATOM)]);
    $app = w06BrowserApplication($context);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    w06BrowserRequire(DB::scalar('SELECT DATABASE()') === $context['database'], 'Effective database differs after bootstrap.');
    // The fresh schema's pending set was fingerprinted before creation; no
    // existing organisation schema or test shutdown/reset helper is involved.
    $phase = 'pending_migrations';
    w06BrowserSaveNew($context['root'].'/bootstrap-phase-migrations.json', ['phase' => $phase, 'pid' => getmypid(), 'at' => gmdate(DATE_ATOM)]);
    $migrationExit = $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);
    if ($migrationExit !== 0) {
        // Console failures may be caught internally by Laravel. Retain only
        // allowlisted diagnostics, never raw SQL, bindings or console output.
        $migrationOutput = $kernel->output();
        $migrationDiagnostics['exit_code'] = $migrationExit;
        if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $migrationOutput, $matches)) {
            $migrationDiagnostics['sqlstate'] = $matches[1];
        }
        foreach (['Lock wait timeout', 'Deadlock found', 'Duplicate key name', 'Cannot drop index', "Can't DROP", 'Too many keys', 'Specified key was too long'] as $reason) {
            if (str_contains($migrationOutput, $reason)) {
                $migrationDiagnostics['recognised_reason'] = $reason;
                break;
            }
        }
        unset($migrationOutput);
    }
    w06BrowserRequire($migrationExit === 0, 'Pending synthetic-schema migrations failed; inspect retained isolated state.');
    $phase = 'base_fixtures';
    w06BrowserSaveNew($context['root'].'/bootstrap-phase-fixtures.json', ['phase' => $phase, 'pid' => getmypid(), 'at' => gmdate(DATE_ATOM)]);
    require __DIR__.'/w06-draft-browser-fixtures.php';
    $fixtures = w06BrowserCreateFixtures($context);
    $ready = ['ready' => true, 'created_at' => gmdate(DATE_ATOM), 'database' => $context['database'],
        'registry_count' => DB::table('migrations')->count(), 'fixtures' => $fixtures,
        'drafts_enabled' => app(\App\Domain\It\Services\ItTicketDraftService::class)->enabled(),
        'synthetic_retention_days' => [2, 3],
        'provider_configuration_checks' => [
            'mailboxes_absent' => DB::table('it_mailbox_connections')->count() === 0,
            'app_settings_absent' => DB::table('app_settings')->count() === 0,
            'service_providers_cleared' => collect(['google', 'microsoft', 'postmark', 'resend', 'ses', 'slack', 'sms', 'push', 'webpush', 'xero', 'ird'])
                ->every(fn (string $provider): bool => config('services.'.$provider) === []),
        ]];
    w06BrowserRequire($ready['drafts_enabled'] && DB::table('it_ticket_drafts')->count() === 0
        && ! in_array(false, $ready['provider_configuration_checks'], true), 'Synthetic readiness invariants failed.');
    if ($context['mailbox_fixtures'] ?? false) {
        $phase = 'mailbox_fixtures';
        require_once __DIR__.'/w11-mailbox-browser-fixture.php';
        $ready['provider_configuration_checks']['mailboxes_absent_before_synthetic_mailbox_setup'] = $ready['provider_configuration_checks']['mailboxes_absent'];
        unset($ready['provider_configuration_checks']['mailboxes_absent']);
        $ready['mailbox_fixtures'] = w11BrowserCreateMailboxFixtures($context, $fixtures);
    }
    if ($context['api_fixtures'] ?? false) {
        $phase = 'api_fixtures';
        require_once __DIR__.'/w13-api-browser-fixture.php';
        $ready['api_fixtures'] = w13BrowserCreateApiFixtures($context, $fixtures);
    }
    if ($context['monitoring_fixtures'] ?? false) {
        $phase = 'monitoring_fixtures';
        require_once __DIR__.'/w14-monitoring-browser-fixture.php';
        $ready['monitoring_fixtures'] = w14BrowserCreateMonitoringFixtures($context, $fixtures);
    }
    if ($context['catalogue_fixtures'] ?? false) {
        $phase = 'catalogue_fixtures';
        require_once __DIR__.'/w15-catalogue-browser-fixture.php';
        $ready['catalogue_fixtures'] = w15BrowserCreateCatalogueFixtures($context, $fixtures);
    }
    w06BrowserSaveNew($context['root'].'/ready.json', $ready);
    echo json_encode($ready, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    if (isset($context)) {
        try {
            w06BrowserSaveNew($context['root'].'/bootstrap-failure.json', [
                'token' => $context['token'], 'phase' => $phase,
                'exception_class' => $exception::class, 'migration' => $migrationDiagnostics,
                'raw_sql_or_values_emitted' => false,
            ]);
        } catch (Throwable) {
            // A diagnostics write failure must not hide the bootstrap failure.
        }
    }
    fwrite(STDERR, 'W06 isolated bootstrap stopped. No raw SQL, private values or secrets emitted. Inspect only the owned token directory/schema; no reset or cleanup was attempted.'.PHP_EOL);
    exit(1);
}
