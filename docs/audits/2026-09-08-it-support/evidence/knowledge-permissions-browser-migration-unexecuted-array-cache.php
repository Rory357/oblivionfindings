<?php

/**
 * Default/--pretend is a READ ONLY preview; it never calls migration up().
 * --apply=<reviewed fingerprint> runs one exact DML-only migration through the
 * canonical migrator, inside one local transaction with pre-commit invariants.
 * Existing permission definitions and all explicit non-admin grants survive.
 * No seeder, rollback migration, schema command, provider or user edit is used.
 */

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const KNOWLEDGE_GATE_DATABASE = 'oblivion_findings_codex_test';
const KNOWLEDGE_GATE_MIGRATION = '2026_09_09_000006_add_knowledge_and_credential_audit_permissions';
const KNOWLEDGE_GATE_LOCK = 'it-support-knowledge-permissions-local-migration';
const KNOWLEDGE_GATE_DEFINITIONS = [
    'it.knowledge.author' => ['description' => 'Author and submit scoped IT knowledge drafts', 'group' => 'it', 'module' => 'Operations'],
    'it.knowledge.review' => ['description' => 'Publish and retire scoped IT knowledge after review', 'group' => 'it', 'module' => 'Operations'],
    'credentials.audit' => ['description' => 'Review scoped credential activity without revealing secrets', 'group' => 'credentials', 'module' => 'Operations'],
];
const KNOWLEDGE_GATE_PRESERVED = [
    'users' => ['id'], 'roles' => ['id'], 'role_user' => ['role_id', 'user_id'],
    'permission_user' => ['permission_id', 'user_id'], 'sites' => ['id'],
    'it_tickets' => ['id'], 'it_ticket_comments' => ['id'], 'it_ticket_events' => ['id'],
    'it_attachments' => ['id'], 'it_email_deliveries' => ['id'],
];

final class KnowledgeGateRefusal extends RuntimeException {}

function knowledgeGateRequire(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new KnowledgeGateRefusal($reason);
    }
}

function knowledgeGateJson(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}

function knowledgeGateDesignHashes(): array
{
    $baseline = json_decode(file_get_contents(__DIR__.'/implementation-design-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($baseline as $entry) {
        foreach ((array) $entry['path'] as $index => $path) {
            knowledgeGateRequire($path === 'DESIGN.md' || str_starts_with($path, 'design_styles/'), 'Unexpected protected-baseline path.');
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
    knowledgeGateRequire($actual === $expected, 'Protected design hashes differ from the implementation baseline.');

    return $actual;
}

/** Hash full rows in memory; no names, bodies, addresses, secrets or row values leave this function. */
function knowledgeGateDigest(string $table, array $order, bool $lock = false, ?array $existingIds = null): array
{
    $query = DB::table($table);
    foreach ($order as $column) {
        $query->orderBy($column);
    }
    if ($existingIds !== null) {
        $query->whereIn('id', $existingIds);
    }
    if ($lock) {
        $query->sharedLock();
    }
    $hash = hash_init('sha256');
    $count = 0;
    foreach ($query->cursor() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
        $count++;
    }

    return ['count' => $count, 'sha256' => hash_final($hash)];
}

function knowledgeGateSnapshot(bool $lock = false): array
{
    foreach ([...array_keys(KNOWLEDGE_GATE_PRESERVED), 'permissions', 'role_permission', 'migrations'] as $table) {
        knowledgeGateRequire(Schema::hasTable($table), 'A required canonical table is missing.');
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [KNOWLEDGE_GATE_DATABASE, $table]);
        knowledgeGateRequire($engine?->engine === 'InnoDB', 'All touched and protected tables must support transaction rollback.');
    }
    $preserved = [];
    foreach (KNOWLEDGE_GATE_PRESERVED as $table => $order) {
        $preserved[$table] = knowledgeGateDigest($table, $order, $lock);
    }
    $permissions = knowledgeGateDigest('permissions', ['id'], $lock);
    $grants = knowledgeGateDigest('role_permission', ['role_id', 'permission_id'], $lock);
    $registry = knowledgeGateDigest('migrations', ['id'], $lock);
    $adminIds = DB::table('roles')->where('name', 'admin')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    knowledgeGateRequire($adminIds !== [], 'The existing canonical admin role is required.');
    $targets = [];
    $inserts = [];
    $attachments = [];
    foreach (KNOWLEDGE_GATE_DEFINITIONS as $key => $definition) {
        $rows = DB::table('permissions')->where('key', $key)->get();
        knowledgeGateRequire($rows->count() <= 1, 'A target permission key is duplicated; inspect before migration.');
        $permissionId = $rows->first()?->id;
        $targets[$key] = ['id' => $permissionId === null ? null : (int) $permissionId, 'existing_definition_preserved' => $permissionId !== null];
        if ($permissionId === null) {
            $inserts[$key] = $definition;
        }
        foreach ($adminIds as $roleId) {
            if ($permissionId === null || ! DB::table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                $attachments[] = ['role_id' => $roleId, 'role_name' => 'admin', 'permission_key' => $key];
            }
        }
    }
    $ran = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    knowledgeGateRequire(count(array_keys($ran, KNOWLEDGE_GATE_MIGRATION, true)) <= 1, 'The target migration has duplicate registry entries.');
    $applied = in_array(KNOWLEDGE_GATE_MIGRATION, $ran, true);
    if ($applied) {
        knowledgeGateRequire($inserts === [] && $attachments === [], 'Applied migration has an incomplete capability result; no automatic role repair is permitted.');
    }
    $all = array_map(fn (string $path) => basename($path, '.php'), glob(database_path('migrations/*.php')));

    return [
        'preserved_records' => $preserved,
        'permissions' => $permissions,
        'role_permission' => $grants,
        'migration_registry' => $registry,
        'migration_state' => $applied ? 'applied' : 'pending',
        'unrelated_pending' => array_values(array_diff($all, $ran, [KNOWLEDGE_GATE_MIGRATION])),
        'protected_hashes' => knowledgeGateDesignHashes(),
        'existing_admin_role_ids' => $adminIds,
        'target_permissions' => $targets,
        'planned_permission_inserts' => $inserts,
        'planned_admin_grants' => $attachments,
    ];
}

function knowledgeGateSaveNew(string $name, array $data): void
{
    $handle = fopen(__DIR__.'/'.$name, 'x');
    knowledgeGateRequire($handle !== false, 'Evidence file already exists or cannot be created; inspect the previous result before retrying.');
    try {
        $text = knowledgeGateJson($data);
        knowledgeGateRequire(fwrite($handle, $text) === strlen($text) && fflush($handle), 'Could not persist complete migration evidence.');
    } finally {
        fclose($handle);
    }
}

$locked = false;
$commitStarted = false;
$committed = false;
$migrationRunning = false;
$pdo = null;
try {
    knowledgeGateRequire(count($argv) <= 2, 'Only --pretend or the exact reviewed --apply fingerprint is accepted.');
    $argument = $argv[1] ?? '--pretend';
    knowledgeGateRequire($argument === '--pretend' || preg_match('/^--apply=[a-f0-9]{64}$/D', $argument) === 1, 'Unsupported migration-gate argument.');
    $config = config('database.connections.mysql');
    knowledgeGateRequire(app()->environment('local')
        && strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') === 0
        && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
        && config('database.default') === 'mysql'
        && $config['host'] === '127.0.0.1' && (string) $config['port'] === '3306'
        && $config['database'] === KNOWLEDGE_GATE_DATABASE
        && empty($config['url']) && empty($config['read']) && empty($config['write'])
        && ! app()->configurationIsCached()
        && config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array'
        && config('queue.default') === 'sync'
        && in_array(config('broadcasting.default'), [null, 'null'], true), 'Exact local checkout/database/notification guard failed.');
    // Canonical MigrationsEnded fires SchemaCache::flush(). This DML-only
    // migration changes no schema: keep its cache effect in this process and
    // never increment a persistent file/Redis/database cache stamp.
    knowledgeGateRequire(config('cache.stores.array.driver') === 'array', 'The in-process array cache store is required.');
    Cache::setDefaultDriver('array');
    knowledgeGateRequire(Cache::store()->getStore() instanceof ArrayStore, 'Migration event cache must remain in process.');
    Notification::fake();
    Mail::fake();
    Bus::fake();
    Http::preventStrayRequests();
    knowledgeGateRequire(DB::scalar('SELECT DATABASE()') === KNOWLEDGE_GATE_DATABASE && DB::transactionLevel() === 0, 'Effective database or transaction boundary differs from the local guard.');
    $path = database_path('migrations/'.KNOWLEDGE_GATE_MIGRATION.'.php');
    knowledgeGateRequire(is_file($path), 'The exact allowlisted migration file is missing.');
    $sources = ['migration_sha256' => hash_file('sha256', $path), 'gate_sha256' => hash_file('sha256', __FILE__)];

    DB::statement('SET TRANSACTION READ ONLY');
    DB::beginTransaction();
    try {
        $before = knowledgeGateSnapshot();
    } finally {
        DB::rollBack();
    }
    $fingerprint = hash('sha256', knowledgeGateJson([$sources, $before]));
    $review = [
        'mode' => 'pretend', 'database_mutations_performed' => false,
        'approval_fingerprint' => $fingerprint, 'migration' => KNOWLEDGE_GATE_MIGRATION,
        ...$sources, 'database' => KNOWLEDGE_GATE_DATABASE, 'event_cache_driver' => 'array', 'before' => $before,
        'preview_method' => 'Read-only comparison against the exact DML migration definitions; up() is not called or simulated.',
    ];
    if ($argument === '--pretend') {
        echo knowledgeGateJson($review);
        exit(0);
    }
    knowledgeGateRequire($argument === '--apply='.$fingerprint, 'The reviewed source/data/design fingerprint changed; obtain and review a new preview.');
    knowledgeGateRequire($before['migration_state'] === 'pending', 'The exact migration is already applied; nothing will be changed.');
    knowledgeGateRequire(! is_file(__DIR__.'/knowledge-permissions-migration-before.json') && ! is_file(__DIR__.'/knowledge-permissions-migration-after.json'), 'Prior apply evidence exists; inspect it before any retry.');
    knowledgeGateRequire((int) DB::scalar('SELECT GET_LOCK(?, 0)', [KNOWLEDGE_GATE_LOCK]) === 1, 'Another local capability migration gate is active.');
    $locked = true;
    $pdo = DB::connection()->getPdo();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    DB::beginTransaction();
    knowledgeGateRequire(knowledgeGateSnapshot(true) === $before, 'Protected records or grants changed after preview; migration was not started.');
    knowledgeGateRequire(hash_file('sha256', $path) === $sources['migration_sha256'] && hash_file('sha256', __FILE__) === $sources['gate_sha256'], 'Reviewed source files changed after preflight.');
    $existingPermissionIds = DB::table('permissions')->orderBy('id')->pluck('id')->all();
    $existingRegistryIds = DB::table('migrations')->orderBy('id')->pluck('id')->all();
    $expectedGrants = DB::table('role_permission')->get()->map(fn ($row) => (int) $row->role_id.':'.(int) $row->permission_id)->all();
    knowledgeGateSaveNew('knowledge-permissions-migration-before.json', $review);

    // This reviewed migration needs only reads and INSERTs into three tables.
    // Block unexpected UPDATE/DELETE/DDL, including any observer side effect.
    DB::connection()->beforeExecuting(function (string $query) use (&$migrationRunning): void {
        if ($migrationRunning) {
            knowledgeGateRequire(preg_match('/^\s*(?:select\b|insert\s+into\s+`?(?:permissions|role_permission|migrations)`?\s*\()/i', $query) === 1, 'Migration attempted a statement outside the reviewed additive DML boundary.');
        }
    });
    $migrationRunning = true;
    try {
        app('migrator')->run([$path], ['step' => true]);
    } finally {
        $migrationRunning = false;
    }
    knowledgeGateRequire(DB::connection()->getPdo() === $pdo && $pdo->inTransaction() && DB::transactionLevel() === 1, 'The canonical migration escaped the guarded primary transaction.');

    $after = knowledgeGateSnapshot(true);
    foreach ($before['planned_permission_inserts'] as $key => $definition) {
        $row = DB::table('permissions')->where('key', $key)->first();
        knowledgeGateRequire($row !== null && $row->description === $definition['description'] && $row->group === $definition['group'] && $row->module === $definition['module'], 'A newly inserted permission differs from the reviewed definition.');
    }
    foreach ($before['planned_admin_grants'] as $grant) {
        $expectedGrants[] = $grant['role_id'].':'.$after['target_permissions'][$grant['permission_key']]['id'];
    }
    $actualGrants = DB::table('role_permission')->get()->map(fn ($row) => (int) $row->role_id.':'.(int) $row->permission_id)->all();
    sort($expectedGrants);
    sort($actualGrants);
    $invariants = [
        'protected_records_unchanged' => $before['preserved_records'] === $after['preserved_records'],
        'existing_permissions_unchanged' => knowledgeGateDigest('permissions', ['id'], false, $existingPermissionIds) === $before['permissions'],
        'only_missing_permissions_added' => $after['permissions']['count'] === $before['permissions']['count'] + count($before['planned_permission_inserts']),
        'only_reviewed_admin_grants_added' => $expectedGrants === $actualGrants,
        'existing_registry_unchanged' => knowledgeGateDigest('migrations', ['id'], false, $existingRegistryIds) === $before['migration_registry'],
        'only_exact_migration_registered' => $after['migration_state'] === 'applied' && $after['migration_registry']['count'] === $before['migration_registry']['count'] + 1,
        'unrelated_pending_unchanged' => $before['unrelated_pending'] === $after['unrelated_pending'],
        'protected_design_unchanged' => $before['protected_hashes'] === $after['protected_hashes'],
    ];
    knowledgeGateRequire(! in_array(false, $invariants, true), 'Pre-commit preservation check failed; the DML transaction will be rolled back.');
    $commitStarted = true;
    DB::commit();
    $committed = true;
    $result = ['mode' => 'applied', 'approval_fingerprint' => $fingerprint, ...$sources, 'invariants_verified' => true, 'invariants' => $invariants, 'before' => $before, 'after' => $after];
    knowledgeGateSaveNew('knowledge-permissions-migration-after.json', $result);
    echo knowledgeGateJson($result);
} catch (Throwable $exception) {
    $rolledBack = false;
    if (! $committed && $pdo !== null && DB::connection()->getPdo() === $pdo && $pdo->inTransaction()) {
        try {
            DB::rollBack();
            $rolledBack = true;
        } catch (Throwable) {
            // An unknown database outcome requires inspection, never a rollback claim.
        }
    }
    $outcome = $committed ? 'Committed; evidence persistence needs inspection.'
        : ($rolledBack ? 'The active DML transaction was rolled back.'
            : ($commitStarted ? 'Commit acknowledgement is unknown; inspect the exact registry and saved before evidence.' : 'No committed migration result is claimed.'));
    $reason = $exception instanceof KnowledgeGateRefusal ? $exception->getMessage() : 'A guarded local operation failed; raw database details are suppressed.';
    fwrite(STDERR, 'KNOWLEDGE_GATE stopped: '.$reason.' '.$outcome.PHP_EOL);
    exit(1);
} finally {
    if ($locked && $pdo !== null && DB::connection()->getPdo() === $pdo) {
        try {
            DB::select('SELECT RELEASE_LOCK(?)', [KNOWLEDGE_GATE_LOCK]);
        } catch (Throwable) {
            // A lost connection releases its own named lock; do not reconnect or mutate.
        }
    }
}
