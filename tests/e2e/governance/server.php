<?php

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

// Built-in health check for webServer probe so Playwright knows the server process is alive
if ($uri === '/_health' || $uri === '/health') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo "OK\n";
    exit(0);
}

$dbStateFile = __DIR__.'/.governance-e2e-db.json';
if (! file_exists($dbStateFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "E2E Governance test server failed closed: Missing state file .governance-e2e-db.json\n";
    exit(1);
}

$state = json_decode(file_get_contents($dbStateFile), true);
if (empty($state['dbName']) || ! str_starts_with($state['dbName'], 'oblivion_gov_e2e_')) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "E2E Governance test server failed closed: Invalid or unsafe test database name in .governance-e2e-db.json\n";
    exit(1);
}

$disposableConfigCache = sys_get_temp_dir().'/oblivion_gov_server_config_'.$state['dbName'].'.php';
putenv("DB_DATABASE={$state['dbName']}");
$_ENV['DB_DATABASE'] = $state['dbName'];
$_SERVER['DB_DATABASE'] = $state['dbName'];
putenv("APP_CONFIG_CACHE={$disposableConfigCache}");
$_ENV['APP_CONFIG_CACHE'] = $disposableConfigCache;
$_SERVER['APP_CONFIG_CACHE'] = $disposableConfigCache;
putenv("MAIL_MAILER=array");
$_ENV['MAIL_MAILER'] = 'array';
$_SERVER['MAIL_MAILER'] = 'array';
putenv("QUEUE_CONNECTION=sync");
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_SERVER['QUEUE_CONNECTION'] = 'sync';
putenv("CACHE_STORE=array");
$_ENV['CACHE_STORE'] = 'array';
$_SERVER['CACHE_STORE'] = 'array';
putenv("SESSION_DRIVER=file");
$_ENV['SESSION_DRIVER'] = 'file';
$_SERVER['SESSION_DRIVER'] = 'file';

$root = dirname(__DIR__, 3);

// Serve static files directly (so PHP applies proper MIME types).
if ($uri !== '/' && file_exists($root.'/public'.$uri)) {
    return false;
}

require_once $root.'/public/index.php';
