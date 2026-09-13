<?php

use App\Domain\It\Data\ItAttachmentStorageReservation;
use App\Domain\It\Services\ItAttachmentStorageIntentService;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// This worker joins only its parent's schema and never migrates or removes it.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('Storage fencing requires the parent isolated schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array' || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Storage worker isolation was not retained.');
}
Notification::fake();
require_once __DIR__.'/draft-concurrency-filesystem.php';
configureItDraftConcurrencyStorage();

[$script, $operation, $actorId, $ticketId, $intentId, $uuid, $ready, $attempt, $release] = $argv;
if (! in_array($operation, ['store', 'cleanup'], true)) {
    throw new RuntimeException('Unexpected storage worker operation.');
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot)
        || ! preg_match('/^it-storage-fence-[a-f0-9-]+(?:-[01]\.(?:ready|attempt)|\.release)$/', substr($normalized, strlen($barrierRoot)))) {
        throw new RuntimeException('Storage barriers must use the exact isolated directory.');
    }
}
$actor = User::query()->findOrFail((int) $actorId);
$ticket = ItTicket::query()->findOrFail((int) $ticketId);
$reference = new ItAttachmentStorageReservation((int) $intentId, $uuid, 'it_attachments/'.$uuid);
$service = app(ItAttachmentStorageIntentService::class);
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Storage parent did not release its workers.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);
try {
    if ($operation === 'store') {
        DB::transaction(function () use ($service, $ticket, $actor, $reference): void {
            $paths = [];
            $service->storeReservedDirect($ticket,
                [UploadedFile::fake()->createWithContent('fencing.txt', 'Synthetic exact storage fencing bytes')],
                $actor, [$reference], $paths);
        });
        $result = ['status' => 'attached'];
    } else {
        $result = ['status' => 'cleanup', ...$service->requestRollbackCleanup([$reference])];
    }
} catch (DomainException) {
    $result = ['status' => 'fenced'];
}
echo json_encode([...$result, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
