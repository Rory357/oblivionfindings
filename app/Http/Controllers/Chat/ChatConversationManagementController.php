<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Services\Chat\ChatConversationManagement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatConversationManagementController extends Controller
{
    public function update(Request $request, OpsConversation $conversation, ChatConversationManagement $management)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['rename', 'remove', 'transfer', 'leave'])],
            'version' => ['required', 'integer', 'min:0'],
            'title' => ['required_if:action,rename', 'string', 'max:200'],
            'user_id' => ['required_if:action,remove,transfer', 'nullable', 'integer', 'min:1'],
        ]);
        if (isset($data['user_id'])) {
            $data['user_id'] = (int) $data['user_id'];
        }
        $management->change($request->user(), $conversation, $data);

        return response()->noContent();
    }
}
