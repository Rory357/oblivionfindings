<?php

namespace App\Services\Clients;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\OpsConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientPortalMembershipService
{
    public function __construct(private readonly HrCurrentStaffService $currentStaff) {}

    public function link(Client $client, User $user, string $relation): void
    {
        $this->assertLinkable($user);

        $client->portalUsers()->syncWithoutDetaching([
            $user->id => ['relation' => $relation],
        ]);
    }

    public function assertLinkable(User $user): void
    {
        if ($this->currentStaff->historicalProfileFor($user)) {
            throw ValidationException::withMessages([
                'email' => 'Use a separate portal identity for a current or former staff member.',
            ]);
        }
    }

    public function unlink(Client $client, User $user): bool
    {
        return DB::transaction(function () use ($client, $user): bool {
            $isLinked = DB::table('client_portal_users')
                ->where('client_id', $client->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->exists();

            if (! $isLinked) {
                return false;
            }

            $client->portalUsers()->detach($user->id);

            // A later re-link must not silently restore access to historical
            // private conversations. Message authorship remains on OpsMessage;
            // only the obsolete access-granting participant rows are removed.
            OpsConversationParticipant::query()
                ->where('user_id', $user->id)
                ->whereHas('conversation', fn ($query) => $query
                    ->where('client_id', $client->id)
                    ->where('conversation_type', 'family'))
                ->delete();

            return true;
        });
    }

    public function withLockedMembership(Client $client, User $user, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback, $client, $user): mixed {
            abort_if($this->currentStaff->historicalProfileFor($user), 403);

            $isLinked = DB::table('client_portal_users')
                ->where('client_id', $client->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->exists();

            abort_unless($isLinked, 403);
            $currentUser = $user->fresh();
            abort_unless(
                $currentUser && $currentUser->canAccessClientPortal($client),
                403,
            );

            return $callback();
        });
    }
}
