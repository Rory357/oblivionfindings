<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\Medication\ClientAllergyRecordService;

/**
 * Canonical shape for the Client Safety Ribbon.
 *
 * Given a Client, returns a JSON-serialisable array with the allergies,
 * critical risks, and care-critical flags a frontline worker must see on any
 * client screen. Callers should eager-load `medicalProfile` and `risks` first
 * to avoid N+1.
 */
class ClientSafetyPayload
{
    /**
     * Disabilities treated as care-critical. These surface as dedicated flags
     * in the ribbon using the plain-language label from DISABILITY_OPTIONS.
     *
     * Keep this list conservative — only disabilities that meaningfully change
     * day-of-shift safety behaviour.
     */
    private const CARE_CRITICAL_DISABILITIES = [
        'epilepsy',
        'dementia',
        'nonverbal',
        'wheelchair_user',
        'spinal_cord_injury',
        'limited_mobility',
        'deafblind',
        'acquired_brain_injury',
    ];

    /**
     * @return array{
     *   has_any: bool,
     *   allergies: array<int,array{key:?string,label:string,group:?string,severity:?string,reaction:?string}>,
     *   allergy_record: array|null,
     *   critical_risks: array<int,array{id:int,label:string,severity:string}>,
     *   other_risks_count: int,
     *   active_risks_count: int,
     *   care_flags: array<int,array{key:string,label:string,tone:string,icon:string}>,
     *   risk_level: ?string,
     *   safeguarding_flag: bool
     * }
     */
    public static function forViewer(Client $client, User $viewer): array
    {
        $sections = app(ClientProfileSectionAccess::class)->for($viewer, $client);

        return self::forClient(
            $client,
            includeMedical: $viewer->can('viewMedications', $client),
            includeRisks: (bool) $sections['risks'],
        );
    }

    public static function forClient(
        Client $client,
        bool $includeMedical = true,
        bool $includeRisks = true,
        ?array $allergyRecord = null,
    ): array {
        $profile = $includeMedical
            ? ($client->relationLoaded('medicalProfile')
                ? $client->medicalProfile
                : $client->medicalProfile()->first())
            : null;

        $risks = $includeRisks
            ? ($client->relationLoaded('risks')
                ? $client->risks
                : $client->risks()->get())
            : collect();

        if ($includeMedical) {
            $records = new ClientAllergyRecordService;
            $allergyRecord ??= $client->exists
                ? $records->summary($client)
                : $records->summaryFromEvidence((int) $client->id, collect(), $profile);
        } else {
            $allergyRecord = null;
        }
        $allergies = self::normaliseAllergies($allergyRecord);
        $disabilities = self::normaliseDisabilities($profile);

        $activeRisks = collect($risks)->filter(fn ($r) => (bool) ($r->active ?? false))->values();
        $criticalRisks = $activeRisks
            ->filter(fn ($r) => in_array(strtolower((string) $r->severity), ['critical', 'high'], true))
            ->sortByDesc(fn ($r) => strtolower((string) $r->severity) === 'critical' ? 2 : 1)
            ->values()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'label' => (string) $r->label,
                'severity' => strtolower((string) $r->severity),
            ])
            ->all();

        $careFlags = self::buildCareFlags(
            $client,
            $disabilities,
            includeRiskSignals: $includeRisks,
        );

        $hasAny = ! empty($allergies)
            || ! empty($criticalRisks)
            || ! empty($careFlags);

        return [
            'has_any' => $hasAny,
            'allergies' => $allergies,
            'allergy_record' => $allergyRecord,
            'critical_risks' => $criticalRisks,
            'other_risks_count' => max(0, $activeRisks->count() - count($criticalRisks)),
            'active_risks_count' => $activeRisks->count(),
            'care_flags' => $careFlags,
            'risk_level' => $includeRisks && $client->risk_level
                ? strtolower((string) $client->risk_level)
                : null,
            'safeguarding_flag' => $includeRisks
                ? (bool) ($client->safeguarding_flag ?? false)
                : false,
        ];
    }

    /**
     * Compact row-level summary for client list/index cards. Only the bare
     * essentials needed for a scannable pill set.
     *
     * @return array{
     *   has_any: bool,
     *   allergies_count: int,
     *   allergy_record: array|null,
     *   critical_risks_count: int,
     *   active_risks_count: int,
     *   safeguarding: bool,
     *   risk_level: ?string,
     *   top_allergy: ?string,
     *   top_risk: ?string
     * }
     */
    public static function summaryForClient(
        Client $client,
        bool $includeMedical = true,
        bool $includeRisks = true,
        ?array $allergyRecord = null,
    ): array {
        $full = self::forClient(
            $client,
            includeMedical: $includeMedical,
            includeRisks: $includeRisks,
            allergyRecord: $allergyRecord,
        );

        return [
            'has_any' => $full['has_any']
                || $full['safeguarding_flag']
                || in_array($full['risk_level'], ['high', 'critical'], true),
            'allergies_count' => count($full['allergies']),
            'allergy_record' => $full['allergy_record'],
            'critical_risks_count' => count($full['critical_risks']),
            'active_risks_count' => $full['active_risks_count'],
            'safeguarding' => $full['safeguarding_flag'],
            'risk_level' => $full['risk_level'],
            'top_allergy' => $full['allergies'][0]['label'] ?? null,
            'top_risk' => $full['critical_risks'][0]['label'] ?? null,
        ];
    }

    /**
     * @return array<int,array{key:?string,label:string,group:?string,severity:?string,reaction:?string}>
     */
    private static function normaliseAllergies(?array $record): array
    {
        $options = collect(ClientMedicalProfile::ALLERGEN_OPTIONS)->keyBy('label');

        return array_map(static function (array $entry) use ($options): array {
            $label = (string) $entry['allergen'];

            return [
                'key' => $entry['key'] ?? null,
                'label' => $label,
                'group' => $options->get($label)['group'] ?? null,
                'severity' => $entry['severity'] ?? null,
                'reaction' => $entry['reaction'] ?? null,
            ];
        }, $record['entries'] ?? []);
    }

    /**
     * @return array<int,string> Known disability keys for this client.
     */
    private static function normaliseDisabilities(?ClientMedicalProfile $profile): array
    {
        if (! $profile) {
            return [];
        }

        $raw = $profile->disabilities;

        if (is_string($raw) || ! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $disabilities
     * @return array<int,array{key:string,label:string,tone:string,icon:string}>
     */
    private static function buildCareFlags(
        Client $client,
        array $disabilities,
        bool $includeRiskSignals = true,
    ): array {
        $flags = [];

        if ($includeRiskSignals && (bool) ($client->safeguarding_flag ?? false)) {
            $flags[] = [
                'key' => 'safeguarding',
                'label' => 'Safeguarding',
                'tone' => 'danger',
                'icon' => 'shield',
            ];
        }

        $riskLevel = $includeRiskSignals && $client->risk_level
            ? strtolower((string) $client->risk_level)
            : null;
        if ($riskLevel === 'critical') {
            $flags[] = [
                'key' => 'risk_level_critical',
                'label' => 'Critical risk',
                'tone' => 'danger',
                'icon' => 'alert',
            ];
        } elseif ($riskLevel === 'high') {
            $flags[] = [
                'key' => 'risk_level_high',
                'label' => 'High risk',
                'tone' => 'warning',
                'icon' => 'alert',
            ];
        }

        if (! empty($disabilities)) {
            $labelsByKey = collect(ClientMedicalProfile::DISABILITY_OPTIONS)
                ->keyBy('value');

            foreach (self::CARE_CRITICAL_DISABILITIES as $key) {
                if (! in_array($key, $disabilities, true)) {
                    continue;
                }

                $flags[] = [
                    'key' => 'disability_'.$key,
                    'label' => $labelsByKey->get($key)['label'] ?? self::humanise($key),
                    'tone' => self::disabilityTone($key),
                    'icon' => self::disabilityIcon($key),
                ];
            }
        }

        return $flags;
    }

    private static function disabilityTone(string $key): string
    {
        return match ($key) {
            'epilepsy', 'spinal_cord_injury' => 'warning',
            default => 'info',
        };
    }

    private static function disabilityIcon(string $key): string
    {
        return match ($key) {
            'epilepsy' => 'zap',
            'wheelchair_user', 'limited_mobility' => 'accessibility',
            'nonverbal' => 'messageOff',
            'dementia', 'acquired_brain_injury' => 'brain',
            'deafblind' => 'eye',
            'spinal_cord_injury' => 'spine',
            default => 'info',
        };
    }

    private static function humanise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
