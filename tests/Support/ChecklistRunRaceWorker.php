<?php

declare(strict_types=1);

use App\Models\SiteChecklistRun;
use App\Models\User;
use App\Services\Sites\SiteChecklistRunExecutionService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[
    $script,
    $action,
    $database,
    $runId,
    $actorId,
    $replacementId,
    $itemId,
    $readyPath,
    $attemptPath,
    $releasePath,
] = $argv;

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
        fwrite(STDERR, "Timed out waiting for the checklist race release barrier.\n");
        exit(2);
    }

    usleep(20_000);
}
file_put_contents($attemptPath, 'attempting', LOCK_EX);

try {
    $run = SiteChecklistRun::query()->findOrFail((int) $runId);
    $actor = User::query()->findOrFail((int) $actorId);
    $service = $app->make(SiteChecklistRunExecutionService::class);

    if ($action === 'complete') {
        $result = $service->complete(
            $run,
            $actor,
            [[
                'template_item_id' => (int) $itemId,
                'response_value' => 'yes',
                'is_failed' => false,
            ]],
            'Concurrent Original Owner',
            null,
            '203.0.113.11',
            'Checklist Race Worker',
        );
        $status = $result['replayed'] ? 'replayed' : 'completed';
    } elseif ($action === 'reassign') {
        $service->reassign($run, $actor, (int) $replacementId);
        $status = 'reassigned';
    } else {
        throw new RuntimeException('Unsupported checklist race action.');
    }

    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => 'http_'.$exception->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
