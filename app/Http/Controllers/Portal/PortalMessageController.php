<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use Illuminate\Http\Request;

class PortalMessageController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $conversations = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with(['latestMessage', 'participants.user:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($convo) => [
                'id' => $convo->id,
                'title' => $convo->title,
                'updated_at' => $convo->updated_at?->toISOString(),
                'is_archived' => $convo->is_archived,
                'latest_message' => $convo->latestMessage ? [
                    'id' => $convo->latestMessage->id,
                    'content' => $convo->latestMessage->content,
                    'sender_type' => $convo->latestMessage->sender_type,
                    'created_at' => $convo->latestMessage->created_at?->toISOString(),
                ] : null,
                'participants' => $convo->participants->map(fn ($p) => [
                    'id' => $p->user?->id,
                    'name' => $p->user?->name,
                ])->filter(fn ($p) => $p['id'] !== null)->values(),
            ]);

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'conversations' => $conversations->values(),
        ]);
    }

    public function show(Request $request, Client $client, OpsConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        // Verify user is a participant of this conversation
        $isParticipant = $conversation->participants()
            ->where('user_id', $user->id)
            ->exists();
        abort_unless($isParticipant, 403);

        // Verify conversation belongs to the client
        abort_unless($conversation->client_id === $client->id, 403);

        $messages = $conversation->messages()
            ->with('sender:id,name,profile_photo_path')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_id' => $msg->sender_id,
                'sender_type' => $msg->sender_type,
                'sender' => $msg->sender ? [
                    'id' => $msg->sender->id,
                    'name' => $msg->sender->name,
                    'avatar' => $msg->sender->avatar,
                ] : null,
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        // Mark unread messages as read
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'is_read' => true]);

        $participants = $conversation->participants()
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->user?->id,
                'name' => $p->user?->name,
            ])
            ->filter(fn ($p) => $p['id'] !== null)
            ->values();

        return inertia('portal/messages/show', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'is_archived' => $conversation->is_archived,
            ],
            'messages' => $messages->values(),
            'participants' => $participants,
        ]);
    }

    public function storeMessage(Request $request, Client $client, OpsConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $isParticipant = $conversation->participants()
            ->where('user_id', $user->id)
            ->exists();
        abort_unless($isParticipant, 403);
        abort_unless($conversation->client_id === $client->id, 403);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        OpsMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'family',
            'content' => $validated['content'],
            'message_type' => 'text',
            'client_id' => $client->id,
        ]);

        $conversation->update(['updated_at' => now()]);

        return redirect()->back();
    }

    public function startConversation(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'content' => 'required|string|max:5000',
        ]);

        $conversation = OpsConversation::create([
            'title' => $validated['title'] ?? 'Family Message',
            'conversation_type' => 'family',
            'client_id' => $client->id,
        ]);

        // Add the family member as a participant
        OpsConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        // Add the client's key worker as a participant if one exists
        $client->load('keyWorker');
        if ($client->key_worker_id) {
            OpsConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $client->key_worker_id,
            ]);
        }

        // Create the first message
        OpsMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'family',
            'content' => $validated['content'],
            'message_type' => 'text',
            'client_id' => $client->id,
        ]);

        return redirect()->route('portal.messages.show', [$client, $conversation]);
    }
}
