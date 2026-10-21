<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Services\Chat\ChatSendAttempts;
use Illuminate\Http\Request;

final class ChatSendAttemptController extends Controller
{
    public function store(Request $request, OpsConversation $conversation, ChatSendAttempts $attempts)
    {
        $input = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'draft_version' => ['required', 'integer', 'min:1', 'max:9007199254740000'],
            'draft_boundary' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'audience_token' => ['nullable', 'string', 'size:64'],
        ]);
        $input['draft_version'] = (int) $input['draft_version'];
        $input['audience_token'] ??= null;

        return response()->json($attempts->reserve($conversation, $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, OpsConversation $conversation, string $mutation, ChatSendAttempts $attempts)
    {
        return response()->json($attempts->read($conversation, $request->user(), $mutation))->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, OpsConversation $conversation, string $mutation, ChatSendAttempts $attempts)
    {
        return response()->json($attempts->cancel($conversation, $request->user(), $mutation))->header('Cache-Control', 'private, no-store');
    }
}
