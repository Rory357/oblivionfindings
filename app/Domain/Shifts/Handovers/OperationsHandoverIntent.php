<?php

namespace App\Domain\Shifts\Handovers;

use Illuminate\Support\Facades\Validator;

/** Immutable original intent. Credentials are one-shot transport proof, never part of the journal or digest. */
final class OperationsHandoverIntent
{
    private function __construct(
        public readonly string $requestId,
        public readonly string $action,
        public readonly int $shiftId,
        public readonly ?int $handoverId,
        public readonly ?int $expectedVersion,
        public readonly string $expectedSource,
        public readonly array $fields,
        public readonly string $hash,
    ) {}

    public static function fromBody(string $action, int $shiftId, ?int $handoverId, array $body): self
    {
        abort_unless(in_array($action, ['create', 'update', 'submit'], true) && $shiftId > 0
            && ($action === 'create' ? $handoverId === null : $handoverId !== null && $handoverId > 0), 422);
        $keys = array_keys($body); sort($keys);
        abort_unless($keys === ['expected_source', 'expected_version', 'fields', 'intent_hash', 'request_id'], 422);
        abort_unless(is_string($body['request_id']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['request_id']) === 1
            && is_string($body['expected_source']) && preg_match('/^[0-9a-f]{64}$/D', $body['expected_source']) === 1
            && is_string($body['intent_hash']) && preg_match('/^[0-9a-f]{64}$/D', $body['intent_hash']) === 1
            && ($action === 'create' ? $body['expected_version'] === null : is_int($body['expected_version']) && $body['expected_version'] > 0)
            && is_array($body['fields']) && ($action === 'submit' ? $body['fields'] === [] : !array_is_list($body['fields'])), 422);
        $fields = $body['fields'];
        if ($action === 'submit') { abort_unless($fields === [], 422); }
        else {
            $allowed = ['handover_notes', 'client_mood', 'incoming_shift_id', 'incoming_staff_id', 'medications_due_text',
                'incidents_to_note_text', 'follow_up_items_text', 'tasks_pending_text', 'cd_result', 'cd_witness_id', 'cd_notes', 'submit'];
            abort_unless(array_diff(array_keys($fields), $allowed) === [] && array_key_exists('submit', $fields) && is_bool($fields['submit']), 422);
            foreach (['incoming_shift_id', 'incoming_staff_id', 'cd_witness_id'] as $key) {
                abort_unless(!array_key_exists($key, $fields) || $fields[$key] === null || is_int($fields[$key]) && $fields[$key] > 0, 422);
            }
            foreach (array_diff($allowed, ['incoming_shift_id', 'incoming_staff_id', 'cd_witness_id', 'submit']) as $key) {
                abort_unless(!array_key_exists($key, $fields) || $fields[$key] === null || is_string($fields[$key]), 422);
            }
        }
        $canonical = ['action' => $action, 'shift_id' => $shiftId, 'handover_id' => $handoverId,
            'expected_version' => $body['expected_version'], 'expected_source' => $body['expected_source'], 'fields' => $action === 'submit' ? new \stdClass : $fields];
        $hash = hash('sha256', self::json($canonical));
        abort_unless(hash_equals($hash, $body['intent_hash']), 422);
        return new self($body['request_id'], $action, $shiftId, $handoverId, $body['expected_version'], $body['expected_source'], $fields, $hash);
    }

    public function validateFields(): void
    {
        if ($this->action === 'submit') { return; }
        Validator::make($this->fields, ['handover_notes' => ['required', 'string', 'max:5000'], 'client_mood' => ['nullable', 'string', 'max:255'],
            'cd_result' => ['nullable', 'in:verified,discrepancy'], 'cd_notes' => ['nullable', 'string', 'max:2000']])->validate();

        Validator::make($this->fields, array_fill_keys(['medications_due_text', 'incidents_to_note_text', 'follow_up_items_text', 'tasks_pending_text'], ['nullable', 'string']))->validate();
    }

    public function identity(int $actorId): array
    {
        return ['version' => 1, 'actor_id' => $actorId, 'request_id' => $this->requestId, 'action' => $this->action,
            'shift_id' => $this->shiftId, 'requested_handover_id' => $this->handoverId, 'expected_version' => $this->expectedVersion,
            'expected_source' => $this->expectedSource, 'intent_hash' => $this->hash];
    }

    public function data(?string $credential): array
    {
        if ($this->action === 'submit') { return []; }
        $data = $this->fields;
        foreach (['medications_due', 'incidents_to_note', 'follow_up_items', 'tasks_pending'] as $key) {
            if (array_key_exists($key.'_text', $data)) {
                $data[$key] = collect(preg_split('/\r\n|\r|\n/', (string) ($data[$key.'_text'] ?? '')))
                    ->map(fn ($line) => trim((string) $line))->filter(fn ($line) => $line !== '')->map(fn ($line) => ['label' => $line])->values()->all();
                unset($data[$key.'_text']);
            }
        }
        $data['expected_version'] = $this->expectedVersion;
        $data['cd_verification_input'] = ['result' => $data['cd_result'] ?? null, 'witness_id' => $data['cd_witness_id'] ?? null,
            'notes' => $data['cd_notes'] ?? null, 'witness_credential' => $credential];
        unset($data['cd_result'], $data['cd_witness_id'], $data['cd_notes']);
        return $data;
    }

    /** Object keys sort recursively; finite JSON primitives only. This is not an AFTER codec. */
    public static function json(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if ($item instanceof \stdClass && get_object_vars($item) === []) { return $item; }
            if (is_array($item)) { if (!array_is_list($item)) { ksort($item); } return array_map($sort, $item); }
            if ($item !== null && !is_string($item) && !is_bool($item) && !is_int($item)) { throw new \LogicException('Unsupported handover intent value.'); }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    public function __debugInfo(): array { return ['kind' => 'private_operations_handover_intent']; }
    public function __serialize(): array { throw new \LogicException('Private handover intent cannot be serialized.'); }
    private function __clone() {}
}
