<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\OpsMessageReaction;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function __construct(
        private readonly ClientFamilyCommunicationAccess $familyCommunicationAccess,
        private readonly OpsMessageVisibilityService $messageVisibility,
    ) {}

    public function index(Request $request)
    {
        $auth = $this->staffUser($request);

        $conversationIds = OpsConversationParticipant::query()
            ->where('user_id', $auth->id)
            ->pluck('conversation_id');

        $conversations = OpsConversation::query()
            ->when($conversationIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $conversationIds))
            ->when($conversationIds->isEmpty(), fn ($q) => $q->whereRaw('1=0'))
            ->with([
                'participants.user:id,name,role,approved_at',
                'client:id,site_id,first_name,last_name',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (OpsConversation $conversation) => $this->canAccessConversation($auth, $conversation))
            ->map(function (OpsConversation $conversation) {
                $this->filterVisibleParticipants($conversation);
                $conversation->setRelation(
                    'latestMessage',
                    $this->visibleMessages($conversation)
                        ->with('sender:id,name')
                        ->latest('created_at')
                        ->latest('id')
                        ->first(),
                );

                return $conversation;
            })
            ->values();

        // Update current user's presence
        $auth->update(['last_seen_at' => now(), 'presence_status' => 'online']);

        // Load users for "New Chat" dropdown with presence
        $users = $this->messageVisibility->visibleCurrentStaffQuery($auth)
            ->where('id', '!=', $auth->id)
            ->select('id', 'name', 'email', 'last_seen_at', 'presence_status')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                // Derive status: if last_seen_at > 5 min ago and status is 'online', show as 'away'
                if ($user->presence_status === 'online' && $user->last_seen_at && $user->last_seen_at->lt(now()->subMinutes(5))) {
                    $user->presence_status = 'away';
                }
                if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(15))) {
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
        $auth = $this->staffUser($request);

        $data = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $participantIds = collect($data['participant_ids'])->push($auth->id)->unique()->values();

        return DB::transaction(function () use ($auth, $data, $participantIds) {
            $lockedIds = $participantIds->sort()->values();
            User::query()
                ->whereIn('id', $lockedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            HrEmployeeProfile::query()
                ->whereIn('user_id', $lockedIds)
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get(['id', 'user_id']);

            $eligibleParticipantCount = $this->messageVisibility
                ->visibleCurrentStaffQuery($auth)
                ->whereIn('id', $lockedIds)
                ->count();
            if ($eligibleParticipantCount !== $lockedIds->count()) {
                throw ValidationException::withMessages([
                    'participant_ids' => 'Choose staff members from the staff chat directory.',
                ]);
            }

            // Locking the same sorted staff rows serializes concurrent direct
            // creation for this pair and makes the re-check authoritative.
            if ($lockedIds->count() === 2) {
                $existing = OpsConversation::query()
                    ->where('conversation_type', 'direct')
                    ->whereNull('client_id')
                    ->has('participants', '=', 2)
                    ->whereHas('participants', fn ($q) => $q->where('user_id', $lockedIds[0]))
                    ->whereHas('participants', fn ($q) => $q->where('user_id', $lockedIds[1]))
                    ->get()
                    ->first(fn (OpsConversation $conversation) => $this->canAccessConversation($auth, $conversation));

                if ($existing) {
                    return redirect()->back()->with('selected_conversation_id', $existing->id);
                }
            }

            $conversation = OpsConversation::create([
                'conversation_type' => $lockedIds->count() > 2 ? 'group' : 'direct',
                'title' => $data['title'] ?? null,
            ]);

            foreach ($lockedIds as $uid) {
                OpsConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $uid,
                    'role' => $uid === $auth->id ? 'admin' : 'member',
                ]);
            }

            return redirect()->back()->with('selected_conversation_id', $conversation->id);
        });
    }

    public function show(Request $request, $conversation)
    {
        $auth = $this->staffUser($request);

        $conversation = OpsConversation::query()
            ->with([
                'participants.user:id,name,role,approved_at',
                'client:id,site_id,first_name,last_name',
            ])
            ->findOrFail($conversation);

        // Verify user is a participant
        $isParticipant = $conversation->participants->contains('user_id', $auth->id);
        abort_unless($isParticipant, 403);
        $this->assertConversationAccess($auth, $conversation);
        $this->filterVisibleParticipants($conversation);

        $messages = $this->visibleMessages($conversation, withTrashed: true)
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
                'is_deleted' => $msg->trashed(),
                'reactions' => $msg->reactions->groupBy('emoji')->map(fn ($g, $e) => [
                    'emoji' => $e, 'count' => $g->count(),
                    'user_ids' => $g->pluck('user_id')->all(),
                    'user_names' => $g->map(fn ($r) => $r->user?->name)->filter()->values()->all(),
                ])->values()->all(),
                'created_at' => $msg->created_at?->toISOString(),
            ]);

        $pinnedMessages = $this->visibleMessages($conversation)
            ->where('is_pinned', true)->with('sender:id,name')->orderByDesc('created_at')->limit(5)->get()
            ->map(fn ($m) => ['id' => $m->id, 'content' => $m->content, 'sender_name' => $m->sender?->name, 'created_at' => $m->created_at?->toISOString()]);

        OpsConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $auth->id)
            ->update(['last_read_at' => now()]);

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
        $auth = $this->staffUser($request);

        $conversation = OpsConversation::query()
            ->findOrFail($conversation);

        // Verify user is a participant
        $isParticipant = OpsConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $auth->id)
            ->exists();
        abort_unless($isParticipant, 403);
        $this->assertConversationAccess($auth, $conversation, manage: true);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'message_type' => ['nullable', 'string', 'max:50'],
        ]);

        OpsMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $auth->id,
            'sender_type' => 'user',
            'message_type' => $data['message_type'] ?? 'text',
            'content' => $data['content'],
            'client_id' => $conversation->conversation_type === 'family'
                ? $conversation->client_id
                : null,
        ]);

        $conversation->touch();

        return redirect()->back();
    }

    public function toggleReaction(Request $request, OpsMessage $message)
    {
        $auth = $this->staffUser($request);
        $this->assertMessageParticipant($message, $auth, manage: true);

        $validated = $request->validate(['emoji' => 'required|string|max:10']);

        $existing = OpsMessageReaction::where('message_id', $message->id)
            ->where('user_id', $auth->id)->where('emoji', $validated['emoji'])->first();

        if ($existing) {
            $existing->delete();
        } else {
            OpsMessageReaction::create(['message_id' => $message->id, 'user_id' => $auth->id, 'emoji' => $validated['emoji']]);
        }

        return redirect()->back();
    }

    public function togglePin(Request $request, OpsMessage $message)
    {
        $auth = $this->staffUser($request);
        $this->assertMessageParticipant($message, $auth, manage: true);
        $message->update(['is_pinned' => ! $message->is_pinned]);

        return redirect()->back();
    }

    public function archiveMessage(Request $request, OpsMessage $message)
    {
        $auth = $this->staffUser($request);
        $this->assertMessageParticipant($message, $auth, manage: true);
        abort_unless($message->sender_id === $auth->id, 403);

        $message->delete();

        return redirect()->back();
    }

    public function searchMessages(Request $request)
    {
        $auth = $this->staffUser($request);

        $q = $request->query('q', '');
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $conversationIds = OpsConversation::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $auth->id))
            ->with('client:id,site_id')
            ->get()
            ->filter(fn (OpsConversation $conversation) => $this->canAccessConversation($auth, $conversation))
            ->pluck('id');

        return response()->json(
            $this->applyCanonicalMessageProvenance(
                OpsMessage::query()->whereIn('conversation_id', $conversationIds),
            )
                ->where('content', 'like', "%{$q}%")
                ->with('sender:id,name')
                ->orderByDesc('created_at')->limit(20)->get()
                ->map(fn ($m) => ['id' => $m->id, 'content' => $m->content, 'sender_name' => $m->sender?->name, 'conversation_id' => $m->conversation_id, 'created_at' => $m->created_at?->toISOString()])
        );
    }

    private function getConversations($auth)
    {
        return OpsConversation::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $auth->id))
            ->with(['participants.user:id,name,role,approved_at', 'client:id,site_id,first_name,last_name'])
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (OpsConversation $conversation) => $this->canAccessConversation($auth, $conversation))
            ->each(function (OpsConversation $conversation): void {
                $this->filterVisibleParticipants($conversation);
                $conversation->setRelation(
                    'latestMessage',
                    $this->visibleMessages($conversation)
                        ->with('sender:id,name')
                        ->latest('created_at')
                        ->latest('id')
                        ->first(),
                );
            })
            ->values();
    }

    private function getUsers($auth)
    {
        return $this->messageVisibility->visibleCurrentStaffQuery($auth)
            ->where('id', '!=', $auth->id)
            ->select(['id', 'name', 'email', 'presence_status', 'last_seen_at'])
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                $status = 'offline';
                if ($u->presence_status === 'online' && $u->last_seen_at?->gt(now()->subMinutes(5))) {
                    $status = 'online';
                } elseif ($u->last_seen_at?->gt(now()->subMinutes(15))) {
                    $status = 'away';
                }

                return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'presence_status' => $status, 'last_seen_at' => $u->last_seen_at?->toISOString()];
            });
    }

    public function markRead(Request $request, $conversation)
    {
        $auth = $this->staffUser($request);

        $participant = OpsConversationParticipant::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', $auth->id)
            ->firstOrFail();
        $conversationRecord = OpsConversation::query()
            ->with('client:id,site_id')
            ->findOrFail($conversation);
        $this->assertConversationAccess($auth, $conversationRecord);

        $participant->update(['last_read_at' => now()]);

        return redirect()->back();
    }

    private function assertMessageParticipant(OpsMessage $message, User $user, bool $manage = false): void
    {
        abort_unless(
            OpsConversationParticipant::query()
                ->where('conversation_id', $message->conversation_id)
                ->where('user_id', $user->id)
                ->exists(),
            403,
        );

        $conversation = OpsConversation::query()
            ->with('client:id,site_id')
            ->findOrFail($message->conversation_id);
        abort_unless($this->messageMatchesConversation($message, $conversation), 403);
        $this->assertConversationAccess($user, $conversation, $manage);
    }

    private function assertConversationAccess(User $user, OpsConversation $conversation, bool $manage = false): void
    {
        abort_unless($this->canAccessConversation($user, $conversation, $manage), 403);
    }

    private function canAccessConversation(User $user, OpsConversation $conversation, bool $manage = false): bool
    {
        if ($conversation->conversation_type !== 'family') {
            return $this->messageVisibility->canViewConversation($user, $conversation);
        }

        if (! is_numeric($conversation->client_id) || (int) $conversation->client_id <= 0) {
            return false;
        }

        $client = $conversation->relationLoaded('client')
            ? $conversation->client
            : $conversation->client()->first();

        if (! $client) {
            return false;
        }

        return $manage
            ? $this->familyCommunicationAccess->canManage($user, $client)
            : $this->familyCommunicationAccess->canView($user, $client);
    }

    /**
     * Stored family membership is historical state, not current authority.
     * Never serialize a stale portal link or former assigned worker as an
     * active participant in the general staff messaging workspace.
     */
    private function filterVisibleParticipants(OpsConversation $conversation): void
    {
        if ($conversation->conversation_type !== 'family') {
            return;
        }

        $client = $conversation->relationLoaded('client')
            ? $conversation->client
            : $conversation->client()->first();

        $conversation->setRelation(
            'participants',
            $conversation->participants
                ->filter(fn (OpsConversationParticipant $participant) => $client
                    && $participant->user
                    && $this->familyCommunicationAccess->canAppearAsParticipant(
                        $participant->user,
                        $client,
                    ))
                ->values(),
        );
    }

    private function visibleMessages(
        OpsConversation $conversation,
        bool $withTrashed = false,
    ): Builder {
        $query = OpsMessage::query()
            ->when($withTrashed, fn (Builder $builder) => $builder->withTrashed())
            ->where('conversation_id', $conversation->id);

        if ($conversation->conversation_type === 'family') {
            return $query->where('client_id', $conversation->client_id);
        }

        return $query->whereNull('client_id');
    }

    private function applyCanonicalMessageProvenance(Builder $query): Builder
    {
        return $query->whereHas('conversation', function (Builder $conversation): void {
            $conversation->where(function (Builder $provenance): void {
                $provenance
                    ->where(function (Builder $family): void {
                        $family->where('conversation_type', 'family')
                            ->whereNotNull('ops_conversations.client_id')
                            ->whereColumn('ops_messages.client_id', 'ops_conversations.client_id');
                    })
                    ->orWhere(function (Builder $staff): void {
                        $staff->whereIn('conversation_type', ['direct', 'group'])
                            ->whereNull('ops_conversations.client_id')
                            ->whereNull('ops_messages.client_id');
                    });
            });
        });
    }

    private function messageMatchesConversation(
        OpsMessage $message,
        OpsConversation $conversation,
    ): bool {
        if ($conversation->conversation_type === 'family') {
            return $conversation->client_id !== null
                && $message->client_id !== null
                && (int) $message->client_id === (int) $conversation->client_id;
        }

        return in_array($conversation->conversation_type, ['direct', 'group'], true)
            && $conversation->client_id === null
            && $message->client_id === null;
    }

    private function staffUser(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user
                && $this->messageVisibility->isCurrentStaff($user),
            403,
        );

        return $user;
    }
}
