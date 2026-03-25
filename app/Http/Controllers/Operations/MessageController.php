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
        abort_unless($auth, 403);

        $conversationIds = OpsConversationParticipant::query()
            ->where('user_id', $auth->id)
            ->pluck('conversation_id');

        $conversations = OpsConversation::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->when($conversationIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $conversationIds))
            ->when($conversationIds->isEmpty(), fn ($q) => $q->whereRaw('1=0'))
            ->with([
                'participants.user:id,name',
                'client:id,first_name,last_name',
                'messages' => fn ($q) => $q->with('sender:id,name')->latest()->limit(1),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($conv) {
                $conv->latest_message = $conv->messages->first();
                unset($conv->messages);
                return $conv;
            });

        // Update current user's presence
        $auth->update(['last_seen_at' => now(), 'presence_status' => 'online']);

        // Load users for "New Chat" dropdown with presence
        $users = \App\Models\User::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('id', '!=', $auth->id)
            ->select('id', 'name', 'email', 'last_seen_at', 'presence_status')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                // Derive status: if last_seen_at > 5 min ago and status is 'online', show as 'away'
                if ($user->presence_status === 'online' && $user->last_seen_at && $user->last_seen_at->lt(now()->subMinutes(5))) {
                    $user->presence_status = 'away';
                }
                if (!$user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(15))) {
                    $user->presence_status = 'offline';
                }
                return $user;
            });

        return inertia('operations/messages/Index', [
            'conversations' => $conversations,
            'users' => $users,
            'currentUserId' => $auth->id,
        ]);
    }

    public function createConversation(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $data = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $participantIds = collect($data['participant_ids'])->push($auth->id)->unique()->values();

        // Check if a direct conversation already exists between these 2 users
        if ($participantIds->count() === 2) {
            $existing = OpsConversation::query()
                ->where('conversation_type', 'direct')
                ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $participantIds[0]))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $participantIds[1]))
                ->first();

            if ($existing) {
                return redirect()->back()->with('selected_conversation_id', $existing->id);
            }
        }

        $conversation = OpsConversation::create([
            'organization_id' => $auth->organization_id,
            'conversation_type' => $participantIds->count() > 2 ? 'group' : 'direct',
            'title' => $data['title'] ?? null,
        ]);

        foreach ($participantIds as $uid) {
            OpsConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $uid,
                'role' => $uid === $auth->id ? 'admin' : 'member',
            ]);
        }

        return redirect()->back()->with('selected_conversation_id', $conversation->id);
    }

    public function show(Request $request, $conversation)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

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
        abort_unless($auth, 403);

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
        abort_unless($auth, 403);

        $participant = OpsConversationParticipant::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', $auth->id)
            ->firstOrFail();

        $participant->update(['last_read_at' => now()]);

        return redirect()->back();
    }
}
