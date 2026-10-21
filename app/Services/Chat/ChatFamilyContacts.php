<?php

namespace App\Services\Chat;

use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\Shift;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Support\Collection;

final class ChatFamilyContacts
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly ClientFamilyCommunicationAccess $family,
        private readonly OpsMessageVisibilityService $staff,
    ) {}

    /** Named contact choices use the same current authority as conversation creation. */
    public function namedPeople(Client $client): Collection
    {
        $client->loadMissing(['keyWorker', 'supportWorkers']);
        $shiftWorkers = Shift::where('client_id', $client->id)
            ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
            ->where('ends_at', '>=', now()->subDays(7))->with('staff')->get()->pluck('staff')->filter();

        return $client->supportWorkers->concat($shiftWorkers)->push($client->keyWorker)->filter()->unique('id')
            ->filter(fn (User $worker) => $this->family->canManage($worker, $client))->values();
    }

    /** Creation callers hold the portal membership lock; the conversation lock serializes audience changes. */
    public function privateConversation(Client $client, User $actor, ?int $namedWorkerId, bool $lock = false): ?OpsConversation
    {
        $query = OpsConversation::query()
            ->where('conversation_type', 'family')->where('client_id', $client->id)
            ->where('chat_created_by', $actor->id)
            ->where('family_contact_kind', $namedWorkerId === null ? 'care_team' : 'named_worker')
            ->where('family_contact_user_id', $namedWorkerId)
            ->where('care_team_cover', true)->where('is_archived', false)
            ->whereHas('participants', fn ($members) => $members->where('user_id', $actor->id)->where('is_archived_for_user', false))
            ->with(['participants.user', 'client'])->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->first(function (OpsConversation $conversation) use ($actor, $client, $namedWorkerId): bool {
            if (! $this->access->allows($actor, $conversation, true)) {
                return false;
            }
            if ($namedWorkerId !== null && ! $conversation->participants->contains('user_id', $namedWorkerId)) {
                return false;
            }

            // Do not reopen a shared-relative audience from a private-contact
            // shortcut, even if that other relative currently has revoked access.
            return $conversation->participants->every(fn ($member) => (int) $member->user_id === (int) $actor->id
                || $member->role === OpsConversationParticipant::COVER_READER
                || ($member->role !== 'family' && $member->user && $this->staff->isCurrentStaff($member->user)
                    && $this->family->canManage($member->user, $client)));
        });
    }
}
