<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsMessage;
use App\Services\Chat\ChatMessageEditing;
use App\Services\Chat\ChatProjection;
use Illuminate\Http\Request;

final class ChatMessageEditController extends Controller
{
    public function update(Request $request, OpsMessage $message, ChatMessageEditing $editing, ChatProjection $projection)
    {
        $data = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'content' => ['present', 'nullable', 'string', 'max:10000'],
            'mentions' => ['present', 'array', 'max:30'], 'mentions.*' => ['array:user_id,start,length'],
            'mentions.*.user_id' => ['required', 'integer', 'min:1'],
            'mentions.*.start' => ['required', 'integer', 'between:0,10000'],
            'mentions.*.length' => ['required', 'integer', 'between:2,256'],
            'files' => ['prohibited'], 'attachments' => ['prohibited'], 'reply_to_id' => ['prohibited'],
        ]);

        return response()->json(['message' => $projection->message($editing->edit($request->user(), $message, $data), $request->user())])->header('Cache-Control', 'private, no-store');
    }
}
