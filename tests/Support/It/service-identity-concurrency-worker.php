<?php

use App\Domain\It\Services\ItApiWorkItemService;
use App\Http\Middleware\RecordItApiRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.getenv('TEST_TOKEN')) {
        throw new RuntimeException('This worker only joins its parent disposable IT schema.');
    }
    if (count($argv) < 2 || ! in_array($argv[1], ['rotate', 'issue', 'old-token-api'], true)) {
        throw new RuntimeException('Unexpected service identity concurrency worker operation.');
    }

    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Service identity concurrency worker isolation was lost.');
    }
    Notification::fake();
    Http::preventStrayRequests();

    $mode = $argv[1];
    $barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
    $token = (string) getenv('TEST_TOKEN');
    $waitFor = static function (string $path): void {
        $deadline = microtime(true) + 30;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Parent barrier release timed out.');
            }
            usleep(10_000);
        }
    };
    $checkBarrier = static function (string $path, string $pattern) use ($barrierRoot, $token): void {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (! str_starts_with($normalized, $barrierRoot.$token.'-service-identity-')
            || preg_match($pattern, basename($normalized)) !== 1
            || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Barriers must remain within the parent-owned test storage directory.');
        }
    };

    if ($mode === 'issue') {
        if (count($argv) !== 6 || ! ctype_digit($argv[2]) || ! ctype_digit($argv[3])
            || preg_match('/^[a-f0-9-]{36}$/D', (string) getenv('IT_SERVICE_IDENTITY_ISSUE_UUID')) !== 1
            || ! is_string(getenv('IT_SERVICE_IDENTITY_ISSUE_PAYLOAD')) || getenv('IT_SERVICE_IDENTITY_ISSUE_PAYLOAD') === '') {
            throw new RuntimeException('Issue worker arguments are invalid.');
        }
        [, , $managerId, $executionAccountId, $ready, $attempt] = $argv;
        $pattern = '/^'.preg_quote($token, '/').'-service-identity-crossed-site-issue-[a-f0-9-]{36}-[ab]\\.(?:ready|attempt)$/D';
        $checkBarrier($ready, $pattern);
        $checkBarrier($attempt, $pattern);
        $payload = json_decode((string) getenv('IT_SERVICE_IDENTITY_ISSUE_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)
            || ($payload['request_uuid'] ?? null) !== getenv('IT_SERVICE_IDENTITY_ISSUE_UUID')
            || (int) ($payload['viewer_user_id'] ?? 0) !== (int) $managerId
            || (int) ($payload['actor_user_id'] ?? 0) !== (int) $executionAccountId
            || ($payload['allowed_site_ids'] ?? null) !== []) {
            throw new RuntimeException('Issue worker payload is invalid.');
        }
        $manager = User::query()->findOrFail((int) $managerId);
        Auth::setUser($manager);
        touch($ready);
        $attemptedAt = null;
        DB::connection()->beforeExecuting(function (string $query) use (&$attemptedAt, $attempt): void {
            $sql = strtolower($query);
            if ($attemptedAt === null && str_contains($sql, 'from `sites`') && str_contains($sql, 'for update')) {
                $attemptedAt = microtime(true);
                touch($attempt);
            }
        });
        $request = Request::create('/it/setup/api-identities', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn (): User => $manager);
        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        $kernel->terminate($request, $response);
        if ($attemptedAt === null) {
            throw new RuntimeException('Management issue did not reach the ordered Site evidence lock.');
        }
        echo json_encode([
            'status' => $response->getStatusCode(),
            'identity_id' => is_numeric($body['identity_id'] ?? null) ? (int) $body['identity_id'] : 0,
            'configuration_version' => is_numeric($body['configuration_version'] ?? null) ? (int) $body['configuration_version'] : 0,
            'replayed' => (bool) ($body['replayed'] ?? false),
            'credential_present' => is_string($body['credential']['token'] ?? null),
            'attempted_at' => $attemptedAt,
            'completed_at' => microtime(true),
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'rotate') {
        if (count($argv) !== 7 || ! ctype_digit($argv[2]) || ! ctype_digit($argv[3])
            || preg_match('/^[a-f0-9-]{36}$/D', (string) getenv('IT_SERVICE_IDENTITY_COMMAND_UUID')) !== 1
            || ! ctype_digit((string) getenv('IT_SERVICE_IDENTITY_EXPECTED_VERSION'))) {
            throw new RuntimeException('Rotation worker arguments are invalid.');
        }
        [, , $managerId, $identityId, $ready, $attempt, $release] = $argv;
        $pattern = '/^'.preg_quote($token, '/').'-service-identity-rotate-[a-f0-9-]{36}-[01]\\.(?:ready|attempt)$/D';
        $releasePattern = '/^'.preg_quote($token, '/').'-service-identity-rotate-[a-f0-9-]{36}\\.release$/D';
        $checkBarrier($ready, $pattern);
        $checkBarrier($attempt, $pattern);
        $checkBarrier($release, $releasePattern);
        $manager = User::query()->findOrFail((int) $managerId);
        Auth::setUser($manager);
        touch($ready);
        $waitFor($release);
        $attemptedAt = null;
        DB::connection()->beforeExecuting(function (string $query) use (&$attemptedAt, $attempt): void {
            $sql = strtolower($query);
            if ($attemptedAt === null && str_contains($sql, 'from `users`') && str_contains($sql, 'for update')) {
                $attemptedAt = microtime(true);
                touch($attempt);
            }
        });
        $request = Request::create('/it/setup/api-identities/'.(int) $identityId.'/rotate', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'request_uuid' => getenv('IT_SERVICE_IDENTITY_COMMAND_UUID'),
            'viewer_user_id' => (int) $managerId,
            'expected_version' => (int) getenv('IT_SERVICE_IDENTITY_EXPECTED_VERSION'),
        ], JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn (): User => $manager);
        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        $kernel->terminate($request, $response);
        if ($attemptedAt === null) {
            throw new RuntimeException('Management rotation did not reach the ordered manager mutex.');
        }
        echo json_encode([
            'status' => $response->getStatusCode(),
            'identity_id' => is_numeric($body['identity_id'] ?? null) ? (int) $body['identity_id'] : 0,
            'configuration_version' => is_numeric($body['configuration_version'] ?? null) ? (int) $body['configuration_version'] : 0,
            'replayed' => (bool) ($body['replayed'] ?? false),
            'credential_present' => is_string($body['credential']['token'] ?? null),
            'attempted_at' => $attemptedAt,
            'completed_at' => microtime(true),
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if (count($argv) !== 4) {
        throw new RuntimeException('Old-token API worker arguments are invalid.');
    }
    foreach (['IT_SERVICE_IDENTITY_API_TOKEN', 'IT_SERVICE_IDENTITY_API_KEY', 'IT_SERVICE_IDENTITY_API_PAYLOAD'] as $variable) {
        if (! is_string(getenv($variable)) || getenv($variable) === '') {
            throw new RuntimeException('API credentials must be supplied only through the worker environment.');
        }
    }
    [, , $ready, $release] = $argv;
    $oldPattern = '/^'.preg_quote($token, '/').'-service-identity-old-token-(?:rotate|revoke)-[a-f0-9-]{36}\\.(?:ready|release)$/D';
    $checkBarrier($ready, $oldPattern);
    $checkBarrier($release, $oldPattern);
    $payload = json_decode((string) getenv('IT_SERVICE_IDENTITY_API_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($payload)) {
        throw new RuntimeException('Old-token API payload is invalid.');
    }

    $app->singleton(RecordItApiRequest::class, function () use ($app, $ready, $release): RecordItApiRequest {
        return new class($app->make(ItApiWorkItemService::class), $ready, $release) extends RecordItApiRequest
        {
            public function __construct(
                ItApiWorkItemService $workItems,
                private readonly string $readyPath,
                private readonly string $releasePath,
            ) {
                parent::__construct($workItems);
            }

            public function handle(Request $request, Closure $next): Response
            {
                if (! is_string($request->attributes->get('it_authenticated_token_hash'))
                    || ! $request->attributes->has('it_service_identity')) {
                    throw new RuntimeException('The test pause must follow AuthenticateItServiceIdentity.');
                }
                touch($this->readyPath);
                $deadline = microtime(true) + 30;
                while (! is_file($this->releasePath)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Old-token API barrier timed out.');
                    }
                    usleep(10_000);
                }

                return parent::handle($request, $next);
            }
        };
    });

    $request = Request::create('/api/v1/it/work-items', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.getenv('IT_SERVICE_IDENTITY_API_TOKEN'),
        'HTTP_IDEMPOTENCY_KEY' => getenv('IT_SERVICE_IDENTITY_API_KEY'),
    ], json_encode($payload, JSON_THROW_ON_ERROR));
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $body = json_decode((string) $response->getContent(), true);
    $kernel->terminate($request, $response);
    echo json_encode([
        'status' => $response->getStatusCode(),
        'code' => is_string($body['code'] ?? null) ? $body['code'] : null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Service identity concurrency worker failed.'.PHP_EOL);
    exit(1);
}
