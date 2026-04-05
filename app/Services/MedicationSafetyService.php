<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationInteraction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Perform complete safety check before medication administration
     */
    public function performSafetyCheck(
        Client $client,
        ClientMedication $medication,
        ?Carbon $adminTime = null,
        ?string $doseGiven = null
    ): array {
        $adminTime = $adminTime ?? now();
        $warnings = [];
        $alerts = [];
        $blocked = false;
        $blockReason = null;

        // 1. Check if medication is active
        if (!$medication->isActive()) {
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

        // 2. Check for allergies
        $allergyCheck = $this->checkAllergies($client, $medication);
        if ($allergyCheck['has_match']) {
            foreach ($allergyCheck['matches'] as $allergy) {
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
                $warnings[] = [
                    'type' => 'duplicate',
                    'severity' => 'caution',
                    'message' => "Similar medication active: {$duplicate->name} ({$duplicate->formatted_dose})",
                    'details' => [
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
            foreach ($interactionCheck['interactions'] as $interaction) {
                $severity = $interaction->severity === 'contraindicated' ? 'danger' : 
                    ($interaction->severity === 'major' ? 'warning' : 'caution');
                
                $blocked = $blocked || $interaction->severity === 'contraindicated';
                
                $warning = [
                    'type' => 'interaction',
                    'severity' => $severity,
                    'message' => "Drug Interaction: {$interaction->medication_a} + {$interaction->medication_b}",
                    'details' => [
                        'severity' => $interaction->severity,
                        'description' => $interaction->description,
                        'management' => $interaction->management,
                    ],
                ];
                
                if ($interaction->severity === 'contraindicated') {
                    $warning['message'] .= ' - CONTRAINDICATED';
                    $blockReason = "Contraindicated drug interaction detected";
                }
                
                $warnings[] = $warning;
            }
        }

        // 5. Check PRN limits
        if ($medication->is_prn) {
            $prnCheck = $this->checkPrnLimits($medication);

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
                $intervalCheck = $this->checkPrnInterval($medication);
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
                'message' => "⚠️ EXPIRED: This medication expired on {$medication->end_date->format('d/m/Y')}",
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
                    'days_remaining' => $medication->end_date->diffInDays(now()),
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
    public function checkAllergies(Client $client, ClientMedication $medication): array
    {
        $allergies = MedicationAllergy::where('client_id', $client->id)->get();
        $matches = [];

        foreach ($allergies as $allergy) {
            if ($allergy->matchesMedication($medication->name)) {
                $matches[] = $allergy;
            }
        }

        return [
            'has_match' => count($matches) > 0,
            'matches' => $matches,
            'allergy_count' => $allergies->count(),
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
            ->pluck('name')
            ->toArray();

        $interactions = [];

        foreach ($otherMeds as $otherMed) {
            $interaction = MedicationInteraction::checkInteraction($medication->name, $otherMed);
            if ($interaction) {
                $interactions[] = $interaction;
            }
        }

        // Sort by severity (contraindicated first)
        usort($interactions, function ($a, $b) {
            $severityOrder = ['contraindicated' => 0, 'major' => 1, 'moderate' => 2, 'minor' => 3];
            return ($severityOrder[$a->severity] ?? 4) <=> ($severityOrder[$b->severity] ?? 4);
        });

        return [
            'has_interaction' => count($interactions) > 0,
            'interactions' => $interactions,
        ];
    }

    /**
     * Check PRN limits
     */
    public function checkPrnLimits(ClientMedication $medication): array
    {
        if (!$medication->is_prn || !$medication->max_per_day) {
            return [
                'blocked' => false,
                'near_limit' => false,
                'message' => null,
                'details' => [],
            ];
        }

        $count24h = $medication->prnCountLast24Hours;
        $maxPerDay = (int) filter_var($medication->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        
        if ($maxPerDay <= 0) {
            return [
                'blocked' => false,
                'near_limit' => false,
                'message' => null,
                'details' => [],
            ];
        }

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
        if (!$medication->is_prn) {
            return [];
        }

        $history = $medication->administrations()
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
            'safe' => !$blocked && $safetyLevel !== 'danger',
            'blocked' => $blocked,
            'block_reason' => $blockReason,
            'safety_level' => $safetyLevel,
            'safety_info' => self::SAFETY_LEVELS[$safetyLevel],
            'warnings' => $warnings,
            'alerts' => $alerts,
            'warning_count' => count($warnings),
            'can_proceed' => !$blocked,
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
        if (!preg_match('/(\d+(?:\.\d+)?)/', $doseGiven, $matches)) {
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
     * Blocks administration if min_hours_between_doses hasn't elapsed since last dose
     */
    public function checkPrnInterval(ClientMedication $medication): array
    {
        $minHours = (float) $medication->min_hours_between_doses;

        if ($minHours <= 0) {
            return [
                'blocked' => false,
                'message' => null,
                'details' => [],
            ];
        }

        // Find the most recent administration
        $lastAdmin = $medication->administrations()
            ->where('status', 'given')
            ->orderByDesc('administered_at')
            ->first();

        if (!$lastAdmin || !$lastAdmin->administered_at) {
            return [
                'blocked' => false,
                'message' => null,
                'details' => [],
            ];
        }

        $hoursSinceLast = $lastAdmin->administered_at->diffInMinutes(now()) / 60;

        if ($hoursSinceLast < $minHours) {
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

        return [
            'blocked' => false,
            'message' => null,
            'details' => [],
        ];
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
            'requires_reason' => !$withinWindow,
            'message' => $withinWindow 
                ? 'Within acceptable time window'
                : ($diffMinutes < 0 
                    ? "Too early by " . abs($diffMinutes) . " minutes"
                    : "Late by {$diffMinutes} minutes"),
        ];
    }
}
