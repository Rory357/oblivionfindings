<?php

namespace App\Services\Chat;

use App\Models\UserPushSubscription;
use Illuminate\Support\Str;

/** Compatibility evidence from our worker handshake, not a replacement for user/device authority. */
final class ChatBrowserProtocol
{
    public const VERSION = 2;

    public static function supports(UserPushSubscription $subscription): bool
    {
        return $subscription->provider === 'webpush'
            && (int) $subscription->chat_protocol_version === self::VERSION
            && Str::isUuid($subscription->chat_registration_id ?? '');
    }
}
