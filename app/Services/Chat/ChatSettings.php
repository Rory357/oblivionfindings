<?php

namespace App\Services\Chat;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\OpsConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChatSettings
{
    public const KEY = 'chat.configuration';

    public const OVERRIDABLE = ['enabled', 'family_enabled', 'files_enabled', 'voice_notes_enabled', 'max_file_mb', 'max_files',
        'message_change_window_minutes', 'calls_enabled', 'family_calls_enabled', 'max_call_participants',
        'response_expectation', 'response_schedule', 'urgent_contact'];

    public const DEFAULTS = [
        'enabled' => true,
        'family_enabled' => true,
        'files_enabled' => true,
        'voice_notes_enabled' => true,
        'max_file_mb' => 20,
        'max_files' => 5,
        'message_change_window_minutes' => null,
        'calls_enabled' => false,
        'family_calls_enabled' => false,
        'max_call_participants' => 8,
        'response_expectation' => '',
        'response_schedule' => null,
        'urgent_contact' => null,
        'site_overrides' => [],
    ];

    public function read(?int $siteId = null): array
    {
        $stored = AppSetting::query()->where('key', self::KEY)->value('value');

        return self::effective(is_array($stored) ? $stored : [], $siteId);
    }

    public static function effective(array $stored, ?int $siteId = null): array
    {
        $value = array_replace(self::DEFAULTS, $stored);
        $value['version'] = (int) ($value['version'] ?? 0);
        if ($siteId !== null) {
            $override = $value['site_overrides'][(string) $siteId] ?? [];
            // Missing fields inherit; explicit null remains meaningful for an unconfigured policy.
            $value = array_replace($value, array_intersect_key($override, array_flip(self::OVERRIDABLE)));
        }

        return $value;
    }

    public function forConversation(OpsConversation $conversation): array
    {
        // Scope follows the canonical record, never the viewer's currently selected site.
        // Private staff conversations without a canonical site use organisation defaults.
        return $this->read($conversation->client?->site_id ?? $conversation->channel_site_id);
    }

    /** $policy: the conversation's settings when the caller has already read them. */
    public function publicPolicy(OpsConversation $conversation, ?array $policy = null): array
    {
        return array_intersect_key($policy ?? $this->forConversation($conversation), array_flip([
            'enabled', 'family_enabled', 'files_enabled', 'voice_notes_enabled', 'max_file_mb', 'max_files', 'response_expectation',
        ])) + ['max_voice_note_seconds' => ChatVoiceInspector::MAX_SECONDS, 'voice_notes_available' => app(ChatVoiceInspector::class)->configured(), 'draft_recovery_enabled' => (bool) config('chat.draft_recovery', false)];
    }

    public function save(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            AppSetting::query()->firstOrCreate(['key' => self::KEY], ['value' => self::DEFAULTS + ['version' => 0]]);
            $setting = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $before = array_replace(self::DEFAULTS, $setting->value);
            abort_unless((int) ($before['version'] ?? 0) === (int) $input['version'], 409, 'Chat settings changed. Reload and review the latest settings before saving.');
            $value = array_replace(array_intersect_key($before, self::DEFAULTS), array_intersect_key($input, self::DEFAULTS));
            $value['version'] = (int) $input['version'] + 1;
            $setting->update(['value' => $value]);
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'chat.settings.updated',
                'auditable_type' => AppSetting::class,
                'auditable_id' => $setting->id,
                'meta' => ['before' => $before, 'after' => $value],
            ]);

            return $this->read();
        });
    }
}
