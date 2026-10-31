<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Incidents\{IncidentRecordingCommandService, IncidentRecordingReconstructionValues};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentRecordingCommandController extends Controller
{
    public function __construct(private readonly IncidentRecordingCommandService $commands) {}

    public function attach(Request $request, string $incident): JsonResponse
    {
        $this->actor($request); $id = $this->incident($incident); $input = $this->body($request);
        $result = $this->commands->attach($request, $id, $input);
        return response()->json($result['response'], $result['created'] ? 201 : 200);
    }

    public function recordWindow(Request $request, string $incident): JsonResponse
    {
        $this->actor($request); $id = $this->incident($incident); $input = $this->body($request);
        $result = $this->commands->recordWindow($request, $id, $input);
        return response()->json($result['response'], $result['created'] ? 201 : 200);
    }

    public function recoverAttachment(Request $request, string $incident, string $idempotencyKey): JsonResponse
    {
        $this->actor($request); $id = $this->incident($incident); $actor = $this->recovery($request, $idempotencyKey);
        return response()->json($this->commands->recoverAttachment($request, $id, $actor, $idempotencyKey));
    }

    public function recoverWindow(Request $request, string $incident, string $idempotencyKey): JsonResponse
    {
        $this->actor($request); $id = $this->incident($incident); $actor = $this->recovery($request, $idempotencyKey);
        return response()->json($this->commands->recoverWindow($request, $id, $actor, $idempotencyKey));
    }

    private function actor(Request $request): void { abort_unless($request->user() instanceof User, 403); }

    private function incident(string $id, int $status = 404): int
    {
        abort_unless(preg_match('/\A[1-9][0-9]*\z/D', $id) === 1 && strlen($id) <= strlen((string) PHP_INT_MAX)
            && (strlen($id) < strlen((string) PHP_INT_MAX) || strcmp($id, (string) PHP_INT_MAX) <= 0), $status);
        return (int) $id;
    }

    private function recovery(Request $request, string $key): int
    {
        IncidentRecordingReconstructionValues::uuid($key, 'idempotency_key');
        // Inspect the raw query to reject repeated parameters, bracket syntax and silent PHP coercion.
        $query = $request->server->get('QUERY_STRING', '');
        abort_unless(is_string($query) && preg_match('/\Aexpected_actor_id=([1-9][0-9]*)\z/D', $query, $match) === 1, 400);
        abort_unless($request->getContent() === '' && $request->files->count() === 0, 400);
        $actor = $this->incident($match[1], 400);
        return $actor;
    }

    private function body(Request $request): array
    {
        abort_unless($request->server->get('QUERY_STRING', '') === '' && $request->files->count() === 0, 400);
        abort_unless(preg_match('~\Aapplication/json(?:\s*;\s*charset=utf-8)?\z~iD', $request->header('Content-Type', '')) === 1, 400);
        $raw = $request->getContent(); abort_unless(strlen($raw) <= 131072, 400);
        $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        abort_unless($object instanceof \stdClass, 400);
        $offset = 0; $this->uniqueMembers($raw, $offset);
        // Decode raw bytes, not Laravel's globally trimmed/coerced input bag.
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    /** Full JSON syntax is already checked; this walk rejects duplicate decoded member names at every level. */
    private function uniqueMembers(string $raw, int &$at): void
    {
        while (isset($raw[$at]) && str_contains(" \r\n\t", $raw[$at])) { $at++; }
        $start = $at; $token = $raw[$at];
        if ($token === '{' || $token === '[') {
            $object = $token === '{'; $end = $object ? '}' : ']'; $at++; $keys = [];
            while (true) {
                while (isset($raw[$at]) && str_contains(" \r\n\t", $raw[$at])) { $at++; }
                if ($raw[$at] === $end) { $at++; return; }
                if ($object) {
                    $start = $at; $this->uniqueMembers($raw, $at);
                    $key = json_decode(substr($raw, $start, $at - $start), true, 32, JSON_THROW_ON_ERROR);
                    abort_unless(!array_key_exists(':' . $key, $keys), 400); $keys[':' . $key] = true;
                    while (str_contains(" \r\n\t", $raw[$at])) { $at++; } $at++;
                }
                $this->uniqueMembers($raw, $at);
                while (isset($raw[$at]) && str_contains(" \r\n\t", $raw[$at])) { $at++; }
                if ($raw[$at] === ',') { $at++; continue; } $at++; return;
            }
        }
        if ($token === '"') {
            $at++;
            while ($raw[$at] !== '"') { if ($raw[$at] === '\\') { $at++; } $at++; } $at++; return;
        }
        while (isset($raw[$at]) && !str_contains(",]} \r\n\t", $raw[$at])) { $at++; }
    }
}
