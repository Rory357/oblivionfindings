<?php

namespace App\Services\Chat;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ChatAvailability
{
    public const VALUES = ['available', 'busy', 'dnd', 'off_duty', 'unavailable'];

    /** Only non-essential Chat delivery uses this; operational alerts keep their own rules. */
    public function deferUntil(User $user): ?CarbonInterface
    {
        if (app(ChatPersonalPreferences::class)->quietIds([$user->id])->contains($user->id)) {
            return now()->addMinutes(5);
        }
        $state = DB::table('chat_preferences')->where('user_id', $user->id)->value('availability');

        return $this->quietUntil($state, (bool) $user->dnd_enabled, $user->dnd_until);
    }

    public function quietUntil(?string $state, bool $dnd, ?CarbonInterface $until): ?CarbonInterface
    {
        // An expired global DND timer must not cause an off-duty retry loop.
        if (in_array($state, ['dnd', 'off_duty'], true)) {
            return now()->addMinutes(5);
        }
        if ($dnd && (! $until || $until->isFuture())) {
            return $until ?? now()->addMinutes(5);
        }

        return null;
    }
}
