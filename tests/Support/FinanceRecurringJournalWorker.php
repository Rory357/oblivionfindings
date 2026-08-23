<?php

declare(strict_types=1);

use App\Domain\Finance\Services\RecurringJournalService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $recurringJournalId, $scheduledFor, $organizationId, $readyPath, $releasePath] = $argv;

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
        fwrite(STDERR, "Timed out waiting for the recurring-journal concurrency barrier.\n");
        exit(2);
    }

    usleep(20_000);
}

try {
    $journal = $app->make(RecurringJournalService::class)->processOccurrence(
        (int) $recurringJournalId,
        $scheduledFor,
        (int) $organizationId,
    );

    echo json_encode([
        'journal_id' => $journal->id,
        'source_id' => $journal->source_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
