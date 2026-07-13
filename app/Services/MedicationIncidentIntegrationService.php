<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledDrugLossReport;
use App\Models\FleetMedicationTransitLog;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationSignalService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MedicationIncidentIntegrationService
{
    public function __construct(
        protected ?MedicationSignalService $signalService = null,
        protected ?IncidentJourneyService $journeyService = null,
    ) {
        $this->signalService ??= app(MedicationSignalService::class);
        $this->journeyService ??= app(IncidentJourneyService::class);
    }

    /**
     * Auto-create incident draft for missed dose
     */
    public function handleMissedDose(
        ClientMedicationAdministration $administration,
        ?int $createdBy = null
    ): ?ClientIncident {
        return $this->withLockedMedicationSource(
            $administration,
            'missed_dose',
            function (ClientMedicationAdministration $locked, ?ClientIncident $incident) use ($createdBy): ?ClientIncident {
                $medication = $locked->medication;
                $client = $locked->client;

                if (! $this->shouldAutoCreateIncident('missed_dose', $medication)) {
                    return null;
                }

                if ($incident === null) {
                    $incident = new ClientIncident;
                    $incident->client_id = $client->id;
                    $incident->title = "Missed medication: {$medication->name}";
                    $incident->description = $this->buildMissedDoseDescription($locked, $medication);
                    $incident->category = 'medication';
                    $incident->severity = $this->determineSeverity('missed_dose', $medication);
                    $incident->status = 'draft';
                    $incident->occurred_at = $locked->scheduled_for ?? now();
                    $incident->reported_by = $createdBy;
                    $this->assignIncidentServiceContext($incident, $locked->service_context_id);
                    $this->stampMedicationIncidentSource($incident, $locked, 'missed_dose');
                    $incident->save();
                    $this->linkToMedication($incident, $medication);
                }

                MedicationDashboardAlert::createOrUpdateAlert(
                    $client->id,
                    'missed_dose',
                    'warning',
                    "Missed dose: {$medication->name} scheduled for ".($locked->scheduled_for?->format('H:i') ?? 'unknown time'),
                    $medication->id
                );

                $severity = $medication->controlled_drug ? 'high' : ($medication->high_risk ? 'high' : 'medium');
                $this->signalService->emit(
                    MedicationSignalService::TYPE_MISSED_DOSE,
                    $client->id,
                    $severity,
                    "Missed dose: {$medication->name} scheduled for ".($locked->scheduled_for?->format('H:i') ?? 'unknown time'),
                    [
                        'incident_id' => $incident->id,
                        'client_medication_id' => $medication->id,
                        'administration_id' => $locked->id,
                        'medication_name' => $medication->name,
                        'scheduled_for' => $locked->scheduled_for?->toIso8601String(),
                        'controlled_drug' => $medication->controlled_drug,
                        'high_risk' => $medication->high_risk,
                        'site_id' => $client->site_id,
                        'occurred_at' => $incident->occurred_at?->toIso8601String(),
                    ],
                );

                return $incident->fresh();
            },
        );
    }

    /**
     * Handle PRN over-limit attempt
     */
    public function handlePrnOverLimit(
        Client $client,
        ClientMedication $medication,
        int $attemptedBy
    ): ?ClientIncident {
        return DB::transaction(function () use ($client, $medication, $attemptedBy): ?ClientIncident {
            $lockedClient = Client::query()
                ->whereKey($client->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedMedication = ClientMedication::query()
                ->whereKey($medication->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedMedication->client_id !== (int) $lockedClient->id) {
                throw new \DomainException('The PRN medication does not belong to the selected client.');
            }

            if (! $this->shouldAutoCreateIncident('prn_over_limit', $lockedMedication)) {
                return null;
            }

            $count24h = $lockedMedication->prnCountLast24Hours;
            $maxPerDay = (int) filter_var($lockedMedication->max_per_day, FILTER_SANITIZE_NUMBER_INT);
            $attemptedAt = now();
            $attemptId = (string) Str::uuid();

            $incident = new ClientIncident;
            $incident->client_id = $lockedClient->id;
            $incident->title = "PRN limit exceeded: {$lockedMedication->name}";
            $incident->description = "Attempted to administer PRN medication {$lockedMedication->name} when limit already reached.\n\n".
                "Maximum per 24h: {$maxPerDay}\n".
                "Given in last 24h: {$count24h}\n".
                "Attempted by: User ID {$attemptedBy}\n\n".
                'System blocked administration.';
            $incident->category = 'medication';
            $incident->severity = 'high';
            $incident->status = 'draft';
            $incident->occurred_at = $attemptedAt;
            $incident->reported_by = $attemptedBy;
            $incident->metadata = [
                'medication_prn_attempt' => [
                    'id' => $attemptId,
                    'attempted_by' => $attemptedBy,
                    'prn_count_24h' => $count24h,
                    'max_per_day' => $maxPerDay,
                    'attempted_at' => $attemptedAt->toIso8601String(),
                ],
            ];
            $this->assignIncidentServiceContext($incident, $lockedClient->service_context_id);
            $incident->save();

            $this->linkToMedication($incident, $lockedMedication);

            MedicationDashboardAlert::createOrUpdateAlert(
                $lockedClient->id,
                'prn_over_limit',
                'critical',
                "PRN limit exceeded: {$lockedMedication->name} ({$count24h}/{$maxPerDay})",
                $lockedMedication->id
            );

            $this->signalService->emit(
                MedicationSignalService::TYPE_PRN_OVER_LIMIT,
                $lockedClient->id,
                'critical',
                "PRN limit exceeded: {$lockedMedication->name} ({$count24h}/{$maxPerDay})",
                [
                    'incident_id' => $incident->id,
                    'client_medication_id' => $lockedMedication->id,
                    'medication_name' => $lockedMedication->name,
                    'prn_attempt_id' => $attemptId,
                    'prn_count_24h' => $count24h,
                    'max_per_day' => $maxPerDay,
                    'attempted_by' => $attemptedBy,
                    'controlled_drug' => $lockedMedication->controlled_drug,
                    'high_risk' => $lockedMedication->high_risk,
                    'site_id' => $lockedClient->site_id,
                    'occurred_at' => $attemptedAt->toIso8601String(),
                ],
            );

            return $incident->fresh();
        });
    }

    /**
     * Handle controlled drug discrepancy
     */
    public function handleControlledDiscrepancy(
        ClientControlledDrugDiscrepancy $discrepancy,
        ?int $createdBy = null
    ): ?ClientIncident {
        return DB::transaction(function () use ($discrepancy, $createdBy): ClientIncident {
            $lockedDiscrepancy = ClientControlledDrugDiscrepancy::query()
                ->with(['medication', 'client'])
                ->whereKey($discrepancy->id)
                ->lockForUpdate()
                ->firstOrFail();
            $medication = $lockedDiscrepancy->medication;
            $client = $lockedDiscrepancy->client;
            $actor = $this->requireSubmittedMedicationActor(
                $createdBy,
                $lockedDiscrepancy->reported_by,
                'controlled drug discrepancy',
            );
            $incident = $lockedDiscrepancy->incident_id === null
                ? null
                : ClientIncident::query()
                    ->whereKey($lockedDiscrepancy->incident_id)
                    ->lockForUpdate()
                    ->first();

            if ($incident === null) {
                $incident = new ClientIncident;
                $incident->client_id = $client->id;
                $incident->title = "Controlled drug discrepancy: {$medication->name}";
                $incident->description = $this->buildDiscrepancyDescription($lockedDiscrepancy, $medication);
                $incident->category = 'controlled_drug';
                $incident->severity = 'critical';
                $incident->status = 'submitted';
                $incident->submitted_at = now();
                $incident->occurred_at = $lockedDiscrepancy->reported_at ?? now();
                $incident->reported_by = $actor->id;
                $this->assignIncidentServiceContext($incident, $lockedDiscrepancy->service_context_id);
                $incident->save();

                $this->linkToMedication($incident, $medication);
                $lockedDiscrepancy->updateQuietly(['incident_id' => $incident->id]);
            }

            $this->journeyService->ensureForSubmittedIncident($incident, $actor);

            MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'controlled_discrepancy',
                'critical',
                "Controlled drug discrepancy: {$medication->name} (diff: {$lockedDiscrepancy->difference})",
                $medication->id
            );

            $this->signalService->emit(
                MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY,
                $client->id,
                'critical',
                "Controlled drug discrepancy: {$medication->name} (diff: {$lockedDiscrepancy->difference})",
                [
                    'incident_id' => $incident->id,
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->name,
                    'discrepancy_id' => $lockedDiscrepancy->id,
                    'difference' => $lockedDiscrepancy->difference,
                    'site_id' => $client->site_id,
                    'occurred_at' => $lockedDiscrepancy->reported_at?->toIso8601String(),
                ],
            );

            return $incident->fresh();
        });
    }

    /**
     * Handle unsafe correction attempt
     */
    public function handleUnsafeCorrection(
        ClientMedicationAdministration $original,
        array $correctionData,
        int $correctedBy,
        ?ClientMedicationAdministration $correction = null
    ): ?ClientIncident {
        $anchor = $correction ?? $original;

        return $this->withLockedMedicationSource(
            $anchor,
            'unsafe_correction',
            function (ClientMedicationAdministration $lockedAnchor, ?ClientIncident $incident) use (
                $original,
                $correctionData,
                $correctedBy,
                $correction,
            ): ?ClientIncident {
                $lockedOriginal = $lockedAnchor->is($original)
                    ? $lockedAnchor
                    : ClientMedicationAdministration::query()
                        ->with(['medication', 'client'])
                        ->whereKey($original->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                $hoursSince = $lockedOriginal->created_at?->diffInHours(now()) ?? 0;

                if ($hoursSince < 4) {
                    return null;
                }

                $medication = $lockedOriginal->medication;
                $client = $lockedOriginal->client;

                if ($incident === null) {
                    $incident = new ClientIncident;
                    $incident->client_id = $client->id;
                    $incident->title = "Medication correction after {$hoursSince}h: {$medication->name}";
                    $incident->description = $this->buildCorrectionDescription($lockedOriginal, $correctionData, $hoursSince);
                    $incident->category = 'medication';
                    $incident->severity = $hoursSince > 24 ? 'high' : 'medium';
                    $incident->status = 'draft';
                    $incident->occurred_at = $lockedAnchor->created_at ?? now();
                    $incident->reported_by = $correctedBy;
                    $this->assignIncidentServiceContext($incident, $lockedOriginal->service_context_id);
                    $this->stampMedicationIncidentSource($incident, $lockedAnchor, 'unsafe_correction');
                    $incident->save();
                    $this->linkToMedication($incident, $medication);
                }

                MedicationDashboardAlert::createOrUpdateAlert(
                    $client->id,
                    'unsafe_correction',
                    $hoursSince > 24 ? 'critical' : 'warning',
                    "Medication correction after {$hoursSince}h: {$medication->name}",
                    $medication->id
                );

                $this->signalService->emit(
                    MedicationSignalService::TYPE_UNSAFE_CORRECTION,
                    $client->id,
                    $hoursSince > 24 ? 'high' : 'medium',
                    "Medication correction after {$hoursSince}h: {$medication->name}",
                    [
                        'incident_id' => $incident->id,
                        'client_medication_id' => $medication->id,
                        'administration_id' => $lockedOriginal->id,
                        'correction_id' => $correction?->id,
                        'medication_name' => $medication->name,
                        'hours_since_original' => $hoursSince,
                        'corrected_by' => $correctedBy,
                        'controlled_drug' => $medication->controlled_drug,
                        'high_risk' => $medication->high_risk,
                        'site_id' => $client->site_id,
                        'occurred_at' => $incident->occurred_at?->toIso8601String(),
                    ],
                );

                return $incident->fresh();
            },
        );
    }

    /**
     * Handle late dose
     */
    public function handleLateDose(
        ClientMedicationAdministration $administration,
        int $lateMinutes
    ): ?ClientIncident {
        if ($lateMinutes < 120) {
            return null;
        }
        $hoursLate = round($lateMinutes / 60, 1);

        return $this->withLockedMedicationSource(
            $administration,
            'late_dose',
            function (ClientMedicationAdministration $locked, ?ClientIncident $incident) use ($hoursLate): ClientIncident {
                $medication = $locked->medication;
                $client = $locked->client;

                if ($incident === null) {
                    $incident = new ClientIncident;
                    $incident->client_id = $client->id;
                    $incident->title = "Late medication: {$medication->name} ({$hoursLate}h late)";
                    $incident->description = "Medication {$medication->name} was administered {$hoursLate} hours after scheduled time.\n\n".
                        'Scheduled: '.($locked->scheduled_for?->format('d/m/Y H:i') ?? 'Unknown')."\n".
                        'Given: '.($locked->administered_at?->format('d/m/Y H:i') ?? 'Unknown')."\n".
                        'Reason: '.($locked->reason ?? 'Not provided');
                    $incident->category = 'medication';
                    $incident->severity = $hoursLate > 4 ? 'high' : 'medium';
                    $incident->status = 'draft';
                    $incident->occurred_at = $locked->administered_at ?? now();
                    $incident->reported_by = $locked->administered_by;
                    $this->assignIncidentServiceContext($incident, $locked->service_context_id);
                    $this->stampMedicationIncidentSource($incident, $locked, 'late_dose');
                    $incident->save();
                    $this->linkToMedication($incident, $medication);
                }

                MedicationDashboardAlert::createOrUpdateAlert(
                    $client->id,
                    'late_dose',
                    $hoursLate > 4 ? 'critical' : 'warning',
                    "Late dose: {$medication->name} ({$hoursLate}h late)",
                    $medication->id
                );

                $severity = $hoursLate > 4 ? 'high' : 'medium';
                $this->signalService->emit(
                    MedicationSignalService::TYPE_LATE_DOSE,
                    $client->id,
                    $severity,
                    "Late dose: {$medication->name} ({$hoursLate}h late)",
                    [
                        'incident_id' => $incident->id,
                        'client_medication_id' => $medication->id,
                        'administration_id' => $locked->id,
                        'medication_name' => $medication->name,
                        'hours_late' => $hoursLate,
                        'scheduled_for' => $locked->scheduled_for?->toIso8601String(),
                        'administered_at' => $locked->administered_at?->toIso8601String(),
                        'controlled_drug' => $medication->controlled_drug,
                        'high_risk' => $medication->high_risk,
                        'site_id' => $client->site_id,
                        'occurred_at' => $incident->occurred_at?->toIso8601String(),
                    ],
                );

                return $incident->fresh();
            },
        );
    }

    /**
     * Handle refused medication
     */
    public function handleRefusedDose(
        ClientMedicationAdministration $administration
    ): ?ClientIncident {
        return $this->withLockedMedicationSource(
            $administration,
            'refused_dose',
            function (ClientMedicationAdministration $locked, ?ClientIncident $incident): ?ClientIncident {
                $medication = $locked->medication;

                if (! $medication->high_risk && ! $medication->controlled_drug) {
                    return null;
                }

                $client = $locked->client;

                if ($incident === null) {
                    $incident = new ClientIncident;
                    $incident->client_id = $client->id;
                    $incident->title = "Refused medication: {$medication->name}";
                    $incident->description = "Client refused {$medication->name}.\n\n".
                        'Classification: '.($medication->high_risk ? 'High Risk' : '').
                        ($medication->controlled_drug ? ' Controlled Drug' : '')."\n".
                        'Reason given: '.($locked->reason ?? 'Not provided')."\n".
                        'Follow-up may be required.';
                    $incident->category = 'medication';
                    $incident->severity = $medication->controlled_drug ? 'high' : 'medium';
                    $incident->status = 'draft';
                    $incident->occurred_at = $locked->administered_at ?? now();
                    $incident->reported_by = $locked->administered_by;
                    $this->assignIncidentServiceContext($incident, $locked->service_context_id);
                    $this->stampMedicationIncidentSource($incident, $locked, 'refused_dose');
                    $incident->save();
                    $this->linkToMedication($incident, $medication);
                }

                MedicationDashboardAlert::createOrUpdateAlert(
                    $client->id,
                    'refused_dose',
                    $medication->controlled_drug ? 'critical' : 'warning',
                    "Refused dose: {$medication->name}",
                    $medication->id
                );

                $this->signalService->emit(
                    MedicationSignalService::TYPE_REFUSED_DOSE,
                    $client->id,
                    $medication->controlled_drug ? 'high' : 'medium',
                    "Refused dose: {$medication->name}",
                    [
                        'incident_id' => $incident->id,
                        'client_medication_id' => $medication->id,
                        'administration_id' => $locked->id,
                        'medication_name' => $medication->name,
                        'reason' => $locked->reason,
                        'controlled_drug' => $medication->controlled_drug,
                        'high_risk' => $medication->high_risk,
                        'site_id' => $client->site_id,
                        'occurred_at' => $incident->occurred_at?->toIso8601String(),
                    ],
                );

                return $incident->fresh();
            },
        );
    }

    public function handleRefusalEscalation(
        MedicationRefusalFollowup $followup,
        int $recentRefusalCount
    ): ?ClientIncident {
        return DB::transaction(function () use ($followup, $recentRefusalCount): ?ClientIncident {
            $lockedFollowup = MedicationRefusalFollowup::query()
                ->with(['client', 'administration.medication'])
                ->lockForUpdate()
                ->find($followup->id);

            $client = $lockedFollowup?->client;
            $administration = $lockedFollowup?->administration;
            $medication = $administration?->medication;
            $medicationName = $medication?->name ?? 'Medication';

            if (! $lockedFollowup || ! $client) {
                return null;
            }

            $actor = $this->requireSubmittedMedicationActor(
                null,
                $lockedFollowup->created_by,
                'medication refusal escalation',
            );

            $incident = ClientIncident::query()
                ->where('client_id', $client->id)
                ->where('metadata->medication_refusal_followup_id', $lockedFollowup->id)
                ->lockForUpdate()
                ->first();

            if (! $incident) {
                $incident = new ClientIncident;
                $incident->client_id = $client->id;
                $incident->title = "Repeated medication refusal: {$medicationName}";
                $incident->description = $this->buildRefusalEscalationDescription(
                    $lockedFollowup,
                    $medicationName,
                    $recentRefusalCount
                );
                $incident->category = 'medication';
                $incident->severity = ($medication?->controlled_drug || $medication?->high_risk) ? 'high' : 'medium';
                $incident->status = 'submitted';
                $incident->submitted_at = now();
                $incident->occurred_at = $lockedFollowup->created_at ?? now();
                $incident->reported_by = $actor->id;
                $incident->metadata = [
                    'medication_refusal_followup_id' => $lockedFollowup->id,
                ];
                $this->assignIncidentServiceContext(
                    $incident,
                    $administration?->service_context_id ?? $client->service_context_id
                );
                $incident->save();

                if ($medication) {
                    $this->linkToMedication($incident, $medication);
                }
            }

            $this->journeyService->ensureForSubmittedIncident($incident, $actor);

            MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'refusal_escalation',
                'critical',
                "{$medicationName}: repeated refusals require follow-up",
                $medication?->id
            );

            $this->signalService->emit(
                MedicationSignalService::TYPE_REFUSAL_ESCALATION,
                $client->id,
                ($medication?->controlled_drug || $medication?->high_risk) ? 'high' : 'medium',
                "{$medicationName}: repeated refusals require follow-up",
                [
                    'incident_id' => $incident->id,
                    'client_medication_id' => $medication?->id,
                    'administration_id' => $administration?->id,
                    'followup_id' => $lockedFollowup->id,
                    'medication_name' => $medicationName,
                    'recent_refusal_count' => $recentRefusalCount,
                    'gp_notification_required' => (bool) $lockedFollowup->gp_notification_required,
                    'follow_up_due_at' => $lockedFollowup->follow_up_due_at?->toIso8601String(),
                    'controlled_drug' => $medication?->controlled_drug ?? false,
                    'high_risk' => $medication?->high_risk ?? false,
                    'site_id' => $client->site_id,
                    'occurred_at' => $lockedFollowup->created_at?->toIso8601String(),
                ],
            );

            return $incident->fresh();
        });
    }

    public function handleControlledLossReport(
        ControlledDrugLossReport $report,
        ?int $createdBy = null
    ): ?ClientIncident {
        return DB::transaction(function () use ($report, $createdBy): ?ClientIncident {
            $lockedReport = ControlledDrugLossReport::query()
                ->with(['client', 'medication'])
                ->lockForUpdate()
                ->find($report->id);

            $client = $lockedReport?->client;
            if (! $lockedReport || ! $client) {
                return null;
            }

            $actor = $this->requireSubmittedMedicationActor(
                $createdBy,
                $lockedReport->discovered_by,
                'controlled drug loss',
            );

            $medication = $lockedReport->medication;
            $medicationName = $lockedReport->medication_name ?: $medication?->name ?: 'Controlled medication';
            $incident = $lockedReport->incident_id === null
                ? null
                : ClientIncident::query()
                    ->whereKey($lockedReport->incident_id)
                    ->lockForUpdate()
                    ->first();

            if (! $incident) {
                $incident = new ClientIncident;
                $incident->client_id = $client->id;
                $incident->title = "Controlled drug loss: {$medicationName}";
                $incident->description = $this->buildControlledLossDescription($lockedReport, $medicationName);
                $incident->category = 'controlled_drug';
                $incident->severity = 'critical';
                $incident->status = 'submitted';
                $incident->submitted_at = now();
                $incident->occurred_at = $lockedReport->discovered_at ?? now();
                $incident->reported_by = $actor->id;
                $this->assignIncidentServiceContext($incident, $client->service_context_id);
                $incident->save();

                if ($medication) {
                    $this->linkToMedication($incident, $medication);
                }

                $lockedReport->updateQuietly(['incident_id' => $incident->id]);
            }

            $this->journeyService->ensureForSubmittedIncident($incident, $actor);

            MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'controlled_loss',
                'critical',
                "Controlled drug loss reported: {$medicationName}",
                $medication?->id
            );

            $this->signalService->emit(
                MedicationSignalService::TYPE_CONTROLLED_LOSS,
                $client->id,
                'critical',
                "Controlled drug loss reported: {$medicationName}",
                [
                    'incident_id' => $incident->id,
                    'client_medication_id' => $medication?->id,
                    'loss_report_id' => $lockedReport->id,
                    'medication_name' => $medicationName,
                    'quantity_lost' => (string) $lockedReport->quantity_lost,
                    'unit' => $lockedReport->unit,
                    'reported_to_police' => (bool) $lockedReport->reported_to_police,
                    'reported_to_pharmacy' => (bool) $lockedReport->reported_to_pharmacy,
                    'site_id' => $client->site_id,
                    'occurred_at' => $lockedReport->discovered_at?->toIso8601String(),
                ],
            );

            return $incident->fresh();
        });
    }

    public function handleTransitException(FleetMedicationTransitLog $log): void
    {
        $log->loadMissing([
            'client',
            'medication',
            'transport',
            'packedBy:id,name',
        ]);

        if (! $log->is_controlled_drug || ! $log->client) {
            return;
        }

        $client = $log->client;
        $medication = $log->medication;
        $medicationName = $log->medication_name ?: ($medication?->name ?? 'Controlled medication');

        if ($log->medication_id) {
            MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'transit_exception',
                'critical',
                "Controlled medication in transit: {$medicationName}",
                $log->medication_id
            );
        }

        $this->signalService->emit(
            MedicationSignalService::TYPE_TRANSIT_EXCEPTION,
            $client->id,
            'high',
            "Controlled medication in transit: {$medicationName}",
            [
                'client_medication_id' => $log->medication_id,
                'transport_log_id' => $log->id,
                'transport_id' => $log->transport_id,
                'medication_name' => $medicationName,
                'packed_at' => $log->packed_at?->toIso8601String(),
                'packed_by' => $log->packed_by_user_id,
                'packed_by_name' => $log->packedBy?->name,
                'packed_witness_name' => $log->packed_witness_name,
                'is_controlled_drug' => true,
                'site_id' => $client->site_id,
            ],
        );
    }

    public function resolveControlledDiscrepancy(
        ClientControlledDrugDiscrepancy $discrepancy,
        string $reason,
        ?int $resolvedBy = null
    ): void {
        $this->resolveDashboardAlerts(
            $discrepancy->client_id,
            ['controlled_discrepancy'],
            $discrepancy->client_medication_id,
            $reason
        );

        $this->signalService->resolveAlerts(
            MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY,
            ['discrepancy_id' => $discrepancy->id],
            $reason,
            'medication_discrepancy_resolution',
            array_filter([
                'resolved_by_user_id' => $resolvedBy,
            ], fn ($value) => $value !== null)
        );
    }

    public function resolveControlledLossReport(
        ControlledDrugLossReport $report,
        string $reason,
        ?int $resolvedBy = null
    ): void {
        $this->resolveDashboardAlerts(
            $report->client_id,
            ['controlled_loss'],
            $report->client_medication_id,
            $reason
        );

        $this->signalService->resolveAlerts(
            MedicationSignalService::TYPE_CONTROLLED_LOSS,
            ['loss_report_id' => $report->id],
            $reason,
            'medication_loss_report_resolution',
            array_filter([
                'resolved_by_user_id' => $resolvedBy,
            ], fn ($value) => $value !== null)
        );
    }

    public function resolveTransitException(
        FleetMedicationTransitLog $log,
        string $reason,
        ?int $resolvedBy = null
    ): void {
        $resolvedCount = $this->signalService->resolveAlerts(
            MedicationSignalService::TYPE_TRANSIT_EXCEPTION,
            ['transport_log_id' => $log->id],
            $reason,
            'medication_transit_workflow',
            array_filter([
                'resolved_by_user_id' => $resolvedBy,
            ], fn ($value) => $value !== null)
        );

        if ($resolvedCount < 1 || ! $log->medication_id) {
            return;
        }

        $otherActiveTransitLogs = FleetMedicationTransitLog::query()
            ->where('client_id', $log->client_id)
            ->where('medication_id', $log->medication_id)
            ->where('id', '!=', $log->id)
            ->whereNull('administered_at')
            ->whereNull('returned_to_house_at')
            ->exists();

        if (! $otherActiveTransitLogs) {
            $this->resolveDashboardAlerts(
                $log->client_id,
                ['transit_exception'],
                $log->medication_id,
                $reason
            );
        }
    }

    public function resolveRefusalEscalation(
        MedicationRefusalFollowup $followup,
        string $reason,
        ?int $resolvedBy = null
    ): void {
        $followup->loadMissing('administration:id,client_medication_id');

        $this->resolveDashboardAlerts(
            $followup->client_id,
            ['refusal_escalation'],
            $followup->administration?->client_medication_id,
            $reason
        );

        $this->signalService->resolveAlerts(
            MedicationSignalService::TYPE_REFUSAL_ESCALATION,
            ['followup_id' => $followup->id],
            $reason,
            'medication_refusal_followup',
            array_filter([
                'resolved_by_user_id' => $resolvedBy,
            ], fn ($value) => $value !== null)
        );
    }

    public function resolveUnsafeCorrection(
        ClientMedicationAdministration $correction,
        string $reason,
        ?int $resolvedBy = null
    ): void {
        $resolvedCount = $this->signalService->resolveAlerts(
            MedicationSignalService::TYPE_UNSAFE_CORRECTION,
            ['correction_id' => $correction->id],
            $reason,
            'medication_correction_workflow',
            array_filter([
                'resolved_by_user_id' => $resolvedBy,
            ], fn ($value) => $value !== null)
        );

        if ($resolvedCount > 0) {
            $this->resolveDashboardAlerts(
                $correction->client_id,
                ['unsafe_correction'],
                $correction->client_medication_id,
                $reason
            );
        }
    }

    public function resolveMedicationError(
        MedicationError $error,
        string $reason,
        ?int $resolvedBy = null
    ): void {
        $this->signalService->resolveAlerts(
            MedicationSignalService::TYPE_ERROR,
            ['medication_error_id' => $error->id],
            $reason,
            'medication_error_resolution',
            array_filter([
                'resolved_by_user_id' => $resolvedBy,
            ], fn ($value) => $value !== null)
        );
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
        $description .= 'Dosage: '.($medication->formatted_dose ?? 'N/A')."\n";
        $description .= 'Scheduled time: '.($administration->scheduled_for?->format('d/m/Y H:i') ?? 'Unknown')."\n";
        $description .= 'Classification: ';

        if ($medication->controlled_drug) {
            $description .= 'Controlled Drug ';
        }
        if ($medication->high_risk) {
            $description .= 'High Risk ';
        }
        if (! $medication->controlled_drug && ! $medication->high_risk) {
            $description .= 'Standard';
        }

        $description .= "\n\nReason: ".($administration->reason ?? 'Not recorded')."\n";
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
        $description .= "\nReason given: ".($discrepancy->reason ?? 'Not provided')."\n";
        $description .= "\n⚠️ CRITICAL: Immediate review required.\n";
        $description .= 'Further controlled drug transactions for this medication are blocked until resolved.';

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
        $description .= '- Dose: '.($original->dose_given ?? 'N/A')."\n";
        $description .= '- Time: '.($original->administered_at?->format('d/m/Y H:i') ?? 'Unknown')."\n\n";

        $description .= "Correction:\n";
        $description .= '- Status: '.($correctionData['status'] ?? 'Unknown')."\n";
        $description .= '- Dose: '.($correctionData['dose_given'] ?? 'N/A')."\n";
        $description .= '- Time: '.(isset($correctionData['administered_at'])
            ? (new Carbon($correctionData['administered_at']))->format('d/m/Y H:i')
            : 'Unknown')."\n";
        $description .= '- Reason: '.($correctionData['correction_reason'] ?? 'Not provided')."\n\n";

        if ($hoursSince > 24) {
            $description .= '⚠️ Significant delay in correction - review required.';
        }

        return $description;
    }

    private function buildRefusalEscalationDescription(
        MedicationRefusalFollowup $followup,
        string $medicationName,
        int $recentRefusalCount
    ): string {
        $description = "Medication refusal pattern requires escalation.\n\n";
        $description .= "Medication: {$medicationName}\n";
        $description .= "Recent refusals/withheld doses in 7 days: {$recentRefusalCount}\n";
        $description .= "Reason category: {$followup->reason_category}\n";
        $description .= 'Detailed reason: '.($followup->detailed_reason ?: 'Not provided')."\n";
        $description .= "Capacity at time: {$followup->client_capacity_at_time}\n";
        $description .= 'GP notification required: '.($followup->gp_notification_required ? 'Yes' : 'No')."\n";
        $description .= 'Follow-up due: '.($followup->follow_up_due_at?->format('d/m/Y H:i') ?? 'Not set');

        return $description;
    }

    private function buildControlledLossDescription(
        ControlledDrugLossReport $report,
        string $medicationName
    ): string {
        $description = "Controlled drug loss report submitted.\n\n";
        $description .= "Medication: {$medicationName}\n";
        $description .= "Quantity lost: {$report->quantity_lost}".($report->unit ? " {$report->unit}" : '')."\n";
        $description .= "Circumstances: {$report->circumstances}\n";
        $description .= 'Reported to police: '.($report->reported_to_police ? 'Yes' : 'No')."\n";
        $description .= 'Reported to pharmacy: '.($report->reported_to_pharmacy ? 'Yes' : 'No');

        return $description;
    }

    /**
     * Use the medication source row as the serialization point, then resolve the
     * one draft incident owned by that source and incident kind.
     *
     * @param  callable(ClientMedicationAdministration, ?ClientIncident): ?ClientIncident  $callback
     */
    private function withLockedMedicationSource(
        ClientMedicationAdministration $source,
        string $kind,
        callable $callback,
    ): ?ClientIncident {
        return DB::transaction(function () use ($source, $kind, $callback): ?ClientIncident {
            $lockedSource = ClientMedicationAdministration::query()
                ->with(['medication', 'client'])
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();
            $sourceType = $lockedSource::class;
            $incident = ClientIncident::query()
                ->where('client_id', $lockedSource->client_id)
                ->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.medication_incident_source.type')) = ?",
                    [$sourceType],
                )
                ->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.medication_incident_source.id')) = ?",
                    [(string) $lockedSource->id],
                )
                ->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.medication_incident_source.kind')) = ?",
                    [$kind],
                )
                ->lockForUpdate()
                ->first();

            return $callback($lockedSource, $incident);
        });
    }

    private function stampMedicationIncidentSource(
        ClientIncident $incident,
        ClientMedicationAdministration $source,
        string $kind,
    ): void {
        $metadata = $incident->metadata ?? [];
        $metadata['medication_incident_source'] = [
            'type' => $source::class,
            'id' => $source->id,
            'kind' => $kind,
        ];
        $incident->metadata = $metadata;
    }

    private function requireSubmittedMedicationActor(
        ?int $suppliedActorId,
        ?int $sourceReporterId,
        string $journeyType,
    ): User {
        $actorId = $suppliedActorId ?? $sourceReporterId;
        $actor = $actorId === null ? null : User::query()->find($actorId);

        if ($actor === null) {
            throw new \DomainException(
                "Submitted {$journeyType} journey requires an explicit actor or source reporter before any records are written.",
            );
        }

        return $actor;
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
        if (! $this->incidentSupportsMetadata()) {
            return;
        }

        // Store medication reference in incident metadata
        $metadata = $incident->metadata ?? [];
        $metadata['medication_id'] = $medication->id;
        $metadata['medication_name'] = $medication->name;
        $metadata['controlled_drug'] = $medication->controlled_drug;
        $metadata['high_risk'] = $medication->high_risk;
        $incident->metadata = $metadata;
        $incident->save();
    }

    private function resolveDashboardAlerts(
        int $clientId,
        array $alertTypes,
        ?int $medicationId,
        ?string $reason = null
    ): void {
        $query = MedicationDashboardAlert::query()
            ->where('client_id', $clientId)
            ->whereIn('alert_type', $alertTypes)
            ->where('status', 'active');

        if ($medicationId !== null) {
            $query->where('client_medication_id', $medicationId);
        }

        $query->get()->each->resolve($reason);
    }

    /**
     * Get incidents related to medications
     */
    public function getMedicationIncidents(
        ?int $clientId = null,
        ?string $category = null,
        ?string $severity = null,
        int $limit = 50
    ): Collection {
        $query = ClientIncident::query()
            ->orderByDesc('created_at');

        if ($this->incidentSupportsMetadata()) {
            $query->whereNotNull('metadata->medication_id');
        } else {
            $query->whereIn('type', ['medication', 'controlled_drug']);
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($category) {
            $query->where('type', $category);
        }

        if ($severity) {
            $query->where('severity', $severity);
        }

        return $query->limit($limit)->get();
    }

    private function assignIncidentServiceContext(ClientIncident $incident, ?int $serviceContextId): void
    {
        if ($serviceContextId === null || ! $this->incidentSupportsServiceContext()) {
            return;
        }

        $incident->service_context_id = $serviceContextId;
    }

    private function incidentSupportsServiceContext(): bool
    {
        static $supportsServiceContext = null;

        if ($supportsServiceContext === null) {
            $supportsServiceContext = Schema::hasTable('client_incidents')
                && Schema::hasColumn('client_incidents', 'service_context_id');
        }

        return $supportsServiceContext;
    }

    private function incidentSupportsMetadata(): bool
    {
        static $supportsMetadata = null;

        if ($supportsMetadata === null) {
            $supportsMetadata = Schema::hasTable('client_incidents')
                && Schema::hasColumn('client_incidents', 'metadata');
        }

        return $supportsMetadata;
    }
}
