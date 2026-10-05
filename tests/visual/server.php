<?php

use Illuminate\Http\Request;
use Tests\Visual\FrozenVisualRuntime;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/FrozenVisualRuntime.php';

if (is_file($root.'/bootstrap/cache/config.php')) {
    throw new LogicException('Frozen visual runtime requires uncached disposable test configuration.');
}
FrozenVisualRuntime::loadEnvironment($root);
$options = FrozenVisualRuntime::configureFromEnvironment();

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && file_exists($root.'/public'.$uri)) {
    return false;
}

if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

$app = require $root.'/bootstrap/app.php';
FrozenVisualRuntime::seedFaker($app, $options['seed']);

$app->handleRequest(Request::capture());
