<?php

namespace App\Services\Chat;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatPersonalPreferences
{
    public const DEFAULTS = [
        'text_size' => 'normal', 'spacing' => 'comfortable', 'sending' => 'enter_sends',
        'quiet_enabled' => false, 'show_previews' => true,
    ];

    public function quietIds(array $ids): Collection
    {
        // Existing Chat delivery can run before this optional preferences migration.
        // No quiet preference can be saved until its table exists.
        if ($ids === [] || ! Schema::hasTable('chat_personal_preferences')) {
            return collect();
        }

        return DB::table('chat_personal_preferences')->whereIn('user_id', $ids)
            ->where('values->quiet_enabled', true)->pluck('user_id');
    }

    public function read(User $actor): array
    {
        $stored = DB::table('chat_personal_preferences')->where('user_id', $actor->id)->value('values');
        $values = [...self::DEFAULTS, ...array_intersect_key(json_decode($stored ?? '{}', true, flags: JSON_THROW_ON_ERROR), self::DEFAULTS),
            'theme' => $actor->theme ?? 'system',
            'sound_enabled' => (bool) (DB::table('chat_preferences')->where('user_id', $actor->id)->value('sound_enabled') ?? true)];

        return ['actor_id' => (int) $actor->id, 'version' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), 'values' => $values];
    }

    public function update(User $actor, array $changes, string $version): array
    {
        return DB::transaction(function () use ($actor, $changes, $version) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = $this->read($actor);
            abort_unless(hash_equals($current['version'], $version), 409, 'Your Chat preferences changed elsewhere. Review the latest preferences before trying again.');
            $values = [...$current['values'], ...$changes];
            DB::table('chat_personal_preferences')->insertOrIgnore(['user_id' => $actor->id, 'values' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('chat_personal_preferences')->where('user_id', $actor->id)->update([
                'values' => json_encode(array_intersect_key($values, self::DEFAULTS), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            if (array_key_exists('theme', $changes)) {
                $actor->update(['theme' => $changes['theme']]);
            }
            if (array_key_exists('sound_enabled', $changes)) {
                DB::table('chat_preferences')->insertOrIgnore(['user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('chat_preferences')->where('user_id', $actor->id)->update(['sound_enabled' => $changes['sound_enabled'], 'updated_at' => now()]);
            }

            return $this->read($actor);
        });
    }
}
