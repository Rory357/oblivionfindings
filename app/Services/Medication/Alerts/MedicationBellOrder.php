<?php

namespace App\Services\Medication\Alerts;

use App\Models\AppSetting;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Optional ordering of the existing bell; only shared unattended follow-ups move. */
final class MedicationBellOrder
{
    public function apply(MorphMany $query): MorphMany
    {
        if (! SchemaCache::hasTable('medication_alerts') || AppSetting::query()->where('key', MedicationSettingsRegistry::DELIVERY_PIN_UNATTENDED)->value('value') !== 'yes') {
            return $query;
        }

        // Order BEFORE the bell's limit. JSON stores the canonical alert ID;
        // shared attendance, rather than an individual's read flag, stops pinning.
        return $query->orderByRaw('case when exists (select 1 from medication_alerts where medication_alerts.id = json_extract(notifications.data, ?) and medication_alerts.follow_up = ? and medication_alerts.open_key is not null and medication_alerts.attended_at is null) then 0 else 1 end', ['$.medication_alert_id', true]);
    }
}
