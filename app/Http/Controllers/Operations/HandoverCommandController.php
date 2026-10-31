<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Domain\Shifts\Handovers\OperationsHandoverIntent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Operations\OperationsHandoverCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Authenticated Operations opt in. Existing eMAR and Attendance draft routes are untouched. */
final class HandoverCommandController extends Controller
{
    public function __construct(private OperationsHandoverCommands $commands) {}

    public function source(Request $request, string $shift): JsonResponse
    {
        $row = $request->query('handover_id');
        return $this->json($this->commands->source($this->actor($request), $this->id($shift), $row === null ? null : $this->id($row)));
    }

    public function create(Request $request, string $shift): JsonResponse
    { return $this->command($request, 'create', $this->id($shift), null); }

    public function update(Request $request, string $shift, string $handover): JsonResponse
    { return $this->command($request, 'update', $this->id($shift), $this->id($handover)); }

    public function submit(Request $request, string $shift, string $handover): JsonResponse
    { return $this->command($request, 'submit', $this->id($shift), $this->id($handover)); }

    public function lookup(Request $request, string $shift, string $requestId): JsonResponse
    {
        $actor = $this->actor($request);
        $keys = array_keys($request->query()); sort($keys);
        abort_unless($keys === ['action', 'expected_source', 'expected_version', 'intent_hash', 'requested_handover_id'], 422);
        $action = $request->query('action');
        $row = $request->query('requested_handover_id'); $version = $request->query('expected_version');
        $source = $request->query('expected_source'); $hash = $request->query('intent_hash');
        abort_unless(in_array($action, ['create', 'update', 'submit'], true)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId) === 1
            && is_string($source) && preg_match('/^[a-f0-9]{64}$/D', $source) === 1
            && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 422);
        $rowId = $row === 'none' ? null : $this->id($row);
        $expectedVersion = $version === 'none' ? null : $this->id($version);
        abort_unless($action === 'create' ? $rowId === null && $expectedVersion === null : $rowId !== null && $expectedVersion !== null, 422);
        $identity = ['version' => 1, 'actor_id' => (int) $actor->id, 'request_id' => $requestId, 'action' => $action,
            'shift_id' => $this->id($shift), 'requested_handover_id' => $rowId, 'expected_version' => $expectedVersion,
            'expected_source' => $source, 'intent_hash' => $hash];
        return $this->json($this->commands->lookup($actor, $identity));
    }

    private function command(Request $request, string $action, int $shiftId, ?int $rowId): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($request->isJson() && strlen($request->getContent()) <= 1048576, 422);
        // Decode actual JSON bytes: web TrimStrings must not change the hashed clinical text.
        try { $object = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { abort(422); }
        abort_unless($object instanceof \stdClass && ($object->fields ?? null) instanceof \stdClass, 422);
        $body = (array) $object;
        $body['fields'] = (array) $object->fields;
        $credential = $body['cd_witness_credential'] ?? null;
        abort_unless($credential === null || is_string($credential), 422);
        unset($body['cd_witness_credential']);
        $intent = OperationsHandoverIntent::fromBody($action, $shiftId, $rowId, $body);
        $result = $this->commands->execute($actor, $intent, $credential);
        return $this->json($result['body'], $result['status']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    private function id(mixed $value): int
    {
        abort_unless(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && strlen($value) <= strlen((string) PHP_INT_MAX)
            && (strlen($value) < strlen((string) PHP_INT_MAX) || strcmp($value, (string) PHP_INT_MAX) <= 0), 404);
        return (int) $value;
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }
}
