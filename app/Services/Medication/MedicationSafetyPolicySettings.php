<?php

namespace App\Services\Medication;

use App\Models\AppSetting;

/**
 * Organisation-wide medication safety rules that the organisation, not the
 * code, decides (eMAR P0 decisions of 28 Sep 2026: EM-07 profile allergies,
 * NF-03 competency restrictions and areas).
 *
 * Every rule defaults to the behaviour that existed before the rule was
 * introduced, so nothing new is enforced until an eMAR settings manager
 * turns it on. Values live in `app_settings`; an unknown stored value falls
 * back to the default rather than guessing.
 */
class MedicationSafetyPolicySettings
{
    /** A health-profile allergy (no severity recorded) matches the medicine. */
    public const PROFILE_ALLERGY_MATCH = 'medications.safety.profile_allergy_match';

    /** The administrator's current competency assessment is marked restricted. */
    public const RESTRICTED_COMPETENCY = 'medications.competency.restricted_mode';

    /** Controlled-drug / covert areas of the current assessment were not passed. */
    public const COMPETENCY_AREAS = 'medications.competency.area_enforcement';

    public const OPTIONS = [
        self::PROFILE_ALLERGY_MATCH => ['warn', 'block'],
        self::RESTRICTED_COMPETENCY => ['off', 'block', 'cosigner'],
        self::COMPETENCY_AREAS => ['off', 'failed', 'failed_or_not_seen'],
    ];

    public const DEFAULTS = [
        self::PROFILE_ALLERGY_MATCH => 'warn',
        self::RESTRICTED_COMPETENCY => 'off',
        self::COMPETENCY_AREAS => 'off',
    ];

    public function profileAllergyMatch(): string
    {
        return $this->value(self::PROFILE_ALLERGY_MATCH);
    }

    public function restrictedCompetencyMode(): string
    {
        return $this->value(self::RESTRICTED_COMPETENCY);
    }

    public function competencyAreaEnforcement(): string
    {
        return $this->value(self::COMPETENCY_AREAS);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $stored = AppSetting::query()
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key');

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->normalise($key, $stored[$key] ?? null);
        }

        return $values;
    }

    /**
     * Which rules someone has deliberately saved. A rule with no stored row is
     * still on its default and shows "Default — not yet reviewed".
     *
     * @return array<string, bool>
     */
    public function reviewed(): array
    {
        $stored = AppSetting::query()
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('key')
            ->all();

        $reviewed = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $reviewed[$key] = in_array($key, $stored, true);
        }

        return $reviewed;
    }

    /** @param  array<string, string>  $values  Validated against OPTIONS by the caller. */
    public function save(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->normalise($key, $values[$key])],
            );
        }
    }

    private function value(string $key): string
    {
        return $this->normalise(
            $key,
            AppSetting::query()->where('key', $key)->value('value'),
        );
    }

    private function normalise(string $key, mixed $value): string
    {
        return is_string($value) && in_array($value, self::OPTIONS[$key], true)
            ? $value
            : self::DEFAULTS[$key];
    }
}
