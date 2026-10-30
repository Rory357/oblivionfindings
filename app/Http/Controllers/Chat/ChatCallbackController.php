<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Services\Chat\ChatCallbacks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatCallbackController extends Controller
{
    public function __construct(private readonly ChatCallbacks $callbacks) {}

    public function index(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'through' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->callbacks->page($conversation, $request->user(), $data))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate(['mutation_id' => ['required', 'uuid'], 'preferred_time' => ['required', 'string', 'max:300'], 'request_note' => ['nullable', 'string', 'max:1000']]);
        $result = $this->callbacks->request($conversation, $request->user(), $data);

        return response()->json($result, $result['already_requested'] ? 200 : 201);
    }

    public function update(Request $request, int $callback)
    {
        $data = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['assign', 'schedule', 'attempt', 'complete', 'unreachable', 'cancel'])],
            'assigned_to' => ['present_if:action,assign', 'prohibited_unless:action,assign', 'nullable', 'integer', 'min:1'],
            'agreed_time' => ['required_if:action,schedule', 'prohibited_unless:action,schedule', 'nullable', 'string', 'max:300'],
        ]);
        $this->callbacks->update($callback, $request->user(), $data);

        return response()->noContent();
    }
}
