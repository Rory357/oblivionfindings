<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Services\Chat\ChatFamilyFollowups;
use Illuminate\Http\Request;

final class ChatFamilyFollowupController extends Controller
{
    public function review(Request $request, OpsConversation $conversation, ChatFamilyFollowups $followups)
    {
        return response()->json($followups->review($request->user(), $conversation))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, OpsConversation $conversation, ChatFamilyFollowups $followups)
    {
        $data = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'action' => ['required', 'string', 'max:5000'],
            'assigned_to' => ['required', 'integer', 'min:1'], 'due_date' => ['required', 'date_format:Y-m-d'],
            'audience_token' => ['required', 'string', 'size:64'], 'audience_confirmed' => ['required', 'accepted'],
        ]);
        $result = $followups->create($request->user(), $conversation, $data);

        return response()->json($result, $result['already_created'] ? 200 : 201);
    }
}
