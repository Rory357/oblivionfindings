<?php

namespace App\Services\Medication\Alerts;

use App\Models\AppSetting;
use App\Models\MedicationSiteSetting;
use App\Services\Medication\Settings\MedicationSettingsRegistry;

/**
 * The saved "who gets it" for medication alerts (P11 B2), read the way the
 * settings page saved them: a value nobody has saved is the catalogue's
 * default, shown as "Default — not yet reviewed".
 */
class MedicationAlertSettings
{
    public function __construct(private readonly MedicationSettingsRegistry $registry) {}

    /**
     * Who gets one alert, or null when the alert isn't offered.
     *
     * @return array{inapp: bool, email: bool, push: bool, follow_up: bool, groups: list<string>, people: list<int>}|null
     */
    public function forAlert(string $alert): ?array
    {
        $definition = $this->registry->definition('alerts', $alert);
        if ($definition === null) {
            return null;
        }
        $stored = AppSetting::query()->where('key', $definition->storageKey)->value('value');

        return json_decode($definition->normalise($stored), true);
    }

    /** @return list<int> The extra people a house added for this alert. */
    public function extras(string $alert, ?int $siteId): array
    {
        $definition = $this->registry->definition('alertExtra', $alert);
        if ($definition === null || $siteId === null) {
            return [];
        }
        $stored = MedicationSiteSetting::query()
            ->where('site_id', $siteId)
            ->where('key', $definition->storageKey)
            ->value('value');

        return json_decode($definition->normalise($stored), true);
    }

    /** Email and push leave out client names and medicines (default on). */
    public function privateDelivery(): bool
    {
        $definition = $this->registry->definition('delivery', 'private');
        if ($definition === null) {
            return true;
        }

        return $definition->normalise(AppSetting::query()->where('key', $definition->storageKey)->value('value')) !== 'no';
    }

    /**
     * Follow-up for alerts with Follow up on (B2 chunk 3). Null numbers are
     * off; a re-alert needs both its interval and its count.
     *
     * @return array{realert_every: int|null, realert_max: int|null, attended: string, escalate_after: int|null, escalate_to: list<string>}
     */
    public function followUp(): array
    {
        $value = function (string $key): string {
            $definition = $this->registry->definition('delivery', $key);

            return $definition === null
                ? MedicationSettingsRegistry::FOLLOW_UP_OFF
                : $definition->normalise(AppSetting::query()->where('key', $definition->storageKey)->value('value'));
        };
        $minutes = fn (string $v): ?int => $v === MedicationSettingsRegistry::FOLLOW_UP_OFF || ! ctype_digit($v) ? null : (int) $v;
        $every = $minutes($value('realert_every'));
        $max = $minutes($value('realert_max'));

        return [
            'realert_every' => $every !== null && $max !== null ? $every : null,
            'realert_max' => $every !== null && $max !== null ? $max : null,
            'attended' => $value('attended'),
            'escalate_after' => $minutes($value('escalate_after')),
            'escalate_to' => json_decode($value('escalate_to'), true) ?: [],
        ];
    }

    /** @return list<string> The channels this alert is sent on today. */
    public function channels(string $alert): array
    {
        $setting = $this->forAlert($alert);
        if ($setting === null) {
            return [];
        }

        return array_values(array_filter(
            MedicationAlertCatalogue::CHANNELS_BUILT,
            fn (string $channel): bool => (bool) ($setting[$channel] ?? false),
        ));
    }
}
