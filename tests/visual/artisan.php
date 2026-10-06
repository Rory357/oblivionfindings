<?php

use Dotenv\Dotenv;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\Visual\FrozenVisualRuntime;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/FrozenVisualRuntime.php';

if (is_file($root.'/bootstrap/cache/config.php')) {
    throw new LogicException('Frozen visual runtime requires uncached disposable test configuration.');
}
Dotenv::createImmutable($root)->safeLoad();
$options = FrozenVisualRuntime::configureFromEnvironment();

$app = require $root.'/bootstrap/app.php';
FrozenVisualRuntime::seedFaker($app, $options['seed']);

exit($app->handleCommand(new ArgvInput));
