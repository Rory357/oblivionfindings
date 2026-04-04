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
            ->with(['latestMessage', 'participants.user:id,name,last_seen_at,presence_status'])
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
                'participants' => $convo->participants->map(function ($p) {
                    $u = $p->user;
                    if (!$u) return null;
                    $presence = 'offline';
                    if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $presence = 'online';
                    elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $presence = 'away';
                    return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];
                })->filter()->values(),
            ]);

        // Support workers for "new chat" picker
        $client->load(['supportWorkers:id,name,profile_photo_path,last_seen_at,presence_status', 'keyWorker:id,name,profile_photo_path,last_seen_at,presence_status']);
        $workers = collect();
        if ($client->keyWorker) $workers->push($client->keyWorker);
        foreach ($client->supportWorkers as $w) {
            if (!$workers->contains('id', $w->id)) $workers->push($w);
        }
        $derivePresence = function ($u) {
            if (!$u->last_seen_at) return 'offline';
            if ($u->presence_status === 'online' && $u->last_seen_at->gt(now()->subMinutes(5))) return 'online';
            if ($u->last_seen_at->gt(now()->subMinutes(15))) return 'away';
            return 'offline';
        };

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'conversations' => $conversations->values(),
            'supportWorkers' => $workers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'presence' => $derivePresence($w),
            ])->values(),
            'currentUserId' => $user->id,
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
            ->with('user:id,name,last_seen_at,presence_status')
            ->get()
            ->map(function ($p) {
                $u = $p->user;
                if (!$u) return null;
                $presence = 'offline';
                if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $presence = 'online';
                elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $presence = 'away';
                return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];
            })
            ->filter()
            ->values();

        // Re-fetch conversations for sidebar (same as index)
        $allConversations = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with(['latestMessage', 'participants.user:id,name,last_seen_at,presence_status'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($convo) {
                return [
                    'id' => $convo->id,
                    'title' => $convo->title,
                    'updated_at' => $convo->updated_at?->toISOString(),
                    'latest_message' => $convo->latestMessage ? [
                        'content' => $convo->latestMessage->content,
                        'created_at' => $convo->latestMessage->created_at?->toISOString(),
                        'sender_name' => $convo->latestMessage->sender?->name,
                    ] : null,
                    'participants' => $convo->participants->map(function ($p) {
                        $u = $p->user;
                        if (!$u) return null;
                        $presence = 'offline';
                        if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $presence = 'online';
                        elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $presence = 'away';
                        return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];
                    })->filter()->values(),
                ];
            });

        $client->load(['supportWorkers:id,name,profile_photo_path,last_seen_at,presence_status', 'keyWorker:id,name,profile_photo_path,last_seen_at,presence_status']);
        $workers = collect();
        if ($client->keyWorker) $workers->push($client->keyWorker);
        foreach ($client->supportWorkers as $w) {
            if (!$workers->contains('id', $w->id)) $workers->push($w);
        }
        $derivePresence = function ($u) {
            if (!$u->last_seen_at) return 'offline';
            if ($u->presence_status === 'online' && $u->last_seen_at->gt(now()->subMinutes(5))) return 'online';
            if ($u->last_seen_at->gt(now()->subMinutes(15))) return 'away';
            return 'offline';
        };

        return inertia('portal/messages', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'conversations' => $allConversations->values(),
            'supportWorkers' => $workers->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'presence' => $derivePresence($w)])->values(),
            'currentUserId' => $user->id,
            'activeConversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'participants' => $participants,
            ],
            'activeMessages' => $messages->values(),
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
            'worker_id' => 'nullable|integer|exists:users,id',
        ]);

        $workerId = $validated['worker_id'] ?? $client->key_worker_id;

        // Check if a family conversation already exists between this user and worker for this client
        if ($workerId) {
            $existing = OpsConversation::where('client_id', $client->id)
                ->where('conversation_type', 'family')
                ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $workerId))
                ->first();

            if ($existing) {
                // Add message to existing conversation
                OpsMessage::create([
                    'conversation_id' => $existing->id,
                    'sender_id' => $user->id,
                    'sender_type' => 'family',
                    'content' => $validated['content'],
                    'message_type' => 'text',
                    'client_id' => $client->id,
                ]);
                $existing->touch();

                return redirect("/portal/clients/{$client->id}/messages/{$existing->id}");
            }
        }

        // Create new conversation
        $workerName = $workerId ? \App\Models\User::find($workerId)?->name : null;
        $conversation = OpsConversation::create([
            'title' => $workerName ? "Chat with {$workerName}" : ($validated['title'] ?? 'Family Message'),
            'conversation_type' => 'family',
            'client_id' => $client->id,
        ]);

        OpsConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        if ($workerId) {
            OpsConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $workerId,
            ]);
        }

        OpsMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'family',
            'content' => $validated['content'],
            'message_type' => 'text',
            'client_id' => $client->id,
        ]);

        return redirect("/portal/clients/{$client->id}/messages/{$conversation->id}");
    }
}
