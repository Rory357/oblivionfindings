<?php

declare(strict_types=1);

use App\Models\BehaviourSupportPlan;
use App\Models\User;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use App\Services\HealthSafety\BehaviourSupportPlanApprovalService;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
[$script, $database, $actorId, $planId, $kind, $encoded, $readyPath, $goPath] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test') || ! in_array($kind, ['approval', 'settings', 'update'], true)) {
    throw new RuntimeException('Plan workers require the admitted test database and exact command.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 45;
while (! is_file($goPath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Plan concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    $actor = User::findOrFail((int) $actorId);
    $input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
    try {
        $result = match ($kind) {
            'settings' => $app->make(BehaviourSupportApprovalPolicySettings::class)->save($actor, $input),
            'approval' => $app->make(BehaviourSupportPlanApprovalService::class)->decide(BehaviourSupportPlan::findOrFail((int) $planId), $actor, $input),
            'update' => $app->make(BehaviourSupportPlanService::class)->update(BehaviourSupportPlan::findOrFail((int) $planId), $actor, $input),
        };
        echo json_encode(['kind' => $kind, 'status' => 'accepted', 'receipt' => $result], JSON_THROW_ON_ERROR);
    } catch (ValidationException $error) {
        echo json_encode(['kind' => $kind, 'status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
