<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsMessage;
use App\Services\Chat\ChatDocument;
use App\Services\Chat\ChatMessageEditing;
use App\Services\Chat\ChatProjection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatMessageEditController extends Controller
{
    public function update(Request $request, OpsMessage $message, ChatMessageEditing $editing, ChatProjection $projection, ChatDocument $documents)
    {
        // A formatted edit is the source of its text: content and mentions are written from it.
        $document = $documents->requested($request);
        $data = $request->validate([
            'mutation_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'content' => [Rule::excludeIf($document !== null), 'present', 'nullable', 'string', 'max:10000'],
            'mentions' => [Rule::excludeIf($document !== null), 'present', 'array', 'max:30'], 'mentions.*' => ['array:user_id,start,length'],
            'mentions.*.user_id' => ['required', 'integer', 'min:1'],
            'mentions.*.start' => ['required', 'integer', 'between:0,10000'],
            'mentions.*.length' => ['required', 'integer', 'between:2,256'],
            'files' => ['prohibited'], 'attachments' => ['prohibited'], 'reply_to_id' => ['prohibited'],
        ]);
        if ($document !== null) {
            $data['document'] = $document;
        }

        return response()->json(['message' => $projection->message($editing->edit($request->user(), $message, $data), $request->user())])->header('Cache-Control', 'private, no-store');
    }
}
