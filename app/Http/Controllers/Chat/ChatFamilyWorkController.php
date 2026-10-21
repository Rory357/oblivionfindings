<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Services\Chat\ChatFamilyWork;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatFamilyWorkController extends Controller
{
    public function index(Request $request, OpsConversation $conversation, ChatFamilyWork $work)
    {
        $filters = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'through' => ['nullable', 'integer', 'min:1']]);

        return response()->json($work->page($request->user(), $conversation, $filters))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, OpsConversation $conversation, ChatFamilyWork $work)
    {
        $data = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'action' => ['required', Rule::in(['claim', 'assign', 'handoff', 'status', 'note'])],
            'version' => ['required_unless:action,note', 'integer', 'min:1'],
            'assigned_to' => ['present_if:action,assign,handoff', 'prohibited_unless:action,assign,handoff', 'nullable', 'integer', 'min:1'],
            'status' => ['required_if:action,status', 'prohibited_unless:action,status', Rule::in(['open', 'waiting', 'resolved'])],
            'note' => ['required_if:action,note', 'nullable', 'string', 'max:4000'],
        ]);
        $work->update($request->user(), $conversation, $data);

        return response()->noContent();
    }
}
