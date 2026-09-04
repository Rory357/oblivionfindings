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

        $participantIds = $conversation->participants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($participantIds->isEmpty()) {
            return false;
        }

        return $this->visibleCurrentStaffQuery($user)
            ->whereIn('users.id', $participantIds)
            ->count() === $participantIds->count();
    }

    /**
     * Always computed fresh — the Inertia middleware caches its own copy
     * for the per-request badge (see HandleInertiaRequests), so direct
     * callers (controllers, tests) never see a stale count.
     */
    public function unreadCount(User $user): int
    {
        $conversations = OpsConversation::query()
            ->whereHas('participants', fn (Builder $participants) => $participants
                ->where('user_id', $user->id))
            ->with('client:id,site_id')
            ->get(['id', 'conversation_type', 'client_id']);

        $conversationIds = $this->visibleConversationIds($user, $conversations);

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        return OpsMessage::query()
            ->join('ops_conversation_participants as unread_participant', function ($join) use ($user): void {
                $join->on(
                    'unread_participant.conversation_id',
                    '=',
                    'ops_messages.conversation_id',
                )->where('unread_participant.user_id', $user->id);
            })
            ->whereIn('ops_messages.conversation_id', $conversationIds)
            ->where('ops_messages.sender_id', '!=', $user->id)
            ->where(function (Builder $unread): void {
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
            })
            ->count();
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

        $staff = $conversations->filter(fn (OpsConversation $conversation) => in_array($conversation->conversation_type, ['direct', 'group'], true)
            && $conversation->client_id === null);

        if ($isCurrent && $staff->isNotEmpty()) {
            $participantsByConversation = DB::table('ops_conversation_participants')
                ->whereIn('conversation_id', $staff->pluck('id'))
                ->get(['conversation_id', 'user_id'])
                ->groupBy('conversation_id');

            $allParticipantIds = $participantsByConversation
                ->flatten(1)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $visibleStaffIds = $allParticipantIds->isEmpty()
                ? new Collection
                : $this->visibleCurrentStaffQuery($user)
                    ->whereIn('users.id', $allParticipantIds)
                    ->pluck('users.id')
                    ->map(fn ($id) => (int) $id)
                    ->flip();

            foreach ($staff as $conversation) {
                $participantIds = ($participantsByConversation->get($conversation->id) ?? new Collection)
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique();

                if ($participantIds->isNotEmpty()
                    && $participantIds->every(fn (int $id) => $visibleStaffIds->has($id))) {
                    $visible->push($conversation->id);
                }
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
