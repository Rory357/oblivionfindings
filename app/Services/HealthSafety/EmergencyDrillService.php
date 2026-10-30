<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillAttachment;
use App\Models\EmergencyDrillCommandReceipt;
use App\Models\EmergencyDrillFinding;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Canonical source commands; drill completion and remedy verification remain independent. */
class EmergencyDrillService
{
    public const DRILL_TYPES = ['fire_evacuation', 'earthquake', 'lockdown', 'tsunami', 'chemical_spill', 'medical_emergency', 'other'];

    public function __construct(private readonly EmergencyDrillAccessService $access, private readonly HsEventService $events, private readonly HsCorrectiveActionService $actions, private readonly EmergencyDrillFailureDeliveryService $delivery) {}

    public function create(User $actor, array $data): EmergencyDrill
    {
        $validated = $this->planning($data, true);
        Validator::make($data, ['idempotency_key' => ['required', 'uuid']])->validate();

        return $this->transaction(fn () => $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($validated, $data) {
            abort_unless($current->canDo('hazards.view') && ($current->canDo('hazards.create') || $current->canDo('hazards.manage')), 403);
            abort_unless(in_array((int) $validated['site_id'], $sites, true), 403);
            $fingerprint = $this->fingerprint(['create', $current->id, $validated]);
            $existing = $reads->query(EmergencyDrill::query())->where('creation_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->creation_fingerprint !== $fingerprint || (int) $existing->created_by !== (int) $current->id) {
                    $this->keyConflict();
                }
                $this->access->assertRecord($existing, $current, $sites);

                return $this->confirmedReplay($existing, $current, $data['idempotency_key'], $fingerprint, $reads);
            }
            $this->assertPlanningStaff($validated, (int) $validated['site_id']);
            $record = new EmergencyDrill;
            $record->forceFill([...Arr::except($validated, ['warden_ids']), 'creation_key' => $data['idempotency_key'], 'creation_fingerprint' => $fingerprint, 'status' => 'scheduled', 'lock_version' => 1, 'created_by' => $current->id])->saveQuietly();
            $this->seedParticipants($record, $validated);
            $audit = $this->audit($record, $current, 'scheduled', $data['idempotency_key'], ['site_id' => $record->site_id]);
            $this->receipt($record, $current, $data['idempotency_key'], $fingerprint, $audit);

            return $record;
        }));
    }

    public function update(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        $validated = $this->planning($data, false);

        return $this->command($record, $actor, 'edit', $data, function ($row) use ($validated) {
            $this->assertPlanningStaff($validated, (int) $row->site_id);
            $row->forceFill(Arr::except($validated, ['warden_ids']));
            if (array_key_exists('conducted_by', $validated) || array_key_exists('warden_ids', $validated)) {
                $this->seedParticipants($row, $validated);
            }

            return [];
        });
    }

    public function start(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        return $this->command($record, $actor, 'start', $data, function ($row) {
            $row->forceFill(['status' => 'in_progress', 'started_at' => now()]);

            return [];
        });
    }

    public function complete(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        $validated = Validator::make($data, [
            'completed_at' => ['required', ...$this->instantRules()], 'outcome' => ['required', Rule::in(['passed', 'passed_actions', 'failed'])],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'evacuation_time_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'weather_conditions' => ['nullable', 'string', 'max:255'], 'total_participants' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'residents_evacuated' => ['nullable', 'integer', 'min:0', 'max:9999'], 'observer_notes' => ['nullable', 'string', 'max:5000'],
            'improvements_identified' => ['nullable', 'string', 'max:5000'], 'conducted_by' => ['nullable', 'integer', 'min:1'],
            'all_areas_checked' => ['sometimes', 'boolean'], 'assembly_point_reached' => ['sometimes', 'boolean'], 'roll_call_completed' => ['sometimes', 'boolean'],
        ])->validate();

        $validated['completed_at'] = Carbon::parse($validated['completed_at'])->utc()->format('Y-m-d H:i:s');

        return $this->command($record, $actor, 'complete', $data, function ($row) use ($validated) {
            if (! $row->started_at || Carbon::parse($validated['completed_at'])->lt($row->started_at)) {
                throw ValidationException::withMessages(['completed_at' => 'Completion cannot be earlier than the recorded start.']);
            }
            $this->assertPlanningStaff($validated, (int) $row->site_id);
            if (Carbon::parse($validated['completed_at'])->gt(now())) {
                throw ValidationException::withMessages(['completed_at' => 'Record an actual completion time that has already occurred.']);
            }
            $row->forceFill([...$validated, 'status' => 'completed']);

            return ['outcome' => $row->outcome];
        });
    }

    public function cancel(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        $reason = $this->note($data, 'reason', 2000);

        return $this->command($record, $actor, 'cancel', $data, function ($row, $current) use ($reason) {
            $row->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $current->id, 'cancellation_reason' => $reason]);

            return ['note' => $reason];
        });
    }

    public function upsertParticipant(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        $validated = Validator::make($data, ['user_id' => ['required', 'integer', 'min:1'], 'role' => ['nullable', 'string', 'max:100'], 'attended' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:500'], 'accommodations' => ['nullable', 'string', 'max:2000']])->validate();

        return $this->command($record, $actor, 'add_participant', $data, function ($row) use ($validated) {
            $staff = $this->access->currentStaffAtSite((int) $validated['user_id'], (int) $row->site_id);
            $participant = $row->participants()->updateOrCreate(['user_id' => $staff->id], [...Arr::except($validated, ['user_id']), 'role' => $validated['role'] ?? 'participant']);

            return ['participant_id' => $participant->id, 'user_id' => $staff->id, 'participant_snapshot' => $participant->only(['role', 'attended', 'notes', 'accommodations'])];
        });
    }

    public function addFinding(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        $validated = $this->findingData($data, true);

        return $this->command($record, $actor, 'add_finding', $data, function ($row, $current) use ($validated) {
            if (! empty($validated['assigned_to'])) {
                $this->access->currentStaffAtSite((int) $validated['assigned_to'], (int) $row->site_id);
            }
            $finding = $row->findings()->create([...$validated, 'status' => 'open', 'created_by' => $current->id]);

            return ['finding_id' => $finding->id, 'finding_snapshot' => $this->findingSnapshot($finding)];
        });
    }

    public function updateFinding(EmergencyDrill $record, EmergencyDrillFinding $finding, User $actor, array $data): EmergencyDrill
    {
        $validated = $this->findingData($data, false);
        if (isset($data['status']) && ! in_array($data['status'], ['open', 'in_progress'], true)) {
            throw ValidationException::withMessages(['status' => 'Use the finding resolution command to record a supported outcome.']);
        }
        if (isset($data['status'])) {
            $validated['status'] = $data['status'];
        }

        return $this->command($record, $actor, 'add_finding', [...$data, 'finding_id' => $finding->id], function ($row, $current, $reads) use ($finding, $validated) {
            $child = $this->lockedFinding($row, $finding);
            abort_unless($this->access->findingCan($row, $child, $current)['edit'], 403);
            if (! empty($validated['assigned_to'])) {
                $this->access->currentStaffAtSite((int) $validated['assigned_to'], (int) $row->site_id);
            }
            $child->forceFill([...$validated, 'updated_by' => $current->id])->saveQuietly();

            return ['finding_id' => $child->id, 'finding_snapshot' => $this->findingSnapshot($child)];
        }, 'finding_updated');
    }

    public function resolveFinding(EmergencyDrill $record, EmergencyDrillFinding $finding, User $actor, array $data): EmergencyDrill
    {
        $notes = $this->note($data, 'resolution_notes', 2000);
        $ids = Validator::make($data, ['resolution_evidence_ids' => ['required', 'array', 'min:1'], 'resolution_evidence_ids.*' => ['required', 'integer', 'min:1', 'distinct']])->validate()['resolution_evidence_ids'];

        return $this->command($record, $actor, 'add_finding', [...$data, 'finding_id' => $finding->id], function ($row, $current, $reads) use ($finding, $notes, $ids) {
            $child = $this->lockedFinding($row, $finding);
            abort_unless(! in_array($child->status, ['resolved', 'closed', 'accepted'], true), 403);
            $attachments = $reads->query(EmergencyDrillAttachment::query())->where('emergency_drill_id', $row->id)->whereIn('id', $ids)->get();
            if ($attachments->count() !== count($ids) || $attachments->contains(fn ($file) => $file->disk !== 'private' || ! Storage::disk('private')->exists($file->path))) {
                throw ValidationException::withMessages(['resolution_evidence_ids' => 'Select available supporting evidence from this drill.']);
            }
            if ($child->hs_corrective_action_id !== null) {
                $action = $this->readableAction($row, (int) $child->hs_corrective_action_id, $current, $reads);
                if (! in_array($action->status, [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED], true) || $action->effectiveness_confirmed !== true) {
                    throw ValidationException::withMessages(['hs_corrective_action_id' => 'The required remedy needs independent verification and a positive effectiveness result.']);
                }
            }
            $child->forceFill(['status' => 'resolved', 'resolved_at' => now(\App\Support\Incidents\IncidentOccurrencePeriod::timezone())->toDateString(), 'resolution_notes' => $notes, 'resolution_evidence_ids' => array_map('intval', $ids), 'updated_by' => $current->id])->saveQuietly();

            return ['finding_id' => $child->id, 'hs_corrective_action_id' => $child->hs_corrective_action_id, 'resolution_evidence_ids' => array_map('intval', $ids), 'note' => $notes, 'finding_snapshot' => $this->findingSnapshot($child)];
        }, 'finding_resolved');
    }

    public function createOrLinkCorrectiveAction(EmergencyDrill $record, EmergencyDrillFinding $finding, User $actor, array $data): EmergencyDrill
    {
        Validator::make($data, ['mode' => ['required', Rule::in(['create', 'link'])]])->validate();
        if ($data['mode'] === 'create') {
            Validator::make($data, ['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'assigned_to_user_id' => ['required', 'integer', 'min:1'], 'due_date' => ['required', 'date_format:Y-m-d'], 'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])]])->validate();
        } else {
            Validator::make($data, ['hs_corrective_action_id' => ['required', 'integer', 'min:1']])->validate();
        }

        return $this->command($record, $actor, 'add_finding', [...$data, 'finding_id' => $finding->id], function ($row, $current, $reads) use ($finding, $data) {
            $child = $this->lockedFinding($row, $finding);
            abort_unless(! in_array($child->status, ['resolved', 'closed', 'accepted'], true) && $child->finding_type !== 'positive', 403);
            if ($child->hs_corrective_action_id !== null) {
                throw ValidationException::withMessages(['hs_corrective_action_id' => 'This finding already has a required remedy. Open its existing action.']);
            }
            if ($data['mode'] === 'link') {
                $action = $this->readableAction($row, (int) $data['hs_corrective_action_id'], $current, $reads);
            } else {
                $owner = $this->access->currentStaffAtSite((int) $data['assigned_to_user_id'], (int) $row->site_id);
                abort_unless($owner->canDo('hazards.manage'), 403);
                $event = $this->ensureEvent($row, $current, $child);
                abort_unless(app(HsEventAccessService::class)->canView($event, $current), 403);
                $action = $this->actions->createStandalone($event, Arr::only($data, ['title', 'description', 'assigned_to_user_id', 'due_date', 'priority']), $current);
            }
            $child->forceFill(['hs_corrective_action_id' => $action->id, 'updated_by' => $current->id])->saveQuietly();

            return ['finding_id' => $child->id, 'hs_corrective_action_id' => $action->id, 'hs_event_id' => $action->hs_event_id, 'mode' => $data['mode'], 'finding_snapshot' => $this->findingSnapshot($child)];
        }, 'corrective_action_linked');
    }

    public function scheduleNext(EmergencyDrill $record, User $actor, array $data): EmergencyDrill
    {
        $validated = $this->planning($data, true);

        return $this->command($record, $actor, 'schedule_next', $data, function ($row, $current) use ($validated, $data) {
            if ((int) $validated['site_id'] !== (int) $row->site_id) {
                throw ValidationException::withMessages(['site_id' => 'Schedule the next drill for the same approved site.']);
            }
            $this->assertPlanningStaff($validated, (int) $row->site_id);
            $next = new EmergencyDrill;
            $next->forceFill([...Arr::except($validated, ['warden_ids']), 'previous_drill_id' => $row->id, 'creation_key' => $data['idempotency_key'], 'creation_fingerprint' => $this->fingerprint(['schedule_next', $current->id, $row->id, $validated]), 'status' => 'scheduled', 'lock_version' => 1, 'created_by' => $current->id])->saveQuietly();
            $this->seedParticipants($next, $validated);
            $this->audit($next, $current, 'scheduled', $data['idempotency_key'], ['previous_drill_id' => $row->id]);

            return ['next_drill_id' => $next->id];
        });
    }

    public function addEvidence(EmergencyDrill $record, User $actor, array $data, UploadedFile $file): EmergencyDrill
    {
        Validator::make([...$data, 'file' => $file], ['file' => ['required', 'file', 'max:20480'], 'kind' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:1000'], 'alt_text' => ['nullable', 'string', 'max:255']])->validate();
        $paths = [];
        try {
            $confirmed = $this->command($record, $actor, 'add_evidence', [...Arr::except($data, ['file']), 'file_digest' => hash_file('sha256', $file->getRealPath()), 'file_name' => $file->getClientOriginalName()], function ($row, $current) use ($data, $file, &$paths) {
                $path = $file->store('emergency_drill_attachments', 'private');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Drill evidence storage failed.');
                }
                $paths[] = $path;
                $attachment = $row->attachments()->create(['uploaded_by' => $current->id, 'disk' => 'private', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), ...Arr::only($data, ['kind', 'notes', 'alt_text'])]);

                return ['attachment_id' => $attachment->id, 'names' => [basename($attachment->original_name)]];
            });

        } catch (Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
        // Retries may return another transaction's confirmed receipt without storing
        // a new blob. Keep only paths actually committed to this canonical source.
        $kept = EmergencyDrillAttachment::withTrashed()->where('emergency_drill_id', $confirmed->id)->whereIn('path', $paths)->pluck('path')->all();
        foreach (array_diff($paths, $kept) as $abandoned) {
            Storage::disk('private')->delete($abandoned);
        }

        return $confirmed;
    }

    public function removeEvidence(EmergencyDrill $record, EmergencyDrillAttachment $attachment, User $actor, array $data): EmergencyDrill
    {
        return $this->command($record, $actor, 'remove_evidence', [...$data, 'attachment_id' => $attachment->id], function ($row) use ($attachment) {
            $file = EmergencyDrillAttachment::query()->where('emergency_drill_id', $row->id)->whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            if ($row->findings()->whereJsonContains('resolution_evidence_ids', (int) $file->id)->exists()) {
                throw ValidationException::withMessages(['attachment' => 'This document supports a recorded finding resolution and must be retained.']);
            }
            // Retain private content for evidence history; physical deletion is not a source command.
            $file->delete();

            return ['attachment_id' => $file->id, 'names' => [basename($file->original_name)]];
        });
    }

    private function command(EmergencyDrill $record, User $actor, string $capability, array $data, callable $operation, ?string $auditCommand = null): EmergencyDrill
    {
        Validator::make($data, ['expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $command = $auditCommand ?? match ($capability) {
            'edit' => 'updated', 'start' => 'started', 'complete' => 'completed', 'cancel' => 'cancelled',
            'add_participant' => 'participant_recorded', 'add_finding' => 'finding_added',
            'add_evidence' => 'evidence_added', 'remove_evidence' => 'evidence_removed', 'schedule_next' => 'next_scheduled',
            default => $capability,
        };

        return $this->transaction(function () use ($record, $actor, $capability, $data, $operation, $command) {
            $row = EmergencyDrill::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $capability, $data, $operation, $command) {
                $this->access->assertRecord($row, $current, $sites, true);
                $fingerprint = $this->fingerprint([$row->id, $current->id, $command, Arr::except($data, ['idempotency_key', 'return_to', 'section', 'action', 'participant', 'finding'])]);
                $receipt = $reads->query(EmergencyDrillCommandReceipt::query())->where('idempotency_key', $data['idempotency_key'])->first();
                if ($receipt) {
                    return $this->confirmedReplay($row, $current, $data['idempotency_key'], $fingerprint, $reads);
                }
                if ((int) $data['expected_version'] !== (int) $row->lock_version) {
                    throw ValidationException::withMessages(['expected_version' => 'This drill changed. Review its current state before retrying.']);
                }
                abort_unless($this->access->commandCan($row, $current)[$capability] ?? false, 403);
                $before = $row->only(['status', 'lock_version', 'outcome']);
                $meta = $operation($row, $current, $reads);
                $row->forceFill(['lock_version' => $row->lock_version + 1, 'updated_by' => $current->id])->saveQuietly();
                $audit = $this->audit($row, $current, $command, $data['idempotency_key'], ['before' => $before, 'after' => $row->only(['status', 'lock_version', 'outcome']), 'from' => $before['status'], 'to' => $row->status, ...$meta]);
                $this->receipt($row, $current, $data['idempotency_key'], $fingerprint, $audit, $meta);
                if ($capability === 'complete' && $row->outcome !== 'passed') {
                    $this->delivery->stage($row);
                }

                return $row;
            });
        });
    }

    private function confirmedReplay(EmergencyDrill $row, User $actor, string $key, string $fingerprint, CurrentAuthorizationReads $reads): EmergencyDrill
    {
        $receipt = $reads->query(EmergencyDrillCommandReceipt::query())->where('idempotency_key', $key)->first();
        if (! $receipt || $receipt->fingerprint !== $fingerprint || (int) $receipt->user_id !== (int) $actor->id || (int) $receipt->emergency_drill_id !== (int) $row->id) {
            $this->keyConflict();
        }
        $confirmed = clone $row;
        $confirmed->setRawAttributes($receipt->confirmed_result['record'], true);

        return $confirmed;
    }

    private function receipt(EmergencyDrill $row, User $actor, string $key, string $fingerprint, int $auditId, array $meta = []): void
    {
        EmergencyDrillCommandReceipt::create(['emergency_drill_id' => $row->id, 'user_id' => $actor->id, 'audit_log_id' => $auditId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'confirmed_result' => ['record' => $row->getAttributes(), 'result' => $meta]]);
    }

    private function audit(EmergencyDrill $row, User $actor, string $command, string $key, array $meta): int
    {
        $request = clone request();
        $request->setUserResolver(fn () => $actor);
        AuditLogger::logOrFail('drill.'.$command, $row, ['actor_id' => $actor->id, 'idempotency_key' => $key, 'snapshot' => $row->only(['title', 'drill_type', 'scheduled_at', 'started_at', 'completed_at', 'outcome', 'scenario_description', 'assembly_point', 'evacuation_scheme', 'is_unannounced', 'duration_minutes', 'evacuation_time_seconds', 'total_participants', 'residents_evacuated', 'all_areas_checked', 'assembly_point_reached', 'roll_call_completed', 'weather_conditions', 'observer_notes', 'improvements_identified', 'conducted_by']), ...$meta], $request);

        return (int) AuditLog::query()->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', 'drill.'.$command)->where('meta->idempotency_key', $key)->latest('id')->firstOrFail()->id;
    }

    private function ensureEvent(EmergencyDrill $row, User $actor, EmergencyDrillFinding $finding): HsEvent
    {
        $event = $this->events->recordEvent(['source' => $row, 'event_category' => HsEvent::CATEGORY_DRILL_FAILURE, 'severity' => $finding->severity ?? 'medium', 'site_id' => $row->site_id, 'occurred_at' => $finding->created_at, 'reported_at' => now(), 'created_by' => $actor->id]);
        if (! $event || $event->event_category !== HsEvent::CATEGORY_DRILL_FAILURE || ! in_array($event->source_type, array_unique([EmergencyDrill::class, $row->getMorphClass()]), true) || (int) $event->source_id !== (int) $row->id || (int) $event->site_id !== (int) $row->site_id) {
            throw new \RuntimeException('The canonical drill safety review could not be recorded.');
        }

        return $event;
    }

    private function readableAction(EmergencyDrill $row, int $id, User $actor, CurrentAuthorizationReads $reads): HsCorrectiveAction
    {
        $action = $reads->query(HsCorrectiveAction::query())->findOrFail($id);
        $event = $reads->query(HsEvent::query())->findOrFail($action->hs_event_id);
        abort_unless($event->event_category === HsEvent::CATEGORY_DRILL_FAILURE && in_array($event->source_type, array_unique([EmergencyDrill::class, $row->getMorphClass()]), true) && (int) $event->source_id === (int) $row->id && (int) $event->site_id === (int) $row->site_id, 404);
        abort_unless(app(HsEventAccessService::class)->canView($event, $actor) && app(TaskAggregator::class)->findItemFor($actor, 'corrective_action', $action->id, ['include_done' => true]), 403);

        return $action;
    }

    private function lockedFinding(EmergencyDrill $row, EmergencyDrillFinding $finding): EmergencyDrillFinding
    {
        return EmergencyDrillFinding::query()->where('emergency_drill_id', $row->id)->whereKey($finding->id)->lockForUpdate()->firstOrFail();
    }

    private function findingSnapshot(EmergencyDrillFinding $finding): array
    {
        return [...$finding->only(['finding_type', 'description', 'severity', 'status', 'corrective_action', 'resolution_notes', 'resolution_evidence_ids']),
            'due_date' => $finding->due_date?->toDateString(), 'resolved_at' => $finding->resolved_at?->toDateString()];
    }

    private function planning(array $data, bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        $validated = Validator::make($data, [
            'site_id' => $create ? ['required', 'integer', 'min:1'] : ['prohibited'], 'title' => [$required, 'required', 'string', 'max:255'],
            'drill_type' => [$required, 'required', Rule::in([...self::DRILL_TYPES, 'fire'])], 'scheduled_at' => [$required, 'required', ...$this->instantRules()],
            'scenario_description' => ['nullable', 'string', 'max:5000'], 'description' => ['nullable', 'string', 'max:5000'],
            'assembly_point' => ['nullable', 'string', 'max:255'], 'evacuation_scheme' => ['nullable', 'string', 'max:255'],
            'conducted_by' => ['nullable', 'integer', 'min:1'], 'total_participants' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_unannounced' => ['sometimes', 'boolean'], 'warden_ids' => ['nullable', 'array'], 'warden_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        if (isset($validated['scheduled_at'])) {
            $validated['scheduled_at'] = Carbon::parse($validated['scheduled_at'])->utc()->format('Y-m-d H:i:s');
        }
        if (($validated['drill_type'] ?? null) === 'fire') {
            $validated['drill_type'] = 'fire_evacuation';
        }

        return $validated;
    }

    private function instantRules(): array
    {
        // Browser wall-clock values never identify an actual instant without their offset.
        return ['bail', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', 'date'];
    }

    private function findingData(array $data, bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return Validator::make($data, ['finding_type' => [$required, Rule::in(['observation', 'non_conformance', 'improvement', 'positive'])], 'description' => [$required, 'required', 'string', 'max:2000'], 'severity' => [$required, Rule::in(['critical', 'high', 'medium', 'low'])], 'corrective_action' => ['nullable', 'string', 'max:2000'], 'assigned_to' => ['nullable', 'integer', 'min:1'], 'due_date' => ['nullable', 'date_format:Y-m-d']])->validate();
    }

    private function assertPlanningStaff(array $data, int $siteId): void
    {
        foreach (array_unique(array_filter([...(array) ($data['warden_ids'] ?? []), $data['conducted_by'] ?? null])) as $id) {
            $this->access->currentStaffAtSite((int) $id, $siteId);
        }
    }

    private function seedParticipants(EmergencyDrill $row, array $data): void
    {
        $roles = [];
        foreach ($data['warden_ids'] ?? [] as $id) {
            $roles[(int) $id] = 'warden';
        }
        if (! empty($data['conducted_by'])) {
            $roles[(int) $data['conducted_by']] = 'coordinator';
        }
        foreach ($roles as $id => $role) {
            $row->participants()->firstOrCreate(['user_id' => $id], ['role' => $role, 'attended' => false]);
        }
    }

    private function note(array $data, string $field, int $max): string
    {
        Validator::make($data, [$field => ['required', 'string', 'max:'.$max]])->validate();
        if (trim($data[$field]) === '') {
            throw ValidationException::withMessages([$field => 'Add an attributable note.']);
        }

        return $data[$field];
    }

    private function fingerprint(array $parts): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($parts), JSON_THROW_ON_ERROR));
    }

    private function keyConflict(): never
    {
        throw ValidationException::withMessages(['idempotency_key' => 'This command key belongs to a different actor, drill or payload. Review the current record.']);
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (\Illuminate\Database\DeadlockException) {
                // Inside a transaction Laravel raises a deadlock (1213) as DeadlockException, not QueryException.
                throw ValidationException::withMessages(['expected_version' => 'This drill or its authority changed concurrently. Review the current record before retrying.']);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_version' => 'This drill or its authority changed concurrently. Review the current record before retrying.']);
            }
            throw $exception;
        }
    }
}
