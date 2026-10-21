<?php

namespace App\Services\Chat;

use App\Models\ChatCallParticipant;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChatCallAudience
{
    public function __construct(private readonly ChatAccess $access) {}

    /** Membership and current client/site authority determine who may be invited. */
    public function people(User $viewer, OpsConversation $conversation): Collection
    {
        $members = $conversation->members()->pluck('user_id');
        // A non-member passes allows() only as current staff with canManage(), so cover
        // candidates are shortlisted in SQL; allows() remains the final check.
        $cover = $conversation->conversation_type === 'family' && $conversation->care_team_cover && $conversation->client
            ? app(ClientFamilyCommunicationAccess::class)->candidateStaffQuery($conversation->client, true)->select('users.id') : null;

        return User::query()->where('id', '!=', $viewer->id)
            ->where(fn ($query) => $query->whereIn('id', $members)
                ->when($cover, fn ($candidates) => $candidates->orWhereIn('id', $cover)))
            ->get()->filter(fn ($person) => $this->access->allows($person, $conversation, true))->values();
    }

    public function availableIds(Collection $people): Collection
    {
        $quiet = app(ChatPersonalPreferences::class)->quietIds($people->pluck('id')->all());
        $available = DB::table('chat_preferences')->whereIn('user_id', $people->pluck('id'))
            ->where('availability', 'available')->where('last_seen_at', '>', now()->subSeconds(45))->pluck('user_id');
        $busy = ChatCallParticipant::whereIn('user_id', $people->pluck('id'))->whereIn('status', ['accepted', 'connected'])
            ->whereHas('call', fn ($query) => $query->whereNull('ended_at'))->pluck('user_id');

        return $people->filter(fn ($person) => $available->contains($person->id) && ! $busy->contains($person->id) && ! $quiet->contains($person->id)
            && ! ($person->dnd_enabled && (! $person->dnd_until || $person->dnd_until->isFuture())))
            ->pluck('id');
    }

    public function canCallCareTeam(User $viewer, OpsConversation $conversation): bool
    {
        return $conversation->conversation_type === 'family' && $conversation->care_team_cover
            && $conversation->client && $viewer->canAccessClientPortal($conversation->client);
    }

    public function responders(User $viewer, OpsConversation $conversation): Collection
    {
        if (! $this->canCallCareTeam($viewer, $conversation)) {
            return collect();
        }

        return $this->people($viewer, $conversation)
            ->reject(fn ($person) => $person->canAccessClientPortal($conversation->client))->values();
    }
}
