<?php

use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketLinkService;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$token = (string) getenv('TEST_TOKEN');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1
    || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token) {
    throw new RuntimeException('This worker can only join its exact parent isolated relationship schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array' || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Relationship worker database or notification isolation differs.');
}
Notification::fake();
[$script, $operation, $actorId, $sourceId, $targetId, $encodedInput, $ready, $attempt, $release] = $argv;
if (! in_array($operation, ['execute', 'cancel'], true)) {
    throw new RuntimeException('Unexpected relationship race operation.');
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot)
        || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)
        || ! str_starts_with(basename($normalized), 'it-relationship-concurrency-'.$token.'-')) {
        throw new RuntimeException('Relationship barriers must stay inside the exact parent test scope.');
    }
}
$input = json_decode($encodedInput, true, flags: JSON_THROW_ON_ERROR);
$actor = User::query()->findOrFail((int) $actorId);
$source = ItTicket::query()->findOrFail((int) $sourceId);
$target = ItTicket::query()->findOrFail((int) $targetId);
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent did not release relationship workers.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);
try {
    $service = app(ItTicketLinkService::class);
    $output = $operation === 'cancel'
        ? $service->recoverRelated($source, $target, $actor, $input, true)
        : $service->changeRelated($source, $target, $actor, $input);
} catch (ItTicketVersionConflict) {
    $output = ['status' => 'stale_ticket'];
}
echo json_encode([...$output, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
