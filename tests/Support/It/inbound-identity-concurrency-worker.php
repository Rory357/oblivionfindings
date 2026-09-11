<?php

use App\Domain\It\InboundEmailIngestor;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$token = (string) getenv('TEST_TOKEN');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
    || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token) {
    throw new RuntimeException('Inbound worker must join its exact parent test schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array' || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Inbound worker isolation differs from its parent.');
}
Http::preventStrayRequests();
Notification::fake();
[$script, $actorId, $identity, $body, $ready, $release, $ticketId] = $argv;
if (! in_array($identity, ['identical', 'conflicting', 'reply'], true)
    || ! ctype_digit($ticketId) || (($identity === 'reply') !== ((int) $ticketId > 0))) {
    throw new RuntimeException('Unexpected synthetic identity.');
}
$root = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $leaf = substr($normalized, strlen($root));
    if (! str_starts_with($normalized, $root) || str_contains($leaf, DIRECTORY_SEPARATOR)
        || ! str_starts_with($leaf, 'inbound-identity-'.$token.'-')) {
        throw new RuntimeException('Identity barrier is outside its parent-owned test paths.');
    }
}
$attemptedAt = null;
ItInboundEmail::creating(function (ItInboundEmail $receipt) use ($ready, $release, &$attemptedAt): void {
    if ($receipt->identity_claim_key === null || $attemptedAt !== null) {
        return;
    }
    $attemptedAt = microtime(true);
    touch($ready);
    $deadline = microtime(true) + 30;
    while (! is_file($release)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Parent did not release identity insertion.');
        }
        usleep(10_000);
    }
});
$actor = User::findOrFail((int) $actorId);
try {
    $receipt = app(InboundEmailIngestor::class)->ingest([
        'from' => $actor->email,
        'subject' => $identity === 'reply' ? 'Re: '.ItTicket::findOrFail((int) $ticketId)->reference : 'Synthetic concurrent email',
        'message_id' => '<'.$identity.'@example.test>', 'text' => $body,
    ]);
} catch (Throwable $failure) {
    // Laravel's console exception renderer can exit zero without a JSON result.
    // Report a failing process with no message content or raw SQL in its output.
    fwrite(STDERR, json_encode(['exception_class' => $failure::class, 'code' => $failure->getCode()], JSON_THROW_ON_ERROR));
    exit(1);
}
echo json_encode([
    'id' => $receipt->id, 'status' => $receipt->status,
    'attempted_at' => $attemptedAt, 'completed_at' => microtime(true),
], JSON_THROW_ON_ERROR);
