<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Services\Chat\ChatPrivateDrafts;
use Illuminate\Http\Request;

final class ChatPrivateDraftController extends Controller
{
    public function show(Request $request, OpsConversation $conversation, ChatPrivateDrafts $drafts)
    {
        return response()->json($drafts->read($conversation, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, OpsConversation $conversation, ChatPrivateDrafts $drafts)
    {
        $input = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0', 'max:9007199254740000'],
            'boundary' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'draft' => ['present', 'nullable', 'array:content,reply_to_id,mentions,uploads'],
            'draft.content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'draft.reply_to_id' => ['nullable', 'integer', 'min:1'],
            'draft.mentions' => ['sometimes', 'array', 'max:30'], 'draft.mentions.*' => ['array:user_id,start,length'],
            'draft.mentions.*.user_id' => ['required', 'integer', 'min:1'],
            'draft.mentions.*.start' => ['required', 'integer', 'between:0,10000'], 'draft.mentions.*.length' => ['required', 'integer', 'between:2,256'],
            'draft.uploads' => ['sometimes', 'array', 'max:5'], 'draft.uploads.*' => ['array:id,caption'],
            'draft.uploads.*.id' => ['required', 'uuid', 'distinct'], 'draft.uploads.*.caption' => ['nullable', 'string', 'max:1000'],
        ]);
        $input['version'] = (int) $input['version'];
        if ($input['draft'] !== null) {
            $input['draft'] = ['content' => $input['draft']['content'] ?? '', 'reply_to_id' => isset($input['draft']['reply_to_id']) ? (int) $input['draft']['reply_to_id'] : null,
                'mentions' => array_map(fn ($item) => ['user_id' => (int) $item['user_id'], 'start' => (int) $item['start'], 'length' => (int) $item['length']], $input['draft']['mentions'] ?? []),
                'uploads' => array_map(fn ($item) => ['id' => $item['id'], 'caption' => $item['caption'] ?? ''], $input['draft']['uploads'] ?? [])];
        }

        return response()->json($drafts->save($conversation, $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }
}
