<?php

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('This worker can only join its parent isolated task schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array' || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Task worker database or notification isolation differs.');
}
Notification::fake();
[$script, $operation, $actorId, $uuid, $ticketId, $version, $taskId, $ready, $attempt, $release] = $argv;
if (! in_array($operation, ['create', 'update', 'complete', 'reopen', 'reorder', 'cancel-create', 'resolve', 'auto-close', 'ticket-close', 'ticket-reopen', 'ticket-versioned-reopen', 'ticket-generic-reopen', 'csat-low', 'csat-high', 'confirm-resolution'], true)) {
    throw new RuntimeException('Unexpected task race operation.');
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot)
        || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Task barriers must stay inside the parent test storage directory.');
    }
}
$actor = User::query()->findOrFail((int) $actorId);
$ticket = ItTicket::query()->findOrFail((int) $ticketId);
$task = (int) $taskId > 0 ? ItWorkTask::query()->where('ticket_id', $ticket->id)->findOrFail((int) $taskId) : null;
$fields = match ($operation) {
    'create' => ['title' => 'Synthetic concurrent task', 'is_required' => false],
    'update' => ['title' => 'Synthetic concurrent task edit'],
    'complete' => ['completion_note' => 'Synthetic completion check'],
    'reopen' => ['reason' => 'Synthetic completion requires another check'],
    'reorder' => ['ordered_ids' => $ticket->tasks()->orderByDesc('sort_order')->orderByDesc('id')->pluck('id')->all()],
    default => [],
};
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent did not release task workers.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);
try {
    if (in_array($operation, ['csat-low', 'csat-high'], true)) {
        $saved = app(ItTicketInteractionService::class)->submitCsat($ticket, $actor,
            $operation === 'csat-low' ? 2 : 4, 'Synthetic overlapping rating.', (int) $version);
        $output = ['status' => 'rated', 'score' => (int) $saved->csat_score];
    } elseif ($operation === 'confirm-resolution') {
        app(ItWorkTransitionService::class)->transition($ticket, new ItTransitionInput(
            actor: $actor, to: ItWorkflowState::Closed,
            reason: 'Requester confirmed the fix.', source: 'requester_confirmation', expectedVersion: (int) $version,
        ));
        $output = ['status' => 'confirmed'];
    } elseif ($operation === 'auto-close') {
        $closed = app(ItWorkTransitionService::class)->autoCloseResolved((int) $ticket->id, now()->subDays(7), 7);
        $output = ['status' => $closed ? 'closed' : 'skipped'];
    } elseif ($operation === 'ticket-close') {
        $saved = app(ItTicketTriageService::class)->closeWithReason(
            $ticket, $actor, 'Synthetic concurrent closure.', expectedVersion: (int) $version,
        );
        $output = ['status' => 'closed', 'lock_version' => (int) $saved->lock_version];
    } elseif ($operation === 'ticket-reopen') {
        app(ItTicketInteractionService::class)->reopenWithReason($ticket, $actor, 'Synthetic competing reopen needs attention.');
        $output = ['status' => 'reopened'];
    } elseif ($operation === 'ticket-versioned-reopen') {
        app(ItTicketInteractionService::class)->reopenWithReason($ticket, $actor, 'Synthetic versioned reopen needs attention.', (int) $version);
        $output = ['status' => 'reopened'];
    } elseif ($operation === 'ticket-generic-reopen') {
        app(ItWorkTransitionService::class)->transition($ticket, new ItTransitionInput(
            actor: $actor, to: ItWorkflowState::Submitted, reason: 'Synthetic generic reopen needs attention.',
            source: 'workspace', expectedVersion: (int) $version,
        ));
        $output = ['status' => 'reopened'];
    } elseif ($operation === 'resolve') {
        $saved = app(ItTicketInteractionService::class)->resolveWithPublicNote($ticket, $actor,
            'Synthetic task settlement race', (int) $version, resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic task evidence checked.']);
        $output = ['status' => 'committed', 'data' => ['lock_version' => (int) $saved->lock_version]];
    } else {
        $service = app(ItWorkTaskCommandService::class);
        $result = $operation === 'cancel-create'
            ? $service->cancel($ticket, $actor, 'create', $uuid, (int) $actor->id)
            : $service->execute($ticket, $actor, $operation, ['actor_user_id' => $actor->id,
                'request_uuid' => $uuid, 'expected_version' => (int) $version, ...$fields], $task);
        $output = $result->toArray();
    }
} catch (ItTicketVersionConflict $exception) {
    $output = ['status' => 'stale_ticket', 'data' => ['lock_version' => $exception->current['lock_version']]];
} catch (AuthorizationException) {
    $output = ['status' => 'access_denied'];
} catch (DomainException) {
    $output = ['status' => 'task_blocked'];
}
echo json_encode([...$output, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
