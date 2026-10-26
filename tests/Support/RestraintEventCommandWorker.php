<?php

declare(strict_types=1);

use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\HealthSafety\RestraintService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $actorId, $eventId, $encodedPayload, $readyPath, $releasePath] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test')) {
    throw new RuntimeException('Restraint concurrency workers require the admitted test database.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake();
file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 45;
while (! is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The restraint concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    $actor = User::findOrFail((int) $actorId);
    $payload = json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
    try {
        $receipt = $app->make(RestraintService::class)->correct(RestraintEvent::findOrFail((int) $eventId), $actor, $payload);
        echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
    } catch (ValidationException $error) {
        echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
