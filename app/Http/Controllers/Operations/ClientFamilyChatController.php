<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use Illuminate\Http\Request;

/**
 * Staff side of the client family chat. Reuses the same OpsConversation
 * records the family portal messaging uses (conversation_type=family,
 * client-scoped) so whānau on the portal and staff on the profile see one
 * thread. Fetch is JSON (popup polls), send content-negotiates.
 */
class ClientFamilyChatController extends Controller
{
    public function __construct(
        private readonly ClientFamilyCommunicationAccess $familyCommunicationAccess,
    ) {}

    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $user = $request->user();
        abort_unless($this->familyCommunicationAccess->canView($user, $client), 403);

        $conversation = $this->resolveConversation($client, createIfMissing: false);

        $portalUsers = $client->portalUsers()->get(['users.id', 'users.name']);

        if (! $conversation) {
            return response()->json([
                'conversation' => null,
                'messages' => [],
                'meta' => [
                    'total' => 0,
                    'loaded' => 0,
                    'has_more' => false,
                ],
                'portal_users' => $portalUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            ]);
        }

        $messagesBase = $conversation->messages()
            ->where('client_id', $client->id)
            ->where(function ($query) use ($client): void {
                $query->where('organization_id', $client->organization_id)
                    ->orWhereNull('organization_id');
            });
        $messagesTotal = (clone $messagesBase)->count();
        $messages = (clone $messagesBase)
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
            'meta' => [
                'total' => $messagesTotal,
                'loaded' => $messages->count(),
                'has_more' => $messagesTotal > $messages->count(),
            ],
            'portal_users' => $portalUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $user = $request->user();
        abort_unless($this->familyCommunicationAccess->canManage($user, $client), 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $conversation = $this->resolveConversation($client, createIfMissing: true);

        OpsConversationParticipant::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        OpsMessage::create([
            'organization_id' => $client->organization_id,
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

    private function resolveConversation(Client $client, bool $createIfMissing): ?OpsConversation
    {
        $conversation = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->where('is_archived', false)
            ->where(function ($query) use ($client): void {
                $query->where('organization_id', $client->organization_id)
                    ->orWhereNull('organization_id');
            })
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
