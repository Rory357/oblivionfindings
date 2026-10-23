<?php

namespace App\Services\Operations;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OpsMessageVisibilityService
{
    public function __construct(
        private readonly HrCurrentStaffService $currentStaff,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ClientFamilyCommunicationAccess $familyCommunicationAccess,
    ) {}

    /** @return Builder<User> */
    public function visibleCurrentStaffQuery(User $viewer): Builder
    {
        return $this->siteAccess->applyStaffScope(User::query(), $viewer);
    }

    public function isCurrentStaff(User $user): bool
    {
        return $this->currentStaff->isCurrent($user);
    }

    public function canViewConversation(User $user, OpsConversation $conversation): bool
    {
        if (! config('chat.shared_experience') && $conversation->participants()->where('user_id', $user->id)->first()?->history_after_id !== null) {
            return false;
        }
        if ($conversation->channel_site_id && ! in_array((int) $conversation->channel_site_id, $this->siteAccess->accessibleSiteIds($user), true)) {
            return false;
        }
        if ($conversation->conversation_type === 'family') {
            $client = $conversation->relationLoaded('client')
                ? $conversation->client
                : $conversation->client()->first();

            if (! $client) {
                return false;
            }

            if ($user->canAccessClientPortal($client)) {
                return $this->currentStaff->historicalProfileFor($user) === null;
            }

            return $this->currentStaff->isCurrent($user)
                && $this->familyCommunicationAccess->canView($user, $client);
        }

        if (
            ! in_array($conversation->conversation_type, ['direct', 'group'], true)
            || $conversation->client_id !== null
            || ! $this->currentStaff->isCurrent($user)
        ) {
            return false;
        }

        // A staff conversation stays with its members (membership is checked by the caller): a
        // colleague moving Site keeps their name in it and nobody loses the chat (Stephan, 23 Oct
        // 2026). A managed channel follows its Site, checked above. Who may be added is decided when
        // people are added: new groups need everyone to see everyone, and an addition needs the adder
        // and the new person to see each other (ChatInvitations::eligible()).
        return true;
    }

    /**
     * Short-lived per-user badge copy for every-request callers (Inertia
     * share, call status polling). Busted after the user's own writes
     * (HandleInertiaRequests::handle, chat read/preferences) and after commit
     * for every recipient of a new chat message (ChatMessageDelivery).
     */
    public function cachedUnreadCount(User $user): int
    {
        return (int) Cache::remember(self::unreadCacheKey((int) $user->id), config('chat.shared_experience') ? 20 : 60,
            fn (): int => $this->unreadCount($user));
    }

    public static function unreadCacheKey(int $userId): string
    {
        return "user:{$userId}:ops-unread:v1";
    }

    public static function forgetUnreadCount(int $userId): void
    {
        Cache::forget(self::unreadCacheKey($userId));
    }

    /**
     * Always computed fresh — the Inertia middleware caches its own copy
     * for the per-request badge (see HandleInertiaRequests), so direct
     * callers (controllers, tests) never see a stale count.
     */
    public function unreadCount(User $user): int
    {
        // Cover conversations are narrowed in SQL to clients canView() could accept;
        // visibleConversationIds() still applies canView() once per client.
        $conversations = OpsConversation::query()
            ->where(fn (Builder $audience) => $audience->whereHas('participants', fn (Builder $participants) => $participants->where('user_id', $user->id))
                ->when(config('chat.shared_experience') && $this->isCurrentStaff($user), fn (Builder $cover) => $cover->orWhere(fn (Builder $family) => $family->where('conversation_type', 'family')->where('care_team_cover', true)
                    ->whereHas('client', fn (Builder $clients) => $this->familyCommunicationAccess->scopeViewableClients($clients, $user)))))
            ->with('client:id,site_id')
            ->get(config('chat.shared_experience') ? ['id', 'conversation_type', 'client_id', 'channel_site_id', 'is_managed_channel'] : ['id', 'conversation_type', 'client_id']);

        if (! config('chat.shared_experience')) {
            $conversations = $conversations->filter(fn ($conversation) => $conversation->participants()->where('user_id', $user->id)->first()?->history_after_id === null);
        }
        $conversationIds = $this->visibleConversationIds($user, $conversations);

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        $unreadMessages = OpsMessage::query()
            ->leftJoin('ops_conversation_participants as unread_participant', function ($join) use ($user): void {
                $join->on(
                    'unread_participant.conversation_id',
                    '=',
                    'ops_messages.conversation_id',
                )->where('unread_participant.user_id', $user->id);
            })
            ->whereIn('ops_messages.conversation_id', $conversationIds)
            ->when(config('chat.shared_experience'), fn (Builder $query) => $query->where(fn (Builder $history) => $history
                ->whereNull('unread_participant.history_after_id')
                ->orWhereColumn('ops_messages.id', '>', 'unread_participant.history_after_id')
                ->orWhereExists(fn ($grant) => $grant->selectRaw('1')->from('chat_history_grants')
                    ->whereColumn('message_id', 'ops_messages.id')->whereColumn('participant_id', 'unread_participant.id'))))
            ->where('ops_messages.sender_id', '!=', $user->id)
            ->where(function (Builder $unread): void {
                if (config('chat.shared_experience')) {
                    $unread->where(function (Builder $cursor): void {
                        $cursor->whereNotNull('unread_participant.last_read_message_id')
                            ->whereColumn('ops_messages.id', '>', 'unread_participant.last_read_message_id');
                    })->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('unread_participant.last_read_message_id')
                            ->where(fn (Builder $timestamp) => $timestamp->whereNull('unread_participant.last_read_at')
                                ->orWhereColumn('ops_messages.created_at', '>', 'unread_participant.last_read_at'));
                    });

                    return;
                }
                $unread->whereNull('unread_participant.last_read_at')
                    ->orWhereColumn(
                        'ops_messages.created_at',
                        '>',
                        'unread_participant.last_read_at',
                    );
            })
            ->where(function (Builder $provenance): void {
                $provenance
                    ->where(function (Builder $family): void {
                        $family->whereNotNull('ops_messages.client_id')
                            ->whereHas('conversation', fn (Builder $conversation) => $conversation
                                ->where('conversation_type', 'family')
                                ->whereNotNull('ops_conversations.client_id')
                                ->whereColumn('ops_messages.client_id', 'ops_conversations.client_id'));
                    })
                    ->orWhere(function (Builder $staff): void {
                        $staff->whereNull('ops_messages.client_id')
                            ->whereHas('conversation', fn (Builder $conversation) => $conversation
                                ->whereIn('conversation_type', ['direct', 'group'])
                                ->whereNull('ops_conversations.client_id'));
                    });
            });

        $count = (clone $unreadMessages)->count();
        if (config('chat.shared_experience')) {
            $unreadConversations = (clone $unreadMessages)->distinct()->pluck('ops_messages.conversation_id');
            // Personal reminders add one only when there is no actual unread message.
            // The delivery/read cursor is never moved backwards.
            $count += DB::table('ops_conversation_participants')->where('user_id', $user->id)
                ->whereIn('conversation_id', $conversationIds)->whereNotIn('conversation_id', $unreadConversations)
                ->whereNotNull('unread_marker')->count();
        }

        return $count;
    }

    /**
     * Batched equivalent of filtering with canViewConversation(): the same
     * per-conversation semantics, computed with a fixed number of queries
     * instead of ~2 per conversation.
     *
     * @param  Collection<int, OpsConversation>  $conversations
     * @return Collection<int, int>
     */
    private function visibleConversationIds(User $user, Collection $conversations): Collection
    {
        if ($conversations->isEmpty()) {
            return new Collection;
        }

        $isCurrent = $this->currentStaff->isCurrent($user);
        $visible = new Collection;
        $siteIds = $isCurrent ? $this->siteAccess->accessibleSiteIds($user) : [];

        $staff = $conversations->filter(fn (OpsConversation $conversation) => in_array($conversation->conversation_type, ['direct', 'group'], true)
            && $conversation->client_id === null
            && (! $conversation->channel_site_id || in_array((int) $conversation->channel_site_id, $siteIds, true)));

        // Same as canViewConversation(): a current staff member keeps the staff conversations they
        // belong to (the caller's query is by membership); a channel's Site is checked above.
        if ($isCurrent) {
            foreach ($staff as $conversation) {
                $visible->push($conversation->id);
            }
        }

        $family = $conversations->filter(fn (OpsConversation $conversation) => $conversation->conversation_type === 'family');

        if ($family->isNotEmpty()) {
            $isHistorical = null; // resolved lazily, once, on the first portal-access client
            $decisionByClient = [];

            foreach ($family as $conversation) {
                $client = $conversation->client;

                if (! $client) {
                    continue;
                }

                if (! array_key_exists($client->id, $decisionByClient)) {
                    if ($user->canAccessClientPortal($client)) {
                        $isHistorical ??= $this->currentStaff->historicalProfileFor($user) !== null;
                        $decisionByClient[$client->id] = ! $isHistorical;
                    } else {
                        $decisionByClient[$client->id] = $isCurrent
                            && $this->familyCommunicationAccess->canView($user, $client);
                    }
                }

                if ($decisionByClient[$client->id]) {
                    $visible->push($conversation->id);
                }
            }
        }

        return $visible;
    }
}
