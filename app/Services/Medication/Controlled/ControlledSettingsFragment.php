<?php

namespace App\Services\Medication\Controlled;

use App\Services\Medication\Settings\MedicationSettingDefinition as Definition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;

/** P07-owned definitions; Main/P11 adds groups() to the shared registry. */
final class ControlledSettingsFragment
{
    /** @return list<MedicationSettingGroup> */
    public static function groups(): array
    {
        return [
            new MedicationSettingGroup(
                key: 'controlled_witness',
                view: MedicationSettingsRegistry::VIEW_RULES,
                effect: 'From the next controlled dose recorded; an order can still require a witness',
                auditEvent: 'medications.controlled_witness_policy.updated',
                definitions: [
                    new Definition(
                        group: 'controlled_witness', key: 'organisation',
                        storageKey: ControlledPolicy::WITNESS_REQUIRED,
                        scope: Definition::SCOPE_ORGANISATION, section: 'controlled',
                        label: 'Witness required for controlled drugs',
                        options: ['on' => 'Required', 'off' => 'Not required'],
                        default: 'on', rank: ['off', 'on'],
                    ),
                    new Definition(
                        group: 'controlled_witness', key: 'house',
                        storageKey: ControlledPolicy::HOUSE_WITNESS,
                        scope: Definition::SCOPE_SITE, section: 'controlled',
                        label: 'Witness at this house',
                        options: ['org' => 'Follow the organisation', 'on' => 'Always', 'off' => 'Not required'],
                        default: 'org', rank: ['off', 'org', 'on'],
                    ),
                ],
            ),
            new MedicationSettingGroup(
                key: 'controlled_counts',
                view: MedicationSettingsRegistry::VIEW_RULES,
                effect: 'From the next count due at each house. Weekly due and overdue reminders are unavailable.',
                auditEvent: 'medications.controlled_count_policy.updated',
                definitions: [
                    new Definition(
                        group: 'controlled_counts', key: 'cadence',
                        storageKey: ControlledPolicy::COUNT_CADENCE,
                        scope: Definition::SCOPE_ORGANISATION, section: 'controlled',
                        label: 'How often controlled medicines are counted',
                        options: ['shift' => 'Every shift change', 'day' => 'Once a day, at the morning shift change', 'week' => 'Weekly — timing unavailable'],
                        default: '', rank: ['week', 'day', 'shift'],
                        whenNotConfigured: 'Not configured — nothing shows as due or overdue until the count cadence is saved.',
                    ),
                    new Definition(
                        group: 'controlled_counts', key: 'overdue_minutes',
                        storageKey: ControlledPolicy::COUNT_OVERDUE_MINUTES,
                        scope: Definition::SCOPE_ORGANISATION, section: 'controlled',
                        label: 'Counts as overdue', options: [], default: '60',
                        numeric: ['direction' => Definition::HIGHER_IS_LOOSER, 'off' => null, 'off_is_loosest' => false],
                        range: [1, 1440], unit: 'minutes after the shift change',
                    ),
                ],
            ),
            new MedicationSettingGroup(
                key: 'controlled_destruction',
                view: MedicationSettingsRegistry::VIEW_RULES,
                effect: 'From the next destruction recorded; return to pharmacy remains available',
                auditEvent: 'medications.controlled_destruction_policy.updated',
                definitions: [
                    new Definition(
                        group: 'controlled_destruction', key: 'onsite',
                        storageKey: ControlledPolicy::ONSITE_DESTRUCTION,
                        scope: Definition::SCOPE_ORGANISATION, section: 'controlled',
                        label: 'Allow on-site denaturing with two witnesses',
                        options: ['off' => 'Return to pharmacy', 'on' => 'On-site denaturing allowed'],
                        default: 'off', rank: ['on', 'off'],
                    ),
                ],
            ),
        ];
    }
}
