<?php

/** Read-only diagnosis of the one already-created failed browser schema. */
require __DIR__.'/w06-draft-browser-runtime.php';
try {
    $token = '472da947a1b04395';
    $database = W06_BROWSER_DATABASE_PREFIX.$token;
    $root = W06_BROWSER_CHECKOUT.'/storage/framework/testing/it-draft-browser-'.$token;
    $manifest = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    w06BrowserRequire(PHP_SAPI === 'cli' && ($manifest['token'] ?? null) === $token
        && ($manifest['database'] ?? null) === $database && ($manifest['source_hashes'] ?? null) === w06BrowserSources()
        && ($manifest['approval_fingerprint'] ?? null) === '2611e3b7314aacdc894aa59bead93069f34c399fc80780efcd2d79b525da2bb0'
        && is_file($root.'/schema-created.json') && ! is_file($root.'/ready.json'), 'Exact failed bootstrap identity was not confirmed.');
    $xml = simplexml_load_file(W06_BROWSER_CHECKOUT.'/phpunit.xml');
    $access = [];
    foreach ($xml->php->env as $setting) {
        if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
            $access[(string) $setting['name']] = (string) $setting['value'];
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    w06BrowserRequire($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Exact owned database differs.');
    $tables = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
    $tables->execute([$database]);
    $names = $tables->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach (['migrations', 'users', 'roles', 'permissions', 'sites', 'it_tickets', 'it_ticket_drafts'] as $name) {
        $counts[$name] = in_array($name, $names, true) ? (int) $pdo->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn() : null;
    }
    $latest = in_array('migrations', $names, true) ? $pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_COLUMN) : [];
    echo json_encode(['database' => $database, 'table_count' => count($names), 'last_table_names' => array_slice($names, -5), 'counts' => $counts, 'last_migrations' => $latest, 'database_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    if (($argv[1] ?? null) === '--config-only') {
        $environment = [
            'IT_DRAFT_BROWSER_TOKEN' => $token, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8766',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $root.'/storage',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $database,
            'DB_URL' => 'null', 'DB_SOCKET' => '', 'DB_USERNAME' => $access['DB_USERNAME'], 'DB_PASSWORD' => $access['DB_PASSWORD'], 'DB_EMULATE_PREPARES' => 'true',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'it_draft_browser_'.$token, 'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
            'IT_DRAFTS_ENABLED' => 'true', 'IT_DRAFT_RETENTION_DAYS' => '2', 'IT_DRAFT_TERMINAL_RETENTION_DAYS' => '3',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'APP_MAINTENANCE_DRIVER' => 'file', 'BCRYPT_ROUNDS' => '4',
            'IT_INBOUND_MAIL_SECRET' => 'null', 'IT_OUTBOUND_MAIL_STATUS_SECRET' => 'null', 'IT_RELEASE_ACCEPTANCE_ENABLED' => 'false',
            'TEST_TOKEN' => '', 'PARALLEL_PROCESS' => '', 'PROCESS_TOKEN' => '',
        ];
        foreach (['CONFIG', 'ROUTES', 'EVENTS', 'SERVICES', 'PACKAGES'] as $name) {
            $environment['APP_'.$name.'_CACHE'] = $root.'/storage/bootstrap-cache/'.strtolower($name).'.php';
        }
        foreach ($environment as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $context = w06BrowserGuard();
        $app = w06BrowserApplication($context);
        try {
            $app->bootstrapWith([
                Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
                Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
            ]);
            $configError = null;
        } catch (Throwable $error) {
            $configError = ['class' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine(),
                'trace' => array_map(fn (array $frame): array => array_filter(['file' => isset($frame['file']) ? basename($frame['file']) : null, 'line' => $frame['line'] ?? null, 'class' => $frame['class'] ?? null, 'function' => $frame['function'] ?? null]), array_slice($error->getTrace(), 0, 12))];
        }
        if (! $app->bound('config')) {
            echo json_encode(['configuration_error' => $configError, 'config_bound' => false, 'providers_booted' => false, 'database_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            exit(1);
        }
        $db = config('database.connections.mysql', []);
        echo json_encode(['configuration_error' => $configError, 'checks' => [
            'config_not_cached' => ! $app->configurationIsCached(), 'routes_not_cached' => ! is_file($app->getCachedRoutesPath()),
            'local' => config('app.env') === 'local', 'debug_false' => config('app.debug') === false,
            'mysql' => config('database.default') === 'mysql', 'owned_database' => ($db['database'] ?? null) === $database,
            'loopback' => ($db['host'] ?? null) === '127.0.0.1', 'port' => (string) ($db['port'] ?? null) === '3306',
            'no_url' => empty($db['url']), 'no_read' => empty($db['read']), 'no_write' => empty($db['write']),
            'mail_array' => config('mail.default') === 'array', 'array_transport' => config('mail.mailers.array.transport') === 'array',
            'sync' => config('queue.default') === 'sync', 'cache_array' => config('cache.default') === 'array',
            'broadcast_disabled' => in_array(config('broadcasting.default'), [null, 'null'], true),
            'database_session' => config('session.driver') === 'database', 'session_domain_null' => config('session.domain') === null,
            'session_secure_false' => config('session.secure') === false,
            'private_storage_owned' => strcasecmp(w06BrowserPath(config('filesystems.disks.private.root', '')), w06BrowserPath($context['storage'].'/app/private')) === 0,
            'local_storage_owned' => strcasecmp(w06BrowserPath(config('filesystems.disks.local.root', '')), w06BrowserPath($context['storage'].'/app/private')) === 0,
        ], 'providers_booted' => false, 'database_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $error) {
    echo json_encode(['error_class' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'trace' => array_map(fn (array $frame): array => array_filter(['file' => isset($frame['file']) ? basename($frame['file']) : null, 'line' => $frame['line'] ?? null, 'class' => $frame['class'] ?? null, 'function' => $frame['function'] ?? null]), array_slice($error->getTrace(), 0, 12)),
        'database_mutations_performed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
