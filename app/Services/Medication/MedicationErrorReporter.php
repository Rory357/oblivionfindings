<?php

namespace App\Services\Medication;

use App\Jobs\Governance\RegisterIncidentGovernanceEscalationJob;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\MedicationError;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\NotificationService;
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
            'source' => $error->report_source, 'error_type' => $error->error_type,
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
        // EA-112: withoutEvents skips the model's own creation audit row; write it here.
        AuditLogger::log('clientincident.create', $incident, [
            'fields' => array_keys($incident->getAttributes()),
            'medication_error_id' => (int) $error->id,
        ]);
        $error->forceFill(['client_incident_id' => $incident->id])->save();
        $signals = app(MedicationSignalService::class);
        $existingAlert = $signals->attachExistingErrorSignalToIncident($error);
        $journeys = app(IncidentJourneyService::class);
        $journey = $existingAlert === null ? $journeys->ensureForSubmittedIncident($incident, $actor)
            : $journeys->attachAlertToIncident($incident, $existingAlert, $actor);
        $signals->emitError($error);
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
        $actorId = (int) $actor->id;
        DB::afterCommit(fn () => $this->notifySubmitted($incidentId, $actorId));

        return $journey->incident;
    }

    /**
     * EA-112: the notices an incident filed through Incidents sends
     * (IncidentController::notifyIncidentSubmitted) — "submitted", and "high
     * severity" for a high or critical one. A notice never undoes the report.
     */
    private function notifySubmitted(int $incidentId, int $actorId): void
    {
        try {
            $incident = ClientIncident::query()->with('client:id,first_name,last_name')->find($incidentId);
            $client = $incident?->client;
            if (! $client instanceof Client) {
                return;
            }
            $actor = User::query()->find($actorId);
            $body = "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}";
            $notices = app(NotificationService::class);
            $notices->notifyCrud($actor, 'submitted', 'incident', $incident, $client, [
                'event_key' => 'incidents.submitted',
                'severity' => $incident->severity,
                'title' => 'Incident submitted for review',
                'body' => $body,
                'url' => url("/incidents/{$incident->id}"),
                'include_entity_user' => false,
            ]);
            if (! in_array($incident->severity, ['high', 'critical'], true)) {
                return;
            }
            $notices->notifyCrud($actor, 'submitted', 'incident', $incident, $client, [
                'event_key' => 'incidents.high_severity_alert',
                'severity' => $incident->severity,
                'title' => 'High severity incident submitted',
                'body' => $body,
                'url' => url("/incidents/{$incident->id}"),
                'include_assigned_workers' => false,
                'include_entity_user' => false,
                'include_managers' => false,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
