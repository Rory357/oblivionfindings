<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\BroadcastRecipientReadCommand;
use App\Models\User;
use App\Support\ControlRoom\BroadcastCommandInput;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Explicit "mark as read" for staff broadcast messages: one message, or the next batch of
 * up to 200 unread ones. Each command is keyed by (actor, command UUID) and is idempotent:
 *
 *  1. capture — a short transaction records exactly which messages the command covers
 *     (an empty bulk capture stays empty; later arrivals are never added);
 *  2. apply — a second transaction re-admits every captured message, sets read_at only
 *     where it is still null (a first read is never moved), and confirms the receipt.
 *     Any withdrawal or evidence change rolls the whole apply back; the capture survives.
 *
 * Results follow the v3 HTTP/DTO contract: {http_status, body} with a fixed envelope.
 * Never acknowledges, never touches other notification families or medication attendance.
 */
final class BroadcastRecipientReadCommandService
{
    /** Receipt keys in the order the v3 contract returns them. */
    private const RECEIPT_KEYS = ['batch_digest', 'notification_ids', 'captured_at', 'confirmed_at',
        'selected_count', 'changed_count', 'more_remain', 'more_remain_checked_at'];

    public function __construct(private readonly BroadcastRecipientReadService $reader) {}

    public function readSingle(User $authenticated, int $expectedActorId, string $notificationId, string $commandId): array
    {
        return $this->execute($authenticated, $expectedActorId, $commandId, 'single', $notificationId);
    }

    public function readBatch(User $authenticated, int $expectedActorId, string $commandId): array
    {
        return $this->execute($authenticated, $expectedActorId, $commandId, 'bulk', null);
    }

    public function invalidRequest(?User $authenticated, string $transport): array
    {
        return $this->response($authenticated, null, null, in_array($transport, ['single', 'bulk'], true) ? $transport : null,
            400, 'pre_effect', 'invalid_request');
    }

    public function reconcile(User $actor, int $expected, string $key): array
    {
        if (! $this->valid($expected, $key)) {
            return $this->invalidRequest($actor, 'reconcile');
        }
        if ((int) $actor->getKey() !== $expected) {
            return $this->response($actor, $expected, $key, null, 403, 'denied', 'actor_changed');
        }
        $operation = null;
        try {
            $data = DB::transaction(function () use ($actor, $expected, $key, &$operation): array {
                return $this->reader->commandRead($actor, function (BroadcastRecipientReadContext $context) use ($expected, $key, &$operation): array {
                    $command = $this->loadReceipt($expected, $key);
                    if ($command === null) {
                        return ['missing' => true];
                    }
                    $operation = $command->operation;
                    $this->assertIntegrity($command);
                    $references = $command->original_references;
                    $batch = $context->restoreBatch($references);
                    if ($command->state === 'confirmed') {
                        $batch->expectReadAt(DateTimeImmutable::createFromInterface($command->intended_read_at));
                        $this->assertEffects($expected, $command->result['effects']);

                        return [
                            'state' => 'confirmed',
                            'receipt' => $this->storedReceipt($command->result['receipt'] ?? null),
                            'current' => $context->unreadBatch(1)->exportReferences()['records'] !== [],
                            'at' => $this->clock()->toISOString(),
                        ];
                    }
                    if ($command->state !== 'captured') {
                        throw new LogicException('Read command persistence changed.');
                    }

                    return ['state' => 'captured', 'receipt' => $this->capturedReceipt($command)];
                });
            });
            if ($data['missing'] ?? false) {
                return $this->response($actor, $expected, $key, null, 404, 'pre_effect', 'command_not_found');
            }
            $confirmed = $data['state'] === 'confirmed';
            $response = $this->response($actor, $expected, $key, $operation, $confirmed ? 200 : 202,
                $confirmed ? 'confirmed' : 'unknown', $confirmed ? 'command_confirmed' : 'command_captured', $data['receipt']);
            if ($confirmed) {
                $response['body']['current_more_remain'] = $data['current'];
                $response['body']['current_more_remain_checked_at'] = $data['at'];
            }

            return $response;
        } catch (Throwable $failure) {
            return $this->failure($actor, $expected, $key, $operation, $failure);
        }
    }

    private function execute(User $actor, int $expected, string $key, string $operation, ?string $notification): array
    {
        if (! $this->valid($expected, $key) || ($operation === 'single' && ! BroadcastCommandInput::canonicalUuid($notification))) {
            return $this->invalidRequest($actor, $operation);
        }
        if ((int) $actor->getKey() !== $expected) {
            return $this->response($actor, $expected, $key, $operation, 403, 'denied', 'actor_changed');
        }
        $fingerprint = hash('sha256', json_encode([1, $expected, $operation, $notification], JSON_THROW_ON_ERROR));
        try {
            // 1. Capture: committed on its own, so a failed apply keeps the original selection.
            DB::transaction(function () use ($actor, $expected, $key, $operation, $notification, $fingerprint): void {
                $this->reader->commandRead($actor, function (BroadcastRecipientReadContext $context) use ($expected, $key, $operation, $notification, $fingerprint): array {
                    User::query()->whereKey($expected)->lockForUpdate()->firstOrFail();
                    $existing = $this->loadReceipt($expected, $key);
                    if ($existing !== null) {
                        $this->assertRequest($existing, $fingerprint, $operation);

                        return [];
                    }
                    $batch = $operation === 'single' ? $context->singleBatch($notification) : $context->unreadBatch(200);
                    $references = $batch->exportReferences();
                    $at = $this->clock();
                    $receipt = new BroadcastRecipientReadCommand;
                    $receipt->forceFill([
                        'actor_user_id' => $expected, 'command_id' => $key, 'schema_version' => 1,
                        'operation' => $operation, 'request_fingerprint' => $fingerprint, 'original_references' => $references,
                        'batch_digest' => $this->digest($references), 'state' => 'captured',
                        'captured_at' => $at, 'intended_read_at' => $at, 'confirmed_at' => null, 'result' => null,
                        'created_at' => $at, 'updated_at' => $at,
                    ])->saveOrFail();

                    return [];
                });
            });

            // 2. Apply: all-or-nothing over exactly the captured selection.
            $receipt = DB::transaction(function () use ($actor, $expected, $key, $operation, $fingerprint): array {
                return $this->reader->commandRead($actor, function (BroadcastRecipientReadContext $context) use ($expected, $key, $fingerprint, $operation): array {
                    $command = $this->loadReceipt($expected, $key) ?? throw new LogicException('Read command persistence changed.');
                    $this->assertRequest($command, $fingerprint, $operation);
                    $references = $command->original_references;
                    $batch = $context->restoreBatch($references);
                    $at = DateTimeImmutable::createFromInterface($command->intended_read_at);
                    $batch->expectReadAt($at);
                    if ($command->state === 'confirmed') {
                        $this->assertEffects($expected, $command->result['effects']);

                        return $this->storedReceipt($command->result['receipt'] ?? null);
                    }
                    if ($command->state !== 'captured') {
                        throw new LogicException('Read command persistence changed.');
                    }

                    $changed = 0;
                    $effects = [];
                    $firstReads = collect($references['records'])->pluck('first_read_at', 'id');
                    foreach ($batch->records() as $notice) {
                        $id = (string) $notice->getKey();
                        if ($notice->read_at !== null) {
                            // Already read: keep that first time exactly. If it was unread when captured,
                            // only another confirmed broadcast read command may have set it.
                            if ($firstReads[$id] === null) {
                                $this->assertPriorRead($expected, $id, $notice->read_at->format('Y-m-d H:i:s'));
                            }
                            $effects[] = ['id' => $id, 'read_at' => $notice->read_at->format('Y-m-d H:i:s'), 'changed' => false];

                            continue;
                        }
                        $updated = DatabaseNotification::query()->whereKey($id)->whereNull('read_at')
                            ->update(['read_at' => $at->format('Y-m-d H:i:s'), 'updated_at' => $at->format('Y-m-d H:i:s')]);
                        if ($updated !== 1) {
                            throw new LogicException('Read command persistence changed.');
                        }
                        $effects[] = ['id' => $id, 'read_at' => $at->format('Y-m-d H:i:s'), 'changed' => true];
                        $changed++;
                    }
                    $confirmedAt = $this->clock();
                    $result = [
                        'batch_digest' => $command->batch_digest,
                        'notification_ids' => array_column($references['records'], 'id'),
                        'captured_at' => $command->captured_at->toISOString(),
                        'confirmed_at' => $confirmedAt->toISOString(),
                        'selected_count' => count($references['records']),
                        'changed_count' => $changed,
                        'more_remain' => $context->unreadBatch(1)->exportReferences()['records'] !== [],
                        'more_remain_checked_at' => $confirmedAt->toISOString(),
                    ];
                    $command->forceFill(['state' => 'confirmed', 'confirmed_at' => $confirmedAt,
                        'result' => ['receipt' => $result, 'effects' => $effects], 'updated_at' => $confirmedAt])->saveOrFail();
                    $this->assertEffects($expected, $effects);

                    return $result;
                });
            });

            return $this->response($actor, $expected, $key, $operation, 200, 'confirmed', 'command_confirmed', $receipt);
        } catch (Throwable $failure) {
            return $this->failure($actor, $expected, $key, $operation, $failure);
        }
    }

    private function valid(int $expected, string $key): bool
    {
        return $expected > 0 && $expected <= 9007199254740991 && BroadcastCommandInput::canonicalUuid($key);
    }

    private function loadReceipt(int $actor, string $key): ?BroadcastRecipientReadCommand
    {
        return BroadcastRecipientReadCommand::query()->where('actor_user_id', $actor)->where('command_id', $key)->lockForUpdate()->first();
    }

    private function assertRequest(BroadcastRecipientReadCommand $command, string $fingerprint, string $operation): void
    {
        if ((int) $command->schema_version !== 1 || $command->operation !== $operation || ! hash_equals((string) $command->request_fingerprint, $fingerprint)) {
            throw new BroadcastReadCommandConflict;
        }
        $this->assertIntegrity($command);
    }

    private function assertIntegrity(BroadcastRecipientReadCommand $command): void
    {
        $references = $command->original_references;
        if (! is_array($references) || ($references['version'] ?? null) !== 1 || ! is_array($references['records'] ?? null)
            || ! array_is_list($references['records']) || count($references['records']) > 200
            || ! hash_equals((string) $command->batch_digest, $this->digest($references))) {
            throw new LogicException('Read command persistence changed.');
        }
    }

    /**
     * A confirmed receipt as first returned. A MySQL JSON column gives object keys back
     * reordered, so a replay rebuilds the fixed key order instead of echoing the stored copy.
     */
    private function storedReceipt(mixed $stored): array
    {
        if (! is_array($stored) || count($stored) !== count(self::RECEIPT_KEYS)) {
            throw new LogicException('Read command persistence changed.');
        }
        $receipt = [];
        foreach (self::RECEIPT_KEYS as $key) {
            if (! array_key_exists($key, $stored)) {
                throw new LogicException('Read command persistence changed.');
            }
            $receipt[$key] = $stored[$key];
        }

        return $receipt;
    }

    private function capturedReceipt(BroadcastRecipientReadCommand $command): array
    {
        $references = $command->original_references;

        return [
            'batch_digest' => $command->batch_digest,
            'notification_ids' => array_column($references['records'], 'id'),
            'captured_at' => $command->captured_at->toISOString(),
            'confirmed_at' => null,
            'selected_count' => count($references['records']),
            'changed_count' => null,
            'more_remain' => null,
            'more_remain_checked_at' => null,
        ];
    }

    /** A message already read when the command applied must owe that read to another confirmed command. */
    private function assertPriorRead(int $actor, string $id, string $readAt): void
    {
        $exists = BroadcastRecipientReadCommand::query()->where('actor_user_id', $actor)->where('state', 'confirmed')
            ->whereJsonContains('result->effects', ['id' => $id, 'read_at' => $readAt, 'changed' => true])->exists();
        if (! $exists) {
            throw new LogicException('Read command persistence changed.');
        }
    }

    private function assertEffects(int $actor, array $effects): void
    {
        foreach ($effects as $effect) {
            $row = DB::table('notifications')->where('id', $effect['id'])->where('notifiable_id', $actor)->first(['read_at', 'acknowledged_at']);
            $actual = $row?->read_at === null ? null : CarbonImmutable::parse($row->read_at, 'UTC')->format('Y-m-d H:i:s');
            if ($row === null || $row->acknowledged_at !== null || $actual !== $effect['read_at']) {
                throw new LogicException('Read command persistence changed.');
            }
        }
    }

    private function digest(array $references): string
    {
        return hash('sha256', BroadcastRecipientReadService::json($references));
    }

    private function clock(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private function failure(User $actor, int $expected, string $key, ?string $operation, Throwable $failure): array
    {
        if ($failure instanceof BroadcastReadCommandConflict) {
            return $this->response($actor, $expected, $key, $operation, 409, 'conflict', 'command_identity_conflict');
        }
        if ($failure instanceof HttpExceptionInterface) {
            return match ($failure->getStatusCode()) {
                403 => $this->response($actor, $expected, $key, $operation, 403, 'denied', 'current_actor_unavailable'),
                404 => $this->response($actor, $expected, $key, $operation, 404, 'denied', 'current_record_unavailable'),
                409 => $this->response($actor, $expected, $key, $operation, 409, 'conflict', 'command_source_changed'),
                default => $this->response($actor, $expected, $key, $operation, 503, 'unknown', 'command_outcome_unconfirmed'),
            };
        }
        if ($failure instanceof LogicException) {
            return $this->response($actor, $expected, $key, $operation, 409, 'conflict', 'command_source_changed');
        }
        report($failure);

        return $this->response($actor, $expected, $key, $operation, 503, 'unknown', 'command_outcome_unconfirmed');
    }

    private function response(?User $actor, ?int $expected, ?string $key, ?string $operation, int $status, string $outcome, string $code, ?array $receipt = null): array
    {
        return ['http_status' => $status, 'body' => [
            'schema_version' => 1,
            'kind' => 'broadcast_recipient_read_command',
            'actor_user_id' => $actor === null ? null : (int) $actor->getKey(),
            'expected_actor_id' => $expected,
            'command_id' => $key,
            'operation' => $operation,
            'outcome' => $outcome,
            'code' => $code,
            'checked_at' => $this->clock()->toISOString(),
            'receipt' => $receipt,
            'current_more_remain' => null,
            'current_more_remain_checked_at' => null,
        ]];
    }
}
