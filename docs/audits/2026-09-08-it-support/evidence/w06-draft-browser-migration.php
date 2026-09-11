<?php

/**
 * Default/--pretend is read-only. --apply=<reviewed fingerprint> is the only
 * mutation mode, for one exact additive migration while recovery stays OFF.
 * MySQL DDL can commit partially: never claim rollback or blindly retry.
 */

use App\Domain\It\Services\ItTicketDraftService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const DRAFT_GATE_DATABASE = 'oblivion_findings_codex_test';
const DRAFT_GATE_MIGRATION = '2026_09_09_000007_create_it_ticket_drafts';
const DRAFT_GATE_COLUMNS = ['draft_generation_uuid', 'draft_upload_uuid', 'draft_content_hash', 'draft_storage_state', 'draft_cleanup_attempts', 'draft_cleanup_error_code'];
const DRAFT_GATE_PRESERVED = [
    'users' => ['id'], 'roles' => ['id'], 'permissions' => ['id'],
    'role_user' => ['role_id', 'user_id'], 'permission_user' => ['permission_id', 'user_id'],
    'role_permission' => ['role_id', 'permission_id'], 'sites' => ['id'],
    'it_tickets' => ['id'], 'it_ticket_comments' => ['id'], 'it_ticket_events' => ['id'],
    'it_attachments' => ['id'], 'it_ticket_command_receipts' => ['id'],
    'it_email_deliveries' => ['id'], 'it_teams' => ['id'], 'it_queues' => ['id'],
    'it_services' => ['id'], 'it_mailbox_connections' => ['id'], 'app_settings' => ['id'],
];

final class DraftGateRefusal extends RuntimeException {}

function draftGateRequire(bool $condition, string $message): void
{
    if (! $condition) {
        throw new DraftGateRefusal($message);
    }
}

function draftGateJson(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}

function draftGateDesign(): array
{
    $baseline = json_decode(file_get_contents(__DIR__.'/implementation-design-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($baseline as $entry) {
        foreach ((array) $entry['path'] as $index => $path) {
            draftGateRequire($path === 'DESIGN.md' || str_starts_with($path, 'design_styles/'), 'Unexpected protected-baseline path.');
            $expected[$path] = strtolower(((array) $entry['sha256'])[$index]);
        }
    }
    $actual = ['DESIGN.md' => hash_file('sha256', base_path('DESIGN.md'))];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('design_styles'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $actual[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($actual);
    ksort($expected);
    draftGateRequire($actual === $expected, 'Protected design sources differ from the session baseline.');

    return $actual;
}

/** Rows are hashed only in memory; no content, identity, addresses or secrets are output. */
function draftGateDigest(string $table, array $order, ?array $columns = null, ?array $ids = null): array
{
    $query = DB::table($table);
    foreach ($order as $column) {
        $query->orderBy($column);
    }
    if ($columns !== null) {
        $query->select($columns);
    }
    if ($ids !== null) {
        $query->whereIn('id', $ids);
    }
    $hash = hash_init('sha256');
    $count = 0;
    foreach ($query->cursor() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
        $count++;
    }

    return ['count' => $count, 'sha256' => hash_final($hash)];
}

function draftGateSnapshot(?array $attachmentColumns = null): array
{
    $preserved = [];
    foreach (DRAFT_GATE_PRESERVED as $table => $order) {
        draftGateRequire(Schema::hasTable($table), 'A required canonical table is missing.');
        $preserved[$table] = draftGateDigest($table, $order, $table === 'it_attachments' ? $attachmentColumns : null);
    }
    $ran = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    $applied = in_array(DRAFT_GATE_MIGRATION, $ran, true);
    draftGateRequire(count($ran) === ($applied ? 999 : 998), 'The reviewed browser migration registry count changed.');
    draftGateRequire(count(array_keys($ran, DRAFT_GATE_MIGRATION, true)) <= 1, 'The draft migration is duplicated in the registry.');
    foreach (glob(database_path('migrations/2026_09_09_00000[1-6]_*.php')) as $path) {
        draftGateRequire(in_array(basename($path, '.php'), $ran, true), 'A required W01–W04 migration is pending.');
    }
    draftGateRequire(Schema::hasTable('it_ticket_drafts') === $applied, 'Draft table and migration registry disagree; inspect partial DDL before retry.');
    foreach (DRAFT_GATE_COLUMNS as $column) {
        draftGateRequire(Schema::hasColumn('it_attachments', $column) === $applied, 'Staged attachment shape and registry disagree; inspect partial DDL.');
    }
    $all = array_map(fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')));

    return [
        'preserved_records' => $preserved,
        'migration_registry' => draftGateDigest('migrations', ['id']),
        'migration_state' => $applied ? 'applied' : 'pending',
        'unrelated_pending' => array_values(array_diff($all, $ran, [DRAFT_GATE_MIGRATION])),
        'draft_rows' => $applied ? DB::table('it_ticket_drafts')->count() : null,
        'design_hashes' => draftGateDesign(),
    ];
}

function draftGateSaveNew(string $filename, array $data): void
{
    $handle = @fopen(__DIR__.'/'.$filename, 'x');
    draftGateRequire($handle !== false, 'Apply evidence already exists or cannot be written; inspect before retry.');
    try {
        $json = draftGateJson($data);
        draftGateRequire(fwrite($handle, $json) === strlen($json) && fflush($handle), 'Complete apply evidence could not be persisted.');
    } finally {
        fclose($handle);
    }
}

$locked = false;
$ddlStarted = false;
$pdo = null;
try {
    $argument = $argv[1] ?? '--pretend';
    draftGateRequire(count($argv) <= 2 && ($argument === '--pretend' || preg_match('/^--apply=[a-f0-9]{64}$/D', $argument) === 1), 'Only --pretend or an exact reviewed --apply fingerprint is accepted.');
    $db = config('database.connections.mysql');
    draftGateRequire(app()->environment('local')
        && strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') === 0
        && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
        && config('database.default') === 'mysql'
        && $db['host'] === '127.0.0.1' && (string) $db['port'] === '3306'
        && $db['database'] === DRAFT_GATE_DATABASE && empty($db['url']) && empty($db['read']) && empty($db['write'])
        && ! app()->configurationIsCached()
        && config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array'
        && config('queue.default') === 'sync' && in_array(config('broadcasting.default'), [null, 'null'], true)
        && config('cache.default') === 'array' && config('cache.stores.array.driver') === 'array', 'Exact local checkout/database/notification/cache guard failed.');
    draftGateRequire(config('it.drafts.enabled') === false && config('it.drafts.retention_days') === null
        && config('it.drafts.terminal_retention_days') === null && ! app(ItTicketDraftService::class)->enabled(), 'Draft persistence must remain explicitly disabled with both retention values unset.');
    Notification::fake();
    Mail::fake();
    Bus::fake();
    Http::preventStrayRequests();
    draftGateRequire(DB::scalar('SELECT DATABASE()') === DRAFT_GATE_DATABASE && DB::transactionLevel() === 0, 'Effective database/transaction differs from the exact guard.');
    $path = database_path('migrations/'.DRAFT_GATE_MIGRATION.'.php');
    draftGateRequire(is_file($path), 'The exact migration source is missing.');
    $sources = ['migration_sha256' => hash_file('sha256', $path), 'gate_sha256' => hash_file('sha256', __FILE__)];
    $attachmentColumns = array_values(array_diff(Schema::getColumnListing('it_attachments'), DRAFT_GATE_COLUMNS));
    DB::statement('SET TRANSACTION READ ONLY');
    DB::beginTransaction();
    try {
        $before = draftGateSnapshot($attachmentColumns);
        $migration = require $path;
        // Connection::pretend captures these exact reviewed schema statements;
        // it does not execute DDL. This migration contains no data callbacks.
        $preview = array_column(DB::connection()->pretend(fn () => $migration->up()), 'query');
    } finally {
        DB::rollBack();
    }
    $fingerprint = hash('sha256', draftGateJson([$sources, $before, $attachmentColumns, $preview]));
    $review = ['mode' => 'pretend', 'database_mutations_performed' => false, 'database' => DRAFT_GATE_DATABASE,
        'draft_recovery_enabled' => false, 'retention_values_unset' => true, 'approval_fingerprint' => $fingerprint,
        ...$sources, 'before' => $before, 'legacy_attachment_columns' => $attachmentColumns, 'sql_preview' => $preview];
    if ($argument === '--pretend') {
        echo draftGateJson($review);
        exit(0);
    }
    draftGateRequire($argument === '--apply='.$fingerprint, 'Source, protected state or design changed; obtain and review a fresh preview.');
    draftGateRequire($before['migration_state'] === 'pending', 'The exact migration is already applied; nothing will change.');
    draftGateRequire(! is_file(__DIR__.'/w06-draft-migration-before.json') && ! is_file(__DIR__.'/w06-draft-migration-after.json'), 'Prior apply evidence exists; inspect it before any retry.');
    draftGateRequire((int) DB::scalar('SELECT GET_LOCK(?, 0)', ['it-support-draft-local-migration']) === 1, 'Another local draft migration gate is active.');
    $locked = true;
    $pdo = DB::connection()->getPdo();
    draftGateRequire(draftGateSnapshot($attachmentColumns) === $before, 'Protected browser state changed after preview; DDL has not started.');
    draftGateRequire(hash_file('sha256', $path) === $sources['migration_sha256'] && hash_file('sha256', __FILE__) === $sources['gate_sha256'], 'Reviewed source changed after preflight.');
    $registryIds = DB::table('migrations')->orderBy('id')->pluck('id')->all();
    draftGateSaveNew('w06-draft-migration-before.json', $review);
    DB::statement('SET SESSION lock_wait_timeout = 10');
    $migrationRunning = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$migrationRunning, $preview): void {
        if ($migrationRunning) {
            draftGateRequire(in_array($query, $preview, true)
                || preg_match('/^\s*(?:select\b|insert\s+into\s+`?migrations`?\s*\()/i', $query) === 1,
                'Migration attempted SQL outside the exact reviewed DDL/registry boundary.');
        }
    });
    $ddlStarted = true;
    $migrationRunning = true;
    try {
        app('migrator')->run([$path], ['step' => true]);
    } finally {
        $migrationRunning = false;
    }
    draftGateRequire(DB::connection()->getPdo() === $pdo, 'The connection changed during migration; inspect outcome.');
    $after = draftGateSnapshot($attachmentColumns);
    $nonDefaultAttachments = DB::table('it_attachments')->where(function ($query): void {
        foreach (array_diff(DRAFT_GATE_COLUMNS, ['draft_cleanup_attempts']) as $column) {
            $query->orWhereNotNull($column);
        }
        $query->orWhere('draft_cleanup_attempts', '<>', 0);
    })->exists();
    $invariants = [
        'all_preserved_records_unchanged' => $before['preserved_records'] === $after['preserved_records'],
        'legacy_registry_unchanged' => draftGateDigest('migrations', ['id'], null, $registryIds) === $before['migration_registry'],
        'only_exact_migration_registered' => $after['migration_state'] === 'applied' && $after['migration_registry']['count'] === 999,
        'draft_table_empty' => $after['draft_rows'] === 0,
        'legacy_attachment_stage_columns_at_defaults' => ! $nonDefaultAttachments,
        'unrelated_pending_unchanged' => $before['unrelated_pending'] === $after['unrelated_pending'],
        'protected_design_unchanged' => $before['design_hashes'] === $after['design_hashes'],
        'draft_recovery_still_disabled' => ! app(ItTicketDraftService::class)->enabled(),
    ];
    $result = ['mode' => 'applied', 'approval_fingerprint' => $fingerprint, ...$sources,
        'invariants_verified' => ! in_array(false, $invariants, true), 'invariants' => $invariants, 'before' => $before, 'after' => $after];
    draftGateSaveNew('w06-draft-migration-after.json', $result);
    echo draftGateJson($result);
    draftGateRequire($result['invariants_verified'], 'Post-DDL preservation check failed; inspect saved evidence. No rollback will run.');
} catch (Throwable $exception) {
    $reason = $exception instanceof DraftGateRefusal ? $exception->getMessage() : 'A guarded operation failed; private/raw database details are suppressed.';
    fwrite(STDERR, 'DRAFT_GATE stopped: '.$reason.($ddlStarted ? ' DDL may have committed partially; inspect schema/registry/before evidence before retry. No rollback was attempted.' : ' No applied migration result is claimed.').PHP_EOL);
    exit(1);
} finally {
    if ($locked && $pdo !== null && DB::connection()->getPdo() === $pdo) {
        try {
            DB::select('SELECT RELEASE_LOCK(?)', ['it-support-draft-local-migration']);
        } catch (Throwable) {
            // A lost connection releases its own lock; do not reconnect/mutate.
        }
    }
}
