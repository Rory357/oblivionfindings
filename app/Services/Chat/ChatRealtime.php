<?php

namespace App\Services\Chat;

use App\Jobs\WakeChatClients;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final class ChatRealtime
{
    public function configured(): bool
    {
        return config('chat.shared_experience') && config('chat.realtime.enabled')
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

    public function queueConversation(int $conversationId, ?int $onlyUserId = null): void
    {
        if (! $this->configured()) {
            return;
        }
        $dispatch = function () use ($conversationId, $onlyUserId): void {
            try {
                Bus::dispatch(new WakeChatClients($conversationId, $onlyUserId));
            } catch (\Throwable $error) {
                // The committed message/change cursor is authoritative. A failed wake-up
                // must not turn a committed mutation into a misleading write failure.
                report($error);
            }
        };
        DB::transactionLevel() ? DB::afterCommit($dispatch) : $dispatch();
    }

    public function publish(int $conversationId, ?int $onlyUserId = null): void
    {
        if (! $this->configured() || ! ($conversation = OpsConversation::find($conversationId))) {
            return;
        }
        $people = User::whereIn('id', $conversation->participants()->pluck('user_id'))->get();
        if ($conversation->conversation_type === 'family' && $conversation->care_team_cover) {
            // Non-members reach allows() only through canView(); shortlist those staff in SQL.
            $people = $people->concat($conversation->client
                ? app(ClientFamilyCommunicationAccess::class)->candidateStaffQuery($conversation->client)->get() : collect());
        }
        $channels = $people->unique('id')->filter(fn ($user) => (! $onlyUserId || $user->id === $onlyUserId)
            && $this->eligible($user) && app(ChatAccess::class)->allows($user, $conversation))
            ->map(fn ($user) => new PrivateChannel('chat.user.'.$user->id))->values();
        foreach ($channels->chunk(100) as $chunk) {
            Broadcast::connection('chat')->broadcast($chunk->all(), 'chat.changed', ['refresh' => true]);
        }
    }
}
