<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationError;
use App\Models\MedicationErrorEntry;
use App\Models\User;
use App\Services\Incidents\IncidentAlertLifecycleSignalService;
use App\Services\Incidents\IncidentClosureService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\MedicationIncidentIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Commands run within the canonical client/error transaction owned by the controller. */
final class MedicationErrorCommands
{
    public function __construct(private readonly MedicationErrorWorkflow $workflow, private readonly MedicationErrorReporter $reports) {}

    public function report(Request $request, Client $client, User $actor, array $data)
    {
        $fingerprint = hash('sha256', json_encode([
            'client' => (int) $client->id, 'medicine' => isset($data['client_medication_id']) ? (int) $data['client_medication_id'] : null,
            'type' => $data['error_type'], 'occurred' => $data['occurred_at'], 'reach' => $data['reached_client'],
            'harm' => $data['reached_client'] === 'no' ? 'none' : ($data['harm_level'] ?? null),
            'description' => str_replace("\r\n", "\n", trim($data['description'])),
            'immediate' => trim($data['immediate_action'] ?? ''), 'contributing' => trim($data['contributing_factors'] ?? ''),
            'incident' => (bool) ($data['create_incident'] ?? false), 'duplicate' => isset($data['duplicate_id']) ? (int) $data['duplicate_id'] : null,
            'separate_reason' => trim($data['separate_reason'] ?? ''),
        ], JSON_THROW_ON_ERROR));
        $medId = $data['client_medication_id'] ?? null;
        if ($medId !== null) {
            $medicine = ClientMedication::withTrashed()->whereKey($medId)->where('client_id', $client->id)->lockForUpdate()->first();
            abort_unless($medicine, 404);
            abort_if($medicine->controlled_drug && ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY), 404);
        }
        if ($actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $namedControlled = ClientMedication::withTrashed()->where('client_id', $client->id)->where('controlled_drug', true)->get(['id', 'name'])
                ->first(fn ($m) => mb_strlen(trim($m->name)) >= 3 && str_contains(mb_strtolower($data['description'].' '.($data['immediate_action'] ?? '').' '.($data['contributing_factors'] ?? '')), mb_strtolower(trim($m->name))));
            if ($namedControlled && (int) $namedControlled->id !== (int) $medId) {
                throw ValidationException::withMessages(['client_medication_id' => 'Your account names a controlled medicine. Choose it from the chart so its details are protected.']);
            }
        }
        $existing = MedicationError::query()->where('report_token', $data['report_token'])->lockForUpdate()->first();
        if ($existing) {
            abort_unless((int) $existing->client_id === (int) $client->id && (int) $existing->reported_by === (int) $actor->id, 404);
            $this->assertReplay($existing->report_fingerprint, $fingerprint);

            return back()->with('success', 'Report already saved.');
        }
        // A duplicate account also has a durable replay identity, held under the client lock.
        $account = MedicationErrorEntry::query()->where('kind', 'account')->where('data->report_token', $data['report_token'])->first();
        if ($account) {
            abort_unless((int) $account->actor_id === (int) $actor->id && MedicationError::query()->whereKey($account->medication_error_id)->where('client_id', $client->id)->exists(), 404);
            $this->assertReplay($account->data['fingerprint'] ?? null, $fingerprint);

            return back()->with('success', 'Account already saved.');
        }
        $at = $this->workflow->time($data['occurred_at'], 'occurred_at');
        if ($at->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'When it happened cannot be in the future.']);
        }
        $day = $at->copy()->tz('Pacific/Auckland')->startOfDay();
        $duplicate = MedicationError::query()->where('client_id', $client->id)->where('client_medication_id', $medId)->where('status', '!=', 'closed')
            ->whereRaw('COALESCE(occurred_at, reported_at) >= ? AND COALESCE(occurred_at, reported_at) < ?', [$day->copy()->subDay()->utc(), $day->copy()->addDays(2)->utc()])
            ->lockForUpdate()->latest('id')->first();
        if ($duplicate && isset($data['duplicate_id'])) {
            abort_unless((int) $duplicate->id === (int) $data['duplicate_id'], 404);
            $this->workflow->append($duplicate, $actor, 'account', $data['description'], [
                'immediate_action' => $data['immediate_action'] ?? null, 'contributing_factors' => $data['contributing_factors'] ?? null,
                'report_token' => $data['report_token'], 'fingerprint' => $fingerprint,
            ]);

            return back()->with('success', 'Your account was added to '.$duplicate->reference_number.'.');
        }
        if ($duplicate && trim($data['separate_reason'] ?? '') === '') {
            return back()->withErrors(['duplicate' => MedicationErrorSummary::for($duplicate), 'duplicate_id' => (string) $duplicate->id]);
        }
        $attributes = collect($data)->except(['duplicate_id', 'separate_reason', 'create_incident'])->all();
        $attributes['occurred_at'] = $at;
        $attributes['harm_level'] = $data['reached_client'] === 'no' ? 'none' : $data['harm_level'];
        $attributes['severity'] = $this->workflow->severity($data['reached_client'], $attributes['harm_level']);
        $attributes['report_source'] = 'page';
        $attributes['report_fingerprint'] = $fingerprint;
        $error = $this->reports->report($client, (int) $client->site_id, $actor, $attributes, $request->boolean('create_incident'));
        if ($duplicate) {
            $this->workflow->append($error, $actor, 'separate_report', $data['separate_reason'], ['related_error_id' => $duplicate->id]);
        }

        return back()->with('success', 'Medication error reported.');
    }

    public function run(string $command, Request $request, MedicationError $error, Client $client, User $actor, ?int $actionId = null)
    {
        if (! in_array($command, ['reopen', 'close'], true)) {
            $this->workflow->assertOpen($error);
        }
        switch ($command) {
            case 'account':
            case 'note':
                $data = $request->validate(['text' => 'required|string|max:5000']);
                $this->workflow->append($error, $actor, $command, $data['text']);
                break;
            case 'triage':
                $data = $request->validate([
                    'owner_id' => 'required|integer|min:1', 'investigation_due_at' => 'required|date_format:Y-m-d\\TH:i',
                    'reached_client' => 'required|in:no,yes,unknown', 'harm_level' => ['required', Rule::in($this->workflow::HARMS)], 'review_notes' => 'required|string|max:5000',
                ]);
                $this->assertOwner($client, (int) $data['owner_id'], true, $error);
                $error->forceFill([
                    'owner_id' => $data['owner_id'], 'investigation_due_at' => $this->workflow->time($data['investigation_due_at'], 'investigation_due_at'),
                    'reached_client' => $data['reached_client'], 'harm_level' => $data['reached_client'] === 'no' ? 'none' : $data['harm_level'],
                    'severity' => $this->workflow->severity($data['reached_client'], $data['harm_level']),
                    'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'workflow_stage' => 'investigating', 'status' => 'investigating',
                ])->save();
                $this->workflow->append($error, $actor, 'triaged', $data['review_notes'], [
                    'owner_id' => (int) $data['owner_id'], 'reached_client' => $error->reached_client,
                    'harm_level' => $error->harm_level, 'investigation_due_at' => $error->investigation_due_at->toIso8601String(),
                ]);
                if (trim((string) $request->input('immediate_action')) !== '') {
                    $immediate = $request->validate(['immediate_action' => 'required|string|max:5000']);
                    $this->workflow->append($error, $actor, 'immediate_action', $immediate['immediate_action']);
                }
                if ($this->workflow->incidentRequired($error)) {
                    $this->reports->ensureIncident($error, $actor);
                }
                app(MedicationAlertSources::class)->errorResolved($error, 'The error was triaged.');
                break;
            case 'resolve':
                $this->assertTriaged($error);
                $data = $request->validate(['outcome' => 'required|string|max:5000', 'preventive_actions' => 'required|string|max:5000']);
                $error->forceFill(['workflow_stage' => 'actions', 'status' => 'resolved'])->save();
                $this->workflow->append($error, $actor, 'investigation_completed', $data['outcome'], ['preventive_actions' => $data['preventive_actions']]);
                break;
            case 'action':
                $this->assertTriaged($error);
                $data = $request->validate(['description' => 'required|string|max:5000', 'owner_id' => 'required|integer|min:1', 'due_at' => 'required|date_format:Y-m-d\\TH:i']);
                $this->assertOwner($client, (int) $data['owner_id'], true, $error);
                $action = $error->actions()->create([
                    'description' => $data['description'], 'owner_id' => $data['owner_id'], 'due_at' => $this->workflow->time($data['due_at'], 'due_at'), 'created_by' => $actor->id, 'created_at' => now(),
                ]);
                $error->forceFill(['workflow_stage' => 'actions', 'status' => 'resolved'])->save();
                $this->workflow->append($error, $actor, 'action_added', null, ['action_id' => $action->id]);
                break;
            case 'complete':
                $action = $error->actions()->whereKey($actionId)->lockForUpdate()->first();
                abort_unless($action, 404);
                if ($action->completed_at === null) {
                    $data = $request->validate(['completion_note' => 'required|string|max:5000']);
                    $action->forceFill(['completed_at' => now(), 'completed_by' => $actor->id, 'completion_note' => $data['completion_note']])->save();
                    $this->workflow->append($error, $actor, 'action_completed', null, ['action_id' => $action->id]);
                }
                break;
            case 'disclosure':
                $data = $request->validate([
                    'state' => 'required|in:told,not_yet', 'who' => 'required_if:state,told|array', 'who.*' => 'in:person,whanau',
                    'whanau_name' => 'nullable|string|max:200', 'by' => 'required_if:state,told|nullable|string|max:200',
                    'at' => 'required_if:state,told|nullable|date_format:Y-m-d\\TH:i', 'how' => 'required_if:state,told|nullable|string|max:1000',
                    'reason' => 'required_if:state,not_yet|nullable|string|max:1000',
                ]);
                if ($data['state'] === 'told') {
                    if (empty($data['who'])) {
                        throw ValidationException::withMessages(['who' => 'Record who was told.']);
                    }
                    if (in_array('whanau', $data['who'], true) && trim($data['whanau_name'] ?? '') === '') {
                        throw ValidationException::withMessages(['whanau_name' => 'Record the whānau member’s name.']);
                    }
                    $at = $this->workflow->time($data['at'], 'at');
                    if ($at->isFuture()) {
                        throw ValidationException::withMessages(['at' => 'When they were told cannot be in the future.']);
                    }
                    $data['at'] = $at->toIso8601String();
                }
                $this->workflow->append($error, $actor, 'disclosure', null, $data);
                $error->forceFill(['open_disclosure' => $data['state'] === 'told' ? 'done' : 'pending'])->save();
                break;
            case 'incident':
                $data = $request->validate(['immediate_action' => 'nullable|string|max:5000']);
                if (trim((string) ($data['immediate_action'] ?? '')) !== '') {
                    $this->workflow->append($error, $actor, 'immediate_action', $data['immediate_action']);
                }
                $incident = $this->reports->ensureIncident($error, $actor);
                $this->workflow->append($error, $actor, 'incident_linked', null, ['incident_id' => $incident->id]);
                break;
            case 'close':
                if ($error->stage() === 'closed') {
                    return back()->with('success', 'Error already closed.');
                }
                $data = $request->validate(['close_note' => 'required|string|max:5000']);
                if ($blockers = $this->workflow->closeBlockers($error, $actor)) {
                    throw ValidationException::withMessages(['status' => implode(' ', $blockers)]);
                }
                $error->forceFill(['workflow_stage' => 'closed', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id])->save();
                $this->workflow->append($error, $actor, 'closed', $data['close_note']);
                app(MedicationIncidentIntegrationService::class)->resolveMedicationError($error, 'Medication error closed.', $actor->id);
                $incident = $error->incident;
                $message = 'Medication error closed.';
                if ($incident && $incident->status !== 'closed') {
                    $message .= ' Linked incident is ready for Incidents review and closure.';
                    if (Gate::forUser($actor)->allows('close', $incident)) {
                        [$incident, $problem, $outboxId] = app(IncidentClosureService::class)->close($incident, $actor, ['closed_outcome' => 'Medication error closed', 'closed_notes' => MedicationErrorSummary::for($error)], ['healthSafety.viewAllSites', 'reports.viewAny']);
                        if ($outboxId !== null) {
                            DB::afterCommit(fn () => app(IncidentAlertLifecycleSignalService::class)->dispatch($outboxId));
                            $message = 'Medication error and linked incident closed.';
                        } elseif ($problem) {
                            $message .= ' '.$problem;
                        }
                    }
                }

                return back()->with('success', $message);
            case 'reopen':
                abort_unless($error->stage() === 'closed', 409);
                if ((int) $error->reported_by === (int) $actor->id) {
                    throw ValidationException::withMessages(['status' => 'Someone other than the reporter must reopen this error.']);
                }
                $data = $request->validate(['reason' => 'required|string|max:1000']);
                $error->forceFill(['workflow_stage' => 'investigating', 'status' => 'investigating', 'closed_at' => null, 'closed_by' => null])->save();
                $this->workflow->append($error, $actor, 'reopened', $data['reason']);
                app(MedicationAlertSources::class)->error($error);
                break;
            default:
                abort(404);
        }

        return back()->with('success', 'Saved to the medication error record.');
    }

    private function assertTriaged(MedicationError $error): void
    {
        if ($error->stage() === 'triage' || $error->owner_id === null) {
            throw ValidationException::withMessages(['status' => 'Triage this error first.']);
        }
    }

    private function assertReplay(?string $original, string $submitted): void
    {
        if ($original === null || ! hash_equals($original, $submitted)) {
            throw ValidationException::withMessages(['report_token' => 'This request was already saved with different details. The original is kept. Open that report and add a new account, or start a separate report.'])->status(409);
        }
    }

    private function assertOwner(Client $client, int $id, bool $manager, MedicationError $error): void
    {
        $candidate = User::query()->find($id);
        $visible = app(MedicationGovernanceScopeService::class)->staffPicker([(int) $client->site_id])->contains('id', $id);
        if (! $candidate || ! $visible || ($manager && ! $candidate->canDo(MedicationErrorWorkflow::MANAGE)) || ($error->medication?->controlled_drug && ! $candidate->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY))) {
            throw ValidationException::withMessages(['owner_id' => 'Choose a permitted owner at this house.']);
        }
    }
}
