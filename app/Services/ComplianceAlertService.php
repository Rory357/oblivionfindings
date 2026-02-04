<?php

namespace App\Services;

use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Model;

/**
 * Service for creating Control Room alerts from compliance events.
 *
 * Integrates the Control Room module with compliance modules including:
 * - Safeguarding concerns
 * - Training & vetting (DBS checks, certifications)
 * - Consent management
 * - Medication compliance (MAR exceptions, controlled drug discrepancies)
 * - Incident reporting
 * - Care plan reviews
 */
class ComplianceAlertService
{
    /**
     * Create a control room alert for a safeguarding concern.
     */
    public function alertSafeguardingConcern(
        Model $concern,
        string $severity = 'high',
        ?int $userId = null
    ): ControlRoomAlert {
        return $this->createAlert(
            source: 'compliance',
            alertType: 'Safeguarding Concern',
            severity: $severity,
            context: [
                'concern_id' => $concern->id,
                'concern_type' => $concern->concern_type ?? null,
                'client_id' => $concern->client_id ?? null,
                'reported_at' => $concern->created_at?->toISOString(),
            ],
            notes: 'New safeguarding concern raised - requires immediate review',
            userId: $userId
        );
    }

    /**
     * Create a control room alert for expired or expiring training.
     */
    public function alertTrainingExpiry(
        int $userId,
        string $courseName,
        string $expiryDate,
        bool $isExpired = false
    ): ControlRoomAlert {
        $severity = $isExpired ? 'high' : 'medium';
        $alertType = $isExpired ? 'Training Expired' : 'Training Expiring Soon';

        return $this->createAlert(
            source: 'compliance',
            alertType: $alertType,
            severity: $severity,
            context: [
                'user_id' => $userId,
                'course_name' => $courseName,
                'expiry_date' => $expiryDate,
                'is_expired' => $isExpired,
            ],
            notes: $isExpired
                ? "Training certification '{$courseName}' has expired"
                : "Training certification '{$courseName}' expiring on {$expiryDate}"
        );
    }

    /**
     * Create a control room alert for DBS check expiry or issues.
     */
    public function alertDbsCheckIssue(
        int $userId,
        string $checkType,
        string $status,
        ?string $expiryDate = null
    ): ControlRoomAlert {
        $isExpired = $status === 'expired';
        $severity = $isExpired ? 'critical' : 'high';

        return $this->createAlert(
            source: 'compliance',
            alertType: 'DBS Check ' . ucfirst($status),
            severity: $severity,
            context: [
                'user_id' => $userId,
                'check_type' => $checkType,
                'status' => $status,
                'expiry_date' => $expiryDate,
            ],
            notes: "DBS check ({$checkType}) is {$status}" . ($expiryDate ? " - expires {$expiryDate}" : '')
        );
    }

    /**
     * Create a control room alert for consent expiry.
     */
    public function alertConsentExpiry(
        int $clientId,
        string $consentType,
        string $expiryDate,
        bool $isExpired = false
    ): ControlRoomAlert {
        return $this->createAlert(
            source: 'compliance',
            alertType: $isExpired ? 'Consent Expired' : 'Consent Expiring Soon',
            severity: $isExpired ? 'high' : 'medium',
            context: [
                'client_id' => $clientId,
                'consent_type' => $consentType,
                'expiry_date' => $expiryDate,
                'is_expired' => $isExpired,
            ],
            notes: $isExpired
                ? "Client consent for '{$consentType}' has expired"
                : "Client consent for '{$consentType}' expiring on {$expiryDate}"
        );
    }

    /**
     * Create a control room alert for care plan review due.
     */
    public function alertCarePlanReviewDue(
        int $clientId,
        string $clientName,
        string $reviewDueDate,
        bool $isOverdue = false
    ): ControlRoomAlert {
        return $this->createAlert(
            source: 'compliance',
            alertType: $isOverdue ? 'Care Plan Review Overdue' : 'Care Plan Review Due',
            severity: $isOverdue ? 'high' : 'medium',
            context: [
                'client_id' => $clientId,
                'client_name' => $clientName,
                'review_due_date' => $reviewDueDate,
                'is_overdue' => $isOverdue,
            ],
            notes: $isOverdue
                ? "Care plan review for {$clientName} is overdue (was due {$reviewDueDate})"
                : "Care plan review for {$clientName} is due on {$reviewDueDate}"
        );
    }

    /**
     * Create a control room alert for medication errors/MAR exceptions.
     */
    public function alertMedicationError(
        int $clientId,
        string $errorType,
        string $medicationName,
        ?int $userId = null
    ): ControlRoomAlert {
        $severityMap = [
            'missed' => 'high',
            'refused' => 'medium',
            'withheld' => 'medium',
            'wrong_dose' => 'critical',
            'wrong_time' => 'high',
            'wrong_medication' => 'critical',
        ];

        return $this->createAlert(
            source: 'compliance',
            alertType: 'Medication Error',
            severity: $severityMap[$errorType] ?? 'high',
            context: [
                'client_id' => $clientId,
                'error_type' => $errorType,
                'medication_name' => $medicationName,
                'staff_user_id' => $userId,
            ],
            notes: "Medication {$errorType}: {$medicationName}",
            userId: $userId
        );
    }

    /**
     * Create a control room alert for controlled drug discrepancy.
     */
    public function alertControlledDrugDiscrepancy(
        int $discrepancyId,
        int $clientId,
        string $drugName,
        string $discrepancyType
    ): ControlRoomAlert {
        return $this->createAlert(
            source: 'compliance',
            alertType: 'Controlled Drug Discrepancy',
            severity: 'critical',
            context: [
                'discrepancy_id' => $discrepancyId,
                'client_id' => $clientId,
                'drug_name' => $drugName,
                'discrepancy_type' => $discrepancyType,
            ],
            notes: "Controlled drug discrepancy detected: {$drugName} ({$discrepancyType})"
        );
    }

    /**
     * Create a control room alert for an incident report.
     */
    public function alertIncidentReported(
        Model $incident,
        ?int $userId = null
    ): ControlRoomAlert {
        $severityMap = [
            'minor' => 'medium',
            'moderate' => 'high',
            'major' => 'critical',
            'critical' => 'critical',
        ];

        $incidentSeverity = $incident->severity ?? 'moderate';

        return $this->createAlert(
            source: 'compliance',
            alertType: 'Incident Reported',
            severity: $severityMap[$incidentSeverity] ?? 'high',
            context: [
                'incident_id' => $incident->id,
                'incident_type' => $incident->incident_type ?? $incident->type ?? null,
                'client_id' => $incident->client_id ?? null,
                'severity' => $incidentSeverity,
                'reported_at' => $incident->created_at?->toISOString(),
            ],
            notes: 'New incident reported - ' . ($incident->description ?? 'see incident details'),
            userId: $userId
        );
    }

    /**
     * Create a control room alert for break-glass access.
     */
    public function alertBreakGlassAccess(
        int $userId,
        int $clientId,
        string $reason,
        ?string $accessedResource = null
    ): ControlRoomAlert {
        return $this->createAlert(
            source: 'compliance',
            alertType: 'Break Glass Access',
            severity: 'high',
            context: [
                'user_id' => $userId,
                'client_id' => $clientId,
                'reason' => $reason,
                'accessed_resource' => $accessedResource,
            ],
            notes: "Emergency break-glass access used: {$reason}",
            userId: $userId
        );
    }

    /**
     * Create a generic control room alert.
     */
    protected function createAlert(
        string $source,
        string $alertType,
        string $severity,
        array $context = [],
        ?string $notes = null,
        ?int $userId = null
    ): ControlRoomAlert {
        // Check for recent duplicate alert (same type and context within 30 minutes)
        $contextKey = md5(json_encode([
            $alertType,
            $context['client_id'] ?? null,
            $context['user_id'] ?? null,
            $context['concern_id'] ?? null,
            $context['incident_id'] ?? null,
            $context['discrepancy_id'] ?? null,
        ]));

        $existing = ControlRoomAlert::query()
            ->where('source', $source)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->where('triggered_at', '>=', now()->subMinutes(30))
            ->whereRaw("JSON_EXTRACT(context, '$.dedup_key') = ?", [$contextKey])
            ->first();

        if ($existing) {
            return $existing;
        }

        return ControlRoomAlert::create([
            'source' => $source,
            'alert_type' => $alertType,
            'severity' => $severity,
            'status' => 'open',
            'triggered_at' => now(),
            'context' => array_merge($context, ['dedup_key' => $contextKey]),
            'notes' => $notes,
            'created_by_user_id' => $userId,
        ]);
    }
}
