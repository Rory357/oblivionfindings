<?php

namespace App\Services\HealthSafety;

use App\Http\Requests\HealthSafety\StoreFirstAidRecordRequest;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidCommandReceipt;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Guarded factual source commands; accepted writes never imply narrative-read authority. */
class FirstAidService
{
    public function __construct(private readonly FirstAidAccessService $access, private readonly FirstAidDeliveryService $delivery) {}

    public function create(User $actor, array $data): FirstAidRecord
    {
        return $this->transaction(fn () => $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($data) {
            abort_unless($current->canDo('hazards.create') || $current->canDo('hazards.manage'), 403);
            $values = $this->planning($data, true);
            Validator::make($data, ['idempotency_key' => ['required', 'uuid']])->validate();
            $fingerprint = $this->fingerprint(['create', $current->id, $values]);
            $existing = FirstAidRecord::withTrashed()->where('creation_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                $this->access->assertCommand($existing, $current, $reads, $sites, 'create');

                return $this->replay($existing, $current, $data['idempotency_key'], $fingerprint, $reads);
            }
            $row = new FirstAidRecord;
            $row->forceFill([...$values, 'lock_version' => 1, 'creation_key' => $data['idempotency_key'], 'creation_fingerprint' => $fingerprint, 'created_by' => $current->id, 'updated_by' => $current->id]);
            $this->access->assertSource($row, $current, $reads, $sites);
            $this->validateChoices($row, $current, $reads, $values, null);
            $this->notFuture($row->treatment_date, 'treatment_date');
            try {
                $row->reference_number = app(ReferenceNumberGenerator::class)->next(FirstAidRecord::REFERENCE_PREFIX);
            } catch (Throwable $referenceFailure) {
                report($referenceFailure);
            }
            $row->saveQuietly();
            $row->refresh();
            $this->recordConfirmation($row, $current, 'create', 'created', $data['idempotency_key'], $fingerprint);
            $this->delivery->stageReview($row, $current);

            return $row;
        }));
    }

    public function update(FirstAidRecord $record, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'update', 'updated', $data, fn ($values) => $this->planning($values, false), function ($row, $current, $reads, $values, $sites) {
            $old = clone $row;
            if ($row->related_incident_id || $reads->query(HsEvent::query())->whereIn('source_type', array_unique([FirstAidRecord::class, $row->getMorphClass()]))->where('source_id', $row->id)->exists()
                || $reads->query(FirstAidFollowup::query())->where('first_aid_record_id', $row->id)->exists()
                || $reads->query(FirstAidAttachment::withTrashed())->where('first_aid_record_id', $row->id)->exists()) {
                foreach (['site_id', 'client_id', 'treated_person_id', 'treated_person_type'] as $field) {
                    if (array_key_exists($field, $values) && $values[$field] != $row->{$field}) {
                        throw ValidationException::withMessages([$field => 'This treatment has retained follow-up, evidence, review or incident history. Changing its subject or Site requires an explicit source-safe correction; existing medical history cannot be transferred.']);
                    }
                }
            }
            $row->forceFill($values);
            if ($row->treated_person_type !== 'client') {
                $row->client_id = null;
            }
            if ($row->treated_person_type !== 'staff') {
                $row->treated_person_id = null;
            }
            $this->access->assertSource($row, $current, $reads, $sites);
            $this->validateChoices($row, $current, $reads, $values, $old);
            if (array_key_exists('treatment_date', $values)) {
                $this->notFuture($row->treatment_date, 'treatment_date');
            }

            return [];
        });
    }

    public function archive(FirstAidRecord $record, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'archive', 'archived', $data, fn () => [], function ($row, $current, $reads) {
            if ($this->access->hasOpenFollowups($row, $reads)) {
                throw ValidationException::withMessages(['followups' => 'Complete or record why each outstanding follow-up is not required before archiving this treatment.']);
            }
            $row->deleted_at = now();

            return [];
        });
    }

    public function addFollowup(FirstAidRecord $record, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'add_followup', 'followup_added', $data, fn ($values) => $this->followupValues($values), function ($row, $current, $reads, $values) {
            $this->assignee($values['assigned_to_user_id'] ?? null, $row, $reads);
            $followup = new FirstAidFollowup;
            $followup->forceFill([...$values, 'first_aid_record_id' => $row->id, 'created_by' => $current->id, 'assignment_revision' => 1])->saveQuietly();
            $this->delivery->stageAssignment($row, $followup, $current);

            return ['followup_id' => $followup->id, 'followup_snapshot' => $this->followupSnapshot($followup)];
        });
    }

    public function updateFollowup(FirstAidRecord $record, FirstAidFollowup $followup, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'update_followup', 'followup_updated', [...$data, 'followup_id' => $followup->id], fn ($values) => [...$this->followupValues($values), 'followup_id' => (int) $followup->id], function ($row, $current, $reads, $values) use ($followup) {
            $child = $this->lockedFollowup($row, $followup);
            if ($child->completed_at !== null) {
                throw ValidationException::withMessages(['followup' => 'A recorded completion must be retained. Add any additional work as a new follow-up.']);
            }
            $previous = $child->assigned_to_user_id;
            $this->assignee($values['assigned_to_user_id'] ?? null, $row, $reads);
            $child->forceFill(Arr::except($values, ['followup_id']));
            if ($previous !== $child->assigned_to_user_id) {
                $child->assignment_revision = (int) $child->assignment_revision + 1;
            }
            $child->saveQuietly();
            if ($previous !== $child->assigned_to_user_id) {
                $this->delivery->stageAssignment($row, $child, $current);
            }

            return ['followup_id' => $child->id, 'followup_snapshot' => $this->followupSnapshot($child)];
        });
    }

    public function completeFollowup(FirstAidRecord $record, FirstAidFollowup $followup, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'complete_followup', 'followup_completed', [...$data, 'followup_id' => $followup->id], fn ($values) => $this->completionValues($values), function ($row, $current, $reads, $values) use ($followup) {
            $child = $this->lockedFollowup($row, $followup);
            abort_unless($current->canDo('hazards.manage') || ($current->canDo('hazards.view') && (int) $child->assigned_to_user_id === (int) $current->id), 403);
            if ($child->completed_at !== null) {
                throw ValidationException::withMessages(['followup' => 'This follow-up already has a recorded completion.']);
            }
            foreach ($values['completion_evidence_ids'] as $id) {
                $file = $reads->query(FirstAidAttachment::query())->where('first_aid_record_id', $row->id)->findOrFail($id);
                if ($file->disk !== 'private' || ! $file->path || ! Storage::disk('private')->exists($file->path)) {
                    throw ValidationException::withMessages(['completion_evidence_ids' => 'Select available evidence from this treatment.']);
                }
            }
            if ($values['completion_outcome'] !== null) {
                $this->notFuture(Carbon::parse($values['completion_outcome']['occurred_at']), 'completion_outcome.occurred_at');
            }
            $child->forceFill([...Arr::only($values, ['completion_notes', 'completion_outcome', 'completion_evidence_ids']), 'completed_at' => now(), 'completed_by_user_id' => $current->id])->saveQuietly();

            return ['followup_id' => $child->id, 'note' => $values['completion_notes'], 'followup_snapshot' => $this->followupSnapshot($child)];
        });
    }

    public function linkIncident(FirstAidRecord $record, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'link_incident', 'incident_linked', $data, fn ($values) => Validator::make($values, [
            'related_incident_id' => ['nullable', 'integer', 'min:1'], 'immediate_action_taken' => ['nullable', 'string', 'max:5000'],
        ])->validate(), function ($row, $current, $reads, $values) {
            if ($row->treated_person_type !== 'client' || ! $row->client_id) {
                throw ValidationException::withMessages(['related_incident_id' => 'This source has no matching Client incident. Keep the recorded staff, visitor or contractor subject.']);
            }
            if ($row->related_incident_id) {
                throw ValidationException::withMessages(['related_incident_id' => 'This treatment already has an incident link.']);
            }
            if (! empty($values['related_incident_id'])) {
                $incident = $reads->query(ClientIncident::query())->findOrFail($values['related_incident_id']);
                $this->access->assertIncident($row, $incident, $current, $reads, true);
            } else {
                abort_unless($current->canDo('incidents.create'), 403);
                if (($row->ambulance_called || $row->treatment_outcome === 'sent_to_hospital') && blank($values['immediate_action_taken'] ?? null)) {
                    throw ValidationException::withMessages(['immediate_action_taken' => 'Record the actual immediate action for this significant treatment.']);
                }
                $incident = ClientIncident::withoutEvents(fn () => ClientIncident::create([
                    'client_id' => $row->client_id, 'site_id' => $row->site_id, 'title' => 'First aid: '.str_replace('_', ' ', $row->injury_illness_type),
                    'description' => $row->injury_illness_description, 'occurred_at' => $row->treatment_date, 'reported_by' => $current->id,
                    'severity' => ($row->ambulance_called || $row->treatment_outcome === 'sent_to_hospital') ? 'high' : 'medium',
                    'status' => 'submitted', 'submitted_at' => now(), 'type' => 'first_aid', 'immediate_action_taken' => $values['immediate_action_taken'] ?? null,
                ]));
                $incident = app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $current)->incident;
            }
            $row->related_incident_id = $incident->id;
            $row->incident_reported = true;
            $event = $reads->query(HsEvent::query())->whereIn('source_type', array_unique([FirstAidRecord::class, $row->getMorphClass()]))->where('source_id', $row->id)->where('event_category', HsEvent::CATEGORY_INJURY)->first();
            if ($event) {
                $request = clone request();
                $request->setUserResolver(fn () => $current);
                AuditLogger::logOrFail('healthSafety.event.firstAidSupersessionRecorded', $event, ['first_aid_record_id' => $row->id, 'linked_incident_id' => $incident->id, 'closure_required' => $event->status !== HsEvent::STATUS_CLOSED], $request);
            }

            return ['incident_id' => $incident->id];
        });
    }

    public function addEvidence(FirstAidRecord $record, User $actor, array $data, UploadedFile $file): FirstAidRecord
    {
        $paths = [];
        $values = [...Arr::except($data, ['file']), 'file_manifest' => ['sha256' => hash_file('sha256', $file->getRealPath()), 'name' => basename($file->getClientOriginalName()), 'size' => $file->getSize(), 'mime' => $file->getMimeType()]];
        try {
            $result = $this->command($record, $actor, 'add_evidence', 'evidence_added', $values, function ($values) use ($file) {
                Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx']])->validate();

                return [...Validator::make($values, ['kind' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:1000'], 'alt_text' => ['nullable', 'string', 'max:255']])->validate(), 'file_manifest' => $values['file_manifest']];
            }, function ($row, $current, $reads, $values) use ($file, &$paths) {
                $path = $file->store('first_aid_attachments', 'private');
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Private evidence storage did not confirm a saved file.');
                }
                $paths[] = $path;
                $attachment = FirstAidAttachment::create([...Arr::only($values, ['kind', 'notes', 'alt_text']), 'first_aid_record_id' => $row->id, 'uploaded_by' => $current->id, 'disk' => 'private', 'path' => $path,
                    'original_name' => basename($file->getClientOriginalName()), 'mime' => $file->getMimeType(), 'size' => $file->getSize()]);

                return ['attachment_id' => $attachment->id, 'names' => [$attachment->original_name]];
            });
        } catch (Throwable $error) {
            $this->cleanupEvidence($record->id, $paths);
            throw $error;
        }
        $this->cleanupEvidence($record->id, $paths);

        return $result;
    }

    public function removeEvidence(FirstAidRecord $record, FirstAidAttachment $attachment, User $actor, array $data): FirstAidRecord
    {
        return $this->command($record, $actor, 'remove_evidence', 'evidence_removed', [...$data, 'attachment_id' => $attachment->id], fn () => ['attachment_id' => (int) $attachment->id], function ($row) use ($attachment) {
            $file = FirstAidAttachment::query()->where('first_aid_record_id', $row->id)->whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            if (FirstAidFollowup::where('first_aid_record_id', $row->id)->whereNotNull('completed_at')->whereJsonContains('completion_evidence_ids', (int) $file->id)->exists()) {
                throw ValidationException::withMessages(['attachment' => 'This evidence supports a recorded follow-up outcome and must be retained.']);
            }
            $file->delete();

            return ['attachment_id' => $file->id, 'names' => [basename($file->original_name)]];
        });
    }

    private function command(FirstAidRecord $record, User $actor, string $command, string $semantic, array $data, callable $normalize, callable $operation): FirstAidRecord
    {
        return $this->transaction(function () use ($record, $actor, $command, $semantic, $data, $normalize, $operation) {
            $row = FirstAidRecord::withTrashed()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $command, $semantic, $data, $normalize, $operation) {
                $this->access->assertCommand($row, $current, $reads, $sites, $command);
                Validator::make($data, ['expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
                $values = $normalize($data);
                if ($command === 'complete_followup') {
                    $child = $reads->query(FirstAidFollowup::query())->where('first_aid_record_id', $row->id)->findOrFail($values['followup_id']);
                    abort_unless($current->canDo('hazards.manage') || ($current->canDo('hazards.view') && (int) $child->assigned_to_user_id === (int) $current->id), 403);
                }
                $fingerprint = $this->fingerprint([$row->id, $current->id, $command, (int) $data['expected_version'], $values]);
                if ($reads->query(FirstAidCommandReceipt::query())->where('idempotency_key', $data['idempotency_key'])->exists()) {
                    return $this->replay($row, $current, $data['idempotency_key'], $fingerprint, $reads);
                }
                if ($row->trashed()) {
                    throw ValidationException::withMessages(['record' => 'This treatment is archived. Its recorded history remains available to authorized readers.']);
                }
                if ((int) $row->lock_version !== (int) $data['expected_version']) {
                    throw ValidationException::withMessages(['expected_version' => 'This treatment changed. Review the current record before retrying.']);
                }
                $meta = $operation($row, $current, $reads, $values, $sites);
                $this->access->assertSource($row, $current, $reads, $sites);
                $row->forceFill(['lock_version' => (int) $row->lock_version + 1, 'updated_by' => $current->id])->saveQuietly();
                $row->refresh();
                $this->recordConfirmation($row, $current, $command, $semantic, $data['idempotency_key'], $fingerprint, $meta);
                if ($command === 'update') {
                    $this->delivery->stageReview($row, $current);
                }

                return $row;
            });
        });
    }

    private function recordConfirmation(FirstAidRecord $row, User $actor, string $command, string $semantic, string $key, string $fingerprint, array $meta = []): void
    {
        $request = clone request();
        $request->setUserResolver(fn () => $actor);
        $snapshot = $row->only(['site_id', 'treated_person_id', 'client_id', 'treated_person_name', 'treated_person_type', 'injury_illness_type', 'injury_illness_description', 'body_part', 'treatment_given', 'treatment_outcome', 'ambulance_called', 'first_aider_id', 'first_aider_notes', 'incident_reported', 'related_incident_id']);
        $snapshot['treatment_date'] = $row->treatment_date?->toIso8601String();
        $snapshot['deleted_at'] = $row->deleted_at?->toIso8601String();
        AuditLogger::logOrFail('first_aid.'.$semantic, $row, ['actor_id' => $actor->id, 'idempotency_key' => $key, 'snapshot' => $snapshot, ...$meta], $request);
        $auditId = AuditLog::where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', 'first_aid.'.$semantic)->where('meta->idempotency_key', $key)->latest('id')->firstOrFail()->id;
        FirstAidCommandReceipt::create(['first_aid_record_id' => $row->id, 'user_id' => $actor->id, 'command' => $command, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'audit_log_id' => $auditId, 'confirmed_result' => ['record' => $row->getAttributes(), 'result' => $meta]]);
    }

    private function replay(FirstAidRecord $row, User $actor, string $key, string $fingerprint, CurrentAuthorizationReads $reads): FirstAidRecord
    {
        $receipt = $reads->query(FirstAidCommandReceipt::query())->where('idempotency_key', $key)->first();
        if (! $receipt || $receipt->fingerprint !== $fingerprint || (int) $receipt->first_aid_record_id !== (int) $row->id || (int) $receipt->user_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'This command key belongs to another actor, treatment or payload.']);
        }
        $confirmed = clone $row;
        $confirmed->setRawAttributes($receipt->confirmed_result['record'], true);

        return $confirmed;
    }

    public function confirmationForViewer(User $viewer, mixed $candidate): ?array
    {
        if (! is_array($candidate) || ! is_int($candidate['id'] ?? null) || ! is_int($candidate['lock_version'] ?? null) || ! Str::isUuid((string) ($candidate['idempotency_key'] ?? ''))) {
            return null;
        }
        try {
            return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($candidate) {
                $row = $reads->query(FirstAidRecord::withTrashed())->find($candidate['id']);
                $receipt = $reads->query(FirstAidCommandReceipt::query())->where('idempotency_key', $candidate['idempotency_key'])->where('user_id', $current->id)->where('first_aid_record_id', $candidate['id'])->first();
                if (! $row || ! $receipt || (int) data_get($receipt->confirmed_result, 'record.lock_version') !== $candidate['lock_version']) {
                    return null;
                }
                $this->access->assertCommand($row, $current, $reads, $sites, $receipt->command);
                if ($receipt->command === 'complete_followup') {
                    $child = $reads->query(FirstAidFollowup::query())->where('first_aid_record_id', $row->id)->find(data_get($receipt->confirmed_result, 'result.followup_id'));
                    if (! $child || (! $current->canDo('hazards.manage') && (! $current->canDo('hazards.view') || (int) $child->assigned_to_user_id !== (int) $current->id))) {
                        return null;
                    }
                }

                return ['id' => (int) $row->id, 'lock_version' => $candidate['lock_version'], 'idempotency_key' => $receipt->idempotency_key, 'command' => $receipt->command, 'removed' => $receipt->command === 'archive'];
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return null;
            }
            throw $error;
        }
    }

    private function planning(array $data, bool $create): array
    {
        $rules = (new StoreFirstAidRecordRequest)->rules();
        foreach ($rules as $field => &$rule) {
            $rule = array_values(array_filter($rule, fn ($value) => ! str_starts_with($value, 'exists:')));
            if (in_array($field, ['site_id', 'treated_person_id', 'client_id', 'first_aider_id', 'related_incident_id'], true)) {
                $rule = [...$rule, 'integer', 'min:1'];
            }
            if (! $create) {
                $rule = array_map(fn ($value) => $value === 'required' ? 'sometimes' : $value, $rule);
            }
        }
        unset($rule);
        $rules['treatment_date'][] = $this->offsetRule();
        $values = Validator::make($data, $rules)->validate();
        foreach (['site_id', 'treated_person_id', 'client_id', 'first_aider_id', 'related_incident_id'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = (int) $values[$field];
            }
        }
        foreach (['ambulance_called', 'incident_reported'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = (bool) $values[$field];
            }
        }
        if (isset($values['treatment_date'])) {
            $values['treatment_date'] = Carbon::parse($values['treatment_date'])->utc()->toDateTimeString();
        }
        if (isset($values['treated_person_type']) && $values['treated_person_type'] !== 'client') {
            $values['client_id'] = null;
        }
        if (isset($values['treated_person_type']) && $values['treated_person_type'] !== 'staff') {
            $values['treated_person_id'] = null;
        }

        return $values;
    }

    private function validateChoices(FirstAidRecord $row, User $actor, CurrentAuthorizationReads $reads, array $values, ?FirstAidRecord $old): void
    {
        if ($old === null || (isset($values['first_aider_id']) && $old->first_aider_id !== $row->first_aider_id)) {
            $this->access->eligibleStaff((int) $row->first_aider_id, (int) $row->site_id, $reads, true);
        }
        if ($row->treated_person_type === 'staff' && $row->treated_person_id && ($old === null || $old->treated_person_id !== $row->treated_person_id || $old->site_id !== $row->site_id)) {
            $this->access->eligibleStaff((int) $row->treated_person_id, (int) $row->site_id, $reads);
        }
        if ($row->related_incident_id && ($old === null || $old->related_incident_id !== $row->related_incident_id)) {
            abort_unless($actor->canDo('hazards.manage'), 403);
            $incident = $reads->query(ClientIncident::query())->findOrFail($row->related_incident_id);
            $this->access->assertIncident($row, $incident, $actor, $reads, true);
        }
    }

    private function followupValues(array $data): array
    {
        $values = Validator::make($data, ['notes' => ['required', 'string', 'max:2000'], 'assigned_to_user_id' => ['nullable', 'integer', 'min:1'], 'due_at' => ['nullable', 'date', $this->offsetRule()]])->validate();
        $values['assigned_to_user_id'] = isset($values['assigned_to_user_id']) ? (int) $values['assigned_to_user_id'] : null;
        $values['due_at'] = isset($values['due_at']) ? Carbon::parse($values['due_at'])->utc()->toDateTimeString() : null;

        return $values;
    }

    private function completionValues(array $data): array
    {
        $values = Validator::make($data, [
            'followup_id' => ['required', 'integer', 'min:1'], 'completion_notes' => ['required', 'string', 'max:2000'],
            'completion_outcome' => ['nullable', 'array:kind,result,occurred_at,note'],
            'completion_outcome.kind' => ['required_with:completion_outcome', Rule::in(['action', 'contact', 'claim_followup', 'observation'])],
            'completion_outcome.result' => ['required_with:completion_outcome', Rule::in(['recorded', 'contacted', 'attempted', 'unable_to_contact', 'not_required', 'other'])],
            'completion_outcome.occurred_at' => ['required_with:completion_outcome', 'date', $this->offsetRule()],
            'completion_outcome.note' => ['nullable', 'string', 'max:2000'], 'completion_evidence_ids' => ['nullable', 'array'],
            'completion_evidence_ids.*' => ['integer', 'min:1', 'distinct'],
        ])->validate();
        $values['followup_id'] = (int) $values['followup_id'];
        $values['completion_outcome'] ??= null;
        if ($values['completion_outcome'] !== null) {
            $values['completion_outcome']['occurred_at'] = Carbon::parse($values['completion_outcome']['occurred_at'])->utc()->toISOString();
        }
        $values['completion_evidence_ids'] = array_map('intval', $values['completion_evidence_ids'] ?? []);
        sort($values['completion_evidence_ids']);

        return $values;
    }

    private function assignee(?int $id, FirstAidRecord $row, CurrentAuthorizationReads $reads): void
    {
        if ($id !== null) {
            $candidate = $this->access->eligibleStaff($id, (int) $row->site_id, $reads, false, true);
            $this->access->withCurrentViewer($candidate, function ($current, $candidateReads, $sites) use ($row): void {
                abort_unless($current->canDo('hazards.view'), 403);
                $this->access->assertSource($row, $current, $candidateReads, $sites);
            });
        }
    }

    private function lockedFollowup(FirstAidRecord $row, FirstAidFollowup $child): FirstAidFollowup
    {
        return FirstAidFollowup::where('first_aid_record_id', $row->id)->whereKey($child->id)->lockForUpdate()->firstOrFail();
    }

    private function followupSnapshot(FirstAidFollowup $child): array
    {
        return [...$child->only(['id', 'notes', 'assigned_to_user_id', 'completed_by_user_id', 'completion_notes', 'completion_outcome', 'completion_evidence_ids']),
            'due_at' => $child->due_at?->toIso8601String(), 'completed_at' => $child->completed_at?->toIso8601String()];
    }

    private function cleanupEvidence(int $recordId, array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $kept = FirstAidAttachment::withTrashed()->where('first_aid_record_id', $recordId)->whereIn('path', $paths)->pluck('path')->all();
        foreach (array_diff($paths, $kept) as $path) {
            Storage::disk('private')->delete($path);
        }
    }

    private function offsetRule(): \Closure
    {
        return function ($attribute, $value, $fail): void {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
                $fail('Use a valid instant with an explicit timezone offset.');
            }
        };
    }

    private function notFuture(?Carbon $instant, string $field): void
    {
        if (! $instant || $instant->gt(now())) {
            throw ValidationException::withMessages([$field => 'Record the actual time; it cannot be in the future.']);
        }
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($data), JSON_THROW_ON_ERROR));
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_version' => 'The treatment or its authority changed concurrently. Review the current record before retrying.']);
            }
            throw $error;
        }
    }
}
