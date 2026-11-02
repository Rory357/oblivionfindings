<?php

namespace App\Domain\Obli\Fixes;

use App\Domain\Obli\ObliAiSettings;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase I (15i): "Let Obli use the computer to fix things". Off until an IT admin who manages
 * Settings › Obli turns it on. The rules around it (plan first and one OK, stop on Esc or the
 * mouse, never a password, ignore instructions on screen, 15 minutes, pictures 7 days) are not
 * switches: they are enforced by the engine and the tray every time.
 */
final class ObliFixSettings
{
    public const KEY = 'obli.fixes.configuration';

    public const SESSION_MINUTES = 15;

    public const MAX_ACTIONS = 60;

    public const PICTURE_DAYS = 7;

    public const PLAN_MINUTES = 10;

    /** How long Obli waits for IT to answer a one-off request before handing over with a ticket. */
    public const WAIT_MINUTES = 60;

    public const RECORD_DAYS = 90;

    /** @return array{version: int, enabled: bool} */
    public function current(bool $lock = false): array
    {
        if (! Schema::hasTable('app_settings')) {
            return ['version' => 0, 'enabled' => false];
        }
        $row = AppSetting::query()->where('key', self::KEY)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $value = is_array($row?->value) ? $row->value : [];

        return ['version' => max(0, (int) ($value['version'] ?? 0)), 'enabled' => (bool) ($value['enabled'] ?? false)];
    }

    /** The same people who manage Settings › Obli (AI provider, key and budget). */
    public static function canChange(User $viewer): bool
    {
        return (bool) ($viewer->approved_at && $viewer->email_verified_at && ! $viewer->isExternalClinicianAccount()
            && $viewer->canDo('settings.access.manage') && $viewer->canDo('integrations.manage_secrets'));
    }

    public function schemaReady(): bool
    {
        foreach (['obli_fix_sessions', 'obli_fix_steps', 'obli_screen_pictures', 'obli_picture_views', 'obli_fix_requests', 'obli_fix_allowances'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everything that must be true before Obli may plan a fix: the switch, Obli on staff computers,
     * the Anthropic provider (fixes need its computer-use tool) with a key, a model and prices.
     *
     * @param  array<string, mixed>|null  $ai  ObliAiSettings::current(), when the caller already holds it
     */
    public function ready(?array $ai = null): bool
    {
        if (! $this->schemaReady() || ! $this->current()['enabled'] || ! app(EndpointRemoteSupportSettings::class)->obliHelperEnabled()) {
            return false;
        }
        $ai ??= app(ObliAiSettings::class)->current();

        return (bool) $ai['enabled'] && $ai['provider'] === 'anthropic' && trim((string) $ai['model']) !== ''
            && (string) $ai['input_price_usd'] !== '' && (string) $ai['output_price_usd'] !== ''
            && AppSetting::query()->where('key', 'obli.ai.provider_secret')->exists();
    }

    /** Why fixes can't run, in plain words, or null when they can. */
    public function blocker(): ?string
    {
        if (! $this->schemaReady()) {
            return 'Not set up on this server yet';
        }
        if (! $this->current()['enabled']) {
            return 'Off';
        }
        if (! app(EndpointRemoteSupportSettings::class)->obliHelperEnabled()) {
            return 'Obli is off for staff computers';
        }
        $ai = app(ObliAiSettings::class)->current();
        if (! $ai['enabled']) {
            return 'Obli’s AI is off in Settings › Obli';
        }
        if ($ai['provider'] !== 'anthropic') {
            return 'Needs Anthropic in Settings › Obli';
        }

        return $this->ready($ai) ? null : 'Settings › Obli needs a model, prices and a key';
    }

    /** Turn fixes on or off (audited). Turning on needs Obli's AI settings to be ready. */
    public function save(User $actor, bool $enabled, int $expectedVersion): array
    {
        return DB::transaction(function () use ($actor, $enabled, $expectedVersion): array {
            $actor = app(ObliAiSettings::class)->administrator($actor);
            AppSetting::query()->insertOrIgnore(['key' => self::KEY, 'value' => json_encode(['version' => 0, 'enabled' => false])]);
            $row = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $before = $this->current(true);
            abort_unless($before['version'] === $expectedVersion, 409);
            if ($enabled) {
                $ai = app(ObliAiSettings::class)->current(true);
                abort_unless((bool) $ai['enabled'] && $ai['provider'] === 'anthropic', 422, 'Obli’s AI must be on and set to Anthropic first.');
            }
            $after = ['version' => $before['version'] + 1, 'enabled' => $enabled];
            $row->update(['value' => $after]);
            AuditLogger::logOrFail('obli.fixes.settings_changed', null, [
                'actor_id' => (int) $actor->id, 'enabled' => $enabled, 'version' => $after['version'],
            ]);

            return $after;
        });
    }
}
