<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatConversationManagement;
use App\Services\Chat\ChatInvitations;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ChatParticipantController extends Controller
{
    public function __construct(private readonly ChatAccess $access, private readonly ClientFamilyCommunicationAccess $family, private readonly OpsMessageVisibilityService $staff) {}

    private function canManage(User $user, OpsConversation $conversation): bool
    {
        return $this->access->canManageParticipants($user, $conversation);
    }

    public function options(Request $request, OpsConversation $conversation, ChatInvitations $invitations)
    {
        $this->access->authorize($request->user(), $conversation, true);
        abort_unless($this->canManage($request->user(), $conversation), 403);
        $members = $conversation->participants()->pluck('user_id');
        $people = $conversation->conversation_type === 'family'
            ? $conversation->client->portalUsers()->whereNotIn('users.id', $members)->get()->filter(fn ($user) => $user->canAccessClientPortal($conversation->client) && $this->family->canAppearAsParticipant($user, $conversation->client))
            : $this->staff->visibleCurrentStaffQuery($request->user())->whereNotIn('users.id', $members)->get()->filter(fn ($person) => $invitations->eligible($request->user(), $person, $conversation));

        $pending = $invitations->pending($conversation->id)->get();

        return response()->json(['max_additional' => $conversation->conversation_type === 'group' && ! $conversation->is_managed_channel ? max(0, ChatAccess::PRIVATE_GROUP_MEMBERS - $members->count()) : 19,
            'invitation_expiry_days' => ChatInvitations::EXPIRY_DAYS,
            'people' => $people->reject(fn ($person) => $pending->contains('user_id', $person->id))->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])->values(),
            'pending' => $pending->map(fn ($invite) => ['id' => $invite->id, 'name' => User::find($invite->user_id)?->name ?? 'Unavailable person'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, OpsConversation $conversation)
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:19'], 'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'version' => ['required', 'integer'], 'mutation_id' => ['required', 'uuid'],
            'history_policy' => ['required', Rule::in(['from_joining', 'selected'])],
            'message_ids' => ['sometimes', 'array', 'max:50'], 'message_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'history_confirmed' => ['exclude_unless:history_policy,selected', 'required', 'accepted'],
        ]);

        return response()->json(app(ChatInvitations::class)->invite($request->user(), $conversation, $data), 201);
    }

    public function invitations(Request $request, ChatInvitations $invitations)
    {
        $data = $request->validate(['client' => ['required', 'integer'], 'before' => ['nullable', 'uuid']]);
        $client = Client::findOrFail($data['client']);
        abort_unless($request->user()->canAccessClientPortal($client), 403);
        // A relative cannot see another relative's private conversation until a family member there agrees.
        $rows = DB::table('chat_invitations')->where('user_id', $request->user()->id)->where('status', 'pending')
            ->where(fn ($consent) => $consent->where('consent_required', false)->orWhereNotNull('consented_by'))
            ->where('created_at', '>', now()->subDays(ChatInvitations::EXPIRY_DAYS))
            ->whereIn('conversation_id', OpsConversation::query()->where('client_id', $client->id)->where('conversation_type', 'family')->select('id'))
            ->when($data['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))->orderByDesc('id')->limit(51)->get();
        $items = [];
        foreach ($rows->take(50) as $row) {
            $conversation = OpsConversation::find($row->conversation_id);
            $inviter = User::find($row->invited_by);
            if (! $conversation || (int) $conversation->client_id !== $client->id || ! $inviter || ! $this->canManage($inviter, $conversation)
                || ! $invitations->eligible($inviter, $request->user(), $conversation)) {
                continue;
            }
            $items[] = ['id' => $row->id, 'conversation_id' => $conversation->id, 'title' => $conversation->title, 'from' => $inviter->name,
                'version' => $conversation->chat_version, 'history_policy' => $row->history_policy, 'shared_count' => count(json_decode($row->message_ids, true)),
                'expires_at' => Carbon::parse($row->created_at)->addDays(ChatInvitations::EXPIRY_DAYS)->toISOString(),
                'members' => $conversation->participants()->with('user')->get()->filter(fn ($member) => $member->user && $this->family->canAppearAsParticipant($member->user, $client))->map(fn ($member) => $member->user->name)->values()];
        }

        return response()->json(['invitations' => $items, 'next_before' => $rows->count() > 50 ? $rows[49]->id : null,
            'consent_requests' => $this->consentRequests($request->user(), $client, $invitations)])->header('Cache-Control', 'private, no-store');
    }

    public function respond(Request $request, string $invitation, ChatInvitations $invitations)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'decline', 'cancel', 'consent', 'refuse'])], 'version' => ['required_if:action,accept', 'integer']]);
        $invitations->respond($request->user(), $invitation, $data['action'], $data['version'] ?? null);

        return response()->noContent();
    }

    /** Invitations waiting for this relative's agreement, in conversations they are currently part of. */
    private function consentRequests(User $viewer, Client $client, ChatInvitations $invitations): array
    {
        $conversations = OpsConversation::query()->where('client_id', $client->id)->where('conversation_type', 'family')
            ->whereHas('members', fn ($members) => $members->where('user_id', $viewer->id)->where('role', 'family'))->pluck('id');
        $rows = DB::table('chat_invitations')->whereIn('conversation_id', $conversations)->where('status', 'pending')
            ->where('consent_required', true)->whereNull('consented_by')->where('user_id', '!=', $viewer->id)
            ->where('created_at', '>', now()->subDays(ChatInvitations::EXPIRY_DAYS))->orderByDesc('created_at')->limit(50)->get();
        $items = [];
        foreach ($rows as $row) {
            $conversation = OpsConversation::find($row->conversation_id);
            $person = User::find($row->user_id);
            $inviter = User::find($row->invited_by);
            if (! $conversation || ! $person || ! $inviter || ! $invitations->familyMembers($conversation)->contains('id', $viewer->id)
                || ! $invitations->eligible($inviter, $person, $conversation)) {
                continue;
            }
            $items[] = ['id' => $row->id, 'conversation_id' => $conversation->id, 'title' => $conversation->title, 'person' => $person->name,
                'from' => $inviter->name, 'history_policy' => $row->history_policy, 'shared_count' => count(json_decode($row->message_ids, true)),
                'expires_at' => Carbon::parse($row->created_at)->addDays(ChatInvitations::EXPIRY_DAYS)->toISOString()];
        }

        return $items;
    }

    public function history(Request $request, OpsConversation $conversation, ChatInvitations $invitations, ChatConversationManagement $management)
    {
        abort_unless($conversation->conversation_type === 'family' && $this->canManage($request->user(), $conversation), 403);
        $data = $request->validate(['before' => ['nullable', 'uuid']]);
        $query = DB::table('chat_invitations')->where('conversation_id', $conversation->id);
        if ($data['before'] ?? null) {
            $anchor = (clone $query)->where('id', $data['before'])->first();
            abort_unless($anchor, 404);
            $query->where(fn ($earlier) => $earlier->where('created_at', '<', $anchor->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $anchor->created_at)->where('id', '<', $anchor->id)));
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(51)->get();
        $capabilities = $management->capabilities($request->user(), $conversation);
        $items = $rows->take(50)->map(function ($row) use ($request, $conversation, $invitations, $capabilities) {
            $person = User::find($row->user_id);
            $eligible = $person && $invitations->eligible($request->user(), $person, $conversation);
            $status = $invitations->status($row);
            $canRemove = $status === 'accepted' && in_array((int) $row->user_id, $capabilities['removable_ids'], true);

            return ['id' => $row->id, 'user_id' => $eligible || $canRemove ? (int) $row->user_id : null,
                'name' => $eligible ? $person->name : 'Former or unavailable relative', 'status' => $status,
                'history_policy' => $row->history_policy, 'shared_count' => count(json_decode($row->message_ids, true)),
                'created_at' => Carbon::parse($row->created_at)->toISOString(),
                'expires_at' => Carbon::parse($row->created_at)->addDays(ChatInvitations::EXPIRY_DAYS)->toISOString(),
                'can_cancel' => in_array($status, ['pending', 'awaiting_family'], true),
                'can_remove' => $canRemove,
                'can_reinvite' => $eligible && in_array($status, ['expired', 'declined', 'cancelled', 'removed', 'refused'], true)
                    && ! $conversation->participants()->where('user_id', $row->user_id)->exists()
                    && ! $invitations->pending($conversation->id)->where('user_id', $row->user_id)->exists()];
        });

        return response()->json(['invitations' => $items, 'version' => $conversation->chat_version, 'next_before' => $rows->count() > 50 ? $rows[49]->id : null])->header('Cache-Control', 'private, no-store');
    }
}
