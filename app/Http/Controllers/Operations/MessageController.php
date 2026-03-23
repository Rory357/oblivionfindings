<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('messages.viewAny'), 403);

        $conversationIds = OpsConversationParticipant::query()
            ->where('user_id', $auth->id)
            ->pluck('conversation_id');

        $conversations = OpsConversation::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->whereIn('id', $conversationIds)
            ->with([
                'latestMessage:id,conversation_id,sender_id,content,created_at',
                'latestMessage.sender:id,name',
                'participants.user:id,name',
                'client:id,first_name,last_name',
            ])
            ->withCount([
                'messages as unread_count' => function ($q) use ($auth) {
                    $q->where('sender_id', '!=', $auth->id)
                        ->whereDoesntHave('conversation.participants', function ($pq) use ($auth) {
                            $pq->where('user_id', $auth->id)
                                ->whereColumn('ops_conversation_participants.last_read_at', '>=', 'ops_messages.created_at');
                        });
                },
            ])
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        return inertia('operations/messages/Index', [
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, $conversation)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('messages.view'), 403);

        $conversation = OpsConversation::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'participants.user:id,name',
                'client:id,first_name,last_name',
            ])
            ->findOrFail($conversation);

        // Verify user is a participant
        $isParticipant = $conversation->participants->contains('user_id', $auth->id);
        abort_unless($isParticipant, 403);

        $messages = OpsMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with(['sender:id,name'])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return inertia('operations/messages/Show', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, $conversation)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('messages.create'), 403);

        $conversation = OpsConversation::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($conversation);

        // Verify user is a participant
        $isParticipant = OpsConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $auth->id)
            ->exists();
        abort_unless($isParticipant, 403);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'message_type' => ['nullable', 'string', 'max:50'],
        ]);

        OpsMessage::create([
            'organization_id' => $auth->organization_id,
            'conversation_id' => $conversation->id,
            'sender_id' => $auth->id,
            'sender_type' => 'user',
            'message_type' => $data['message_type'] ?? 'text',
            'content' => $data['content'],
        ]);

        $conversation->touch();

        return redirect()->back();
    }

    public function markRead(Request $request, $conversation)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('messages.view'), 403);

        $participant = OpsConversationParticipant::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', $auth->id)
            ->firstOrFail();

        $participant->update(['last_read_at' => now()]);

        return redirect()->back();
    }
}
