<?php

namespace App\Services\Medication;

use App\Jobs\Governance\RegisterIncidentGovernanceEscalationJob;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\MedicationError;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates a medication error and, when asked, its one linked incident, then
 * tells the people Medication Settings › Alerts & access chooses — the same
 * writes and hooks whether a worker reports the error from the error form or
 * the recording dialog raises it ("More than ordered was given", P01).
 *
 * Callers authorise first and run this inside their governing transaction.
 */
final class MedicationErrorReporter
{
    /**
     * @param  array<string, mixed>  $attributes  medication_errors columns (client_id is set here).
     */
    public function report(
        Client $client,
        int $siteId,
        User $reporter,
        array $attributes,
        bool $createIncident,
    ): MedicationError {
        $attributes['client_id'] = $client->id;
        $attributes['reported_by'] = $reporter->id;
        $attributes['reported_at'] = now();
        $attributes['status'] = 'reported';

        $incident = null;
        if ($createIncident) {
            $incident = ClientIncident::withoutEvents(
                fn () => ClientIncident::create([
                    'client_id' => $client->id,
                    'site_id' => $siteId,
                    'title' => 'Medication Error: '.str_replace('_', ' ', (string) $attributes['error_type']),
                    'description' => $attributes['description'],
                    'immediate_action_taken' => $attributes['immediate_action'] ?? null,
                    'occurred_at' => now(),
                    'reported_by' => $reporter->id,
                    'severity' => match ($attributes['severity']) {
                        'critical' => 'critical',
                        'major' => 'high',
                        'moderate' => 'medium',
                        default => 'low',
                    },
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'type' => 'medication_error',
                ]),
            );
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $reporter);
        }

        $attributes['client_incident_id'] = $incident?->id;
        $error = MedicationError::create($attributes);

        app(MedicationSignalService::class)->emitError($error);
        // Every reported error tells whoever Medication Settings › Alerts &
        // access chooses (P11 B2); Control Room still gets major and critical.
        app(MedicationAlertSources::class)->error($error);

        if ($incident !== null) {
            app(TimelineEmitter::class)->project($incident->fresh());

            $incidentId = (int) $incident->id;
            DB::afterCommit(function () use ($incidentId): void {
                try {
                    RegisterIncidentGovernanceEscalationJob::dispatch($incidentId);
                } catch (Throwable $exception) {
                    Log::error('Medication incident governance dispatch failed', [
                        'client_incident_id' => $incidentId,
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }

        return $error;
    }
}
