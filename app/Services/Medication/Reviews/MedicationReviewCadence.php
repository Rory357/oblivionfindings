<?php

namespace App\Services\Medication\Reviews;

use App\Models\AppSetting;
use App\Models\Client;
use App\Models\MedicationReviewEvent;
use App\Models\MedicationSettingChange;
use App\Services\Medication\Settings\MedicationSettingDefinition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;

/** P05-owned definition. P11 owns its drafts, approval and change-history UI. */
final class MedicationReviewCadence
{
    public const STORAGE_KEY = 'medications.reviews.interval_months';

    public const DEFAULT_MONTHS = 3;

    public static function settingsGroup(): MedicationSettingGroup
    {
        return new MedicationSettingGroup(
            key: 'review_cadence', view: MedicationSettingsRegistry::VIEW_RULES,
            effect: 'For future regular reviews; already booked dates stay as they are',
            auditEvent: 'medications.review_default.updated',
            definitions: [new MedicationSettingDefinition(
                group: 'review_cadence', key: 'months', storageKey: self::STORAGE_KEY,
                scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                section: 'reviews', label: 'Regular medication review interval',
                options: [], default: (string) self::DEFAULT_MONTHS, range: [1, 12], unit: 'months',
                numeric: ['direction' => MedicationSettingDefinition::HIGHER_IS_LOOSER, 'off' => null, 'off_is_loosest' => false],
            )],
        );
    }

    public function organisation(): array
    {
        $stored = AppSetting::query()->where('key', self::STORAGE_KEY)->value('value');
        $definition = self::settingsGroup()->definition('months');

        return ['months' => (int) $definition->normalise($stored),
            'reviewed' => $stored !== null || MedicationSettingChange::query()->where('setting_group', 'review_cadence')->where('setting_key', 'months')->exists()];
    }

    public function forClient(Client $client): array
    {
        $own = $client->medication_review_interval_months;
        $org = $this->organisation();

        if ($own === null) {
            return ['months' => $org['months'], 'own' => false, 'reviewed' => $org['reviewed']];
        }

        // A value carried forward from the old chart field has no recorded
        // approval. Only the latest real interval decision can mark it reviewed.
        $decision = MedicationReviewEvent::query()->where('client_id', $client->id)
            ->whereNull('review_id')->where('event', 'interval_changed')->latest('id')->first();
        $reviewed = $decision !== null && $decision->actor_id !== null
            && ($decision->details['to_months'] ?? null) !== null
            && (int) $decision->details['to_months'] === (int) $own;

        return ['months' => (int) $own, 'own' => true, 'reviewed' => $reviewed];
    }
}
