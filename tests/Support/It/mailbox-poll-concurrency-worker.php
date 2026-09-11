<?php

use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\Services\ItMailboxPollingToken;
use App\Domain\It\Services\ItMailboxPollState;
use App\Http\Controllers\Settings\ItMailboxOAuthController;
use App\Models\ItMailboxConnection;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$token = (string) getenv('TEST_TOKEN');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
    || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token) {
    throw new RuntimeException('Mailbox worker must join its exact parent test schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array' || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Mailbox worker isolation differs from its parent.');
}
Http::preventStrayRequests();
Notification::fake();
[$script, $operation, $actorId, $connectionId, $ready, $attempt, $release] = $argv;
if (! in_array($operation, ['claim', 'refresh', 'disconnect'], true)) {
    throw new RuntimeException('Unexpected mailbox worker operation.');
}
$root = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $leaf = substr($normalized, strlen($root));
    if (! str_starts_with($normalized, $root) || str_contains($leaf, DIRECTORY_SEPARATOR)
        || ! str_starts_with($leaf, 'mailbox-'.$token.'-')) {
        throw new RuntimeException('Mailbox barrier is outside its parent-owned test paths.');
    }
}
$actor = User::findOrFail((int) $actorId);
$claim = ItMailboxConnection::findOrFail((int) $connectionId);
$state = app(ItMailboxPollState::class);
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent did not release mailbox workers.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);
try {
    if ($operation === 'claim') {
        $status = $state->claim((int) $connectionId) ? 'claimed' : 'skipped';
    } elseif ($operation === 'refresh') {
        (new ItMailboxPollingToken($claim, $state))->storeRefreshedToken('synthetic-refreshed', 'synthetic-refresh', 3600);
        $status = 'refreshed';
    } else {
        $request = Request::create('/settings/it-mailbox/connect/microsoft', 'DELETE', [
            'connection_id' => $claim->id, 'expected_version' => $claim->configuration_version,
        ]);
        $request->setUserResolver(fn () => $actor);
        app(ItMailboxOAuthController::class)->disconnect($request, 'microsoft');
        $status = 'disconnected';
    }
} catch (ItMailboxPollSuperseded) {
    $status = 'superseded';
}
echo json_encode(['status' => $status, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
