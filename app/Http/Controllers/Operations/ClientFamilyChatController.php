<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\User;
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

        $conversation = $this->resolveConversation($client, $user, createIfMissing: false);

        $portalUsers = $client->portalUsers()
            ->get(['users.id', 'users.name', 'users.role', 'users.approved_at'])
            ->filter(fn (User $portalUser) => $portalUser->canAccessClientPortal($client))
            ->values();

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
            ->where('client_id', $client->id);
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
                    ->map(function ($participant) use ($client) {
                        $participantUser = $participant->user;

                        if (
                            ! $participantUser
                            || ! $this->familyCommunicationAccess
                                ->canAppearAsParticipant($participantUser, $client)
                        ) {
                            return null;
                        }

                        return [
                            'id' => $participantUser->id,
                            'name' => $participantUser->name,
                        ];
                    })
                    ->filter()
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

        $conversation = $this->resolveConversation($client, $user, createIfMissing: true);

        OpsConversationParticipant::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        OpsMessage::create([
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

    private function resolveConversation(
        Client $client,
        User $user,
        bool $createIfMissing,
    ): ?OpsConversation {
        $conversation = OpsConversation::where('client_id', $client->id)
            ->where('conversation_type', 'family')
            ->where('is_archived', false)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->with('participants.user:id,name,role,approved_at')
            ->orderByDesc('updated_at')
            ->first();

        if ($conversation || ! $createIfMissing) {
            return $conversation;
        }

        $conversation = OpsConversation::create([
            'title' => trim("{$client->first_name} {$client->last_name}").' — whānau chat',
            'conversation_type' => 'family',
            'client_id' => $client->id,
        ]);

        // Seed whānau participants from the client's portal users.
        $portalUserIds = $client->portalUsers()
            ->get(['users.id', 'users.role', 'users.approved_at'])
            ->filter(fn (User $portalUser) => $portalUser->canAccessClientPortal($client))
            ->pluck('id');

        foreach ($portalUserIds as $portalUserId) {
            OpsConversationParticipant::firstOrCreate([
                'conversation_id' => $conversation->id,
                'user_id' => $portalUserId,
            ]);
        }

        return $conversation->load('participants.user:id,name,role,approved_at');
    }
}
