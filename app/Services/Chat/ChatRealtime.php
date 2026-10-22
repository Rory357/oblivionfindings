<?php

namespace App\Services\Chat;

use App\Jobs\PublishChatUnread;
use App\Jobs\WakeChatClients;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Who hears about a Chat change, and how. Oblivion Live (chat.live) sends targeted, content-free
 * events; the older Reverb connection (chat.realtime) still sends a bare refresh until Oblivion
 * Live replaces it. Events are hints only: every fetch re-checks access.
 */
final class ChatRealtime
{
    public function configured(): bool
    {
        return (bool) config('chat.shared_experience') && ($this->usesLive() || $this->reverbConfigured());
    }

    /** True when browsers connect to Oblivion Live rather than Reverb. */
    public function usesLive(): bool
    {
        return (bool) config('chat.shared_experience') && app(OblivionLive::class)->enabled();
    }

    private function reverbConfigured(): bool
    {
        return config('chat.realtime.enabled')
            && filled(config('broadcasting.connections.chat.key')) && filled(config('broadcasting.connections.chat.secret'));
    }

    public function eligible(User $user): bool
    {
        if (! $this->configured() || ! $user->approved_at || $user->isExternalClinicianAccount()) {
            return false;
        }

        return app(OpsMessageVisibilityService::class)->isCurrentStaff($user)
            || $user->portalClients()->get()->contains(fn ($client) => $user->canAccessClientPortal($client));
    }

    /**
     * Tell everyone who may read this conversation that it changed, after the transaction commits.
     *
     * @param  string|array<int, string>  $kinds  what changed, e.g. message, edit, reaction, call
     * @param  string|null  $inbox  a list-level reason (added, archived, renamed) sends inbox.changed instead
     */
    public function queueConversation(int $conversationId, ?int $onlyUserId = null, string|array $kinds = 'message', ?string $callId = null, ?string $inbox = null): void
    {
        if (! $this->configured()) {
            return;
        }
        $kinds = array_values(array_unique((array) $kinds));
        $dispatch = function () use ($conversationId, $onlyUserId, $kinds, $callId, $inbox): void {
            try {
                Bus::dispatch(new WakeChatClients($conversationId, $onlyUserId, $kinds, $callId, $inbox));
            } catch (\Throwable $error) {
                // The committed message/change cursor is authoritative. A failed wake-up
                // must not turn a committed mutation into a misleading write failure.
                report($error);
            }
        };
        DB::transactionLevel() ? DB::afterCommit($dispatch) : $dispatch();
    }

    /** @param  array<int, string>  $kinds */
    public function publish(int $conversationId, ?int $onlyUserId = null, array $kinds = ['message'], ?string $callId = null, ?string $inbox = null): void
    {
        if (! $this->configured() || ! ($conversation = OpsConversation::find($conversationId))) {
            return;
        }
        $ids = $this->recipients($conversation, $onlyUserId);
        if ($ids === []) {
            return;
        }
        if (! $this->usesLive()) {
            // Reverb, kept behind CHAT_REALTIME_ENABLED until Oblivion Live passes end to end.
            foreach (collect($ids)->map(fn (int $id) => new PrivateChannel('chat.user.'.$id))->chunk(100) as $chunk) {
                Broadcast::connection('chat')->broadcast($chunk->values()->all(), 'chat.changed', ['refresh' => true]);
            }

            return;
        }
        if ($inbox !== null) {
            app(OblivionLive::class)->publish($ids, 'inbox.changed', ['reason' => $inbox]);

            return;
        }
        $events = [['to' => $ids, 'type' => 'conversation.changed', 'data' => [
            'conversation_id' => $conversation->id,
            // The newest change ID lets a tab skip an event its cursor has already passed.
            'change_id' => (int) DB::table('chat_message_changes')->where('conversation_id', $conversation->id)->max('id'),
            'kinds' => $kinds ?: ['message'],
        ]]];
        if ($callId !== null) {
            // Call status goes to the call's own people; everyone else sees it through the conversation.
            $people = DB::table('chat_call_participants')->where('call_id', $callId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if ($callers = array_values(array_intersect($ids, $people))) {
                $events[] = ['to' => $callers, 'type' => 'call.changed', 'data' => ['call_id' => $callId, 'conversation_id' => $conversation->id]];
            }
        }
        app(OblivionLive::class)->publishMany($events);
    }

    /**
     * Members plus care-team cover staff, each passing allows() and eligible() now.
     *
     * @return array<int, int>
     */
    public function recipients(OpsConversation $conversation, ?int $onlyUserId = null): array
    {
        $people = User::whereIn('id', $conversation->participants()->pluck('user_id'))->get();
        if ($conversation->conversation_type === 'family' && $conversation->care_team_cover) {
            // Non-members reach allows() only through canView(); shortlist those staff in SQL.
            $people = $people->concat($conversation->client
                ? app(ClientFamilyCommunicationAccess::class)->candidateStaffQuery($conversation->client)->get() : collect());
        }

        return $people->unique('id')->filter(fn ($user) => (! $onlyUserId || $user->id === $onlyUserId)
            && $this->eligible($user) && app(ChatAccess::class)->allows($user, $conversation))
            ->map(fn ($user) => (int) $user->id)->values()->all();
    }

    /**
     * A list-level change addressed to named people (for example someone just removed, who no
     * longer passes allows()). The reason is all they learn; their list fetch re-checks access.
     *
     * @param  array<int, int>  $userIds
     */
    public function inboxChanged(array $userIds, string $reason): void
    {
        if ($this->usesLive()) {
            app(OblivionLive::class)->publish($userIds, 'inbox.changed', ['reason' => $reason]);
        }
    }

    /**
     * Send these people their unread count once the badge cache has been cleared.
     *
     * @param  array<int, int>  $userIds
     */
    public function unreadChanged(array $userIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === [] || ! $this->usesLive()) {
            return;
        }
        $dispatch = function () use ($ids): void {
            try {
                Bus::dispatch(new PublishChatUnread($ids));
            } catch (\Throwable $error) {
                report($error);
            }
        };
        DB::transactionLevel() ? DB::afterCommit($dispatch) : $dispatch();
    }

    /**
     * Let these people pass call signalling to each other on this call (spec section 6).
     *
     * @param  array<int, int>  $userIds
     */
    public function grantCall(string $callId, array $userIds): void
    {
        if ($this->usesLive()) {
            // Long enough for any call; leaving, removal or the end of the call revokes it sooner.
            app(OblivionLive::class)->grantCall($callId, $userIds, now()->addHours(12)->getTimestamp());
        }
    }

    /** @param  array<int, int>  $userIds */
    public function revokeCall(string $callId, array $userIds): void
    {
        if ($this->usesLive()) {
            app(OblivionLive::class)->revokeCall($callId, $userIds);
        }
    }

    /**
     * Close connections now: sign-out passes that sign-in's session ID, so only it closes;
     * lost access, offboarding, role change and suspension close all of the person's connections.
     *
     * @param  array<int, int>  $userIds
     */
    public function revoke(array $userIds, ?string $sessionId = null): void
    {
        if ($this->usesLive()) {
            app(OblivionLive::class)->revoke($userIds, $sessionId === null ? [] : [OblivionLive::sessionHash($sessionId)]);
        }
    }

    /**
     * A bell entry was added or cleared for these people.
     *
     * @param  array<int, int>  $userIds
     */
    public function noticeChanged(array $userIds, string $kind): void
    {
        if ($this->usesLive()) {
            app(OblivionLive::class)->publish($userIds, 'notice', ['kind' => $kind]);
        }
    }
}
