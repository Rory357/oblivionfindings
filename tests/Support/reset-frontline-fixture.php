<?php

use Database\Seeders\FrontlineLifecycleDemoSeeder;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\FrontlineFixtureResetDiagnostic;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require_once __DIR__.'/FrontlineFixtureResetDiagnostic.php';

try {
    if (is_file($root.'/bootstrap/cache/config.php')) {
        throw new LogicException('Frontline fixture diagnostic requires uncached disposable test configuration.');
    }
    Dotenv::createImmutable($root)->safeLoad();
    $read = static function (string $key): string {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    };
    FrontlineFixtureResetDiagnostic::assertDisposableContext(
        $read('APP_ENV'),
        $read('DB_CONNECTION'),
        $read('DB_DATABASE'),
        $read('DB_URL'),
    );

    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    FrontlineFixtureResetDiagnostic::assertDisposableContext(
        $app->environment(),
        config('database.default'),
        config('database.connections.mysql.database'),
        (string) config('database.connections.mysql.url'),
    );
    $seeder = $app->make(FrontlineLifecycleDemoSeeder::class);
    $seeder->setContainer($app);
    $seeder->__invoke();
    fwrite(STDOUT, json_encode(['ok' => true], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(FrontlineFixtureResetDiagnostic::describe($exception), JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
