<?php

// Reproduce only the public pending index migration in the exact empty, failed disposable schema.
require __DIR__.'/w06-draft-browser-runtime.php';
$token = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || preg_match('/\A[a-f0-9]{16}\z/', $token) !== 1) { exit(2); }
$root = W06_BROWSER_CHECKOUT.'/storage/framework/testing/it-draft-browser-'.$token;
$settings = [
    'IT_DRAFT_BROWSER_TOKEN' => $token, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8766',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $root.'/storage',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => W06_BROWSER_DATABASE_PREFIX.$token,
    'DB_URL' => 'null', 'DB_SOCKET' => '', 'DB_EMULATE_PREPARES' => 'true', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database',
    'SESSION_COOKIE' => 'it_draft_browser_'.$token, 'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
    'IT_DRAFTS_ENABLED' => 'true', 'IT_DRAFT_RETENTION_DAYS' => '2', 'IT_DRAFT_TERMINAL_RETENTION_DAYS' => '3',
    'IT_MAILBOX_BROWSER_FIXTURES' => 'false', 'IT_API_BROWSER_FIXTURES' => 'false', 'IT_MONITORING_BROWSER_FIXTURES' => 'false', 'IT_CATALOGUE_BROWSER_FIXTURES' => 'true',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
];
foreach (simplexml_load_file(W06_BROWSER_CHECKOUT.'/phpunit.xml')->php->env as $setting) {
    if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) { $settings[(string) $setting['name']] = (string) $setting['value']; }
}
foreach (['CONFIG', 'ROUTES', 'EVENTS', 'SERVICES', 'PACKAGES'] as $name) { $settings['APP_'.$name.'_CACHE'] = $root.'/storage/bootstrap-cache/'.strtolower($name).'.php'; }
foreach ($settings as $name => $value) { putenv($name.'='.$value); $_ENV[$name] = $_SERVER[$name] = $value; }
unset($settings);
$context = w06BrowserGuard();
w06BrowserRequire(is_file($root.'/launch-failed.json') && ! is_file($root.'/ready.json'), 'Only the retained failed fixture can be inspected.');
$app = w06BrowserApplication($context);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
w06BrowserRequire(Illuminate\Support\Facades\DB::scalar('SELECT DATABASE()') === $context['database'], 'Database identity changed.');
w06BrowserRequire(count($argv) === 3 && ($argv[2] ?? '') === '--reproduce-owned-index-migration', 'Explicit index-only diagnostic mode required.');
w06BrowserRequire(Illuminate\Support\Facades\DB::table('users')->count() === 0 && Illuminate\Support\Facades\DB::table('sites')->count() === 0, 'Only an empty failed fixture schema may be diagnosed.');
$relative = '2026_09_04_120000_add_missing_indexes_and_drop_duplicates.php';
$path = W06_BROWSER_CHECKOUT.'/database/migrations/'.$relative;
w06BrowserRequire(hash_file('sha256', $path) === ($context['migration_hashes'][$relative] ?? null), 'Index migration differs from the reviewed source.');
w06BrowserRequire(!Illuminate\Support\Facades\DB::table('migrations')->where('migration', substr($relative, 0, -4))->exists(), 'Only the pending index migration may be diagnosed.');
try {
    $migration = require $path;
    $migration->up();
    echo json_encode(['token' => $token, 'reproduced_failure' => false, 'pending_index_migration_completed' => true, 'migration_registry_modified' => false, 'scope' => 'owned empty disposable schema only']).PHP_EOL;
} catch (Throwable $exception) {
    $error = $exception instanceof Illuminate\Database\QueryException ? $exception->getPrevious() : $exception;
    $info = $error instanceof PDOException ? $error->errorInfo : [];
    $message = $info[2] ?? '';
    $known = null;
    foreach (['Lock wait timeout', 'Deadlock found', 'Duplicate key name', 'Cannot drop index', "Can't DROP", 'Too many keys', 'Specified key was too long'] as $reason) {
        if (str_contains($message, $reason)) { $known = $reason; break; }
    }
    echo json_encode(['token' => $token, 'reproduced_failure' => true, 'exception_class' => $exception::class,
        'sqlstate' => $info[0] ?? null, 'driver_code' => $info[1] ?? null, 'recognised_reason' => $known,
        'scope' => 'owned empty disposable schema only', 'raw_sql_or_values_emitted' => false]).PHP_EOL;
    exit(1);
}