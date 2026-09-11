<?php

use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketApprovalCommandService;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('This worker can only join its parent isolated approval schema.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array' || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync' || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('Approval worker database or notification isolation differs.');
}
Notification::fake();
[$script, $operation, $actorId, $uuid, $ticketId, $version, $approvalId, $ready, $attempt, $release] = $argv;
$primaryId = (int) ($argv[10] ?? 0);
if (! in_array($operation, ['request', 'approve', 'reject', 'withdraw', 'timing', 'cancel-request', 'resolve'], true)) {
    throw new RuntimeException('Unexpected approval race operation.');
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot)
        || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)
        || ! str_starts_with(basename($normalized), 'it-approval-concurrency-'.getenv('TEST_TOKEN').'-')) {
        throw new RuntimeException('Approval barriers must stay inside the exact parent test scope.');
    }
}
$actor = User::query()->findOrFail((int) $actorId);
$ticket = ItTicket::query()->findOrFail((int) $ticketId);
$approval = (int) $approvalId > 0 ? ItTicketApproval::query()->where('it_ticket_id', $ticket->id)->findOrFail((int) $approvalId) : null;
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent did not release approval workers.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);
try {
    if ($operation === 'resolve') {
        $saved = app(ItTicketInteractionService::class)->resolveWithPublicNote($ticket, $actor, 'Synthetic approval settlement race', (int) $version, resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic approved work checked.']);
        $output = ['status' => 'committed', 'data' => ['lock_version' => (int) $saved->lock_version]];
    } elseif ($operation === 'timing') {
        $output = ['status' => app(ItTicketApprovalService::class)->processTiming($approval->id)];
    } else {
        $service = app(ItTicketApprovalCommandService::class);
        $result = $operation === 'cancel-request'
            ? $service->cancel($ticket, $actor, 'request', $uuid, (int) $actor->id)
            : $service->execute($ticket, $actor, in_array($operation, ['request', 'withdraw'], true) ? $operation : 'decide', [
                'actor_user_id' => $actor->id, 'request_uuid' => $uuid, 'expected_version' => (int) $version,
                'reason' => $operation === 'request' ? 'Synthetic concurrent approval request' : 'Synthetic concurrent decision',
                ...($operation === 'request' ? ['primary_approver_user_id' => $primaryId] : []),
                ...(in_array($operation, ['request', 'withdraw'], true) ? [] : ['decision' => $operation]),
            ], $approval);
        $output = $result->toArray();
    }
} catch (ItTicketVersionConflict $exception) {
    $output = ['status' => 'stale_ticket', 'data' => ['lock_version' => $exception->current['lock_version']]];
} catch (DomainException) {
    $output = ['status' => 'approval_blocked'];
}
echo json_encode([...$output, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
