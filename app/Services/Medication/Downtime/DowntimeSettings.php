<?php

namespace App\Services\Medication\Downtime;

use App\Models\AppSetting;
use App\Services\Medication\Settings\MedicationSettingDefinition as Definition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;

/**
 * Settings › Medication rules › Records & reporting › Paper records
 * (Main's D2, EA-001, Lane C1).
 *
 * A paper dose given while the medicine's stock wasn't pack-tracked is
 * posted without pack evidence and its stock is flagged for a counted
 * recount (PaperStockTracking). This setting decides whether a downtime can
 * be finished while those recounts are still outstanding:
 *
 * - "yes" (default, stricter): count first, then finish — the P10 finish
 *   step already asks for the closing controlled count first.
 * - "no": finish now; each recount carries on in Follow-ups.
 *
 * The recount itself is a safety floor and is always raised; only the
 * finish gate is a choice. Organisation-wide: a house can't loosen it.
 */
final class DowntimeSettings
{
    public const RECOUNT_BEFORE_FINISH = 'medications.downtime.recount_before_finish';

    public const OPTIONS = ['no', 'yes'];

    public const DEFAULT = 'yes';

    public static function group(): MedicationSettingGroup
    {
        return new MedicationSettingGroup(
            key: 'downtimeRecords',
            view: MedicationSettingsRegistry::VIEW_RULES,
            effect: 'From the next downtime finished; finished downtimes are kept',
            auditEvent: 'medications.downtime_settings.updated',
            definitions: [
                new Definition(
                    group: 'downtimeRecords', key: 'recount_before_finish', storageKey: self::RECOUNT_BEFORE_FINISH,
                    scope: Definition::SCOPE_ORGANISATION, section: 'records',
                    label: 'Count the stock before a downtime is finished',
                    options: [
                        'no' => 'Off — the recount carries on in Follow-ups',
                        'yes' => 'On — count first, then finish',
                    ],
                    default: self::DEFAULT, rank: self::OPTIONS,
                ),
            ],
        );
    }

    /** Whether finishing waits for the recounts of posted untracked paper doses. */
    public function recountBeforeFinish(): bool
    {
        $value = AppSetting::query()->where('key', self::RECOUNT_BEFORE_FINISH)->first()?->value;

        return (in_array($value, self::OPTIONS, true) ? $value : self::DEFAULT) === 'yes';
    }
}
