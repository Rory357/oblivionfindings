<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketApprovalCommandResult;
use App\Domain\It\Data\ItTicketApprovalInput;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketCommandReceipt;
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

/** Original approval intent and outcome in the existing command receipt inventory. */
final class ItTicketApprovalCommandService
{
    public function __construct(private readonly ItTicketApprovalService $approvals, private readonly ItTicketVersionService $versions) {}

    public function execute(ItTicket $ticket, User $actor, string $operation, array $input, ?ItTicketApproval $approval = null): ItTicketApprovalCommandResult
    {
        $prepared = null;
        $hash = null;
        $connection = DB::connection();
        $outerLevel = $connection->transactionLevel();
        try {
            return DB::transaction(function () use ($ticket, $actor, $operation, $input, $approval, &$prepared, &$hash): ItTicketApprovalCommandResult {
                [$locked, $current] = $this->approvals->lockContext($ticket, $actor, mutation: false);
                $this->validateIdentity($current, $operation, $input);
                $this->requireReceiptStorage();
                $target = $this->target($locked, $current, $operation, $approval?->id);
                $payload = ItTicketApprovalInput::normalize($operation, $input);
                $hash = hash('sha256', json_encode(['ticket_id' => (int) $locked->id,
                    'approval_id' => $target?->id, 'operation' => $operation,
                    'expected_version' => (int) $input['expected_version'], 'payload' => $payload], JSON_THROW_ON_ERROR));
                $receipt = $this->receipt($current, $operation, $input['request_uuid'])->lockForUpdate()->first();
                if ($receipt) {
                    $result = $this->replay($locked, $current, $operation, $target?->id, $receipt);
                    if ($result->status !== 'cancelled' && ! hash_equals($receipt->request_hash, $hash)) {
                        throw new ItTicketCommandConflict;
                    }

                    return $prepared = $result;
                }
                $this->versions->assertCurrent($locked, (int) $input['expected_version']);
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $current->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => 'approval.'.$operation, 'request_uuid' => $input['request_uuid'], 'request_hash' => $hash,
                ]);
                $result = match ($operation) {
                    'request' => $this->approvals->request($locked, $current, $payload['reason'], $payload),
                    'withdraw' => $this->approvals->withdraw($target, $current, $payload['reason']),
                    default => $this->approvals->decide($target, $current, $payload['decision'], $payload['reason']),
                };
                $locked->refresh();
                $receipt->forceFill(['it_ticket_id' => $locked->id, 'committed_at' => now(),
                    'committed_ticket_version' => $locked->lock_version,
                    'result_metadata' => ['state' => 'committed', 'approval_id' => (int) $result->id,
                        'approval_status' => $result->status, 'changed' => true],
                ])->save();

                return $prepared = $this->replay($locked, $current, $operation, $target?->id, $receipt, replayed: false);
            });
        } catch (Throwable $exception) {
            // Only exact original hash/target proof from the current primary can
            // resolve a post-commit failure. Absence never authorizes a new intent.
            if ($prepared !== null && $hash !== null && $outerLevel === 0) {
                try {
                    $configuration = $connection->getConfig();
                    $configuration['database'] = $connection->getDatabaseName();
                    $name = 'it_approval_recovery_'.bin2hex(random_bytes(8));
                    $configuration['name'] = $name;
                    if (isset($configuration['write'])) {
                        $configuration['write']['name'] = $name;
                    }
                    try {
                        DB::connectUsing($name, $configuration)->useWriteConnectionWhenReading();
                        $confirmed = DB::usingConnection($name, fn () => DB::transaction(function () use ($ticket, $actor, $operation, $input, $approval, $hash, $prepared) {
                            [$locked, $current] = $this->approvals->lockContext($ticket, $actor, mutation: false);
                            $this->target($locked, $current, $operation, $approval?->id);
                            $receipt = $this->receipt($current, $operation, $input['request_uuid'])->lockForUpdate()->first();
                            if (! $receipt || ! hash_equals($receipt->request_hash, $hash)) {
                                return null;
                            }
                            $result = $this->replay($locked, $current, $operation, $approval?->id, $receipt);

                            return $result->status === $prepared->status
                                && $result->data['approval_id'] === $prepared->data['approval_id'] ? $result : null;
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
                    // Preserve the original unknown outcome, without leaking payloads.
                }
            }
            throw $exception;
        }
    }

    public function recover(ItTicket $ticket, User $actor, string $operation, string $uuid, ?int $approvalId = null): ItTicketApprovalCommandResult
    {
        return DB::transaction(function () use ($ticket, $actor, $operation, $uuid, $approvalId): ItTicketApprovalCommandResult {
            [$locked, $current] = $this->approvals->lockContext($ticket, $actor, mutation: false);
            $this->validateLookup($operation, $uuid);
            $this->requireReceiptStorage();
            $this->target($locked, $current, $operation, $approvalId);

            return $this->replay($locked, $current, $operation, $approvalId,
                $this->receipt($current, $operation, $uuid)->lockForUpdate()->firstOrFail());
        });
    }

    public function cancel(ItTicket $ticket, User $actor, string $operation, string $uuid, int $originalActorId, ?int $approvalId = null): ItTicketApprovalCommandResult
    {
        return DB::transaction(function () use ($ticket, $actor, $operation, $uuid, $originalActorId, $approvalId): ItTicketApprovalCommandResult {
            [$locked, $current] = $this->approvals->lockContext($ticket, $actor, mutation: false);
            if ((int) $current->id !== $originalActorId) {
                throw new AuthorizationException('Recover this approval command using its original account.');
            }
            $this->validateLookup($operation, $uuid);
            $this->requireReceiptStorage();
            $this->target($locked, $current, $operation, $approvalId);
            $receipt = $this->receipt($current, $operation, $uuid)->lockForUpdate()->first();
            $replayed = $receipt !== null;
            if (! $receipt) {
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $current->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => 'approval.'.$operation, 'request_uuid' => $uuid,
                    'request_hash' => hash('sha256', json_encode(['cancelled', (int) $locked->id, $operation, $approvalId], JSON_THROW_ON_ERROR)),
                    'it_ticket_id' => $locked->id,
                    'result_metadata' => ['state' => 'cancelled', 'approval_id' => $approvalId, 'cancelled_at' => now()->toIso8601String()],
                ]);
                AuditLogger::logOrFail('it.ticket.approval.command.cancelled', $locked, [
                    'actor_id' => $current->id, 'approval_id' => $approvalId, 'request_uuid' => $uuid,
                    'operation' => 'approval.'.$operation, 'application_scope' => 'single_application',
                ]);
            }

            return $this->replay($locked, $current, $operation, $approvalId, $receipt, $replayed);
        });
    }

    private function replay(ItTicket $ticket, User $actor, string $operation, ?int $requestedApprovalId, ItTicketCommandReceipt $receipt, bool $replayed = true): ItTicketApprovalCommandResult
    {
        $metadata = $receipt->result_metadata ?? [];
        $approvalId = $metadata['approval_id'] ?? null;
        $cancelled = ($metadata['state'] ?? null) === 'cancelled';
        if ((int) $receipt->it_ticket_id !== (int) $ticket->id
            || ($operation !== 'request' && $approvalId !== $requestedApprovalId)
            || ($cancelled && $operation === 'request' && $approvalId !== null)) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }
        if ($approvalId !== null) {
            ItTicketApproval::query()->whereKey($approvalId)->where('it_ticket_id', $ticket->id)->firstOrFail();
        }
        $identity = ['id' => (int) $ticket->id, 'viewer_user_id' => (int) $actor->id,
            'request_uuid' => $receipt->request_uuid, 'operation' => 'approval.'.$operation,
            'approval_id' => $approvalId, 'replayed' => $replayed];
        if ($cancelled) {
            if ($receipt->committed_at !== null || ! is_string($metadata['cancelled_at'] ?? null)) {
                throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
            }

            return new ItTicketApprovalCommandResult('cancelled', [...$identity, 'cancelled_at' => $metadata['cancelled_at']]);
        }
        if ($receipt->committed_at === null || (int) $receipt->committed_ticket_version < 1
            || ! is_int($approvalId) || ($metadata['changed'] ?? null) !== true
            || ! in_array($metadata['approval_status'] ?? null, match ($operation) {
                'request' => ['pending'], 'withdraw' => ['cancelled'], default => ['approved', 'rejected'],
            }, true)) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        return new ItTicketApprovalCommandResult('committed', [...$identity,
            'approval_status' => $metadata['approval_status'], 'lock_version' => (int) $receipt->committed_ticket_version, 'changed' => true]);
    }

    private function target(ItTicket $ticket, User $actor, string $operation, ?int $approvalId): ?ItTicketApproval
    {
        if ($operation === 'request') {
            if ($approvalId !== null) {
                throw (new ModelNotFoundException)->setModel(ItTicketApproval::class);
            }

            return null;
        }
        $approval = ItTicketApproval::query()->whereKey($approvalId)->where('it_ticket_id', $ticket->id)->firstOrFail();
        if ($operation === 'decide' && (int) $approval->requested_by === (int) $actor->id) {
            throw new AuthorizationException('You cannot decide your own approval request.');
        }

        return $approval;
    }

    private function receipt(User $actor, string $operation, string $uuid): Builder
    {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', ItTicketCommandReceipt::CHANNEL)->where('operation', 'approval.'.$operation)->where('request_uuid', $uuid);
    }

    private function validateIdentity(User $actor, string $operation, array $input): void
    {
        $this->validateLookup($operation, (string) ($input['request_uuid'] ?? ''));
        Validator::make($input, ['actor_user_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1']])->validate();
        if ((int) $actor->id !== (int) $input['actor_user_id']) {
            throw new AuthorizationException('Recover this approval command using its original account.');
        }
    }

    private function validateLookup(string $operation, string $uuid): void
    {
        Validator::make(['operation' => $operation, 'request_uuid' => $uuid], [
            'operation' => ['required', Rule::in(ItTicketApprovalInput::OPERATIONS)], 'request_uuid' => ['required', 'uuid'],
        ])->validate();
    }

    private function requireReceiptStorage(): void
    {
        if (! Schema::hasColumns('it_ticket_command_receipts', ['result_metadata', 'committed_ticket_version'])) {
            throw ValidationException::withMessages(['form' => 'Approval command recovery setup is incomplete. Keep your proposal and retry after setup is complete.']);
        }
    }
}
