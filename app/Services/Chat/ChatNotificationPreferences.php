<?php

namespace App\Services\Chat;

use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;

final class ChatNotificationPreferences
{
    public const MESSAGE_EVENTS = ['chat.message.received', 'chat.mentioned', 'chat.family.reply'];

    public const EVENTS = [...self::MESSAGE_EVENTS, 'chat.family.assigned', 'chat.call.missed'];

    public static function supports(string $key): bool
    {
        return in_array($key, self::EVENTS, true);
    }

    /** Resolve the same defaults for settings and dispatch. Unimplemented transports stay off. */
    public static function resolve(?object $personal, iterable $roles): array
    {
        if ($personal) {
            return ['enabled' => (bool) $personal->enabled, 'inapp' => (bool) $personal->channel_inapp, 'email' => false, 'push' => false];
        }

        $found = $enabled = $inapp = false;
        foreach ($roles as $role) {
            $found = true;
            $enabled = $enabled || (bool) $role->enabled;
            $inapp = $inapp || ($role->enabled && $role->channel_inapp);
        }

        return ['enabled' => ! $found || $enabled, 'inapp' => ! $found || $inapp, 'email' => false, 'push' => false];
    }

    public function forUser(User $user, string $key): array
    {
        if (! self::supports($key)) {
            return ['enabled' => false, 'inapp' => false, 'email' => false, 'push' => false];
        }
        $personal = UserNotificationPreference::where('user_id', $user->id)->where('key', $key)->first();
        $roles = $personal ? [] : RoleNotificationPreference::whereIn('role_id', $user->roles()->pluck('roles.id'))->where('key', $key)->get();

        return self::resolve($personal, $roles);
    }

    /** Independent of in-app opt-in. Browser dispatch stays behind its operational rollout gate. */
    public function browserPushEnabled(User $user, string $key): bool
    {
        if (! config('chat.notifications.browser_push_enabled') || ! in_array($key, self::MESSAGE_EVENTS, true)) {
            return false;
        }
        $personal = UserNotificationPreference::where('user_id', $user->id)->where('key', $key)->first();
        if ($personal) {
            return $personal->enabled && $personal->channel_push;
        }

        return RoleNotificationPreference::whereIn('role_id', $user->roles()->pluck('roles.id'))->where('key', $key)
            ->where('enabled', true)->where('channel_push', true)->exists();
    }
}
