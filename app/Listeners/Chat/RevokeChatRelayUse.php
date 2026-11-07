<?php

namespace App\Listeners\Chat;

use App\Models\User;
use App\Services\Chat\OblivionRelay;
use Illuminate\Events\Dispatcher;

/**
 * Oblivion Relay (spec 3.7): suspending or deactivating an account ends the person's relay use at
 * once. Suspension clears approval, the same change Oblivion Live's revoke hooks into.
 */
final class RevokeChatRelayUse
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen('eloquent.updated: '.User::class, [self::class, 'accountChanged']);
    }

    public function accountChanged(User $user): void
    {
        if ($user->wasChanged('approved_at') && ! $user->approved_at) {
            app(OblivionRelay::class)->revokePerson($user, 'access_removed');
        }
    }
}
