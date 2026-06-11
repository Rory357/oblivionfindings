<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use Illuminate\Http\Request;

/**
 * Staff side of the client family chat. Reuses the same OpsConversation
 * records the family portal messaging uses (conversation_type=family,
 * client-scoped) so whānau on the portal and staff on the profile see one
 * thread. Fetch is JSON (popup polls), send content-negotiates.
 */
class ClientFamilyChatController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $user = $request->user();

        $conversation = $this->resolveConversation($client, $user->id, createIfMissing: false);

        $portalUsers = $client->portalUsers()->get(['users.id', 'users.name']);

        if (! $conversation) {
            return response()->json([
                'conversation' => null,
                'messages' => [],
                'portal_users' => $portalUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            ]);
        }

        // Joining staff become participants so portal-side queries include them.
        OpsConversationParticipant::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender?->name,
                'sender_type' => $msg->sender_type,
                'mine' => $msg->sender_id === $user->id,
                'created_at' => $msg->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'participants' => $conversation->participants
                    ->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->user?->name])
                    ->filter(fn ($p) => $p['name'])
                    ->values(),
            ],
            'messages' => $messages,
            'portal_users' => $portalUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $user = $request->user();

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $conversation = $this->resolveConversation($client, $user->id, createIfMissing: true);

        OpsConversationParticipant::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        OpsMessage::create([
            'organization_id' => $user->organization_id,
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_type' => 'user',
            'message_type' => 'text',
            'content' => $data['content'],
            'client_id' => $client->id,
        ]);

        $conversation->touch();

        if ($request->header('X-Inertia')) {
            return back();
        }

        return response()->json(['success' => true]);
    }

    private function resolveConversation(Client $client, int $userId, bool $createIfMissing): ?OpsConversation
    {
        $conversation = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->where('is_archived', false)
            ->with('participants.user:id,name')
            ->orderByDesc('updated_at')
            ->first();

        if ($conversation || ! $createIfMissing) {
            return $conversation;
        }

        $conversation = OpsConversation::create([
            'organization_id' => $client->organization_id,
            'title' => trim("{$client->first_name} {$client->last_name}").' — whānau chat',
            'conversation_type' => 'family',
            'client_id' => $client->id,
        ]);

        // Seed whānau participants from the client's portal users.
        foreach ($client->portalUsers()->pluck('users.id') as $portalUserId) {
            OpsConversationParticipant::firstOrCreate([
                'conversation_id' => $conversation->id,
                'user_id' => $portalUserId,
            ]);
        }

        return $conversation->load('participants.user:id,name');
    }
}
