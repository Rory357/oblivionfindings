<?php

use App\Domain\It\Services\ItSetupCommandService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('This worker only joins its parent disposable IT schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
    || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
    || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Setup worker database or notification isolation was lost.');
}
Notification::fake();
[$script, $operation, $actorId, $resource, $uuid, $ready, $attempt, $release] = $argv;
if (! in_array($operation, ['create', 'cancel'], true) || ! in_array($resource, ['teams', 'queues', 'services', 'provisioning-templates'], true)) {
    throw new RuntimeException('Unexpected isolated Setup operation.');
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot) || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Barriers must remain within the owned test storage directory.');
    }
}
$actor = User::findOrFail((int) $actorId);
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent barrier release timed out.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);
$commands = app(ItSetupCommandService::class);
$result = $operation === 'cancel' ? $commands->cancel($actor, $resource, $uuid, (int) $actor->id)
    : $commands->create($actor, $resource, [
        'actor_user_id' => $actor->id, 'request_uuid' => $uuid, 'name' => 'Isolated Setup '.$uuid,
        'is_active' => false, ...(in_array($resource, ['queues', 'services'], true) ? ['key' => 'isolated-'.$uuid] : []),
        ...($resource === 'services' ? ['status' => 'operational', 'criticality' => 'medium'] : []),
        ...($resource === 'provisioning-templates' ? [
            'lifecycle_type' => 'joiner', 'site_id' => null, 'position_role' => null,
            'employment_type' => null, 'selection_priority' => 0,
            'tasks' => [[
                'task_key' => 'account', 'title' => 'Verify synthetic account',
                'description' => 'Manual verification only', 'category' => 'account', 'action' => 'verify',
                'request_type' => 'account', 'responsible_team_id' => null, 'stage' => 1, 'sort_order' => 0,
                'dependency_task_keys' => [], 'trigger_fields' => [], 'approval_required' => true,
                'evidence_required' => true, 'due_offset_days' => 0, 'fulfiller_fields' => ['work_email'],
            ]],
        ] : []),
    ]);
echo json_encode([...$result, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
