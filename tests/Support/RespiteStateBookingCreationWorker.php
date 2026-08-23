<?php

declare(strict_types=1);

use App\Http\Controllers\Respite\RespiteBookingController;
use App\Models\RespiteBookingRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $requestId, $userId, $readyPath, $releasePath] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv("DB_DATABASE={$database}");
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the respite-state concurrency barrier.\n");
        exit(2);
    }

    usleep(20_000);
}

try {
    $user = User::query()->findOrFail((int) $userId);
    $sourceRequest = RespiteBookingRequest::query()->findOrFail((int) $requestId);
    Auth::login($user);
    $httpRequest = Request::create('/respite/bookings', 'POST', [
        'booking_request_id' => $sourceRequest->id,
        'client_id' => $sourceRequest->client_id,
        'start_at' => $sourceRequest->requested_start->format('Y-m-d H:i:s'),
        'end_at' => $sourceRequest->requested_end->format('Y-m-d H:i:s'),
    ]);
    $httpRequest->setUserResolver(fn () => $user);
    $httpRequest->setLaravelSession($app->make('session')->driver());
    $app->instance('request', $httpRequest);

    $app->make(RespiteBookingController::class)->store($httpRequest);

    echo json_encode(['created' => true], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode([
        'created' => false,
        'errors' => $exception->errors(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
