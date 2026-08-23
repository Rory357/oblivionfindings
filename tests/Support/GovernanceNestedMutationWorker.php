<?php

declare(strict_types=1);

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[
    $script,
    $database,
    $action,
    $budgetId,
    $adjustmentId,
    $actorId,
    $readyPath,
    $attemptPath,
    $releasePath,
] = $argv;

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
        fwrite(STDERR, "Timed out waiting for the governance mutation release barrier.\n");
        exit(2);
    }

    usleep(20_000);
}

file_put_contents($attemptPath, 'attempting', LOCK_EX);

try {
    $actor = User::query()->findOrFail((int) $actorId);
    $budget = Budget::query()->findOrFail((int) $budgetId);
    $adjustment = BudgetAdjustment::query()->findOrFail((int) $adjustmentId);
    $service = $app->make(GovernanceNestedMutationService::class);

    if ($action === 'approve') {
        $result = $service->approveBudgetAdjustment($actor, $budget, $adjustment);
    } elseif ($action === 'reject') {
        $result = $service->rejectBudgetAdjustment(
            $actor,
            $budget,
            $adjustment,
            'Concurrent governance rejection.',
        );
    } else {
        throw new InvalidArgumentException("Unknown governance mutation action: {$action}");
    }

    echo json_encode(['status' => $result->status], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
