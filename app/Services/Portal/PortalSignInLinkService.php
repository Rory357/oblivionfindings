<?php

namespace App\Services\Portal;

use App\Models\PortalSignInLink;
use App\Models\User;
use App\Notifications\PortalSignInLinkNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Emailed family portal sign-in links. Requesting a link never reveals whether
 * an account exists: the caller always shows "check your email", and links are
 * only created for approved portal accounts.
 */
class PortalSignInLinkService
{
    private const PER_EMAIL_LIMIT = 3;

    private const PER_EMAIL_DECAY_SECONDS = 600;

    /**
     * Creates and emails a link when the email belongs to an approved portal
     * account. Returns the plain device secret the caller must store in the
     * requesting browser's cookie (also returned when no link was sent, so the
     * response looks the same either way).
     */
    public function request(string $email, ?string $ip): string
    {
        $email = strtolower(trim($email));
        $deviceSecret = Str::random(40);
        $limiterKey = 'portal-sign-in-link:'.sha1($email);

        if (RateLimiter::tooManyAttempts($limiterKey, self::PER_EMAIL_LIMIT)) {
            return $deviceSecret;
        }
        RateLimiter::hit($limiterKey, self::PER_EMAIL_DECAY_SECONDS);

        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User || ! $user->isApproved() || ! $user->isPortalAccount()) {
            return $deviceSecret;
        }

        $token = Str::random(64);
        $link = DB::transaction(function () use ($user, $token, $deviceSecret, $ip): PortalSignInLink {
            PortalSignInLink::query()
                ->where('user_id', $user->id)
                ->open()
                ->lockForUpdate()
                ->update(['revoked_at' => now()]);

            return PortalSignInLink::query()->create([
                'user_id' => $user->id,
                'token_hash' => PortalSignInLink::hash($token),
                'device_hash' => PortalSignInLink::hash($deviceSecret),
                'expires_at' => now()->addMinutes(PortalSignInLink::VALID_FOR_MINUTES),
                'requested_ip' => $ip,
            ]);
        }, 3);

        $user->notify(new PortalSignInLinkNotification(
            route('portal.sign-in-link.consume', ['token' => $token]),
        ));

        AuditLogger::log('portal.sign_in_link.requested', null, [
            'actor_id' => (int) $user->id,
            'link_id' => (int) $link->id,
        ]);

        return $deviceSecret;
    }

    /**
     * Uses a link once. Returns the user to sign in, or a reason the link
     * cannot be used: 'invalid' (unknown, used, replaced or expired) or
     * 'device' (opened in a different browser from the one that asked).
     *
     * @return array{user: User|null, failure: 'invalid'|'device'|null}
     */
    public function consume(string $token, ?string $deviceSecret): array
    {
        if (strlen($token) !== 64 || ! ctype_alnum($token)) {
            return ['user' => null, 'failure' => 'invalid'];
        }

        return DB::transaction(function () use ($token, $deviceSecret): array {
            $link = PortalSignInLink::query()
                ->where('token_hash', PortalSignInLink::hash($token))
                ->lockForUpdate()
                ->first();

            if (! $link instanceof PortalSignInLink || ! $link->isUsable()) {
                return ['user' => null, 'failure' => 'invalid'];
            }

            if (! is_string($deviceSecret) || $deviceSecret === ''
                || ! hash_equals($link->device_hash, PortalSignInLink::hash($deviceSecret))) {
                return ['user' => null, 'failure' => 'device'];
            }

            $user = User::query()->whereKey($link->user_id)->lockForUpdate()->first();
            if (! $user instanceof User || ! $user->isApproved() || ! $user->isPortalAccount()) {
                $link->forceFill(['revoked_at' => now()])->save();

                return ['user' => null, 'failure' => 'invalid'];
            }

            $link->forceFill(['used_at' => now()])->save();
            if ($user->email_verified_at === null) {
                // Opening the emailed link proves mailbox control.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            AuditLogger::logOrFail('portal.sign_in_link.used', null, [
                'actor_id' => (int) $user->id,
                'link_id' => (int) $link->id,
            ]);

            return ['user' => $user, 'failure' => null];
        }, 3);
    }
}
