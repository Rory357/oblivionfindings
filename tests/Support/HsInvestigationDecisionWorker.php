<?php

declare(strict_types=1);

use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\HealthSafety\HsInvestigationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $investigationId, $actorId, $decision, $readyPath, $releasePath] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv("DB_DATABASE={$database}");
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($readyPath, (string) getmypid(), LOCK_EX);

$deadline = microtime(true) + 60;
while (! is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the investigation decision barrier.\n");
        exit(2);
    }

    usleep(20_000);
}

try {
    $investigation = HsInvestigation::query()->findOrFail((int) $investigationId);
    $actor = User::query()->findOrFail((int) $actorId);
    Auth::login($actor);
    $service = $app->make(HsInvestigationService::class);

    try {
        if ($decision === 'review') {
            $service->review($investigation, $actor);
        } elseif ($decision === 'approve') {
            $service->complete($investigation, $actor);
        } elseif ($decision === 'rework') {
            $service->returnForRework(
                $investigation,
                'Concurrent reviewer requested more evidence.',
                $actor,
            );
        } else {
            throw new InvalidArgumentException("Unsupported decision [{$decision}].");
        }

        $result = 'accepted';
        $message = null;
    } catch (InvalidArgumentException $exception) {
        $result = 'rejected';
        $message = $exception->getMessage();
    }

    echo json_encode([
        'decision' => $decision,
        'result' => $result,
        'message' => $message,
        'investigation_status' => $investigation->fresh()->status,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
