<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\OpsMessageReaction;
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
            ->with(['sender:id,name', 'reactions.user:id,name'])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($msg) => [
                'id' => $msg->id,
                'content' => $msg->content,
                'sender' => $msg->sender ? ['id' => $msg->sender->id, 'name' => $msg->sender->name] : null,
                'sender_id' => $msg->sender_id,
                'sender_type' => $msg->sender_type,
                'message_type' => $msg->message_type,
                'attachments' => $msg->attachments,
                'is_pinned' => (bool) $msg->is_pinned,
                'is_read' => (bool) $msg->is_read,
                'read_at' => $msg->read_at?->toISOString(),
                'shift_id' => $msg->shift_id,
                'reactions' => $msg->reactions->groupBy('emoji')->map(fn ($g, $e) => [
                    'emoji' => $e, 'count' => $g->count(),
                    'user_ids' => $g->pluck('user_id')->all(),
                    'user_names' => $g->map(fn ($r) => $r->user?->name)->filter()->values()->all(),
                ])->values()->all(),
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        $pinnedMessages = OpsMessage::where('conversation_id', $conversation->id)
            ->where('is_pinned', true)->with('sender:id,name')->orderByDesc('created_at')->limit(5)->get()
            ->map(fn ($m) => ['id' => $m->id, 'content' => $m->content, 'sender_name' => $m->sender?->name, 'created_at' => $m->created_at?->toISOString()]);

        // Mark messages as read
        OpsMessage::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $auth->id)->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return inertia('operations/messages/Index', [
            'conversations' => $this->getConversations($auth),
            'users' => $this->getUsers($auth),
            'currentUserId' => $auth->id,
            'conversation' => $conversation,
            'messages' => $messages,
            'pinnedMessages' => $pinnedMessages->values(),
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

    public function toggleReaction(Request $request, OpsMessage $message)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $validated = $request->validate(['emoji' => 'required|string|max:10']);

        $existing = OpsMessageReaction::where('message_id', $message->id)
            ->where('user_id', $auth->id)->where('emoji', $validated['emoji'])->first();

        if ($existing) { $existing->delete(); } else {
            OpsMessageReaction::create(['message_id' => $message->id, 'user_id' => $auth->id, 'emoji' => $validated['emoji']]);
        }

        return redirect()->back();
    }

    public function togglePin(Request $request, OpsMessage $message)
    {
        $auth = $request->user();
        abort_unless($auth, 403);
        $message->update(['is_pinned' => !$message->is_pinned]);
        return redirect()->back();
    }

    public function searchMessages(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $q = $request->query('q', '');
        if (strlen($q) < 2) return response()->json([]);

        $conversationIds = OpsConversationParticipant::where('user_id', $auth->id)->pluck('conversation_id');

        return response()->json(
            OpsMessage::whereIn('conversation_id', $conversationIds)
                ->where('content', 'like', "%{$q}%")
                ->with('sender:id,name')
                ->orderByDesc('created_at')->limit(20)->get()
                ->map(fn ($m) => ['id' => $m->id, 'content' => $m->content, 'sender_name' => $m->sender?->name, 'conversation_id' => $m->conversation_id, 'created_at' => $m->created_at?->toISOString()])
        );
    }

    private function getConversations($auth)
    {
        return OpsConversation::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $auth->id))
            ->with(['latestMessage.sender:id,name', 'participants.user:id,name', 'client:id,first_name,last_name'])
            ->orderByDesc('updated_at')
            ->get();
    }

    private function getUsers($auth)
    {
        return \App\Models\User::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('id', '!=', $auth->id)
            ->whereNotIn('role', ['client', 'next_of_kin'])
            ->select(['id', 'name', 'email', 'presence_status', 'last_seen_at'])
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                $status = 'offline';
                if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) $status = 'online';
                elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) $status = 'away';
                return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'presence_status' => $status, 'last_seen_at' => $u->last_seen_at?->toISOString()];
            });
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
