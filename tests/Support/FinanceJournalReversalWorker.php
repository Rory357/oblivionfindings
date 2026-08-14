<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\JournalPostingService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $journalId, $readyPath, $releasePath] = $argv;

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
        fwrite(STDERR, "Timed out waiting for the journal-reversal concurrency barrier.\n");
        exit(2);
    }

    usleep(20_000);
}

try {
    $journal = FinJournal::query()->findOrFail((int) $journalId);
    $reversal = $app->make(JournalPostingService::class)->reverse(
        $journal,
        'Concurrent exactly-once reversal',
    );

    echo json_encode([
        'reversal_id' => $reversal->id,
        'reversal_of_journal_id' => $reversal->reversal_of_journal_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
