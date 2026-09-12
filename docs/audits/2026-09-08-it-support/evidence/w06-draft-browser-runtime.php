<?php

/** Shared verification-only guard. Never included by production application code. */

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Http;

const W06_BROWSER_CHECKOUT = 'C:/Users/steph/Herd/oblivionfindings';
const W06_BROWSER_DATABASE_PREFIX = 'oblivion_it_draft_browser_';

function w06BrowserRequire(bool $condition, string $safeMessage): void
{
    if (! $condition) {
        throw new RuntimeException($safeMessage);
    }
}

function w06BrowserEnv(string $key): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return $value === false ? null : (string) $value;
}

function w06BrowserPath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function w06BrowserSources(): array
{
    $files = ['w06-draft-browser-environment.ps1', 'w06-draft-browser-runtime.php', 'w06-draft-browser-bootstrap.php',
        'w06-draft-browser-router.php', 'w06-draft-browser-fixtures.php', 'w06-draft-browser-teardown.php', 'w11-mailbox-browser-fixture.php', 'w11-merge-browser-scenario.php', 'w12-delivery-browser-fixture.php', 'w13-api-browser-fixture.php', 'w14-monitoring-browser-fixture.php', 'w15-catalogue-browser-fixture.php'];
    $hashes = [];
    foreach ($files as $name) {
        w06BrowserRequire(is_file(__DIR__.'/'.$name), 'A required verification source is missing.');
        $hashes[$name] = hash_file('sha256', __DIR__.'/'.$name);
    }

    return $hashes;
}

/** Read manifest and exact paths before any database or application bootstrap. */
function w06BrowserGuard(bool $requireReady = false): array
{
    $token = w06BrowserEnv('IT_DRAFT_BROWSER_TOKEN');
    w06BrowserRequire(is_string($token) && preg_match('/^[a-f0-9]{16}$/D', $token) === 1, 'Invalid isolated browser token.');
    $checkout = realpath(__DIR__.'/../../../..');
    w06BrowserRequire($checkout !== false && strcasecmp(w06BrowserPath($checkout), W06_BROWSER_CHECKOUT) === 0, 'Unexpected checkout.');
    $testing = realpath($checkout.'/storage/framework/testing');
    w06BrowserRequire($testing !== false && ! is_link($testing), 'The canonical testing parent is missing or linked.');
    $root = $testing.'/it-draft-browser-'.$token;
    w06BrowserRequire(is_dir($root) && ! is_link($root) && realpath($root) !== false
        && strcasecmp(w06BrowserPath(realpath($root)), w06BrowserPath($root)) === 0, 'The exact isolated root is missing, linked or escaped.');
    $storage = realpath($root.'/storage');
    w06BrowserRequire($storage !== false && ! is_link($root.'/storage')
        && strcasecmp(w06BrowserPath($storage), w06BrowserPath($root.'/storage')) === 0
        && strcasecmp(w06BrowserPath((string) w06BrowserEnv('LARAVEL_STORAGE_PATH')), w06BrowserPath($storage)) === 0, 'Isolated application storage escaped its owned root.');
    $manifestPath = $root.'/owner.json';
    w06BrowserRequire(is_file($manifestPath) && ! is_link($manifestPath), 'The isolated owner manifest is missing.');
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    w06BrowserRequire(w06BrowserEnv('IT_MAILBOX_BROWSER_FIXTURES') === (($manifest['mailbox_fixtures'] ?? false) ? 'true' : 'false'), 'Mailbox fixture mode differs from its reviewed owner.');
    w06BrowserRequire(w06BrowserEnv('IT_API_BROWSER_FIXTURES') === (($manifest['api_fixtures'] ?? false) ? 'true' : 'false'), 'API fixture mode differs from its reviewed owner.');
    w06BrowserRequire(w06BrowserEnv('IT_MONITORING_BROWSER_FIXTURES') === (($manifest['monitoring_fixtures'] ?? false) ? 'true' : 'false'), 'Monitoring fixture mode differs from its reviewed owner.');
    w06BrowserRequire(w06BrowserEnv('IT_CATALOGUE_BROWSER_FIXTURES') === (($manifest['catalogue_fixtures'] ?? false) ? 'true' : 'false'), 'Catalogue fixture mode differs from its reviewed owner.');
    $database = W06_BROWSER_DATABASE_PREFIX.$token;
    w06BrowserRequire(($manifest['token'] ?? null) === $token && ($manifest['database'] ?? null) === $database
        && ($manifest['source_hashes'] ?? null) === w06BrowserSources()
        && strcasecmp(w06BrowserPath($manifest['root'] ?? ''), w06BrowserPath($root)) === 0, 'Owner/source identity changed; inspect before continuing.');
    w06BrowserRequire(w06BrowserEnv('APP_ENV') === 'local' && w06BrowserEnv('APP_DEBUG') === 'false'
        && w06BrowserEnv('DB_CONNECTION') === 'mysql' && w06BrowserEnv('DB_HOST') === '127.0.0.1'
        && w06BrowserEnv('DB_PORT') === '3306' && w06BrowserEnv('DB_DATABASE') === $database
        && in_array(w06BrowserEnv('DB_URL'), [null, '', 'null'], true), 'Exact isolated application/database environment failed.');
    w06BrowserRequire((int) ($manifest['port'] ?? 0) === 8766 && w06BrowserEnv('APP_URL') === 'http://127.0.0.1:8766'
        && w06BrowserEnv('SESSION_COOKIE') === 'it_draft_browser_'.$token
        && w06BrowserEnv('SESSION_DRIVER') === 'database', 'Isolated host/session environment differs.');
    w06BrowserRequire(w06BrowserEnv('IT_DRAFTS_ENABLED') === 'true' && w06BrowserEnv('IT_DRAFT_RETENTION_DAYS') === '2'
        && w06BrowserEnv('IT_DRAFT_TERMINAL_RETENTION_DAYS') === '3', 'Only the synthetic fixture retention values are permitted here.');
    if ($requireReady) {
        w06BrowserRequire(is_file($root.'/ready.json'), 'The isolated schema/fixtures are not ready.');
    }
    if (PHP_SAPI === 'cli-server') {
        w06BrowserRequire(ini_get('upload_max_filesize') === '16M' && ini_get('post_max_size') === '64M'
            && (int) ini_get('max_file_uploads') === 20, 'Isolated PHP upload ceilings differ from the reviewed validation headroom.');
    }

    return [...$manifest, 'root' => $root, 'storage' => $storage, 'manifest_path' => $manifestPath];
}

function w06BrowserApplication(array $context): Application
{
    require_once W06_BROWSER_CHECKOUT.'/vendor/autoload.php';
    /** @var Application $app */
    $app = require W06_BROWSER_CHECKOUT.'/bootstrap/app.php';
    // Installed Laravel recognises slash-prefixed absolute cache paths by
    // default; this isolated Windows process also uses its explicit drive.
    $app->addAbsoluteCachePathPrefix('C:');
    $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($context): void {
        $db = config('database.connections.mysql');
        w06BrowserRequire(! $app->configurationIsCached() && ! is_file($app->getCachedRoutesPath())
            && config('app.env') === 'local' && config('app.debug') === false
            && config('database.default') === 'mysql' && $db['database'] === $context['database']
            && $db['host'] === '127.0.0.1' && (string) $db['port'] === '3306'
            && empty($db['url']) && empty($db['read']) && empty($db['write'])
            && config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array'
            && config('queue.default') === 'sync' && config('cache.default') === 'array'
            && in_array(config('broadcasting.default'), [null, 'null'], true)
            && config('session.driver') === 'database' && config('session.domain') === null
            && config('session.secure') === false, 'Effective runtime isolation failed.');
        foreach (['private', 'local'] as $disk) {
            w06BrowserRequire(config('filesystems.disks.'.$disk.'.driver') === 'local'
                && strcasecmp(w06BrowserPath(config('filesystems.disks.'.$disk.'.root')), w06BrowserPath($context['storage'].'/app/private')) === 0, 'Canonical private disk escaped isolated storage.');
        }
        // No provider identity/configuration is inherited by this disposable process.
        // These are environment isolation values, not fake business outcomes.
        foreach (['google', 'microsoft', 'postmark', 'resend', 'ses', 'slack', 'sms', 'push', 'webpush', 'xero', 'ird'] as $provider) {
            config(['services.'.$provider => []]);
        }
        config(['it.inbound_mail.secret' => null, 'it.outbound_mail.status_secret' => null,
            'inertia.ssr.enabled' => false,
            'pulse.enabled' => false, 'telescope.enabled' => false, 'nightwatch.enabled' => false]);
    });
    $app->booting(function () use ($context): void {
        Http::preventStrayRequests();
        if ($context['mailbox_fixtures'] ?? false) {
            require_once __DIR__.'/w11-mailbox-browser-fixture.php';
            w11BrowserInstallMailboxHttp($context);
            require_once __DIR__.'/w12-delivery-browser-fixture.php';
            w12BrowserInstallDeliveryTransport($context);
        }
    });

    return $app;
}

function w06BrowserPdo(?string $database = null): PDO
{
    $dsn = 'mysql:host=127.0.0.1;port=3306'.($database === null ? '' : ';dbname='.$database);

    return new PDO($dsn, (string) w06BrowserEnv('DB_USERNAME'), (string) w06BrowserEnv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function w06BrowserSaveNew(string $path, array $data): void
{
    $handle = @fopen($path, 'x');
    w06BrowserRequire($handle !== false, 'An owned evidence file already exists; inspect before retrying.');
    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        w06BrowserRequire(fwrite($handle, $json) === strlen($json) && fflush($handle), 'Could not persist complete isolated evidence.');
    } finally {
        fclose($handle);
    }
}
