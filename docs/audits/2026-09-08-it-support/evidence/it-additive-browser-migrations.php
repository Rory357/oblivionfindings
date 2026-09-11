<?php

/**
 * Local-only additive migration gate. Default/--pretend performs read-only
 * preflight and SQL preview. --apply=<reviewed fingerprint> is required for DDL.
 * Only the five explicit files below can run; no reset, seed, rollback, schema
 * dump load, provider action, or unrelated pending migration is invoked.
 * MySQL DDL is not transactional: partial failure requires forward inspection.
 */
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

const IT_ADDITIVE_MIGRATIONS = [
    '2026_09_09_000001_create_it_ticket_command_receipts',
    '2026_09_09_000002_add_lock_version_to_it_tickets',
    '2026_09_09_000003_add_dispatch_intent_to_it_email_deliveries',
    '2026_09_09_000004_add_it_ticket_routing_decisions',
    '2026_09_09_000005_add_it_ticket_sla_evidence',
];
const IT_ADDITIVE_DATABASE = 'oblivion_findings_codex_test';

function itAdditiveRequire(bool $condition, string $safeReason): void
{
    if (! $condition) {
        throw new RuntimeException($safeReason);
    }
}

function itAdditiveDesignHashes(): array
{
    $baseline = json_decode(file_get_contents(__DIR__.'/implementation-design-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($baseline as $entry) {
        foreach ((array) $entry['path'] as $index => $path) {
            itAdditiveRequire($path === 'DESIGN.md' || str_starts_with($path, 'design_styles/'), 'Unexpected protected-baseline path.');
            $expected[$path] = strtolower(((array) $entry['sha256'])[$index]);
        }
    }
    $actual = ['DESIGN.md' => hash_file('sha256', base_path('DESIGN.md'))];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('design_styles'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $actual[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($actual);
    ksort($expected);
    itAdditiveRequire($actual === $expected, 'Protected design hashes differ from the session baseline.');

    return $actual;
}

function itAdditiveColumn(string $table, string $column): ?object
{
    return DB::selectOne('SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [IT_ADDITIVE_DATABASE, $table, $column]);
}

function itAdditiveIndexes(string $table): array
{
    $rows = DB::select('SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [IT_ADDITIVE_DATABASE, $table]);
    $indexes = [];
    foreach ($rows as $row) {
        $indexes[$row->index_name]['unique'] = (int) $row->non_unique === 0;
        $indexes[$row->index_name]['columns'][] = $row->column_name;
    }

    return $indexes;
}

/** Fail closed on a partially applied migration or incompatible existing shape. */
function itAdditiveMigrationState(): array
{
    itAdditiveRequire(Schema::hasTable('migrations') && DB::table('migrations')->count() > 0, 'Expected populated migration registry is missing.');
    $ran = DB::table('migrations')->pluck('migration')->all();
    $state = [];
    foreach (IT_ADDITIVE_MIGRATIONS as $name) {
        $state[$name] = in_array($name, $ran, true) ? 'applied' : 'pending';
    }

    $hasReceipts = Schema::hasTable('it_ticket_command_receipts');
    itAdditiveRequire($hasReceipts === ($state[IT_ADDITIVE_MIGRATIONS[0]] === 'applied'), 'Receipt table and migration registry disagree.');
    if ($hasReceipts) {
        $expectedColumns = ['id', 'actor_user_id', 'channel', 'operation', 'request_uuid', 'request_hash', 'it_ticket_id', 'committed_at', 'created_at', 'updated_at'];
        itAdditiveRequire(Schema::getColumnListing('it_ticket_command_receipts') === $expectedColumns, 'Receipt table columns differ from the approved migration.');
        $index = itAdditiveIndexes('it_ticket_command_receipts')['it_ticket_command_actor_operation_uuid_unique'] ?? null;
        itAdditiveRequire($index === ['unique' => true, 'columns' => ['actor_user_id', 'channel', 'operation', 'request_uuid']], 'Receipt uniqueness boundary differs from the approved migration.');
        $foreignKeys = DB::select('SELECT COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS reference_table, REFERENCED_COLUMN_NAME AS reference_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME', [IT_ADDITIVE_DATABASE, 'it_ticket_command_receipts']);
        itAdditiveRequire(json_decode(json_encode($foreignKeys), true) === [
            ['column_name' => 'actor_user_id', 'reference_table' => 'users', 'reference_column' => 'id'],
            ['column_name' => 'it_ticket_id', 'reference_table' => 'it_tickets', 'reference_column' => 'id'],
        ], 'Receipt foreign-key boundary differs from the approved migration.');
    }

    $version = itAdditiveColumn('it_tickets', 'lock_version');
    itAdditiveRequire(($version !== null) === ($state[IT_ADDITIVE_MIGRATIONS[1]] === 'applied'), 'Ticket version column and migration registry disagree.');
    if ($version) {
        itAdditiveRequire($version->data_type === 'bigint' && str_contains($version->column_type, 'unsigned') && $version->nullable === 'NO' && (string) $version->default_value === '1', 'Ticket version column differs from the approved migration.');
    }
    $deliveryApplied = $state[IT_ADDITIVE_MIGRATIONS[2]] === 'applied';
    foreach (['dispatch_requested_at', 'dispatch_finished_at'] as $column) {
        $metadata = itAdditiveColumn('it_email_deliveries', $column);
        itAdditiveRequire(($metadata !== null) === $deliveryApplied, 'Delivery column and migration registry disagree.');
        if ($metadata) {
            itAdditiveRequire($metadata->data_type === 'timestamp' && $metadata->nullable === 'YES' && $metadata->default_value === null, 'Delivery column differs from the approved migration.');
        }
    }
    $dispatchIndex = itAdditiveIndexes('it_email_deliveries')['it_delivery_pending_dispatch'] ?? null;
    itAdditiveRequire(($dispatchIndex !== null) === $deliveryApplied, 'Delivery index and migration registry disagree.');
    if ($dispatchIndex) {
        itAdditiveRequire($dispatchIndex === ['unique' => false, 'columns' => ['dispatch_finished_at', 'dispatch_requested_at']], 'Delivery dispatch index differs from the approved migration.');
    }

    foreach ([
        3 => ['priority_decision' => 'json', 'routing_decision' => 'json', 'routing_override' => 'json'],
        4 => ['sla_original_policy_snapshot' => 'json', 'sla_policy_snapshot' => 'json', 'first_response_breached_at' => 'datetime', 'resolution_breached_at' => 'datetime', 'sla_checked_at' => 'datetime'],
    ] as $index => $columns) {
        $isApplied = $state[IT_ADDITIVE_MIGRATIONS[$index]] === 'applied';
        foreach ($columns as $column => $type) {
            $metadata = itAdditiveColumn('it_tickets', $column);
            itAdditiveRequire(($metadata !== null) === $isApplied, 'Additive ticket column and migration registry disagree.');
            if ($metadata) {
                itAdditiveRequire($metadata->data_type === $type && $metadata->nullable === 'YES' && $metadata->default_value === null, 'Additive ticket evidence column differs from the reviewed contract.');
            }
        }
    }

    $allMigrationNames = array_map(fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')));

    return [
        'allowlisted' => $state,
        'unrelated_pending' => array_values(array_diff($allMigrationNames, $ran, IT_ADDITIVE_MIGRATIONS)),
        'registry_count' => count($ran),
    ];
}

function itAdditiveSnapshot(): array
{
    $counts = [];
    foreach (['users', 'sites', 'it_tickets', 'it_ticket_comments', 'it_ticket_events', 'it_attachments', 'it_email_deliveries'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    // Digest only canonical ticket state, never message bodies or credentials.
    $ticketState = DB::table('it_tickets')->orderBy('id')->get([
        'id', 'reference', 'status', 'workflow_state', 'site_id', 'requester_user_id',
        'assigned_to_user_id', 'owner_user_id', 'queue_id', 'team_id',
        'first_response_due_at', 'resolution_due_at', 'first_responded_at', 'created_at', 'updated_at',
    ])->all();

    return [
        'captured_at' => now()->toIso8601String(),
        'record_counts' => $counts,
        'ticket_state_sha256' => hash('sha256', json_encode($ticketState, JSON_THROW_ON_ERROR)),
        'migration_state' => itAdditiveMigrationState(),
        'protected_hashes' => itAdditiveDesignHashes(),
    ];
}

$applied = false;
try {
    $connection = config('database.connections.mysql');
    itAdditiveRequire(app()->environment('local')
        && strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') === 0
        && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
        && config('database.default') === 'mysql'
        && $connection['host'] === '127.0.0.1' && (string) $connection['port'] === '3306'
        && $connection['database'] === IT_ADDITIVE_DATABASE && empty($connection['url'])
        && ! app()->configurationIsCached()
        && config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array'
        && config('queue.default') === 'sync'
        && in_array(config('broadcasting.default'), [null, 'null'], true), 'Exact local checkout/database/notification guard failed.');

    Notification::fake();
    Mail::fake();
    Bus::fake();
    Http::preventStrayRequests();
    $paths = array_map(fn (string $name): string => database_path('migrations/'.$name.'.php'), IT_ADDITIVE_MIGRATIONS);
    foreach ($paths as $path) {
        itAdditiveRequire(is_file($path), 'An allowlisted migration file is missing.');
    }
    $sourceHashes = array_combine(IT_ADDITIVE_MIGRATIONS, array_map(fn (string $path): string => hash_file('sha256', $path), $paths));

    DB::statement('SET TRANSACTION READ ONLY');
    DB::beginTransaction();
    try {
        $before = itAdditiveSnapshot();
        $preview = [];
        foreach ($paths as $index => $path) {
            if ($before['migration_state']['allowlisted'][IT_ADDITIVE_MIGRATIONS[$index]] === 'pending') {
                $migration = require $path;
                $queries = DB::connection()->pretend(fn () => $migration->up());
                $preview[IT_ADDITIVE_MIGRATIONS[$index]] = array_column($queries, 'query');
            }
        }
    } finally {
        DB::rollBack();
    }

    $fingerprint = hash('sha256', json_encode([$sourceHashes, $before['migration_state'], $before['protected_hashes'], $preview], JSON_THROW_ON_ERROR));
    $review = ['mode' => 'pretend', 'database_mutations_performed' => false, 'approval_fingerprint' => $fingerprint, 'migration_sha256' => $sourceHashes, 'before' => $before, 'sql_preview' => $preview];
    $argument = $argv[1] ?? '--pretend';
    if ($argument === '--pretend') {
        echo json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    itAdditiveRequire($argument === '--apply='.$fingerprint, 'Apply requires the exact reviewed preflight fingerprint; run --pretend again.');
    itAdditiveRequire($preview !== [], 'No allowlisted migrations remain pending.');
    itAdditiveRequire(! is_file(__DIR__.'/w03-w04-browser-migration-before.json'), 'A prior apply snapshot exists; review its outcome before any retry.');
    itAdditiveRequire((int) DB::scalar('SELECT GET_LOCK(?, 0)', ['it-support-additive-local-migrations']) === 1, 'Another local IT_ADDITIVE migration operation is active.');

    try {
        itAdditiveRequire(itAdditiveMigrationState() === $before['migration_state'], 'Migration state changed after preflight.');
        file_put_contents(__DIR__.'/w03-w04-browser-migration-before.json', json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        DB::statement('SET SESSION lock_wait_timeout = 10');
        // Passing these FILES to Migrator excludes every unrelated pending path.
        // This does not invoke migrate:fresh, schema loading, seeding or down().
        app('migrator')->run($paths, ['step' => true]);
        $applied = true;
        $after = itAdditiveSnapshot();
        $verified = $before['record_counts'] === $after['record_counts']
            && $before['ticket_state_sha256'] === $after['ticket_state_sha256']
            && $before['protected_hashes'] === $after['protected_hashes']
            && $before['migration_state']['unrelated_pending'] === $after['migration_state']['unrelated_pending']
            && ! in_array('pending', $after['migration_state']['allowlisted'], true);
        $result = ['mode' => 'applied', 'approval_fingerprint' => $fingerprint, 'invariants_verified' => $verified, 'migration_sha256' => $sourceHashes, 'before' => $before, 'after' => $after];
        file_put_contents(__DIR__.'/w03-w04-browser-migration-after.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        itAdditiveRequire($verified, 'Post-migration reconciliation changed; inspect the saved evidence. No automatic rollback will run.');
    } finally {
        DB::select('SELECT RELEASE_LOCK(?)', ['it-support-additive-local-migrations']);
    }
} catch (Throwable $exception) {
    $safeMessage = $exception instanceof RuntimeException && ! $exception instanceof PDOException
        ? $exception->getMessage()
        : 'Database or schema operation failed; no raw exception details emitted.';
    // MySQL DDL may already have committed even if the migrator did not return.
    fwrite(STDERR, 'IT_ADDITIVE migration gate stopped: '.$safeMessage.' Review schema and the before snapshot before retrying; no destructive rollback was attempted.'.PHP_EOL);
    exit(1);
}
