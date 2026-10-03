<?php

namespace App\Services\Medication\Reporting;

use App\Models\AppSetting;
use App\Services\Medication\Settings\MedicationSettingDefinition as Definition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Approved P09 v1.1 README Q7/Q11, approval 806334f96. These are proposed
 * defaults only: no AppSetting rows are inserted, SAC remains off, and P11's
 * reviewed() returns null until a settings editor explicitly reviews them.
 * No retention cleanup or automatic clinical classification occurs here.
 */
final class RecordsReportingSettings
{
    public const RETENTION = 'medication_records_retention';
    public const SAC = 'medication_error_sac';

    public static function group(): MedicationSettingGroup
    {
        $make = fn ($key, $storage, $label, $options, $default, $rank = null) => new Definition('records', $key, $storage, Definition::SCOPE_ORGANISATION, 'records', $label, $options, $default, $rank);
        $sacOptions = ['1' => 'SAC 1', '2' => 'SAC 2', '3' => 'SAC 3', '4' => 'SAC 4'];

        return new MedicationSettingGroup('records', MedicationSettingsRegistry::VIEW_RULES, 'From the next error closed; existing records are kept', 'medications.records_reporting.updated', [
            $make('retention', self::RETENTION, 'Keep medication records for', ['y10' => '10 years after the person’s last service', 'y15' => '15 years after the person’s last service', 'y20' => '20 years after the person’s last service'], 'y10', ['y10', 'y15', 'y20']),
            $make('sac', self::SAC, 'Add SAC ratings when an error is closed', ['off' => 'Off', 'on' => 'On'], 'off', ['off', 'on']),
            $make('sac_death', self::SAC.'.death', 'Death', $sacOptions, '1'),
            $make('sac_moderate', self::SAC.'.moderate', 'Moderate harm', $sacOptions, '3'),
            $make('sac_minor', self::SAC.'.minor', 'Minor or no harm', $sacOptions, '4'),
        ]);
    }

    public function enabled(): bool
    {
        return $this->setting(self::SAC, 'off') === 'on';
    }

    /** P08b calls this during the locked close command; preselection is never confirmation. */
    public function confirmation(?string $reached, ?string $harm, mixed $confirmed): ?int
    {
        if (! $this->enabled() || $reached === 'no') {
            return null;
        }
        if ($reached !== 'yes') {
            throw ValidationException::withMessages(['reached_client' => 'Confirm whether the error reached the person before assigning a SAC rating.']);
        }
        $allowed = in_array($harm, ['severe', 'severe_permanent'], true) ? [1, 2] : [1, 2, 3, 4];
        if (! in_array((string) $confirmed, array_map('strval', $allowed), true)) {
            throw ValidationException::withMessages(['confirmed_sac' => 'Confirm the SAC rating for this error before closing it.']);
        }

        return (int) $confirmed;
    }

    public function preselection(?string $reached, ?string $harm): ?int
    {
        if (! $this->enabled() || $reached !== 'yes') {
            return null;
        }
        $key = match ($harm) { 'death' => 'death', 'moderate' => 'moderate', 'minor', 'none', 'no_harm' => 'minor', default => null };

        $value = $key === null ? null : $this->setting(self::SAC.'.'.$key, ['death' => '1', 'moderate' => '3', 'minor' => '4'][$key]);

        return in_array((string) $value, ['1', '2', '3', '4'], true) ? (int) $value : null;
    }

    private function setting(string $key, string $default): mixed
    {
        return AppSetting::query()->where('key', $key)->first()?->value ?? $default;
    }
}
