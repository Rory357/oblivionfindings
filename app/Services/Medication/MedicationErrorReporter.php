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
use Illuminate\Validation\ValidationException;
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
        $workflow = app(MedicationErrorWorkflow::class);
        $attributes['occurred_at'] ??= now();
        $attributes['workflow_stage'] = 'triage';
        $attributes['report_source'] ??= isset($attributes['client_medication_administration_id']) ? 'more_than_ordered' : 'page';
        $attributes['harm_level'] ??= 'unknown';
        $attributes['triage_due_at'] = $workflow->triageDue();
        $attributes['client_id'] = $client->id;
        $attributes['reported_by'] = $reporter->id;
        $attributes['reported_at'] = now();
        $attributes['status'] = 'reported';

        $error = MedicationError::create($attributes);
        $workflow->append($error, $reporter, 'reported', $error->description, [
            'reached_client' => $error->reached_client, 'harm_level' => $error->harm_level,
            'source' => $error->report_source,
        ]);
        if ($createIncident || $workflow->incidentRequired($error)) {
            $this->ensureIncident($error, $reporter);
        } else {
            app(MedicationSignalService::class)->emitError($error);
        }
        // Every reported error tells whoever Medication Settings › Alerts &
        // access chooses (P11 B2); Control Room still gets major and critical.
        app(MedicationAlertSources::class)->error($error);

        return $error;
    }

    /** Call under the error/client lock. An existing link is never replaced. */
    public function ensureIncident(MedicationError $error, User $actor): ClientIncident
    {
        if ($error->client_incident_id !== null) {
            return ClientIncident::query()->whereKey($error->client_incident_id)
                ->where('client_id', $error->client_id)->where('site_id', $error->client->site_id)
                ->lockForUpdate()->firstOrFail();
        }
        $immediate = trim((string) $error->immediate_action);
        if ($immediate === '') {
            $immediate = trim((string) $error->entries()->where('kind', 'immediate_action')->reorder()->latest('id')->value('text'));
        }
        if (in_array($error->severity, ['major', 'critical'], true) && $immediate === '') {
            throw ValidationException::withMessages(['immediate_action' => 'Record the immediate action actually taken before creating a serious linked incident.']);
        }
        $summary = MedicationErrorSummary::for($error);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::query()->create([
            'client_id' => $error->client_id, 'site_id' => $error->client->site_id,
            'title' => 'Medication error '.$error->reference_number,
            'description' => $summary,
            'immediate_action_taken' => 'Medication error reported. Any recorded immediate action is held in the permitted medication error record.',
            'occurred_at' => $error->occurred_at ?? $error->reported_at,
            'reported_by' => $actor->id, 'severity' => match ($error->severity) {
                'critical' => 'critical', 'major' => 'high', 'moderate' => 'medium', default => 'low',
            },
            'status' => 'submitted', 'submitted_at' => now(), 'type' => 'medication_error',
        ]));
        $error->forceFill(['client_incident_id' => $incident->id])->save();
        $signals = app(MedicationSignalService::class);
        $signals->emitError($error);
        $existingAlert = $signals->attachExistingErrorSignalToIncident($error);
        $journeys = app(IncidentJourneyService::class);
        $journey = $existingAlert === null ? $journeys->ensureForSubmittedIncident($incident, $actor)
            : $journeys->attachAlertToIncident($incident, $existingAlert, $actor);
        app(TimelineEmitter::class)->project($journey->incident);

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

        return $journey->incident;
    }
}
