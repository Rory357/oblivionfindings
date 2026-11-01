<?php

namespace App\Services\Chat;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ChatAccess
{
    public const PRIVATE_GROUP_MEMBERS = 20;

    public function __construct(
        private readonly OpsMessageVisibilityService $visibility,
        private readonly ClientFamilyCommunicationAccess $family,
        private readonly UserSiteAccessService $sites,
        private readonly ChatMembershipGaps $gaps,
    ) {}

    public function allows(User $user, OpsConversation $conversation, bool $write = false): bool
    {
        $member = $conversation->members()->where('user_id', $user->id)->exists();
        if ($conversation->conversation_type !== 'family') {
            return $member && $this->channelSiteAllows($user, $conversation) && $this->visibility->canViewConversation($user, $conversation);
        }
        $client = $conversation->client;
        if (! $client) {
            return false;
        }
        if ($user->canAccessClientPortal($client)) {
            return $member && $this->visibility->canViewConversation($user, $conversation);
        }

        return ($member || $conversation->care_team_cover)
            && ($write ? $this->family->canManage($user, $client) : $this->family->canView($user, $client));
    }

    /**
     * allows() over a listing. The family authority of a non-portal viewer
     * depends only on the client, so it is evaluated once per client instead
     * of once per conversation; every other case defers to allows().
     */
    public function filterAllowed(User $user, Collection $conversations, bool $write = false): Collection
    {
        $portal = [];
        $family = [];

        return $conversations->filter(function (OpsConversation $conversation) use ($user, $write, &$portal, &$family): bool {
            if ($conversation->conversation_type !== 'family' || ! $conversation->client) {
                return $this->allows($user, $conversation, $write);
            }
            $clientId = (int) $conversation->client_id;
            if ($portal[$clientId] ??= $user->canAccessClientPortal($conversation->client)) {
                return $this->allows($user, $conversation, $write);
            }
            // Same membership test as allows(): cover read-state rows are not membership.
            $member = $conversation->relationLoaded('participants')
                ? $conversation->participants->contains(fn ($participant) => (int) $participant->user_id === (int) $user->id
                    && $participant->role !== OpsConversationParticipant::COVER_READER)
                : $conversation->members()->where('user_id', $user->id)->exists();

            return ($member || $conversation->care_team_cover)
                && ($family[$clientId] ??= ($write ? $this->family->canManage($user, $conversation->client) : $this->family->canView($user, $conversation->client)));
        });
    }

    /**
     * Current staff shortlist for "who may act in this conversation": its
     * members plus, for care-team cover, the client's possible family staff.
     * A superset of the staff allows() accepts; callers still apply allows().
     *
     * @return Builder<User>
     */
    public function staffCandidates(OpsConversation $conversation, bool $write = false): Builder
    {
        $members = $conversation->members()->select('user_id');
        $cover = $conversation->conversation_type === 'family' && $conversation->care_team_cover && $conversation->client
            ? $this->family->candidateStaffQuery($conversation->client, $write)->select('users.id') : null;

        return app(HrCurrentStaffService::class)->currentUsersQuery()
            ->where(fn (Builder $people) => $people->whereIn('users.id', $members)
                ->when($cover, fn (Builder $query) => $query->orWhereIn('users.id', $cover)));
    }

    public function authorize(User $user, OpsConversation $conversation, bool $write = false): void
    {
        abort_unless($this->allows($user, $conversation, $write), 403);
    }

    public function channelSiteAllows(User $user, OpsConversation $conversation): bool
    {
        return ! $conversation->channel_site_id || in_array((int) $conversation->channel_site_id, $this->sites->accessibleSiteIds($user), true);
    }

    /** Every member must be able to access the complete proposed staff audience. */
    public function staffAudienceAllows(array $ids, ?OpsConversation $conversation = null): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $people = User::whereIn('id', $ids)->get();

        return count($ids) > 0 && $people->count() === count($ids)
            && $people->every(fn (User $person) => $this->visibility->isCurrentStaff($person)
                && (! $conversation || $this->channelSiteAllows($person, $conversation))
                && $this->visibility->visibleCurrentStaffQuery($person)->whereIn('users.id', $ids)->count() === count($ids));
    }

    public function canManageParticipants(User $user, OpsConversation $conversation): bool
    {
        if ($conversation->is_archived || ! $this->allows($user, $conversation, true)) {
            return false;
        }
        if ($conversation->is_managed_channel) {
            return $user->canDo('settings.access.manage');
        }
        if ($conversation->conversation_type === 'family') {
            return $user->canAccessClientPortal($conversation->client) || $this->family->canManage($user, $conversation->client);
        }

        return $conversation->conversation_type === 'group' && $conversation->participants()->where('user_id', $user->id)->where('role', 'admin')->exists();
    }

    public function messages(OpsConversation $conversation, User $viewer, bool $deleted = false): Builder
    {
        $member = $conversation->participants()->where('user_id', $viewer->id)->first();

        return OpsMessage::query()
            ->when($deleted, fn (Builder $query) => $query->withTrashed())
            ->where('conversation_id', $conversation->id)
            ->when($conversation->conversation_type === 'family',
                fn (Builder $query) => $query->where('client_id', $conversation->client_id),
                fn (Builder $query) => $query->whereNull('client_id'))
            ->when($member?->history_after_id !== null, fn (Builder $query) => $query->where(fn (Builder $history) => $history
                ->where('ops_messages.id', '>', $member->history_after_id)
                ->orWhereExists(fn ($grant) => $grant->selectRaw('1')->from('chat_history_grants')
                    ->whereColumn('message_id', 'ops_messages.id')->where('participant_id', $member->id))))
            // A re-hired member never sees what was sent while they were away.
            ->when($member, fn (Builder $query) => $this->gaps->hide($query, (int) $member->id, (int) $viewer->id));
    }

    public function message(User $user, OpsMessage $message, bool $write = false): OpsConversation
    {
        $conversation = $message->conversation()->firstOrFail();
        $this->authorize($user, $conversation, $write);
        abort_unless($this->messages($conversation, $user, true)->whereKey($message->id)->exists(), 403);

        return $conversation;
    }

    /** Search predicates and pagination must see only currently authorised history. */
    public function messagesAcross(User $viewer, Collection $conversations): Builder
    {
        $ids = $this->filterAllowed($viewer, $conversations)->pluck('id');

        return OpsMessage::query()->select('ops_messages.*')
            ->join('ops_conversations as chat_search_conversations', 'chat_search_conversations.id', '=', 'ops_messages.conversation_id')
            ->leftJoin('ops_conversation_participants as chat_search_members', fn ($join) => $join
                ->on('chat_search_members.conversation_id', '=', 'ops_messages.conversation_id')->where('chat_search_members.user_id', $viewer->id))
            ->whereIn('ops_messages.conversation_id', $ids)
            ->where(fn ($canonical) => $canonical
                ->where(fn ($family) => $family->where('chat_search_conversations.conversation_type', 'family')->whereColumn('ops_messages.client_id', 'chat_search_conversations.client_id'))
                ->orWhere(fn ($staff) => $staff->where('chat_search_conversations.conversation_type', '!=', 'family')->whereNull('ops_messages.client_id')))
            ->where(fn ($history) => $history->whereNull('chat_search_members.history_after_id')
                ->orWhereColumn('ops_messages.id', '>', 'chat_search_members.history_after_id')
                ->orWhereExists(fn ($grants) => $grants->selectRaw('1')->from('chat_history_grants')
                    ->whereColumn('message_id', 'ops_messages.id')->whereColumn('participant_id', 'chat_search_members.id')))
            ->tap(fn ($query) => $this->gaps->hide($query, 'chat_search_members.id', (int) $viewer->id));
    }
}
