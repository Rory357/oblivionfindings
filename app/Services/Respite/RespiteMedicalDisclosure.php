<?php

namespace App\Services\Respite;

use App\Models\Client;
use App\Models\ClientMedicationAlert;
use App\Models\User;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationRecordSafetyPrivacy;
use Illuminate\Support\Facades\Gate;

/**
 * Medical content on respite surfaces (EA-011). Allergies, chart alerts and
 * the medical profile are the person's Medical section: they go only to
 * readers who pass ClientPolicy::viewMedications (the eMAR per-person rule),
 * chart-alert text follows controlled concealment, and allergies come from
 * the canonical allergy record (ClientAllergyRecordService), never the
 * legacy register alone.
 *
 * The anaphylaxis check at check-in is a safety floor: whether one is needed
 * is decided from the canonical record for everyone who can check a guest
 * in, whatever they may read; the allergen itself stays behind the gate.
 */
final class RespiteMedicalDisclosure
{
    public const SEVERE = ['severe', 'life_threatening'];

    public const CONCEALED_ALERT = 'Chart alert — details need controlled-medicine access';

    /** @var array<string, bool> */
    private array $readable = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $entries = [];

    public function __construct(
        private readonly ClientAllergyRecordService $allergies,
        private readonly MedicationRecordSafetyPrivacy $safety,
    ) {}

    public function canReadMedical(?User $viewer, ?Client $client): bool
    {
        if ($viewer === null || $client === null || ! $client->exists) {
            return false;
        }
        $key = $viewer->getKey().':'.$client->getKey();

        return $this->readable[$key] ??= Gate::forUser($viewer)->allows('viewMedications', $client);
    }

    /** @return list<array<string, mixed>> Canonical severe and life-threatening entries. */
    public function severeAllergies(Client $client): array
    {
        $this->entries[(int) $client->id] ??= $this->allergies->forClient($client);

        return array_values(array_filter(
            $this->entries[(int) $client->id],
            fn (array $entry): bool => in_array(mb_strtolower(trim((string) ($entry['severity'] ?? ''))), self::SEVERE, true),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function lifeThreateningAllergies(Client $client): array
    {
        return array_values(array_filter(
            $this->severeAllergies($client),
            fn (array $entry): bool => mb_strtolower(trim((string) $entry['severity'])) === 'life_threatening',
        ));
    }

    public function requiresAnaphylaxisAcknowledgement(Client $client): bool
    {
        return $this->lifeThreateningAllergies($client) !== [];
    }

    /**
     * Allergy and chart-alert rows for a respite critical-alerts list, or
     * none when the viewer may not read the person's Medical section.
     *
     * @return list<array{type:string,label:string,detail:?string,severity:string,requiresAcknowledgement:bool}>
     */
    public function criticalMedicalAlerts(?User $viewer, Client $client): array
    {
        if (! $this->canReadMedical($viewer, $client)) {
            return [];
        }

        $allergies = array_map(fn (array $entry): array => [
            'type' => 'allergy',
            'label' => (string) $entry['allergen'],
            'detail' => $entry['reaction'] ?? null,
            'severity' => mb_strtolower((string) $entry['severity']) === 'life_threatening' ? 'critical' : 'high',
            'requiresAcknowledgement' => mb_strtolower((string) $entry['severity']) === 'life_threatening',
        ], $this->severeAllergies($client));

        $conceal = $this->safety->hidesUnstructuredText($viewer, $client);
        $chartAlerts = ClientMedicationAlert::query()
            ->where('client_id', $client->id)
            ->enabled()
            ->unresolved()
            ->get(['id', 'title', 'detail', 'prompt_on_open'])
            ->map(fn (ClientMedicationAlert $alert): array => [
                'type' => 'medication_alert',
                'label' => $conceal ? self::CONCEALED_ALERT : (string) $alert->title,
                'detail' => $conceal ? null : $alert->detail,
                'severity' => $alert->prompt_on_open ? 'high' : 'medium',
                'requiresAcknowledgement' => (bool) $alert->prompt_on_open,
            ])
            ->all();

        return [...$allergies, ...$chartAlerts];
    }
}
