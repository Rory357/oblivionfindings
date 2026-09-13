<?php

use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\SiteController;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.getenv('TEST_TOKEN')) {
        throw new RuntimeException('This worker only joins its parent disposable IT schema.');
    }
    if (count($argv) < 2 || ! in_array($argv[1], ['role-api', 'role-writer', 'site-api', 'site-writer'], true)) {
        throw new RuntimeException('Unexpected authorization concurrency worker operation.');
    }

    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Authorization concurrency worker isolation was lost.');
    }
    Notification::fake();
    Http::preventStrayRequests();

    $mode = $argv[1];
    $barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
    $checkBarrier = static function (string $path, string $suffix) use ($barrierRoot): void {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $token = (string) getenv('TEST_TOKEN');
        if (! str_starts_with($normalized, $barrierRoot.$token.'-api-authorization-')
            || preg_match('/^'.preg_quote($token, '/').'-api-authorization-(?:role|site)-[a-f0-9-]{36}-(?:api|writer)\.'.preg_quote($suffix, '/').'$/D', basename($normalized)) !== 1
            || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Barriers must remain within the parent-owned test storage directory.');
        }
    };
    $waitFor = static function (string $path): void {
        $deadline = microtime(true) + 30;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Parent barrier release timed out.');
            }
            usleep(10_000);
        }
    };

    if (in_array($mode, ['role-api', 'site-api'], true)) {
        foreach (['IT_API_AUTHORIZATION_TOKEN', 'IT_API_AUTHORIZATION_KEY'] as $variable) {
            if (! is_string(getenv($variable)) || getenv($variable) === '') {
                throw new RuntimeException('API worker credentials must be supplied only through its environment.');
            }
        }
    }

    if ($mode === 'role-api') {
        if (count($argv) !== 4 || ! is_string(getenv('IT_API_AUTHORIZATION_PAYLOAD'))) {
            throw new RuntimeException('Role API worker arguments are invalid.');
        }
        [, , $ready, $release] = $argv;
        $checkBarrier($ready, 'ready');
        $checkBarrier($release, 'release');
        $payload = json_decode((string) getenv('IT_API_AUTHORIZATION_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Role API worker payload is invalid.');
        }

        $app->singleton(AuthorizationEvidenceLockService::class, function () use ($ready, $release): AuthorizationEvidenceLockService {
            return new class($ready, $release) extends AuthorizationEvidenceLockService
            {
                private bool $paused = false;

                public function __construct(
                    private readonly string $readyPath,
                    private readonly string $releasePath,
                ) {}

                public function lockForUsers(iterable $users, array $permissionKeys, array $additionalRoleIds = []): Collection
                {
                    $locked = parent::lockForUsers($users, $permissionKeys, $additionalRoleIds);
                    if (! $this->paused) {
                        $this->paused = true;
                        touch($this->readyPath);
                        $deadline = microtime(true) + 30;
                        while (! is_file($this->releasePath)) {
                            if (microtime(true) >= $deadline) {
                                throw new RuntimeException('Role API evidence barrier timed out.');
                            }
                            usleep(10_000);
                        }
                    }

                    return $locked;
                }
            };
        });

        $request = Request::create('/api/v1/it/work-items', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.getenv('IT_API_AUTHORIZATION_TOKEN'),
            'HTTP_IDEMPOTENCY_KEY' => getenv('IT_API_AUTHORIZATION_KEY'),
        ], json_encode($payload, JSON_THROW_ON_ERROR));
        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        $kernel->terminate($request, $response);
        echo json_encode([
            'status' => $response->getStatusCode(),
            'id' => is_numeric($body['data']['id'] ?? null) ? (int) $body['data']['id'] : 0,
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'role-writer') {
        if (count($argv) !== 7) {
            throw new RuntimeException('Role writer arguments are invalid.');
        }
        [, , $administratorId, $roleId, $ready, $attempt, $go] = $argv;
        foreach ([[$ready, 'ready'], [$attempt, 'attempt'], [$go, 'go']] as [$path, $suffix]) {
            $checkBarrier($path, $suffix);
        }
        $administrator = User::query()->findOrFail((int) $administratorId);
        $role = Role::query()->findOrFail((int) $roleId);
        touch($ready);
        $waitFor($go);
        $attempted = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempted, $attempt): void {
            $sql = strtolower($query);
            if (! $attempted && str_contains($sql, 'from `roles`') && str_contains($sql, 'for update')) {
                $attempted = true;
                touch($attempt);
            }
        });
        $request = Request::create('/settings/roles/'.$role->id, 'PUT', [
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'permission_keys' => $role->permissions()->where('key', '!=', 'it.manage')->pluck('key')->all(),
            'landing_route' => $role->landing_route,
        ]);
        $request->setUserResolver(fn (): User => $administrator);
        $app->make(RolesController::class)->update($request, $role);
        if (! $attempted) {
            throw new RuntimeException('RolesController writer did not reach its Role FOR UPDATE mutex.');
        }
        echo json_encode(['outcome' => 'revoked'], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if ($mode === 'site-api') {
        if (count($argv) !== 5) {
            throw new RuntimeException('Site API worker arguments are invalid.');
        }
        [, , $ticketId, $ready, $release] = $argv;
        $checkBarrier($ready, 'ready');
        $checkBarrier($release, 'release');
        $paused = false;
        DB::listen(function (object $event) use (&$paused, $ready, $release): void {
            $sql = strtolower((string) $event->sql);
            if (! $paused && str_contains($sql, 'from `sites`') && str_contains($sql, 'for update')) {
                $paused = true;
                touch($ready);
                $deadline = microtime(true) + 30;
                while (! is_file($release)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Site API evidence barrier timed out.');
                    }
                    usleep(10_000);
                }
            }
        });
        $request = Request::create('/api/v1/it/work-items/'.(int) $ticketId.'/comments', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.getenv('IT_API_AUTHORIZATION_TOKEN'),
            'HTTP_IDEMPOTENCY_KEY' => getenv('IT_API_AUTHORIZATION_KEY'),
        ], json_encode([
            'body' => 'An authenticated API comment held while Site authorization evidence is mutated.',
        ], JSON_THROW_ON_ERROR));
        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        $kernel->terminate($request, $response);
        if (! $paused) {
            throw new RuntimeException('API comment did not acquire a current Site FOR UPDATE authorization read.');
        }
        echo json_encode([
            'status' => $response->getStatusCode(),
            'id' => is_numeric($body['data']['id'] ?? null) ? (int) $body['data']['id'] : 0,
        ], JSON_THROW_ON_ERROR);

        exit(0);
    }

    if (count($argv) !== 7) {
        throw new RuntimeException('Site writer arguments are invalid.');
    }
    [, , $writerId, $siteId, $ready, $attempt, $go] = $argv;
    foreach ([[$ready, 'ready'], [$attempt, 'attempt'], [$go, 'go']] as [$path, $suffix]) {
        $checkBarrier($path, $suffix);
    }
    $writer = User::query()->findOrFail((int) $writerId);
    $site = Site::query()->findOrFail((int) $siteId);
    Auth::setUser($writer);
    touch($ready);
    $waitFor($go);
    $attempted = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$attempted, $attempt): void {
        if (! $attempted && str_starts_with(strtolower(ltrim($query)), 'update `sites`')) {
            $attempted = true;
            touch($attempt);
        }
    });
    $request = Request::create('/sites/'.$site->id.'/active', 'PATCH', ['is_active' => false]);
    $request->setUserResolver(fn (): User => $writer);
    $app->make(SiteController::class)->toggleActive($request, $site);
    if (! $attempted) {
        throw new RuntimeException('SiteController writer did not reach its Site UPDATE.');
    }
    echo json_encode(['outcome' => 'deactivated'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'API authorization concurrency worker failed.'.PHP_EOL);
    exit(1);
}
