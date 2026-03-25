<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDashboardAlert;

class MedicationIncidentIntegrationService
{
    /**
     * Auto-create incident draft for missed dose
     */
    public function handleMissedDose(
        ClientMedicationAdministration $administration,
        ?int $createdBy = null
    ): ?ClientIncident {
        $medication = $administration->medication;
        $client = $administration->client;

        // Check if we should auto-create
        if (!$this->shouldAutoCreateIncident('missed_dose', $medication)) {
            return null;
        }

        $incident = new ClientIncident();
        $incident->client_id = $client->id;
        $incident->service_context_id = $administration->service_context_id;
        $incident->title = "Missed medication: {$medication->name}";
        $incident->description = $this->buildMissedDoseDescription($administration, $medication);
        $incident->category = 'medication';
        $incident->severity = $this->determineSeverity('missed_dose', $medication);
        $incident->status = 'draft';
        $incident->occurred_at = $administration->scheduled_for ?? now();
        $incident->reported_by = $createdBy;
        $incident->save();

        // Link to medication
        $this->linkToMedication($incident, $medication);

        // Create dashboard alert
        MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'missed_dose',
            'warning',
            "Missed dose: {$medication->name} scheduled for " . ($administration->scheduled_for?->format('H:i') ?? 'unknown time'),
            $medication->id
        );

        return $incident;
    }

    /**
     * Handle PRN over-limit attempt
     */
    public function handlePrnOverLimit(
        Client $client,
        ClientMedication $medication,
        int $attemptedBy
    ): ?ClientIncident {
        if (!$this->shouldAutoCreateIncident('prn_over_limit', $medication)) {
            return null;
        }

        $count24h = $medication->prnCountLast24Hours;
        $maxPerDay = (int) filter_var($medication->max_per_day, FILTER_SANITIZE_NUMBER_INT);

        $incident = new ClientIncident();
        $incident->client_id = $client->id;
        $incident->service_context_id = $client->service_context_id;
        $incident->title = "PRN limit exceeded: {$medication->name}";
        $incident->description = "Attempted to administer PRN medication {$medication->name} when limit already reached.\n\n" .
            "Maximum per 24h: {$maxPerDay}\n" .
            "Given in last 24h: {$count24h}\n" .
            "Attempted by: User ID {$attemptedBy}\n\n" .
            "System blocked administration.";
        $incident->category = 'medication';
        $incident->severity = 'high';
        $incident->status = 'draft';
        $incident->occurred_at = now();
        $incident->reported_by = $attemptedBy;
        $incident->save();

        $this->linkToMedication($incident, $medication);

        MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'prn_over_limit',
            'critical',
            "PRN limit exceeded: {$medication->name} ({$count24h}/{$maxPerDay})",
            $medication->id
        );

        return $incident;
    }

    /**
     * Handle controlled drug discrepancy
     */
    public function handleControlledDiscrepancy(
        ClientControlledDrugDiscrepancy $discrepancy,
        ?int $createdBy = null
    ): ?ClientIncident {
        $medication = $discrepancy->medication;
        $client = $discrepancy->client;

        $incident = new ClientIncident();
        $incident->client_id = $client->id;
        $incident->service_context_id = $discrepancy->service_context_id;
        $incident->title = "Controlled drug discrepancy: {$medication->name}";
        $incident->description = $this->buildDiscrepancyDescription($discrepancy, $medication);
        $incident->category = 'controlled_drug';
        $incident->severity = 'critical';
        $incident->status = 'open';
        $incident->occurred_at = $discrepancy->reported_at ?? now();
        $incident->reported_by = $createdBy ?? $discrepancy->reported_by;
        $incident->save();

        $this->linkToMedication($incident, $medication);

        // Lock further controlled drug actions until resolved
        MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'controlled_discrepancy',
            'critical',
            "Controlled drug discrepancy: {$medication->name} (diff: {$discrepancy->difference})",
            $medication->id
        );

        return $incident;
    }

    /**
     * Handle unsafe correction attempt
     */
    public function handleUnsafeCorrection(
        ClientMedicationAdministration $original,
        array $correctionData,
        int $correctedBy
    ): ?ClientIncident {
        // Only flag if significant time has passed
        $hoursSince = $original->created_at?->diffInHours(now()) ?? 0;
        
        if ($hoursSince < 4) {
            return null; // Don't auto-create for quick corrections
        }

        $medication = $original->medication;
        $client = $original->client;

        $incident = new ClientIncident();
        $incident->client_id = $client->id;
        $incident->service_context_id = $original->service_context_id;
        $incident->title = "Medication correction after {$hoursSince}h: {$medication->name}";
        $incident->description = $this->buildCorrectionDescription($original, $correctionData, $hoursSince);
        $incident->category = 'medication';
        $incident->severity = $hoursSince > 24 ? 'high' : 'medium';
        $incident->status = 'draft';
        $incident->occurred_at = now();
        $incident->reported_by = $correctedBy;
        $incident->save();

        $this->linkToMedication($incident, $medication);

        return $incident;
    }

    /**
     * Handle late dose
     */
    public function handleLateDose(
        ClientMedicationAdministration $administration,
        int $lateMinutes
    ): ?ClientIncident {
        // Only create incident if significantly late (> 2 hours)
        if ($lateMinutes < 120) {
            return null;
        }

        $medication = $administration->medication;
        $client = $administration->client;

        $hoursLate = round($lateMinutes / 60, 1);

        $incident = new ClientIncident();
        $incident->client_id = $client->id;
        $incident->service_context_id = $administration->service_context_id;
        $incident->title = "Late medication: {$medication->name} ({$hoursLate}h late)";
        $incident->description = "Medication {$medication->name} was administered {$hoursLate} hours after scheduled time.\n\n" .
            "Scheduled: " . ($administration->scheduled_for?->format('d/m/Y H:i') ?? 'Unknown') . "\n" .
            "Given: " . ($administration->administered_at?->format('d/m/Y H:i') ?? 'Unknown') . "\n" .
            "Reason: " . ($administration->reason ?? 'Not provided');
        $incident->category = 'medication';
        $incident->severity = $hoursLate > 4 ? 'high' : 'medium';
        $incident->status = 'draft';
        $incident->occurred_at = $administration->administered_at ?? now();
        $incident->reported_by = $administration->administered_by;
        $incident->save();

        $this->linkToMedication($incident, $medication);

        MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'late_dose',
            $hoursLate > 4 ? 'critical' : 'warning',
            "Late dose: {$medication->name} ({$hoursLate}h late)",
            $medication->id
        );

        return $incident;
    }

    /**
     * Handle refused medication
     */
    public function handleRefusedDose(
        ClientMedicationAdministration $administration
    ): ?ClientIncident {
        $medication = $administration->medication;
        
        // Only create incident for high-risk or controlled medications
        if (!$medication->high_risk && !$medication->controlled_drug) {
            return null;
        }

        $client = $administration->client;

        $incident = new ClientIncident();
        $incident->client_id = $client->id;
        $incident->service_context_id = $administration->service_context_id;
        $incident->title = "Refused medication: {$medication->name}";
        $incident->description = "Client refused {$medication->name}.\n\n" .
            "Classification: " . ($medication->high_risk ? 'High Risk' : '') .
            ($medication->controlled_drug ? ' Controlled Drug' : '') . "\n" .
            "Reason given: " . ($administration->reason ?? 'Not provided') . "\n" .
            "Follow-up may be required.";
        $incident->category = 'medication';
        $incident->severity = $medication->controlled_drug ? 'high' : 'medium';
        $incident->status = 'draft';
        $incident->occurred_at = $administration->administered_at ?? now();
        $incident->reported_by = $administration->administered_by;
        $incident->save();

        $this->linkToMedication($incident, $medication);

        return $incident;
    }

    /**
     * Build missed dose description
     */
    private function buildMissedDoseDescription(
        ClientMedicationAdministration $administration,
        ClientMedication $medication
    ): string {
        $description = "Scheduled medication was not administered.\n\n";
        $description .= "Medication: {$medication->name}\n";
        $description .= "Dosage: " . ($medication->formatted_dose ?? 'N/A') . "\n";
        $description .= "Scheduled time: " . ($administration->scheduled_for?->format('d/m/Y H:i') ?? 'Unknown') . "\n";
        $description .= "Classification: ";
        
        if ($medication->controlled_drug) {
            $description .= "Controlled Drug ";
        }
        if ($medication->high_risk) {
            $description .= "High Risk ";
        }
        if (!$medication->controlled_drug && !$medication->high_risk) {
            $description .= "Standard";
        }
        
        $description .= "\n\nReason: " . ($administration->reason ?? 'Not recorded') . "\n";
        $description .= "\nFollow-up actions required per medication policy.";

        return $description;
    }

    /**
     * Build discrepancy description
     */
    private function buildDiscrepancyDescription(
        ClientControlledDrugDiscrepancy $discrepancy,
        ClientMedication $medication
    ): string {
        $description = "Controlled drug stock discrepancy detected.\n\n";
        $description .= "Medication: {$medication->name}\n";
        $description .= "Expected quantity: {$discrepancy->on_hand_before}\n";
        $description .= "Actual quantity: {$discrepancy->on_hand_after}\n";
        $description .= "Difference: {$discrepancy->difference}\n";
        $description .= "\nReason given: " . ($discrepancy->reason ?? 'Not provided') . "\n";
        $description .= "\n⚠️ CRITICAL: Immediate review required.\n";
        $description .= "Further controlled drug transactions for this medication are blocked until resolved.";

        return $description;
    }

    /**
     * Build correction description
     */
    private function buildCorrectionDescription(
        ClientMedicationAdministration $original,
        array $correctionData,
        int $hoursSince
    ): string {
        $description = "Medication administration was corrected after {$hoursSince} hours.\n\n";
        $description .= "Original record:\n";
        $description .= "- Status: {$original->status}\n";
        $description .= "- Dose: " . ($original->dose_given ?? 'N/A') . "\n";
        $description .= "- Time: " . ($original->administered_at?->format('d/m/Y H:i') ?? 'Unknown') . "\n\n";
        
        $description .= "Correction:\n";
        $description .= "- Status: " . ($correctionData['status'] ?? 'Unknown') . "\n";
        $description .= "- Dose: " . ($correctionData['dose_given'] ?? 'N/A') . "\n";
        $description .= "- Time: " . (isset($correctionData['administered_at']) 
            ? (new \Carbon\Carbon($correctionData['administered_at']))->format('d/m/Y H:i')
            : 'Unknown') . "\n";
        $description .= "- Reason: " . ($correctionData['correction_reason'] ?? 'Not provided') . "\n\n";
        
        if ($hoursSince > 24) {
            $description .= "⚠️ Significant delay in correction - review required.";
        }

        return $description;
    }

    /**
     * Determine if incident should be auto-created
     */
    private function shouldAutoCreateIncident(string $type, ClientMedication $medication): bool
    {
        // Always create for controlled drugs and high-risk medications
        if ($medication->controlled_drug || $medication->high_risk) {
            return true;
        }

        // Configurable thresholds could go here
        return true;
    }

    /**
     * Determine incident severity
     */
    private function determineSeverity(string $type, ClientMedication $medication): string
    {
        if ($medication->controlled_drug) {
            return 'critical';
        }
        if ($medication->high_risk) {
            return 'high';
        }

        return match ($type) {
            'missed_dose' => 'medium',
            default => 'low',
        };
    }

    /**
     * Link incident to medication (store in metadata)
     */
    private function linkToMedication(ClientIncident $incident, ClientMedication $medication): void
    {
        // Store medication reference in incident metadata
        $metadata = $incident->metadata ?? [];
        $metadata['medication_id'] = $medication->id;
        $metadata['medication_name'] = $medication->name;
        $metadata['controlled_drug'] = $medication->controlled_drug;
        $metadata['high_risk'] = $medication->high_risk;
        $incident->metadata = $metadata;
        $incident->save();
    }

    /**
     * Get incidents related to medications
     */
    public function getMedicationIncidents(
        ?int $clientId = null,
        ?string $category = null,
        ?string $severity = null,
        int $limit = 50
    ): \Illuminate\Database\Eloquent\Collection {
        $query = ClientIncident::whereNotNull('metadata->medication_id')
            ->orderByDesc('created_at');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($category) {
            $query->where('category', $category);
        }

        if ($severity) {
            $query->where('severity', $severity);
        }

        return $query->limit($limit)->get();
    }
}
