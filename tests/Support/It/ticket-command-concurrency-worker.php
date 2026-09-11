<?php

use App\Domain\It\Data\ItTicketCommentCancellationResult;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItSavedTicketFilterService;
use App\Domain\It\Services\ItTicketDraftAttachmentService;
use App\Domain\It\Services\ItTicketDraftService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Models\ItTicket;
use App\Models\ItTicketDraft;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// This worker never prepares or deletes a schema. It can only join the exact
// random schema already owned by its standalone parent verification process.
if (getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('The concurrency worker requires its parent isolated test schema.');
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')
    || config('mail.default') !== 'array'
    || config('mail.mailers.array.transport') !== 'array'
    || config('queue.default') !== 'sync'
    || ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
    throw new RuntimeException('The concurrency worker did not retain notification and database isolation.');
}
Notification::fake();

[$script, $operation, $actorId, $uuid, $ticketId, $version, $ready, $attempt, $release] = $argv;
if (! in_array($operation, ['create', 'priority-high', 'priority-urgent', 'filter-limit-a', 'filter-limit-b', 'filter-same',
    'draft-initialize', 'draft-save-a', 'draft-save-b', 'draft-discard', 'draft-create', 'draft-upload',
    'comment', 'comment-draft', 'comment-cancel', 'resolve', 'watch-self', 'unwatch-self', 'watch-requester'], true)) {
    throw new RuntimeException('Unexpected isolated concurrency operation.');
}
if (str_starts_with($operation, 'draft-')) {
    require_once __DIR__.'/draft-concurrency-filesystem.php';
    configureItDraftConcurrencyStorage();
}
$barrierRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing')).DIRECTORY_SEPARATOR;
foreach ([$ready, $attempt, $release] as $path) {
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (! str_starts_with($normalized, $barrierRoot) || str_contains(substr($normalized, strlen($barrierRoot)), DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Concurrency barriers must remain inside the test storage directory.');
    }
}
$actor = User::query()->findOrFail((int) $actorId);
$watcherInput = in_array($operation, ['watch-self', 'unwatch-self', 'watch-requester'], true)
    ? ['id' => $operation === 'watch-requester'
        ? (int) ItTicket::query()->findOrFail((int) $ticketId)->requester_user_id : (int) $actor->id,
        'watching' => $operation !== 'unwatch-self']
    : null;
$commentInput = null;
if (in_array($operation, ['comment', 'comment-draft'], true)) {
    $commentInput = ['request_uuid' => $uuid, 'actor_user_id' => $actor->id, 'expected_version' => (int) $version,
        'body' => 'Concurrent isolated public reply', 'is_internal' => false];
    if ($operation === 'comment-draft') {
        config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
        $draft = ItTicketDraft::query()->where('actor_user_id', $actor->id)->where('it_ticket_id', (int) $ticketId)
            ->where('purpose', ItTicketDraftPurpose::PublicReply->value)->sole();
        $commentInput += ['draft_uuid' => $draft->draft_uuid, 'draft_revision' => $draft->revision, 'draft_actor_user_id' => $actor->id];
    }
}
touch($ready);
$deadline = microtime(true) + 30;
while (! is_file($release)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('The parent did not release the concurrency workers.');
    }
    usleep(10_000);
}
$attemptedAt = microtime(true);
touch($attempt);

try {
    if ($watcherInput !== null) {
        $result = app(ItTicketInteractionService::class)->setWatcher(ItTicket::query()->findOrFail((int) $ticketId),
            $actor, $watcherInput['id'], $watcherInput['watching'], (int) $version);
        $output = ['status' => 'committed', 'version' => (int) $result->ticket->lock_version,
            'watcher_id' => $result->watcherId, 'watching' => $result->watching, 'changed' => $result->changed];
    } elseif ($commentInput !== null) {
        $result = app(ItTicketInteractionService::class)->addCommentCommand(ItTicket::query()->findOrFail((int) $ticketId), $actor, $commentInput);
        $output = $result instanceof ItTicketCommentCancellationResult
            ? ['status' => 'cancelled', 'id' => $result->ticket->id]
            : ['status' => 'committed', 'id' => $result->ticket->id, 'comment_id' => $result->comment->id,
                'version' => $result->committedVersion, 'replayed' => $result->replayed];
    } elseif ($operation === 'comment-cancel') {
        $result = app(ItTicketInteractionService::class)->cancelCommentCommand(ItTicket::query()->findOrFail((int) $ticketId), $actor, $uuid, false);
        $output = ['status' => $result instanceof ItTicketCommentCancellationResult ? 'cancelled' : 'committed', 'id' => $result->ticket->id];
    } elseif ($operation === 'resolve') {
        $result = app(ItTicketInteractionService::class)->resolveWithPublicNote(ItTicket::query()->findOrFail((int) $ticketId),
            $actor, 'Concurrent isolated resolution evidence', (int) $version, resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic connection verified.']);
        $output = ['status' => 'committed', 'id' => $result->id, 'version' => $result->lock_version];
    } elseif (str_starts_with($operation, 'draft-')) {
        config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
        $drafts = app(ItTicketDraftService::class);
        if ($operation === 'draft-upload') {
            $result = app(ItTicketDraftAttachmentService::class)->upload($actor, $uuid, (int) $version,
                Uuid::uuid5($uuid, 'isolated-staged-file')->toString(),
                UploadedFile::fake()->createWithContent('concurrent-fixture.txt', 'Concurrent isolated file bytes'));
            $output = ['status' => 'uploaded', 'attachment_id' => $result['attachment']['id'], 'revision' => $result['draft']['revision']];
        } elseif ($operation === 'draft-initialize') {
            $result = $drafts->initialize($actor, ItTicketDraftPurpose::PublicReply, (int) $ticketId, null);
            $output = ['status' => 'initialized', 'draft_uuid' => $result['draft_uuid']];
        } elseif ($operation === 'draft-discard') {
            $result = $drafts->discard($actor, $uuid, (int) $version);
            $output = ['status' => 'discarded', 'revision' => $result['revision']];
        } elseif ($operation === 'draft-create') {
            $draft = ItTicketDraft::query()->where('actor_user_id', $actor->id)->where('draft_uuid', $uuid)->sole();
            $result = app(ItTicketIntakeService::class)->createCommand($actor, [
                'request_uuid' => $draft->request_uuid, 'title' => 'Initial intake snapshot', 'category' => 'hardware', 'priority' => 'normal',
                'draft_uuid' => $uuid, 'draft_revision' => (int) $version, 'draft_actor_user_id' => $actor->id,
            ]);
            $output = ['status' => 'committed', 'id' => $result->ticket->id];
        } else {
            $result = $drafts->save($actor, $uuid, (int) $version,
                [(int) $ticketId > 0 ? 'body' : 'title' => $operation.' revision '.$version], 0, null);
            $output = ['status' => 'saved', 'revision' => $result['revision']];
        }
    } elseif ($operation === 'create') {
        $result = app(ItTicketIntakeService::class)->createCommand($actor, [
            'request_uuid' => $uuid,
            'title' => 'Isolated simultaneous browser command',
            'description' => 'A local concurrency fixture, never real support work.',
            'category' => 'hardware', 'priority' => 'normal',
        ]);
        $output = ['status' => 'committed', ...$result->toArray()];
    } elseif (str_starts_with($operation, 'filter-')) {
        $saved = app(ItSavedTicketFilterService::class)->store($actor, $uuid.' '.$operation, ['ticket_status' => 'open']);
        $output = ['status' => 'committed', 'id' => $saved->id];
    } else {
        $ticket = app(ItTicketTriageService::class)->update(ItTicket::query()->findOrFail((int) $ticketId), $actor, [
            'expected_version' => (int) $version,
            'priority' => substr($operation, strlen('priority-')),
            'priority_reason' => 'Concurrent isolated triage assessment for the service interruption.',
        ]);
        $output = ['status' => 'committed', 'version' => $ticket->lock_version, 'priority' => $ticket->priority];
    }
} catch (ItTicketVersionConflict $conflict) {
    $output = ['status' => 'stale_ticket', 'version' => $conflict->current['lock_version']];
} catch (ItTicketDraftException $exception) {
    $output = ['status' => $exception->errorCode];
} catch (ValidationException $exception) {
    $output = ['status' => 'validation_error', 'errors' => $exception->errors()];
}

echo json_encode([...$output, 'attempted_at' => $attemptedAt, 'completed_at' => microtime(true)], JSON_THROW_ON_ERROR);
