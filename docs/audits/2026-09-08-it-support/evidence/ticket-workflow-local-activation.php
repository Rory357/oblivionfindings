<?php

// Local, additive activation only. No record updates, broad migrations or configuration edits.
use App\Domain\It\Services\ItTicketWorkService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$repo = dirname(__DIR__, 4);
$migration = 'database/migrations/2026_09_13_120000_create_it_ticket_work_records.php';
$migrationName = basename($migration, '.php');
$report = ['phase' => 'preflight', 'applied' => false];
try {
    if (strtolower(str_replace('\\', '/', realpath($repo))) !== 'c:/users/steph/herd/oblivionfindings') {
        throw new RuntimeException('Expected local checkout is required.');
    }
    require $repo.'/vendor/autoload.php';
    $app = require $repo.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $connection = config('database.connections.mysql');
    if (! $app->environment('local') || config('database.default') !== 'mysql'
        || ! in_array($connection['host'], ['127.0.0.1', 'localhost'], true)
        || ! empty($connection['url']) || ! empty($connection['read']) || ! empty($connection['write'])
        || DB::scalar('SELECT DATABASE()') !== $connection['database']) {
        throw new RuntimeException('Activation requires the configured local MySQL application.');
    }
    foreach ([
        'users' => ['id'],
        'it_tickets' => ['id', 'lock_version'],
        'it_ticket_comments' => ['id', 'ticket_id'],
        'it_ticket_command_receipts' => ['request_uuid', 'operation', 'request_hash', 'committed_ticket_version', 'result_metadata'],
        'it_ticket_drafts' => ['draft_uuid', 'purpose', 'revision'],
        'migrations' => ['migration'],
    ] as $table => $columns) {
        if (! Schema::hasColumns($table, $columns)) {
            throw new RuntimeException('Canonical ticket dependencies are incomplete: '.$table);
        }
    }
    $tables = ['it_ticket_work_profiles', 'it_ticket_bookings', 'it_ticket_time_entries', 'it_ticket_costs', 'it_ticket_work_revisions'];
    $present = array_filter($tables, fn ($table) => Schema::hasTable($table));
    $recorded = DB::table('migrations')->where('migration', $migrationName)->exists();
    if (! ((count($present) === 0 && ! $recorded) || (count($present) === 5 && $recorded))) {
        throw new RuntimeException('Partial ticket work schema requires inspection before activation.');
    }
    $report += ['local_environment' => true, 'loopback_mysql' => true, 'canonical_dependencies_ready' => true,
        'owned_tables_present' => count($present), 'migration' => $migration, 'migration_sha256' => hash_file('sha256', $repo.'/'.$migration)];
    if (in_array('--apply', $argv, true) && ! $recorded) {
        $report['phase'] = 'exact_path_migration';
        if ($kernel->call('migrate', ['--path' => [$migration], '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Owned migration did not finish; inspect schema before retrying.');
        }
        $report['applied'] = true;
    }
    $report['ready'] = app(ItTicketWorkService::class)->ready();
    $report['phase'] = 'complete';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    $report['failure'] = $error instanceof RuntimeException ? $error->getMessage() : get_class($error);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
