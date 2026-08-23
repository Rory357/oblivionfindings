<?php

declare(strict_types=1);

use App\Domain\Finance\Services\QuoteLifecycleService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $actorId, $quoteId, $action, $readyPath, $releasePath, $attemptPath] = $argv;

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
        fwrite(STDERR, "Timed out waiting for the quote-conversion release barrier.\n");
        exit(2);
    }

    usleep(20_000);
}
file_put_contents($attemptPath, 'attempting', LOCK_EX);

try {
    $actor = User::query()->findOrFail((int) $actorId);
    $service = $app->make(QuoteLifecycleService::class);

    if ($action === 'invoice') {
        $result = $service->convertToInvoice($actor, (int) $quoteId);
        $payload = [
            'status' => 'invoice',
            'destination_id' => $result['invoice']->id,
            'number' => $result['invoice']->invoice_number,
            'replayed' => $result['replayed'],
        ];
    } elseif ($action === 'agreement') {
        $agreement = $service->convertToAgreement($actor, (int) $quoteId);
        $payload = [
            'status' => 'agreement',
            'destination_id' => $agreement->id,
        ];
    } elseif ($action === 'accept') {
        $quote = $service->accept($actor, (int) $quoteId);
        $payload = ['status' => $quote->status];
    } else {
        throw new RuntimeException('Unsupported quote-conversion worker action.');
    }
} catch (ValidationException $exception) {
    $payload = [
        'status' => 'conflict',
        'errors' => $exception->errors(),
    ];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}

echo json_encode($payload, JSON_THROW_ON_ERROR);
