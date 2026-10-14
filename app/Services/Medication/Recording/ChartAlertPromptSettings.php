<?php

namespace App\Services\Medication\Recording;

use App\Models\AppSetting;

/**
 * Medication settings › Medication rules › Safety checks › Chart alerts
 * (eMAR audit Lane A5, EA-005). How often a worker confirms a person's
 * "Prompt staff" chart alerts in the recording dialog: once a day each
 * (default), or every time they record a dose. The alerts are always shown;
 * only how often they must be confirmed changes.
 */
final class ChartAlertPromptSettings
{
    public const RECONFIRM = 'medications.chart_alerts.reconfirm';

    public const ONCE_A_DAY = 'day';

    public const EVERY_DOSE = 'dose';

    public const DEFAULT = self::ONCE_A_DAY;

    public function everyDose(): bool
    {
        return AppSetting::query()->where('key', self::RECONFIRM)->value('value') === self::EVERY_DOSE;
    }
}
