<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItWorkTaskCommandResult;
use App\Domain\It\Data\ItWorkTaskInput;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItWorkTask;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Task receipts reuse the existing actor/operation UUID inventory and canonical writer. */
final class ItWorkTaskCommandService
{
    public function __construct(private readonly ItWorkTaskService $tasks, private readonly ItTicketVersionService $versions) {}

    public function execute(ItTicket $ticket, User $actor, string $operation, array $input, ?ItWorkTask $task = null): ItWorkTaskCommandResult
    {
        $prepared = null;
        $connection = DB::connection();
        $outerLevel = $connection->transactionLevel();
        $hash = null;
        try {
            return DB::transaction(function () use ($ticket, $actor, $operation, $input, $task, &$prepared, &$hash): ItWorkTaskCommandResult {
                [$locked, $current] = $this->tasks->lockContext($ticket, $actor, $input, mutation: false);
                $this->validateIdentity($current, $operation, $input);
                $this->requireReceiptStorage();
                $target = $this->target($locked, $operation, $task?->id);
                $payload = ItWorkTaskInput::normalize($operation, $input);
                $hash = hash('sha256', json_encode([
                    'ticket_id' => (int) $locked->id, 'operation' => $operation, 'task_id' => $target?->id,
                    'expected_version' => (int) $input['expected_version'], 'payload' => $payload,
                ], JSON_THROW_ON_ERROR));
                $receipt = $this->receipt($current, $operation, $input['request_uuid'])->lockForUpdate()->first();
                if ($receipt) {
                    $result = $this->replay($locked, $current, $operation, $target?->id, $receipt);
                    if ($result->status !== 'cancelled' && ! hash_equals($receipt->request_hash, $hash)) {
                        throw new ItTicketCommandConflict;
                    }

                    return $prepared = $result;
                }
                $this->versions->assertCurrent($locked, (int) $input['expected_version']);
                $before = (int) $locked->lock_version;
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $current->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => 'task.'.$operation, 'request_uuid' => $input['request_uuid'], 'request_hash' => $hash,
                ]);
                $changedTask = match ($operation) {
                    'create' => $this->tasks->create($locked, $current, $payload),
                    'update' => $this->tasks->update($locked, $target, $current, $payload),
                    'complete' => $this->tasks->complete($locked, $target, $current, $payload),
                    'reopen' => $this->tasks->reopen($locked, $target, $current, $payload['reason']),
                    'reorder' => $this->tasks->reorder($locked, $current, $payload['ordered_ids']),
                };
                $locked->refresh();
                $changed = (int) $locked->lock_version !== $before;
                $taskId = $changedTask instanceof ItWorkTask ? (int) $changedTask->id : null;
                $receipt->forceFill([
                    'it_ticket_id' => $locked->id, 'committed_ticket_version' => $locked->lock_version,
                    'committed_at' => now(), 'result_metadata' => [
                        'state' => 'committed', 'task_id' => $taskId, 'changed' => $changed,
                        ...($operation === 'reorder' ? ['ordered_ids' => $payload['ordered_ids']] : []),
                    ],
                ])->save();

                return $prepared = $this->replay($locked, $current, $operation, $target?->id, $receipt, replayed: false);
            });
        } catch (Throwable $exception) {
            // As with intake/replies, after-commit failure is not rollback proof.
            // A separate primary read may confirm the old command; absence never
            // creates a replacement task or changes its immutable identity.
            if ($prepared !== null && $outerLevel === 0 && $hash !== null) {
                try {
                    $configuration = $connection->getConfig();
                    $configuration['database'] = $connection->getDatabaseName();
                    $name = 'it_task_recovery_'.bin2hex(random_bytes(8));
                    $configuration['name'] = $name;
                    if (isset($configuration['write'])) {
                        $configuration['write']['name'] = $name;
                    }
                    try {
                        DB::connectUsing($name, $configuration)->useWriteConnectionWhenReading();
                        $confirmed = DB::usingConnection($name, fn () => DB::transaction(function () use ($ticket, $actor, $operation, $input, $task, $hash, $prepared) {
                            [$locked, $current] = $this->tasks->lockContext($ticket, $actor, mutation: false);
                            $this->target($locked, $operation, $task?->id);
                            $receipt = $this->receipt($current, $operation, $input['request_uuid'])->lockForUpdate()->first();
                            if (! $receipt || ! hash_equals($receipt->request_hash, $hash)) {
                                return null;
                            }
                            $result = $this->replay($locked, $current, $operation, $task?->id, $receipt);

                            return $result->status === $prepared->status
                                && $result->data['task_id'] === $prepared->data['task_id'] ? $result : null;
                        }));

                        if ($confirmed !== null) {
                            return $confirmed;
                        }
                    } finally {
                        DB::purge($name);
                    }
                } catch (AuthorizationException|ModelNotFoundException $denied) {
                    throw $denied;
                } catch (Throwable) {
                    // Preserve the original uncertain failure if proof is unavailable.
                }
            }
            throw $exception;
        }
    }

    public function recover(ItTicket $ticket, User $actor, string $operation, string $uuid, ?int $taskId = null): ItWorkTaskCommandResult
    {
        return DB::transaction(function () use ($ticket, $actor, $operation, $uuid, $taskId): ItWorkTaskCommandResult {
            [$locked, $current] = $this->tasks->lockContext($ticket, $actor, mutation: false);
            $this->validateLookup($operation, $uuid);
            $this->requireReceiptStorage();
            $this->target($locked, $operation, $taskId);
            $receipt = $this->receipt($current, $operation, $uuid)->lockForUpdate()->firstOrFail();

            return $this->replay($locked, $current, $operation, $taskId, $receipt);
        });
    }

    public function cancel(ItTicket $ticket, User $actor, string $operation, string $uuid, int $originalActorId, ?int $taskId = null): ItWorkTaskCommandResult
    {
        return DB::transaction(function () use ($ticket, $actor, $operation, $uuid, $originalActorId, $taskId): ItWorkTaskCommandResult {
            [$locked, $current] = $this->tasks->lockContext($ticket, $actor, mutation: false);
            if ((int) $current->id !== $originalActorId) {
                throw new AuthorizationException('Recover this task command using its original account.');
            }
            $this->validateLookup($operation, $uuid);
            $this->requireReceiptStorage();
            $this->target($locked, $operation, $taskId);
            $receipt = $this->receipt($current, $operation, $uuid)->lockForUpdate()->first();
            if (! $receipt) {
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $current->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => 'task.'.$operation, 'request_uuid' => $uuid,
                    'request_hash' => hash('sha256', json_encode(['cancelled', (int) $locked->id, $operation, $taskId], JSON_THROW_ON_ERROR)),
                    'it_ticket_id' => $locked->id,
                    'result_metadata' => ['state' => 'cancelled', 'task_id' => $taskId, 'cancelled_at' => now()->toIso8601String()],
                ]);
                AuditLogger::logOrFail('it.ticket.task.command.cancelled', $locked, [
                    'actor_id' => $current->id, 'task_id' => $taskId, 'request_uuid' => $uuid,
                    'operation' => 'task.'.$operation, 'application_scope' => 'single_application',
                ]);

                return $this->replay($locked, $current, $operation, $taskId, $receipt, replayed: false);
            }

            return $this->replay($locked, $current, $operation, $taskId, $receipt);
        });
    }

    private function replay(ItTicket $ticket, User $actor, string $operation, ?int $requestedTaskId, ItTicketCommandReceipt $receipt, bool $replayed = true): ItWorkTaskCommandResult
    {
        $metadata = $receipt->result_metadata ?? [];
        $taskId = $metadata['task_id'] ?? null;
        $cancelled = ($metadata['state'] ?? null) === 'cancelled';
        if ((int) $receipt->it_ticket_id !== (int) $ticket->id
            || (! in_array($operation, ['create', 'reorder'], true) && $taskId !== $requestedTaskId)
            || ($operation === 'reorder' && $taskId !== null)
            || ($cancelled && $operation === 'create' && $taskId !== null)) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }
        if ($taskId !== null) {
            ItWorkTask::query()->whereKey($taskId)->where('ticket_id', $ticket->id)->firstOrFail();
        }
        $identity = ['id' => (int) $ticket->id, 'viewer_user_id' => (int) $actor->id,
            'request_uuid' => $receipt->request_uuid, 'operation' => 'task.'.$operation,
            'task_id' => $taskId, 'replayed' => $replayed];
        if ($cancelled) {
            if ($receipt->committed_at !== null || ! is_string($metadata['cancelled_at'] ?? null)) {
                throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
            }

            return new ItWorkTaskCommandResult('cancelled', [...$identity, 'cancelled_at' => $metadata['cancelled_at']]);
        }
        if ($receipt->committed_at === null || (int) $receipt->committed_ticket_version < 1
            || ! is_bool($metadata['changed'] ?? null) || ($operation !== 'reorder' && ! is_int($taskId))) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        return new ItWorkTaskCommandResult('committed', [...$identity,
            'lock_version' => (int) $receipt->committed_ticket_version, 'changed' => $metadata['changed']]);
    }

    private function target(ItTicket $ticket, string $operation, ?int $taskId): ?ItWorkTask
    {
        if (in_array($operation, ['create', 'reorder'], true)) {
            if ($taskId !== null) {
                throw (new ModelNotFoundException)->setModel(ItWorkTask::class);
            }

            return null;
        }

        return ItWorkTask::query()->whereKey($taskId)->where('ticket_id', $ticket->id)->firstOrFail();
    }

    private function receipt(User $actor, string $operation, string $uuid): Builder
    {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', ItTicketCommandReceipt::CHANNEL)->where('operation', 'task.'.$operation)->where('request_uuid', $uuid);
    }

    private function validateIdentity(User $actor, string $operation, array $input): void
    {
        $this->validateLookup($operation, (string) ($input['request_uuid'] ?? ''));
        Validator::make($input, ['actor_user_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1']])->validate();
        if ((int) $actor->id !== (int) $input['actor_user_id']) {
            throw new AuthorizationException('Recover this task command using its original account.');
        }
    }

    private function validateLookup(string $operation, string $uuid): void
    {
        Validator::make(['operation' => $operation, 'request_uuid' => $uuid], [
            'operation' => ['required', Rule::in(ItWorkTaskInput::OPERATIONS)],
            'request_uuid' => ['required', 'uuid'],
        ])->validate();
    }

    private function requireReceiptStorage(): void
    {
        if (! Schema::hasColumns('it_ticket_command_receipts', ['result_metadata', 'committed_ticket_version'])) {
            throw ValidationException::withMessages(['form' => 'Task command recovery setup is incomplete. Keep your work and retry after setup is complete.']);
        }
    }
}
