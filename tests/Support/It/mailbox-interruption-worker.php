<?php

use App\Domain\It\InboundEmailIngestor;
use App\Jobs\PollItMailboxJob;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$token = (string) getenv('TEST_TOKEN');
if (PHP_SAPI !== 'cli' || count($argv) !== 5 || getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || ! preg_match('/^it_[a-f0-9]{16}$/D', $token) || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token) {
    throw new RuntimeException('Interruption worker must join its exact parent schema.');
}
[$script, $provider, $phase, $actorId, $connectionId] = $argv;
if (! in_array($provider, ['microsoft', 'google'], true) || ! in_array($phase, ['before_commit', 'after_commit'], true)) {
    throw new RuntimeException('Unexpected interruption case.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
    || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync') {
    throw new RuntimeException('Interruption worker isolation differs.');
}
require __DIR__.'/draft-concurrency-filesystem.php';
require __DIR__.'/mailbox-interruption-fixture.php';
$root = configureItDraftConcurrencyStorage();
$key = 'interruption-'.$provider.'-'.$phase;
$barrier = $root.'/'.$key.'.ready.json';
if (file_exists($barrier)) {
    throw new RuntimeException('Interruption barrier already exists.');
}
$connection = ItMailboxConnection::findOrFail((int) $connectionId);
if ($connection->provider !== $provider) {
    throw new RuntimeException('Provider fixture identity differs.');
}
$calls = [];
configureMailboxInterruptionFixture($connection, User::findOrFail((int) $actorId)->email, $key, $calls);
$pause = function (int $intentId) use ($barrier, $phase): never {
    $handle = fopen($barrier.'.pending', 'x');
    if (! $handle) {
        throw new RuntimeException('Interruption barrier could not be created.');
    }
    fwrite($handle, json_encode(['phase' => $phase, 'intent_id' => $intentId, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR));
    fflush($handle);
    fclose($handle);
    if (! rename($barrier.'.pending', $barrier)) {
        throw new RuntimeException('Interruption barrier was not published.');
    }
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        usleep(10000);
    }
    throw new RuntimeException('Parent did not terminate its paused worker.');
};
if ($phase === 'before_commit') {
    ItAttachmentStorageIntent::updated(function ($intent) use ($pause): void {
        if ($intent->state === 'attached') {
            $pause((int) $intent->id);
        }
    });
} else {
    Event::listen(TransactionCommitted::class, function ($event) use ($connection, $key, $pause): void {
        if ($event->connection->transactionLevel() !== 0) {
            return;
        }
        $receipt = ItInboundEmail::where('transport_key', hash('sha256', $connection->mailboxScopeHash()."\0".$key))->first();
        if ($receipt?->status === 'processed') {
            $file = $receipt->ticket->attachments()->sole();
            $pause((int) ItAttachmentStorageIntent::where('attachment_id', $file->id)->sole()->id);
        }
    });
}
(new PollItMailboxJob($connection->id))->handle(app(InboundEmailIngestor::class));
throw new RuntimeException('The interruption point was not reached.');
