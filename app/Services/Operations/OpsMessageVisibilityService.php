<?php

namespace App\Services\Operations;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

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

    public function unreadCount(User $user): int
    {
        $conversationIds = OpsConversation::query()
            ->whereHas('participants', fn (Builder $participants) => $participants
                ->where('user_id', $user->id))
            ->with('client:id,site_id')
            ->get()
            ->filter(fn (OpsConversation $conversation) => $this->canViewConversation(
                $user,
                $conversation,
            ))
            ->pluck('id');

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
}
