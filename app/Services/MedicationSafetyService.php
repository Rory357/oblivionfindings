<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationInteraction;
use App\Services\Medication\CheckedOrderAllergyConfirmation;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationSafetyPolicySettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class MedicationSafetyService
{
    /**
     * Safety check result structure
     */
    public const SAFETY_LEVELS = [
        'safe' => ['label' => 'Safe', 'color' => 'green', 'icon' => 'check-circle'],
        'caution' => ['label' => 'Caution', 'color' => 'yellow', 'icon' => 'alert-triangle'],
        'warning' => ['label' => 'Warning', 'color' => 'orange', 'icon' => 'alert-octagon'],
        'danger' => ['label' => 'Danger', 'color' => 'red', 'icon' => 'shield-alert'],
        'blocked' => ['label' => 'Blocked', 'color' => 'red', 'icon' => 'ban'],
    ];

    public function __construct(
        private ?ClientAllergyRecordService $allergyRecords = null,
        private ?MedicationSafetyPolicySettings $policy = null,
    ) {}

    /**
     * Perform complete safety check before medication administration
     */
    public function performSafetyCheck(
        Client $client,
        ClientMedication $medication,
        ?Carbon $adminTime = null,
        ?string $doseGiven = null,
        bool $includeControlled = false,
    ): array {
        $adminTime = $adminTime ?? now();
        $warnings = [];
        $alerts = [];
        $blocked = false;
        $blockReason = null;

        // 1. Check if medication is active
        if (! $medication->isActive()) {
            $state = $medication->state;
            $blocked = true;
            $blockReason = "Medication is currently {$state}. Cannot administer.";
            $warnings[] = [
                'type' => 'state_blocked',
                'severity' => 'danger',
                'message' => $blockReason,
            ];

            return $this->compileSafetyResult($warnings, $alerts, $blocked, $blockReason);
        }

        // 2. Check for allergies — the medication allergy register and the
        // health profile (EM-07). A profile entry has no severity; whether its
        // match warns or blocks is the organisation's setting.
        $allergyCheck = $this->checkAllergies($client, $medication);
        if ($allergyCheck['has_match']) {
            $blockUnratedProfileMatches = ($allergyCheck['profile_match_policy'] ?? 'warn') === 'block';
            $prescriberConfirmation = app(CheckedOrderAllergyConfirmation::class)->forOrder($medication);

            foreach ($allergyCheck['matches'] as $index => $allergy) {
                $confirmedMatch = $prescriberConfirmation !== null && collect($prescriberConfirmation['matches'] ?? [])
                    ->contains(fn ($match) => mb_strtolower(trim($match['allergen'])) === mb_strtolower(trim($allergy->allergen)));
                if ($confirmedMatch) {
                    $warnings[] = [
                        'type' => 'allergy', 'severity' => 'warning',
                        'message' => 'Recorded allergy to '.$allergy->allergen.'. The prescriber confirmed this checked version is safe: '.$prescriberConfirmation['instruction'],
                        'details' => ['allergen' => $allergy->allergen, 'prescriber_confirmation' => $prescriberConfirmation],
                    ];

                    continue;
                }
                $fromProfile = ($allergyCheck['sources'][$index] ?? ClientAllergyRecordService::SOURCE_REGISTER)
                    === ClientAllergyRecordService::SOURCE_PROFILE;

                if ($fromProfile) {
                    // Plain-language copy approved in P00 v2 (no emoji, no capitals).
                    $matchLine = "Possible allergy match — {$medication->name} matches recorded {$allergy->allergen} allergy (health profile)";
                    $warning = [
                        'type' => 'allergy',
                        'severity' => $blockUnratedProfileMatches ? 'danger' : 'warning',
                        'message' => $matchLine,
                        'details' => [
                            'allergen' => $allergy->allergen,
                            'reaction' => null,
                            'severity' => null,
                            'source' => ClientAllergyRecordService::SOURCE_PROFILE,
                        ],
                    ];

                    if ($blockUnratedProfileMatches) {
                        $blocked = true;
                        $blockReason = $matchLine.'. Your organisation blocks allergy matches, so this can’t be recorded as given. Check with the prescriber.';
                    }

                    $warnings[] = $warning;

                    continue;
                }

                $severity = $allergy->severity === 'life_threatening' ? 'danger' : 'warning';
                $blocked = $blocked || $allergy->isSevere();

                $warning = [
                    'type' => 'allergy',
                    'severity' => $severity,
                    'message' => "⚠️ ALLERGY ALERT: Client has {$allergy->severity} allergy to {$allergy->allergen}",
                    'details' => [
                        'allergen' => $allergy->allergen,
                        'reaction' => $allergy->reaction,
                        'severity' => $allergy->severity,
                        'source' => ClientAllergyRecordService::SOURCE_REGISTER,
                    ],
                ];

                if ($allergy->isSevere()) {
                    $warning['message'] .= ' - ADMINISTRATION BLOCKED';
                    $blockReason = "Severe allergy to {$allergy->allergen} detected";
                }

                $warnings[] = $warning;
            }
        }

        // 3. Check for duplicate medications
        $duplicateCheck = $this->checkDuplicates($client, $medication);
        if ($duplicateCheck['has_duplicate']) {
            foreach ($duplicateCheck['duplicates'] as $duplicate) {
                $concealCounterpart = $duplicate->controlled_drug && ! $includeControlled;
                $warnings[] = [
                    'type' => 'duplicate',
                    'severity' => 'caution',
                    'message' => $concealCounterpart
                        ? 'Similar controlled medication is active. Review with an authorised controlled-medication colleague.'
                        : "Similar medication active: {$duplicate->name} ({$duplicate->formatted_dose})",
                    'details' => $concealCounterpart
                        ? ['controlled_counterpart' => true]
                        : [
                            'medication_id' => $duplicate->id,
                            'name' => $duplicate->name,
                            'dose' => $duplicate->formatted_dose,
                        ],
                ];
            }
        }

        // 4. Check for drug interactions
        $interactionCheck = $this->checkInteractions($client, $medication);
        if ($interactionCheck['has_interaction']) {
            foreach ($interactionCheck['interactions'] as $match) {
                $interaction = $match['interaction'];
                $counterpart = $match['medication'];
                $concealCounterpart = $counterpart->controlled_drug && ! $includeControlled;
                $severity = $interaction->severity === 'contraindicated' ? 'danger' :
                    ($interaction->severity === 'major' ? 'warning' : 'caution');

                $blocked = $blocked || $interaction->severity === 'contraindicated';

                $warning = [
                    'type' => 'interaction',
                    'severity' => $severity,
                    'message' => $concealCounterpart
                        ? 'Drug interaction detected with a controlled medication.'
                        : "Drug Interaction: {$interaction->medication_a} + {$interaction->medication_b}",
                    'details' => $concealCounterpart
                        ? [
                            'severity' => $interaction->severity,
                            'controlled_counterpart' => true,
                        ]
                        : [
                            'severity' => $interaction->severity,
                            'description' => $interaction->description,
                            'management' => $interaction->management,
                        ],
                ];

                if ($interaction->severity === 'contraindicated') {
                    $warning['message'] .= ' - CONTRAINDICATED';
                    $blockReason = 'Contraindicated drug interaction detected';
                }

                $warnings[] = $warning;
            }
        }

        // 5. Check PRN limits
        if ($medication->is_prn) {
            $prnCheck = $this->checkPrnLimits($medication, $adminTime);

            if ($prnCheck['blocked']) {
                $blocked = true;
                $blockReason = $prnCheck['message'];
                $warnings[] = [
                    'type' => 'prn_limit',
                    'severity' => 'danger',
                    'message' => $prnCheck['message'],
                    'details' => $prnCheck['details'],
                ];
            } elseif ($prnCheck['near_limit']) {
                $warnings[] = [
                    'type' => 'prn_near_limit',
                    'severity' => 'warning',
                    'message' => $prnCheck['message'],
                    'details' => $prnCheck['details'],
                ];
            }

            // 5b. Check PRN minimum interval between doses
            if ($medication->min_hours_between_doses && $medication->min_hours_between_doses > 0) {
                $intervalCheck = $this->checkPrnInterval($medication, $adminTime);
                if ($intervalCheck['blocked']) {
                    $blocked = true;
                    $blockReason = $intervalCheck['message'];
                    $warnings[] = [
                        'type' => 'prn_interval',
                        'severity' => 'danger',
                        'message' => $intervalCheck['message'],
                        'details' => $intervalCheck['details'],
                    ];
                }
            }
        }

        // 5c. Validate dose against prescribed amount
        if ($doseGiven !== null) {
            $doseWarning = $this->validateDoseAgainstPrescribed($medication, $doseGiven);
            if ($doseWarning) {
                $warnings[] = $doseWarning;
            }
        }

        // 6. Check if expired
        if ($medication->isExpired()) {
            $blocked = true;
            $blockReason = 'Medication has expired';
            $warnings[] = [
                'type' => 'expired',
                'severity' => 'danger',
                'message' => "⚠️ EXPIRED: This medication ended on {$medication->end_date->format('d/m/Y')}",
                'details' => [
                    'expiry_date' => $medication->end_date->toDateString(),
                ],
            ];
        } elseif ($medication->isExpiringSoon()) {
            $warnings[] = [
                'type' => 'expiring_soon',
                'severity' => 'caution',
                'message' => "Expiring soon: This medication expires on {$medication->end_date->format('d/m/Y')}",
                'details' => [
                    'expiry_date' => $medication->end_date->toDateString(),
                    'days_remaining' => $medication->daysUntilEnd(),
                ],
            ];
        }

        // 7. Check for high-risk medication
        if ($medication->high_risk) {
            $warnings[] = [
                'type' => 'high_risk',
                'severity' => 'caution',
                'message' => '🚨 HIGH RISK MEDICATION: Extra care required',
                'details' => [
                    'medication_name' => $medication->name,
                    'requires_double_check' => true,
                ],
            ];
        }

        // 8. Check controlled drug requirements
        if ($medication->controlled_drug) {
            $warnings[] = [
                'type' => 'controlled_drug',
                'severity' => 'caution',
                'message' => '🔒 CONTROLLED DRUG: Witness required for administration',
                'details' => [
                    'requires_witness' => true,
                    'running_balance' => optional($medication->stock)->on_hand,
                ],
            ];
        }

        return $this->compileSafetyResult($warnings, $alerts, $blocked, $blockReason);
    }

    /**
     * Check for allergies to a medication
     */
    /**
     * Match the medicine against every recorded allergy: the medication
     * allergy register and the health profile. Profile matches are unsaved
     * MedicationAllergy instances (severity null) so both sources share the
     * same matching rules; `sources[i]` names where `matches[i]` came from.
     *
     * @return array{has_match: bool, matches: list<MedicationAllergy>, sources: list<string>, allergy_count: int, profile_match_policy: string}
     */
    public function checkAllergies(Client $client, ClientMedication $medication): array
    {
        $records = ($this->allergyRecords ?? app(ClientAllergyRecordService::class))->forClient($client);
        $matches = [];
        $sources = [];

        foreach ($records as $record) {
            if ($record['allergy']->matchesMedication($medication->name)) {
                $matches[] = $record['allergy'];
                $sources[] = $record['source'];
            }
        }

        return [
            'has_match' => count($matches) > 0,
            'matches' => $matches,
            'sources' => $sources,
            'allergy_count' => count($records),
            'profile_match_policy' => ($this->policy ?? app(MedicationSafetyPolicySettings::class))->profileAllergyMatch(),
        ];
    }

    /**
     * Check for duplicate/similar medications
     */
    public function checkDuplicates(Client $client, ClientMedication $medication): array
    {
        // Get all active medications for this client except current
        $activeMeds = ClientMedication::where('client_id', $client->id)
            ->where('id', '!=', $medication->id)
            ->active()
            ->get();

        $duplicates = [];
        $medicationName = strtolower($medication->name);

        foreach ($activeMeds as $med) {
            $otherName = strtolower($med->name);

            // Direct name match or significant overlap
            similar_text($medicationName, $otherName, $similarity);

            if ($similarity > 70 ||
                str_contains($medicationName, $otherName) ||
                str_contains($otherName, $medicationName)) {
                $duplicates[] = $med;

                continue;
            }

            // Check for same drug class (basic implementation)
            $drugClasses = [
                'paracetamol' => ['acetaminophen', 'panadol', 'panamax'],
                'ibuprofen' => ['brufen', 'nurofen'],
                'aspirin' => ['acetylsalicylic acid', 'dispirin'],
            ];

            foreach ($drugClasses as $class => $alternatives) {
                $medInClass = in_array($medicationName, array_merge([$class], $alternatives));
                $otherInClass = in_array($otherName, array_merge([$class], $alternatives));

                if ($medInClass && $otherInClass) {
                    $duplicates[] = $med;
                    break;
                }
            }
        }

        return [
            'has_duplicate' => count($duplicates) > 0,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Check for drug interactions
     */
    public function checkInteractions(Client $client, ClientMedication $medication): array
    {
        // Get all other active medications
        $otherMeds = ClientMedication::where('client_id', $client->id)
            ->where('id', '!=', $medication->id)
            ->active()
            ->get(['id', 'name', 'controlled_drug']);

        $interactions = [];

        foreach ($otherMeds as $otherMed) {
            $interaction = MedicationInteraction::checkInteraction($medication->name, $otherMed->name);
            if ($interaction) {
                $interactions[] = [
                    'interaction' => $interaction,
                    'medication' => $otherMed,
                ];
            }
        }

        // Sort by severity (contraindicated first)
        usort($interactions, function ($a, $b) {
            $severityOrder = ['contraindicated' => 0, 'major' => 1, 'moderate' => 2, 'minor' => 3];

            return ($severityOrder[$a['interaction']->severity] ?? 4)
                <=> ($severityOrder[$b['interaction']->severity] ?? 4);
        });

        return [
            'has_interaction' => count($interactions) > 0,
            'interactions' => $interactions,
        ];
    }

    /**
     * Evaluate one proposed given dose before insertion. Recording callers must
     * hold the canonical medication mutex through this check and the insert;
     * replay/duplicate resolution happens first. These read-side checks grant
     * no recording authority and never include non-given outcomes as doses.
     */
    public function checkPrnLimits(ClientMedication $medication, ?CarbonInterface $adminTime = null): array
    {
        if (! $medication->is_prn || ! $medication->max_per_day) {
            return [
                'blocked' => false,
                'near_limit' => false,
                'message' => null,
                'details' => [],
            ];
        }

        $maxPerDay = (int) filter_var($medication->max_per_day, FILTER_SANITIZE_NUMBER_INT);

        if ($maxPerDay <= 0) {
            return [
                'blocked' => false,
                'near_limit' => false,
                'message' => null,
                'details' => [],
            ];
        }

        // SQL administration instants have second precision. Convert the bounds
        // to UTC without mutating the caller's NZ time; the lower edge remains
        // inclusive, as in the existing last-24-hours check.
        $at = $this->prnInstant($adminTime);
        $history = $this->prnGivenHistory($medication)
            ->whereBetween('administered_at', [$at->copy()->subHours(24), $at->copy()->addHours(24)])
            ->orderBy('administered_at')
            ->orderBy('id')
            ->get(['id', 'administered_at'])
            ->map(fn (ClientMedicationAdministration $dose): Carbon => Carbon::instance($dose->administered_at)->copy()->utc());
        $count24h = $history->filter(fn (Carbon $time): bool => $time->lte($at))->count();
        $remaining = max(0, $maxPerDay - $count24h);
        $percentUsed = ($count24h / $maxPerDay) * 100;

        $details = [
            'count_24h' => $count24h,
            'max_per_day' => $maxPerDay,
            'remaining' => $remaining,
            'percent_used' => round($percentUsed, 1),
        ];

        if ($count24h >= $maxPerDay) {
            return [
                'blocked' => true,
                'near_limit' => false,
                'message' => "⛔ PRN LIMIT REACHED: {$count24h}/{$maxPerDay} doses given in last 24 hours. Cannot administer.",
                'details' => $details,
            ];
        }

        // A historical insertion also belongs to windows ending at subsequent
        // doses through at + 24 hours (inclusive). Count it virtually once, not
        // as a saved dose or an extra dose for every history row. Between dose
        // instants a rolling count only falls, so these endpoints are sufficient.
        $left = 0;
        foreach ($history as $right => $time) {
            if ($time->lte($at)) {
                continue;
            }
            $start = $time->copy()->subHours(24);
            while ($history[$left]->lt($start)) {
                $left++;
            }
            $withProposedDose = $right - $left + 2;
            if ($withProposedDose > $maxPerDay) {
                return [
                    'blocked' => true,
                    'near_limit' => false,
                    'message' => "This dose would exceed the PRN limit of {$maxPerDay} doses in 24 hours at a later recorded dose. Review the paper and the dose history.",
                    'details' => $details + [
                        'proposed_at' => $at->toIso8601String(),
                        'affected_window_at' => $time->toIso8601String(),
                        'affected_count_24h' => $withProposedDose,
                    ],
                ];
            }
        }

        if ($percentUsed >= 75) {
            return [
                'blocked' => false,
                'near_limit' => true,
                'message' => "⚠️ PRN NEAR LIMIT: {$count24h}/{$maxPerDay} doses used ({$remaining} remaining)",
                'details' => $details,
            ];
        }

        return [
            'blocked' => false,
            'near_limit' => false,
            'message' => "PRN usage: {$count24h}/{$maxPerDay} ({$remaining} remaining)",
            'details' => $details,
        ];
    }

    /**
     * Get PRN history for display
     */
    public function getPrnHistory(ClientMedication $medication, int $hours = 24): array
    {
        if (! $medication->is_prn) {
            return [];
        }

        $history = $medication->administrations()
            ->effectiveClinicalEvidence()
            ->where('client_id', $medication->client_id)
            ->where('status', 'given')
            ->where('administered_at', '>=', now()->subHours($hours))
            ->with('administeredBy:id,name')
            ->orderByDesc('administered_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'administered_at' => $a->administered_at?->toIso8601String(),
                'dose_given' => $a->dose_given,
                'reason' => $a->reason,
                'administered_by' => $a->administeredBy?->name,
            ])
            ->toArray();

        return [
            'history' => $history,
            'count' => count($history),
            'max_per_day' => $medication->max_per_day,
            'remaining_today' => $medication->prnRemaining,
        ];
    }

    /**
     * Compile safety check result
     */
    private function compileSafetyResult(
        array $warnings,
        array $alerts,
        bool $blocked,
        ?string $blockReason
    ): array {
        // Determine overall safety level
        if ($blocked) {
            $safetyLevel = 'blocked';
        } elseif (collect($warnings)->contains('severity', 'danger')) {
            $safetyLevel = 'danger';
        } elseif (collect($warnings)->contains('severity', 'warning')) {
            $safetyLevel = 'warning';
        } elseif (collect($warnings)->contains('severity', 'caution')) {
            $safetyLevel = 'caution';
        } else {
            $safetyLevel = 'safe';
        }

        return [
            'safe' => ! $blocked && $safetyLevel !== 'danger',
            'blocked' => $blocked,
            'block_reason' => $blockReason,
            'safety_level' => $safetyLevel,
            'safety_info' => self::SAFETY_LEVELS[$safetyLevel],
            'warnings' => $warnings,
            'alerts' => $alerts,
            'warning_count' => count($warnings),
            'can_proceed' => ! $blocked,
            'requires_acknowledgment' => $blocked || $safetyLevel === 'danger' || $safetyLevel === 'warning',
        ];
    }

    /**
     * Validate dose against prescribed amount
     * Returns a warning if dose_given exceeds prescribed dose_amount by >20%
     */
    public function validateDoseAgainstPrescribed(ClientMedication $medication, string $doseGiven): ?array
    {
        // Extract numeric value from dose_given string
        if (! preg_match('/(\d+(?:\.\d+)?)/', $doseGiven, $matches)) {
            return null; // Cannot parse numeric value
        }

        $givenNumeric = (float) $matches[1];
        $prescribedAmount = (float) $medication->dose_amount;

        if ($prescribedAmount <= 0) {
            return null; // No prescribed dose to compare against
        }

        $threshold = $prescribedAmount * 1.20;

        if ($givenNumeric > $threshold) {
            $percentOver = round((($givenNumeric - $prescribedAmount) / $prescribedAmount) * 100, 1);

            return [
                'type' => 'dose_exceeds_prescribed',
                'severity' => 'warning',
                'message' => "⚠️ DOSE WARNING: {$givenNumeric} exceeds prescribed dose of {$prescribedAmount} by {$percentOver}%",
                'details' => [
                    'dose_given' => $givenNumeric,
                    'dose_prescribed' => $prescribedAmount,
                    'percent_over' => $percentOver,
                    'threshold_percent' => 20,
                ],
            ];
        }

        return null;
    }

    /**
     * Check PRN minimum interval between doses
     * The proposed instant must be far enough from both neighboring given doses.
     * Recording uses the same pre-insert order mutex contract as checkPrnLimits.
     */
    public function checkPrnInterval(ClientMedication $medication, ?CarbonInterface $adminTime = null): array
    {
        $minHours = (float) $medication->min_hours_between_doses;

        if ($minHours <= 0) {
            return [
                'blocked' => false,
                'message' => null,
                'details' => [],
            ];
        }

        $at = $this->prnInstant($adminTime);
        $lastAdmin = $this->prnGivenHistory($medication)
            ->where('administered_at', '<=', $at)
            ->orderByDesc('administered_at')
            ->first();

        $hoursSinceLast = $lastAdmin !== null ? $lastAdmin->administered_at->diffInMinutes($at, false) / 60 : null;
        if ($lastAdmin !== null && $hoursSinceLast < $minHours) {
            $remainingMinutes = (int) ceil(($minHours - $hoursSinceLast) * 60);
            $hoursRemaining = round($minHours - $hoursSinceLast, 1);

            return [
                'blocked' => true,
                'message' => "⛔ INTERVAL NOT ELAPSED: Minimum {$minHours} hours between doses required. Last dose was {$lastAdmin->administered_at->format('H:i')}. Please wait {$remainingMinutes} more minutes.",
                'details' => [
                    'min_hours_between_doses' => $minHours,
                    'hours_since_last' => round($hoursSinceLast, 2),
                    'hours_remaining' => $hoursRemaining,
                    'minutes_remaining' => $remainingMinutes,
                    'last_administered_at' => $lastAdmin->administered_at->toIso8601String(),
                ],
            ];
        }

        $nextAdmin = $this->prnGivenHistory($medication)
            ->where('administered_at', '>', $at)
            ->orderBy('administered_at')
            ->first();
        $hoursUntilNext = $nextAdmin !== null ? $at->diffInMinutes($nextAdmin->administered_at, false) / 60 : null;
        if ($nextAdmin !== null && $hoursUntilNext < $minHours) {
            return [
                'blocked' => true,
                'message' => "This dose is less than the required {$minHours} hours before the next recorded dose. Review the paper and the dose history.",
                'details' => [
                    'min_hours_between_doses' => $minHours,
                    'hours_until_next' => round($hoursUntilNext, 2),
                    'proposed_at' => $at->toIso8601String(),
                    'next_administered_at' => $nextAdmin->administered_at->toIso8601String(),
                ],
            ];
        }

        return [
            'blocked' => false,
            'message' => null,
            'details' => [],
        ];
    }

    private function prnInstant(?CarbonInterface $at): Carbon
    {
        return Carbon::instance($at ?? now())->copy()->utc()->startOfSecond();
    }

    private function prnGivenHistory(ClientMedication $medication): Builder
    {
        return ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $medication->client_id)
            ->where('client_medication_id', $medication->id)
            ->where('status', 'given')
            ->whereNotNull('administered_at');
    }

    /**
     * Validate administration time window
     */
    public function validateTimeWindow(
        Carbon $scheduledTime,
        Carbon $adminTime,
        int $windowBeforeMinutes = 60,
        int $windowAfterMinutes = 30
    ): array {
        $diffMinutes = $scheduledTime->diffInMinutes($adminTime, false);
        // Negative = early, Positive = late

        $withinWindow = $diffMinutes >= -$windowBeforeMinutes && $diffMinutes <= $windowAfterMinutes;

        return [
            'valid' => $withinWindow,
            'early_minutes' => $diffMinutes < 0 ? abs($diffMinutes) : 0,
            'late_minutes' => $diffMinutes > 0 ? $diffMinutes : 0,
            'diff_minutes' => $diffMinutes,
            'window_start' => $scheduledTime->copy()->subMinutes($windowBeforeMinutes)->toIso8601String(),
            'window_end' => $scheduledTime->copy()->addMinutes($windowAfterMinutes)->toIso8601String(),
            'requires_reason' => ! $withinWindow,
            'message' => $withinWindow
                ? 'Within acceptable time window'
                : ($diffMinutes < 0
                    ? 'Too early by '.abs($diffMinutes).' minutes'
                    : "Late by {$diffMinutes} minutes"),
        ];
    }
}
