<?php

namespace App\Models;

use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\DoseTimingSettings;
use App\Support\SecurityPolicy;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        // Supports either scalar (e.g. "Patient") or object/array values.
        'value' => 'json',
    ];

    protected static function booted(): void
    {
        $bustPolicyCache = function (self $setting): void {
            if (in_array($setting->key, SecurityPolicy::keys(), true)) {
                SecurityPolicy::flushCache();
            }
        };

        static::saved($bustPolicyCache);
        static::deleted($bustPolicyCache);

        // Dose timing and competency limits are read once per request; a save makes them re-read.
        $refreshMedicationSettings = static function (self $setting): void {
            DoseTimingSettings::settingChanged($setting->key);
            CompetencyPolicySettings::settingChanged($setting->key);
        };
        static::saved($refreshMedicationSettings);
        static::deleted($refreshMedicationSettings);
    }
}
