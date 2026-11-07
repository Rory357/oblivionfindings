<?php

namespace App\Listeners\Chat;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\Chat\ChatRealtime;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Oblivion Live revocation (spec section 3 and requirement 7): signing out, losing access, a role
 * change or suspension closes the person's live connections within seconds. Signing out closes
 * only that sign-in; the others close every connection the person has.
 */
final class CloseChatLiveConnections
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Logout::class, [self::class, 'signedOut']);
        $events->listen('eloquent.updated: '.User::class, [self::class, 'accountChanged']);
        $events->listen(['eloquent.saved: '.RoleUser::class, 'eloquent.deleted: '.RoleUser::class], [self::class, 'roleChanged']);
        $events->listen(['eloquent.saved: '.HrEmployeeProfile::class, 'eloquent.deleted: '.HrEmployeeProfile::class], [self::class, 'employmentChanged']);
    }

    public function signedOut(Logout $event): void
    {
        if (! $event->user instanceof User || ! $this->live()) {
            return;
        }
        // The session still has its ID here; it is invalidated after the event.
        $session = app()->bound('request') && request()->hasSession() ? request()->session()->getId() : null;
        app(ChatRealtime::class)->revoke([(int) $event->user->getAuthIdentifier()], $session);
    }

    public function accountChanged(User $user): void
    {
        // Suspension clears approval; the legacy role column is still read by access checks.
        if ($this->live() && ($user->wasChanged('role') || ($user->wasChanged('approved_at') && ! $user->approved_at))) {
            app(ChatRealtime::class)->revoke([(int) $user->id]);
        }
    }

    public function roleChanged(RoleUser $membership): void
    {
        if ($this->live()) {
            app(ChatRealtime::class)->revoke([(int) $membership->user_id]);
        }
    }

    public function employmentChanged(HrEmployeeProfile $profile): void
    {
        if (! $this->live() || ! $profile->user_id || ($profile->exists && ! $profile->wasChanged(['is_active', 'start_date', 'end_date', 'user_id', 'deleted_at']))) {
            return;
        }
        $user = User::find($profile->user_id);
        // Only a person who may no longer use Chat loses their connections.
        if ($user && ! app(ChatRealtime::class)->eligible($user)) {
            app(ChatRealtime::class)->revoke([(int) $user->id]);
        }
    }

    private function live(): bool
    {
        return app(ChatRealtime::class)->usesLive();
    }
}
