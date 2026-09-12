<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Models\ItProvisioningTemplate;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $token = (string) getenv('TEST_TOKEN');
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
        || preg_match('/^it_[a-f0-9]{16}$/D', $token) !== 1
        || getenv('DB_DATABASE') !== 'oblivion_it_support_test_'.$token
        || count($argv) !== 8 || ! in_array($argv[1], ['edit', 'launch'], true)
        || ! ctype_digit($argv[2]) || ! ctype_digit($argv[3]) || ! ctype_digit($argv[4]) || ! ctype_digit($argv[5])
        || ! in_array($argv[6], ['first', 'second'], true)) {
        throw new RuntimeException('Template worker requires its exact parent disposable schema.');
    }
    [, $operation, $templateId, $actorId, $profileId, $expectedVersion, $label, $ready] = $argv;
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || config('mail.default') !== 'array'
        || config('mail.mailers.array.transport') !== 'array' || config('queue.default') !== 'sync'
        || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new RuntimeException('Template worker isolation was lost.');
    }
    $root = str_replace('\\', '/', storage_path('framework/testing')).'/';
    $normalized = str_replace('\\', '/', $ready);
    if (! str_starts_with($normalized, $root) || str_contains(substr($normalized, strlen($root)), '/')
        || preg_match('/^'.preg_quote($token, '/').'-template-version-[a-f0-9-]{36}-[01]\.ready$/D', basename($normalized)) !== 1) {
        throw new RuntimeException('Template barrier is outside the parent run.');
    }
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
    $actor = User::query()->findOrFail((int) $actorId);
    Auth::login($actor);
    $template = ItProvisioningTemplate::query()->findOrFail((int) $templateId);
    $waiting = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($ready, &$waiting): void {
        if (! $waiting && str_contains($sql, '`it_provisioning_templates`') && str_contains(strtolower($sql), 'for update')) {
            $waiting = true;
            if (file_put_contents($ready, 'waiting') === false) {
                throw new RuntimeException('Template barrier write failed.');
            }
        }
    });
    try {
        if ($operation === 'edit') {
            $tasks = $template->tasks()->get()->map(fn ($task) => $task->only(array_diff($task->getFillable(), ['provisioning_template_id'])))->all();
            $tasks[0]['title'] = 'Concurrent '.$label.' version '.$expectedVersion;
            $tasks[0]['description'] = 'Instructions for '.$tasks[0]['title'];
            $saved = app(ItProvisioningTemplateService::class)->update($template, $actor, [
                ...$template->only(['name', 'description', 'lifecycle_type', 'position_role', 'site_id', 'employment_type', 'selection_priority', 'is_active']),
                'expected_version' => (int) $expectedVersion, 'tasks' => $tasks,
            ]);
            $result = ['version_id' => $saved->current_version_id];
        } else {
            $profile = HrEmployeeProfile::query()->findOrFail((int) $profileId);
            $workflow = app(ItProvisioningWorkflowService::class)->launch($profile, 'joiner', 'synthetic_race',
                (int) $profile->id, 'template-race:'.basename($ready), (int) $actor->id);
            $result = ['workflow_id' => $workflow->id, 'version_id' => $workflow->template_version_id];
        }
        $status = 'committed';
    } catch (ValidationException $exception) {
        if (! isset($exception->errors()['expected_version'])) {
            throw $exception;
        }
        $status = 'stale';
        $result = [];
    }
    echo json_encode(['status' => $status, 'waiting' => $waiting, 'operation' => $operation,
        'completed_at' => microtime(true), 'transaction_level' => DB::transactionLevel(), ...$result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Template worker failed: '.$exception::class.PHP_EOL);
    exit(1);
}
