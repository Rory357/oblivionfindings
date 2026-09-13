<?php

use App\Models\User;
use App\Services\SsoAuthenticationService;
use App\Services\SsoConfigurationService;
use App\Services\SsoGroupMappingLockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('This worker can only join the exact isolated schema prepared by its parent.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('SSO worker isolation failed.');
}
Http::preventStrayRequests();
Notification::fake();
config(['app.url' => 'https://application.example.test', 'sso.staff_domain' => 'example.test', 'services.google' => ['client_id' => 'synthetic.apps.googleusercontent.com', 'client_secret' => 'synthetic-secret']]);
[$script, $operation, $actorId, $attempt, $acquired, $release] = $argv;
if (! in_array($operation, ['disable', 'rollback', 'admit'], true)) {
    throw new RuntimeException('Unexpected SSO concurrency operation.');
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$attempt, $acquired, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot) || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Invalid isolated barrier path.');
    }
}
touch($attempt);
try {
    DB::transaction(function () use ($operation, $actorId, $acquired, $release): void {
        app(SsoGroupMappingLockService::class)->lockMappingSet();
        touch($acquired);
        if ($operation === 'admit') {
            app(SsoAuthenticationService::class)->usableConfiguration('google', 'staff');

            return;
        }
        $deadline = microtime(true) + 30;
        while (! is_file($release)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Parent did not release the isolated SSO writer.');
            }
            usleep(10_000);
        }
        app(SsoConfigurationService::class)->saveProvider(User::findOrFail((int) $actorId), 'google', [
            'expected_version' => 0, 'client_id' => 'synthetic.apps.googleusercontent.com', 'domain' => 'example.test',
            'staff_enabled' => false, 'portal_enabled' => false, 'secret_action' => 'keep',
        ]);
        if ($operation === 'rollback') {
            throw new RuntimeException('synthetic-rollback');
        }
    });
    $status = $operation === 'admit' ? 'admitted' : 'disabled';
} catch (HttpExceptionInterface $exception) {
    if ($operation !== 'admit' || $exception->getStatusCode() !== 403) {
        throw $exception;
    }
    $status = 'denied';
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'synthetic-rollback') {
        throw $exception;
    }
    $status = 'rolled_back';
}
echo json_encode(['status' => $status, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
