<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and reads family portal invitations. Issuing a new invitation revokes
 * any earlier open one for the same person, so only the latest email works.
 */
class PortalInvitationService
{
    public function issue(User $user, ?Client $client, ?User $inviter): PortalInvitation
    {
        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($user, $client, $inviter, $token): PortalInvitation {
            PortalInvitation::query()
                ->where('user_id', $user->id)
                ->open()
                ->lockForUpdate()
                ->update(['revoked_at' => now()]);

            return PortalInvitation::query()->create([
                'user_id' => $user->id,
                'client_id' => $client?->id,
                'invited_by' => $inviter?->id,
                'token_hash' => PortalInvitation::hashToken($token),
                'expires_at' => now()->addDays(PortalInvitation::VALID_FOR_DAYS),
            ]);
        }, 3);

        $user->notify(new PortalInvitationNotification(
            route('portal.invitations.show', ['token' => $token]),
            $inviter?->name,
            $client?->first_name,
        ));

        AuditLogger::log('portal.invitation.issued', null, [
            'actor_id' => $inviter?->id,
            'subject_user_id' => (int) $user->id,
            'invitation_id' => (int) $invitation->id,
            'client_id' => $client?->id,
        ]);

        return $invitation;
    }

    /** Looks an invitation up by its plain token; malformed tokens never touch the database. */
    public function find(string $token): ?PortalInvitation
    {
        if (strlen($token) !== 64 || ! ctype_alnum($token)) {
            return null;
        }

        return PortalInvitation::query()
            ->with(['user:id,name,email,role,approved_at', 'client:id,first_name', 'inviter:id,name'])
            ->where('token_hash', PortalInvitation::hashToken($token))
            ->first();
    }

    /**
     * Sends a fresh invitation for an expired one. Accepted or replaced
     * invitations cannot be renewed this way (a newer email already exists).
     */
    public function renew(PortalInvitation $invitation): ?PortalInvitation
    {
        if ($invitation->isAccepted() || $invitation->isRevoked() || ! $invitation->isExpired()) {
            return null;
        }

        $user = $invitation->user;
        if (! $user instanceof User || ! $user->isApproved() || ! $user->isPortalAccount()) {
            return null;
        }

        return $this->issue($user, $invitation->client, $invitation->inviter);
    }

    /** Masks an email for display to someone holding only an expired link: m•••@example.com. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
