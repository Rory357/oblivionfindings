<?php

declare(strict_types=1);

use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\Safeguarding\SafeguardingTerminalTransitionService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $concernId, $actorId, $readyPath, $releasePath] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv("DB_DATABASE={$database}");
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($readyPath, (string) getmypid(), LOCK_EX);

$deadline = microtime(true) + 20;
while (! is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the concurrency release barrier.\n");
        exit(2);
    }

    usleep(20_000);
}

try {
    $concern = SafeguardingConcern::query()->findOrFail((int) $concernId);
    $actor = User::query()->findOrFail((int) $actorId);
    $transition = $app->make(SafeguardingTerminalTransitionService::class)->close(
        $concern,
        $actor,
        'Concurrent safeguarding closure evidence.',
    );

    echo json_encode([
        'transition_id' => $transition->id,
        'transition_status' => $transition->status,
        'concern_status' => $concern->fresh()->status,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
