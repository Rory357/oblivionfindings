<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Domain\Shifts\Handovers\OperationsHandoverIntent;
use App\Domain\Shifts\Handovers\OperationsHandoverRoot;
use App\Models\AuditLog;
use App\Models\OperationsHandoverAttempt;
use App\Models\OperationsHandoverCompletion;
use App\Models\OperationsHandoverWitness;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Operations-only original-attempt protocol. No global shared-service binding. */
final class OperationsHandoverCommands
{
    public function __construct(private OperationsCheckedHandoverService $writer, private WorkforceMutationGuard $guard) {}

    public function source(User $actor, int $shiftId, ?int $rowId = null): array
    {
        abort_unless(OperationsHandoverReadiness::available(), 503);
        $root = OperationsHandoverRoot::open(DB::connection());
        try {
            $this->guard->lock();
            $read = $this->writer->current($actor, $shiftId, $rowId);
            $root->commit(fn () => $this->writer->assertRead($read));
        } finally { $root->closeWithoutCommit(); }
        // Ordinary committed callbacks can withdraw access. A fresh current read is mandatory.
        return $this->readCurrent($actor, $shiftId, $rowId)['descriptor'];
    }

    /** $intent is the original immutable command; a repeated UUID never invokes the writer. */
    public function execute(User $actor, OperationsHandoverIntent $intent, ?string $credential): array
    {
        $identity = $intent->identity((int) $actor->id);
        $unknown = $this->unknown($identity);
        if (! OperationsHandoverReadiness::available()) { return ['status' => 202, 'body' => $unknown]; }
        $root = null; $reserved = false; $seen = false;
        try {
            $this->keys(); // Missing signing configuration must not start a writer.
            $root = OperationsHandoverRoot::open(DB::connection());
            $this->guard->lock();
            $old = $this->attempt((int) $actor->id, $intent->requestId);
            $seen = $old !== null;
            if ($seen) {
                $root->closeWithoutCommit(); $root = null;
                $result = $this->lookup($actor, $identity)['result'];
                return ['status' => $result === null ? 202 : 200, 'body' => $result ?? $unknown];
            }
            $read = $this->writer->preflight($actor, $intent, $credential);
            // Once reservation callbacks begin, failure is conservatively unknown.
            // A committed listener may throw after the actual reservation commit;
            // that cannot be reclassified as a first pre-writer refusal.
            $reserved = true;
            $attempt = new OperationsHandoverAttempt;
            $attempt->forceFill(array_diff_key($identity, ['version' => true]));
            $attemptProof = OperationsHandoverRecords::write($attempt);
            $root->commit(function () use ($read, $attemptProof): void {
                OperationsHandoverRecords::verify($attemptProof);
                $this->writer->assertRead($read);
            });
            $root = null;

            // The immutable reservation survives a failed/ambiguous domain invocation.
            // No repeat request may create a second domain execution, even after rollback.
            $root = OperationsHandoverRoot::open(DB::connection());
            $this->guard->lock();
            OperationsHandoverRecords::verify($attemptProof);
            $checked = $this->writer->executeChecked($actor, $intent, $credential);
            $receipt = $checked['receipt'];
            $this->validateSaved($identity, $receipt);
            $completion = new OperationsHandoverCompletion;
            $completion->forceFill(['attempt_id' => (int) $attempt->id, 'handover_id' => $receipt['handover_id'],
                'receipt' => $receipt, 'intended_after_hash' => $checked['intended_after_hash']]);
            $completionProof = OperationsHandoverRecords::write($completion);
            $audit = new AuditLog;
            $audit->forceFill(['user_id' => $identity['actor_id'], 'client_id' => $read['ids']['client'],
                'action' => 'operations.handover.command.completed', 'auditable_type' => OperationsHandoverAttempt::class,
                'auditable_id' => (int) $attempt->id, 'meta' => $identity, 'ip_address' => null, 'user_agent' => null]);
            $auditProof = OperationsHandoverRecords::write($audit);
            $verify = function () use ($checked, $attemptProof, $completionProof, $auditProof): void {
                OperationsHandoverRecords::verify($attemptProof);
                OperationsHandoverRecords::verify($completionProof);
                OperationsHandoverRecords::verify($auditProof);
                ($checked['verify'])(); // Native field/source/RBAC/timeline checks after all normal callbacks.
            };
            $root->commit($verify); $root = null;

            // A prepared result is insufficient. Issuance is reachable only after the retained
            // physical root returned committed and closed. Failure here stays unconfirmed.
            $root = OperationsHandoverRoot::open(DB::connection());
            $this->guard->lock();
            $verify();
            $this->writer->current($actor, $intent->shiftId, $receipt['handover_id']);
            $verify();
            $key = $this->keys()[0];
            $witness = new OperationsHandoverWitness;
            $witness->forceFill(['attempt_id' => (int) $attempt->id, 'key_id' => $key['id'],
                'signature' => hash_hmac('sha256', $this->witnessBody($identity, $receipt, $checked['intended_after_hash']), $key['key'])]);
            $witnessProof = OperationsHandoverRecords::write($witness);
            $root->commit(function () use ($verify, $witnessProof): void {
                OperationsHandoverRecords::verify($witnessProof); $verify();
            }); $root = null;
            $result = $this->lookup($actor, $identity)['result'];
            return ['status' => $result === null ? 202 : 200, 'body' => $result ?? $unknown];
        } catch (\Throwable $error) {
            $closed = false;
            if ($root !== null) { try { $root->closeWithoutCommit(); $closed = true; } catch (\Throwable) {} }
            // Read/Site/direct-object denial must withdraw private form access,
            // not become an editable 409 refusal. This branch is only before
            // reservation with an originally absent UUID and proven owned rollback.
            if (! $reserved && ! $seen && $closed && $error instanceof HttpException
                && in_array($error->getStatusCode(), [403, 404], true)) {
                return ['status' => $error->getStatusCode(), 'body' => $unknown];
            }
            // Only a first, pre-writer refusal with proven owned rollback permits editing.
            // Generic HTTP errors, reused UUIDs and anything after reservation are not no-write proof.
            if (! $reserved && ! $seen && $closed && ($error instanceof ValidationException || $error instanceof HttpException)) {
                return ['status' => $error instanceof ValidationException ? 422 : 409,
                    'body' => ['version' => 1, 'scope' => 'operations_handover_command', 'identity' => $identity,
                        'outcome' => 'refused', 'saved' => null, 'errors' => $this->safeErrors($error)]];
            }
            return ['status' => 202, 'body' => $unknown];
        } finally {
            if ($root !== null) { try { $root->closeWithoutCommit(); } catch (\Throwable) {} }
        }
    }

    /** GET only: original identity and historical witness, plus separately current read authority. */
    public function lookup(User $actor, array $identity): array
    {
        abort_unless(OperationsHandoverReadiness::available(), 503);
        abort_unless($identity['actor_id'] === (int) $actor->id, 404);
        $root = OperationsHandoverRoot::open(DB::connection());
        try {
            $this->guard->lock();
            $read = $this->writer->current($actor, $identity['shift_id'], $identity['requested_handover_id']);
            $attempt = $this->attempt((int) $actor->id, $identity['request_id']);
            $result = null; $proofRows = [];
            if ($attempt !== null && $this->identityFor($attempt) === $identity) {
                $completions = OperationsHandoverRecords::rows('operations_handover_completions', 'attempt_id', [(int) $attempt['id']]);
                $witnesses = OperationsHandoverRecords::rows('operations_handover_witnesses', 'attempt_id', [(int) $attempt['id']]);
                $proofRows = [$attempt, $completions, $witnesses];
                if (count($completions) === 1 && count($witnesses) === 1) {
                    try {
                        $receipt = json_decode($completions[0]['receipt'], true, flags: JSON_THROW_ON_ERROR);
                        $this->validateSaved($identity, $receipt);
                        $hash = $completions[0]['intended_after_hash'];
                        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) { throw new \RuntimeException('Unsupported completion.'); }
                        foreach ($this->keys() as $key) {
                            if (hash_equals($key['id'], $witnesses[0]['key_id'])
                                && hash_equals(hash_hmac('sha256', $this->witnessBody($identity, $receipt, $hash), $key['key']), $witnesses[0]['signature'])) {
                                $result = ['version' => 1, 'scope' => 'operations_handover_command', 'identity' => $identity,
                                    'outcome' => 'confirmed', 'saved' => $receipt];
                            }
                        }
                    } catch (\Throwable) { $result = null; }
                }
            }
            $root->commit(function () use ($read, $identity, $proofRows): void {
                $this->writer->assertRead($read);
                if ($proofRows !== []) {
                    $row = $this->attempt($identity['actor_id'], $identity['request_id']);
                    abort_unless([$row,
                        OperationsHandoverRecords::rows('operations_handover_completions', 'attempt_id', [(int) $row['id']]),
                        OperationsHandoverRecords::rows('operations_handover_witnesses', 'attempt_id', [(int) $row['id']])] === $proofRows, 409);
                }
            });
        } finally { $root->closeWithoutCommit(); }
        $currentRead = $this->readCurrent($actor, $identity['shift_id'], $identity['requested_handover_id']);
        if ($proofRows !== []) {
            $this->postCommitCheck(function () use ($identity, $proofRows, $currentRead): void {
                $row = $this->attempt($identity['actor_id'], $identity['request_id']);
                abort_unless($row !== null && [$row,
                    OperationsHandoverRecords::rows('operations_handover_completions', 'attempt_id', [(int) $row['id']]),
                    OperationsHandoverRecords::rows('operations_handover_witnesses', 'attempt_id', [(int) $row['id']])] === $proofRows, 409);
                $this->writer->assertRead($currentRead); // Current cohort and historical proof share the final retained PDO snapshot.
            });
        }
        return ['version' => 1, 'scope' => 'operations_handover_attempt', 'identity' => $identity, 'result' => $result, 'current' => $currentRead['descriptor']];
    }

    private function readCurrent(User $actor, int $shiftId, ?int $rowId): array
    {
        $root = OperationsHandoverRoot::open(DB::connection());
        try {
            $this->guard->lock();
            $read = $this->writer->current($actor, $shiftId, $rowId);
            $root->commit(fn () => $this->writer->assertRead($read));
        } finally { $root->closeWithoutCommit(); }
        $this->postCommitCheck(fn () => $this->writer->assertRead($read));
        return $read;
    }

    /** Same-PDO raw final read after ordinary committed callbacks; no model or connection events. */
    private function postCommitCheck(\Closure $verify): void
    {
        $connection = DB::connection(); $pdo = $connection->getPdo();
        abort_unless($connection->transactionLevel() === 0 && ! $pdo->inTransaction(), 409);
        if (! $pdo->beginTransaction()) { throw new \RuntimeException('Final handover read unavailable.'); }
        try {
            $verify();
            abort_unless($connection->getPdo() === $pdo && $connection->transactionLevel() === 0 && $pdo->inTransaction(), 409);
        } finally {
            if ($connection->getPdo() === $pdo && $connection->transactionLevel() === 0 && $pdo->inTransaction()) {
                if (! $pdo->rollBack()) { throw new \RuntimeException('Final handover read closure unavailable.'); }
            } else { throw new \RuntimeException('Final handover read ownership unavailable.'); }
        }
    }

    private function attempt(int $actorId, string $uuid): ?array
    {
        return OperationsHandoverRecords::equalRow('operations_handover_attempts', ['actor_id' => $actorId, 'request_id' => $uuid]);
    }

    private function identityFor(array $row): array
    {
        return ['version' => 1, 'actor_id' => (int) $row['actor_id'], 'request_id' => $row['request_id'], 'action' => $row['action'],
            'shift_id' => (int) $row['shift_id'], 'requested_handover_id' => $row['requested_handover_id'] === null ? null : (int) $row['requested_handover_id'],
            'expected_version' => $row['expected_version'] === null ? null : (int) $row['expected_version'],
            'expected_source' => $row['expected_source'], 'intent_hash' => $row['intent_hash']];
    }

    private function validateSaved(array $identity, mixed $saved): void
    {
        if (! is_array($saved)) { throw new \RuntimeException('Invalid receipt.'); }
        $keys = array_keys($saved); sort($keys);
        if ($keys !== ['handover_id', 'saved_at', 'shift_id', 'status', 'version']
            || ! is_int($saved['handover_id']) || $saved['handover_id'] < 1 || ! is_int($saved['version']) || $saved['version'] < 1
            || $saved['shift_id'] !== $identity['shift_id'] || ! in_array($saved['status'], ['draft', 'submitted'], true)
            || ! is_string($saved['saved_at']) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $saved['saved_at']) !== 1
            || ($identity['requested_handover_id'] !== null && $saved['handover_id'] !== $identity['requested_handover_id'])
            || ($identity['expected_version'] !== null && $saved['version'] <= $identity['expected_version'])
            || ($identity['action'] === 'submit' && $saved['status'] !== 'submitted')) { throw new \RuntimeException('Invalid receipt.'); }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $saved['saved_at'], new \DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $saved['saved_at']) { throw new \RuntimeException('Invalid receipt instant.'); }
    }

    private function witnessBody(array $identity, array $receipt, string $hash): string
    { return OperationsHandoverIntent::json(['domain' => 'operations_handover_checked_commit_v1', 'identity' => $identity, 'saved' => $receipt, 'intended_after_hash' => $hash]); }

    private function keys(): array
    {
        $result = [];
        foreach ([(string) config('app.key'), ...config('app.previous_keys', [])] as $value) {
            $secret = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
            if (! is_string($secret) || strlen($secret) !== 32) { throw new \LogicException('Handover witness key is unavailable.'); }
            $key = hash_hmac('sha256', 'operations_handover_checked_commit_v1', $secret, true);
            $result[] = ['key' => $key, 'id' => hash('sha256', $key)];
        }
        return $result;
    }

    private function unknown(array $identity): array
    { return ['version' => 1, 'scope' => 'operations_handover_command', 'identity' => $identity, 'outcome' => 'unconfirmed', 'saved' => null]; }

    private function safeErrors(\Throwable $error): array
    {
        // No arbitrary server message, name or source record is reflected into the wizard.
        $copy = ['handover_notes' => 'Enter handover notes within 5,000 characters.', 'client_mood' => 'Check the mood entry.',
            'incoming_shift_id' => 'Choose the incoming shift before submitting.', 'incoming_staff_id' => 'Choose the incoming worker.',
            'cd_result' => 'Choose the controlled-drug count result.', 'cd_witness_id' => 'Check the witnessing worker.',
            'cd_witness_credential' => 'Check the witness PIN.', 'cd_notes' => 'Describe the discrepancy within 2,000 characters.',
            'medications_due_text' => 'Check this entry.', 'incidents_to_note_text' => 'Check this entry.',
            'follow_up_items_text' => 'Check this entry.', 'tasks_pending_text' => 'Check this entry.'];
        $errors = $error instanceof ValidationException ? array_intersect_key($copy, $error->errors()) : [];
        if ($error instanceof ValidationException) {
            // The eMAR witness checks word their own messages for staff ("Incorrect PIN", when a
            // locked PIN unlocks, a missing competency). The shared handover save shows them; keep them.
            foreach (['cd_witness_id', 'cd_witness_credential'] as $key) {
                $message = $error->errors()[$key][0] ?? null;
                if (isset($errors[$key]) && is_string($message) && trim($message) !== '' && mb_strlen($message) <= 300) {
                    $errors[$key] = $message;
                }
            }
        }
        return $errors ?: ['handover' => 'This handover changed after you opened it. Refresh it, then check your entries.'];
    }
}
