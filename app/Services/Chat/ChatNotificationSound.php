<?php

namespace App\Services\Chat;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChatNotificationSound
{
    public function enabledFor(User $actor): bool
    {
        return ($actor->notification_sounds_enabled ?? true)
            && (bool) (DB::table('chat_preferences')->where('user_id', $actor->id)->value('sound_enabled') ?? true);
    }
}
