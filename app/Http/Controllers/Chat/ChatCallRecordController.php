<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\ClientNote;
use App\Models\FamilyNote;
use App\Services\Chat\ChatAccess;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatCallRecordController extends Controller
{
    public function store(Request $request, ChatCall $call, ChatAccess $access, ClientFamilyCommunicationAccess $family)
    {
        $data = $request->validate([
            'summary' => ['required', 'string', 'max:10000'],
            'publish_family_note' => ['required', 'boolean'],
            'family_summary' => ['nullable', 'required_if:publish_family_note,true', 'string', 'max:5000'],
            'audience_confirmed' => ['sometimes', 'boolean'],
        ]);
        abort_if($data['publish_family_note'] && ! ($data['audience_confirmed'] ?? false), 422, 'Review and confirm the Family Note audience before publishing.');

        return DB::transaction(function () use ($request, $call, $access, $family, $data) {
            $call = ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
            $conversation = $call->conversation;
            $access->authorize($request->user(), $conversation, true);
            $participant = $call->participants()->where('user_id', $request->user()->id)->firstOrFail();
            abort_unless(($call->ended_at || $participant->left_at) && $conversation->client && $conversation->conversation_type === 'family', 422, 'Finish your participation in this family call before saving a summary.');
            abort_unless($family->canManage($request->user(), $conversation->client), 403);
            $this->authorize('view', $conversation->client);
            $this->authorize('create', ClientNote::class);
            $existing = DB::table('chat_call_records')->where('call_id', $call->id)->where('user_id', $request->user()->id)->first();
            if ($existing) {
                return response()->json(['client_note_id' => $existing->client_note_id, 'family_note_id' => $existing->family_note_id, 'already_saved' => true]);
            }
            $note = ClientNote::create([
                'client_id' => $conversation->client_id, 'user_id' => $request->user()->id,
                'type' => 'communication', 'category' => 'communication', 'subject' => 'Family voice call',
                'body' => $data['summary'], 'contact_method' => 'phone', 'occurred_at' => $call->connected_at ?? $call->created_at,
                'visibility' => 'internal', 'is_private' => false, 'is_draft' => false, 'appears_on_timeline' => true,
            ]);
            $familyNote = null;
            if ($data['publish_family_note']) {
                $familyNote = FamilyNote::create([
                    'client_id' => $conversation->client_id, 'created_by' => $request->user()->id,
                    'title' => 'Update following our call', 'description' => $data['family_summary'],
                    'note_type' => 'note', 'priority' => 'normal', 'status' => 'open', 'visibility' => 'portal',
                    'meta' => ['chat_call_id' => $call->id, 'audience_reviewed_by' => $request->user()->id, 'audience_reviewed_at' => now()->toISOString()],
                ]);
            }
            DB::table('chat_call_records')->insert(['call_id' => $call->id, 'user_id' => $request->user()->id, 'client_note_id' => $note->id, 'family_note_id' => $familyNote?->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => $request->user()->id, 'client_id' => $conversation->client_id, 'action' => 'chat.call.summary_saved', 'meta' => ['call_id' => $call->id, 'client_note_id' => $note->id, 'family_note_id' => $familyNote?->id]]);

            return response()->json(['client_note_id' => $note->id, 'family_note_id' => $familyNote?->id, 'already_saved' => false], 201);
        });
    }
}
