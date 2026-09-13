<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
    || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.getenv('TEST_TOKEN')) {
    throw new RuntimeException('This worker only joins its parent disposable IT schema.');
}
foreach (['IT_API_PUBLICATION_TOKEN', 'IT_API_PUBLICATION_KEY', 'IT_API_PUBLICATION_PAYLOAD', 'IT_API_PUBLICATION_METHOD', 'IT_API_PUBLICATION_PATH'] as $variable) {
    if (! is_string(getenv($variable)) || getenv($variable) === '') {
        throw new RuntimeException('API publication worker credentials were not supplied through its environment.');
    }
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
    || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
    || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('API publication worker database or notification isolation was lost.');
}
Notification::fake();
Http::preventStrayRequests();

if (count($argv) !== 4) {
    throw new RuntimeException('Expected exactly the owned barrier paths.');
}
[$script, $ready, $attempt, $release] = $argv;
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot.getenv('TEST_TOKEN').'-api-publication-')
        || preg_match('/^'.preg_quote((string) getenv('TEST_TOKEN'), '/').'-api-publication-[a-f0-9-]{36}(?:-[01]\.(?:ready|attempt)|\.release)$/D', basename($normalized)) !== 1
        || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Barriers must remain within the owned test storage directory.');
    }
}
$payload = json_decode((string) getenv('IT_API_PUBLICATION_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($payload)) {
    throw new RuntimeException('API publication worker payload is invalid.');
}
$method = strtoupper((string) getenv('IT_API_PUBLICATION_METHOD'));
$path = (string) getenv('IT_API_PUBLICATION_PATH');
$permittedCommand = ($method === 'POST' && $path === '/api/v1/it/work-items')
    || ($method === 'PATCH' && preg_match('#^/api/v1/it/work-items/[1-9][0-9]*$#D', $path) === 1)
    || ($method === 'POST' && preg_match('#^/api/v1/it/work-items/[1-9][0-9]*/relationships$#D', $path) === 1);
if (! $permittedCommand) {
    throw new RuntimeException('API publication worker command is outside its owned verification surface.');
}

touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent barrier release timed out.');
    }
    usleep(10_000);
}
$attemptedAt = null;
DB::connection()->beforeExecuting(function (string $query) use (&$attemptedAt, $attempt): void {
    if ($attemptedAt === null && str_contains(strtolower($query), 'from `users`')
        && str_contains(strtolower($query), 'for update')) {
        $attemptedAt = microtime(true);
        touch($attempt);
    }
});
$request = Request::create($path, $method, [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.getenv('IT_API_PUBLICATION_TOKEN'),
    'HTTP_IDEMPOTENCY_KEY' => getenv('IT_API_PUBLICATION_KEY'),
], json_encode($payload, JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$body = json_decode((string) $response->getContent(), true);
$kernel->terminate($request, $response);
if ($attemptedAt === null) {
    throw new RuntimeException('The authenticated request never reached its publication actor mutex.');
}

echo json_encode([
    'status' => $response->getStatusCode(),
    'id' => is_numeric($body['data']['id'] ?? null) ? (int) $body['data']['id'] : 0,
    'version' => is_numeric($body['data']['lock_version'] ?? null) ? (int) $body['data']['lock_version'] : 0,
    'replayed' => $response->headers->get('X-Idempotent-Replay') === 'true',
    'attempted_at' => $attemptedAt,
    'completed_at' => microtime(true),
], JSON_THROW_ON_ERROR);
