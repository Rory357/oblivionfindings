<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsAttachment;
use App\Models\ProcedureAcknowledgementReceipt;
use App\Models\ProcedureReviewCommandReceipt;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One source lock and strict semantic receipt for every controlled-document command. */
class SafeWorkProcedureService
{
    public const CATEGORIES = ['manual_handling', 'challenging_behaviour', 'lone_working', 'medication', 'infection_control', 'fire_safety', 'emergency_procedures', 'equipment_use', 'personal_care'];

    public const CONTENT_FIELDS = ['title', 'reference_number', 'category', 'purpose', 'scope', 'steps', 'ppe_required', 'hazards_addressed', 'emergency_procedures', 'applicable_roles', 'applicable_sites', 'related_training', 'review_date', 'review_frequency_months', 'owner_id'];

    public function __construct(private readonly SafeWorkProcedureAccessService $access) {}

    public function create(User $actor, array $data): SafeWorkProcedure
    {
        return $this->transaction(fn () => $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($data) {
            abort_unless($current->canDo('procedures.view') && ($current->canDo('procedures.create') || $current->canDo('procedures.manage')), 403);
            Validator::make($data, ['applicable_roles' => ['present', 'array'], 'applicable_sites' => ['present', 'array']])->validate();
            $fields = $this->content($data, null, $current, $reads, $sites);
            $record = new SafeWorkProcedure([...$fields, 'status' => 'draft', 'current_version' => 1, 'lock_version' => 1, 'created_by' => $current->id]);
            $record->saveQuietly();
            $version = $this->snapshot($record, [], $current, 'Initial draft');
            $this->audit('procedure.created', $record, $current, null, $version);

            return $record;
        }));
    }

    public function update(SafeWorkProcedure $record, User $actor, array $data): SafeWorkProcedure
    {
        return $this->command($record, $actor, $data, 'manage', function ($locked, $current, $reads, $sites) use ($data) {
            $this->notArchived($locked);
            $this->requireNote($data, 'change_summary');
            $fields = $this->content($data, $locked, $current, $reads, $sites);
            $manifest = $this->manifest($locked, $reads);
            $locked->forceFill([...$fields, 'status' => $locked->status === 'approved' ? 'under_review' : $locked->status, 'current_version' => $locked->current_version + 1]);
            $this->save($locked, $current);
            $version = $this->snapshot($locked, $manifest, $current, $data['change_summary']);
            $this->audit('procedure.revised', $locked, $current, $data['change_summary'], $version);

            return $locked;
        });
    }

    public function lifecycle(SafeWorkProcedure $record, User $actor, string $command, array $data): SafeWorkProcedure
    {
        return $this->command($record, $actor, $data, $command === 'approve' ? 'approve' : 'manage', function ($locked, $current, $reads, $sites) use ($command, $data) {
            $from = $locked->status;
            $note = null;
            $version = $this->workingVersion($locked, $reads);
            if ($command === 'restore') {
                $this->status($locked, ['archived']);
                $locked->forceFill(['status' => 'draft', 'previous_status' => null, 'published_version_id' => null, 'published_review_date' => null, 'approved_by' => null, 'approved_at' => null]);
            } else {
                $this->notArchived($locked);
                if ($command === 'submit-for-review') {
                    $this->status($locked, ['draft']);
                    if ($version?->id === $locked->published_version_id || $version?->provenance !== 'controlled') {
                        $manifest = $this->manifest($locked, $reads);
                        $locked->current_version++;
                        $version = $this->snapshot($locked, $manifest, $current, 'Submitted working revision');
                    }
                    $locked->status = 'under_review';
                } elseif ($command === 'approve') {
                    $this->status($locked, ['under_review']);
                    Validator::make($data, ['approval_note' => ['nullable', 'string', 'max:5000'], 'review_date' => ['sometimes', 'nullable', 'date_format:Y-m-d']])->validate();
                    if ($locked->applicable_roles === null || $locked->applicable_sites === null) {
                        throw ValidationException::withMessages(['applicable_roles' => 'Resolve the document role and Site audiences explicitly before approval.']);
                    }
                    $this->content($locked->only(self::CONTENT_FIELDS), $locked, $current, $reads, $sites);
                    $note = $data['approval_note'] ?? null;
                    $reviewDate = array_key_exists('review_date', $data) ? $data['review_date'] : ($locked->review_date?->toDateString() ?? ($locked->review_frequency_months ? now(config('app.worker_timezone', 'Pacific/Auckland'))->addMonthsNoOverflow($locked->review_frequency_months)->toDateString() : null));
                    $manifest = $this->manifest($locked, $reads);
                    $locked->forceFill(['current_version' => $locked->current_version + 1, 'review_date' => $reviewDate]);
                    $version = $this->snapshot($locked, $manifest, $current, 'Approved controlled publication');
                    $version->forceFill(['approved_by' => $current->id, 'approved_at' => now()])->saveQuietly();
                    $locked->forceFill(['status' => 'approved', 'approved_by' => $current->id, 'approved_at' => now(), 'published_version_id' => $version->id, 'published_review_date' => $reviewDate, 'review_date' => $reviewDate]);
                } elseif ($command === 'request-changes') {
                    $this->status($locked, ['under_review', 'approved']);
                    $note = $this->requireNote($data, 'note');
                    if ($version?->id === $locked->published_version_id) {
                        $manifest = $this->manifest($locked, $reads);
                        $locked->current_version++;
                        $version = $this->snapshot($locked, $manifest, $current, $note);
                    }
                    $locked->status = 'draft';
                } elseif ($command === 'record-review') {
                    abort_unless($locked->published_version_id !== null, 422, 'Publish a procedure before recording its review.');
                    $note = $this->requireNote($data, 'review_note');
                    Validator::make($data, ['review_date' => ['present', 'nullable', 'date_format:Y-m-d']])->validate();
                    $manifest = $this->manifest($locked, $reads);
                    $locked->forceFill(['status' => 'under_review', 'current_version' => $locked->current_version + 1, 'review_date' => $data['review_date']]);
                    $version = $this->snapshot($locked, $manifest, $current, $note);
                } elseif ($command === 'archive') {
                    $locked->forceFill(['previous_status' => $locked->status, 'status' => 'archived', 'published_version_id' => null, 'published_review_date' => null]);
                } else {
                    abort(404);
                }
            }
            $this->save($locked, $current);
            $action = ['submit-for-review' => 'submitted_for_review', 'approve' => 'approved', 'request-changes' => 'changes_requested', 'record-review' => 'review_recorded', 'archive' => 'archived', 'restore' => 'restored'][$command];
            $this->audit('procedure.'.$action, $locked, $current, $note, $version, ['from' => $from, 'to' => $locked->status, 'published_review_date' => $locked->published_review_date?->toDateString()]);

            return $locked;
        });
    }

    public function addEvidence(SafeWorkProcedure $record, User $actor, array $data, UploadedFile $file): void
    {
        $stored = null;
        try {
            $this->command($record, $actor, $data, 'manage', function ($locked, $current, $reads) use ($data, $file, &$stored) {
                $this->notArchived($locked);
                $manifest = $this->manifest($locked, $reads);
                $stored = $file->store('hs/procedures/'.$locked->id, 'private');
                if (! is_string($stored) || trim($stored) === '') {
                    throw ValidationException::withMessages(['file' => 'The document could not be saved. Try the upload again.']);
                }
                $locked->current_version++;
                $attachment = new HsAttachment(['attachable_type' => $locked->getMorphClass(), 'attachable_id' => $locked->id, 'uploaded_by' => $current->id, 'original_name' => $file->getClientOriginalName(), 'path' => $stored, 'disk' => 'private', 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'description' => $data['description'] ?? null, 'version_at_upload' => $locked->current_version]);
                $attachment->saveQuietly();
                $manifest[] = (int) $attachment->id;
                if ($locked->status === 'approved') {
                    $locked->status = 'under_review';
                }
                $this->save($locked, $current);
                $version = $this->snapshot($locked, $manifest, $current, 'Document added');
                $this->audit('procedure.evidence_added', $locked, $current, null, $version, ['names' => [$attachment->original_name]]);
            });
        } catch (\Throwable $error) {
            if (is_string($stored) && $stored !== '') {
                Storage::disk('private')->delete($stored);
            } throw $error;
        }
    }

    public function removeEvidence(SafeWorkProcedure $record, User $actor, array $data, HsAttachment $attachment): void
    {
        $this->command($record, $actor, $data, 'manage', function ($locked, $current, $reads) use ($attachment) {
            $this->notArchived($locked);
            abort_unless($attachment->attachable_type === $locked->getMorphClass() && (int) $attachment->attachable_id === (int) $locked->id, 404);
            $manifest = $this->manifest($locked, $reads);
            if (! in_array((int) $attachment->id, $manifest, true)) {
                throw ValidationException::withMessages(['file' => 'This document is no longer part of the working revision. Refresh the procedure.']);
            }
            $manifest = array_values(array_diff($manifest, [(int) $attachment->id]));
            $locked->current_version++;
            if ($locked->status === 'approved') {
                $locked->status = 'under_review';
            }
            $this->save($locked, $current);
            $version = $this->snapshot($locked, $manifest, $current, 'Document removed from working revision');
            // Preserve the row and bytes: prior immutable publications still refer to this document.
            $this->audit('procedure.evidence_removed', $locked, $current, null, $version, ['names' => [$attachment->original_name]]);
        });
    }

    public function acknowledge(SafeWorkProcedure $record, User $actor, array $data): ProcedureAcknowledgementReceipt
    {
        Validator::make($data, ['published_version_id' => ['required', 'integer', 'min:1']])->validate();

        return $this->transaction(function () use ($record, $actor, $data) {
            $locked = SafeWorkProcedure::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($locked, $data) {
                abort_unless($current->canDo('procedures.view') && $locked->status !== 'archived', 403);
                if ((int) $data['published_version_id'] !== (int) $locked->published_version_id) {
                    throw ValidationException::withMessages(['published_version_id' => 'The published instructions changed. Read the current copy before acknowledging it.']);
                }
                $version = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $locked->id)->findOrFail($locked->published_version_id);
                abort_unless($this->access->isApprovedVersion($version) && $this->access->audienceAllows($version->content_snapshot ?? [], $current, $sites), 403);
                $receipt = ProcedureAcknowledgementReceipt::query()->where('version_id', $version->id)->where('user_id', $current->id)->first();
                if ($receipt) {
                    return $receipt;
                }
                $receipt = ProcedureAcknowledgementReceipt::create(['safe_work_procedure_id' => $locked->id, 'version_id' => $version->id, 'user_id' => $current->id, 'provenance' => $version->provenance, 'content_digest' => $version->content_digest, 'acknowledged_at' => now()]);
                $this->audit('procedure.acknowledged', $locked, $current, null, $version, ['receipt_id' => $receipt->id, 'provenance' => $version->provenance]);

                return $receipt;
            });
        });
    }

    public function requestReview(SafeWorkProcedure $record, User $actor, array $data): ProcedureReviewRequest
    {
        Validator::make($data, ['hs_event_id' => ['required', 'integer', 'min:1'], 'hs_corrective_action_id' => ['nullable', 'integer', 'min:1'], 'requested_version_id' => ['required', 'integer', 'min:1'], 'assigned_to_user_id' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:5000'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $this->requireNote($data, 'note');

        return $this->transaction(function () use ($record, $actor, $data) {
            $locked = SafeWorkProcedure::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($locked, $data) {
                $this->notArchived($locked);
                abort_unless($current->canDo('procedures.view'), 403);
                $event = $this->access->assertSource($data, $current, $reads, $sites, true);
                $version = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $locked->id)->findOrFail($data['requested_version_id']);
                abort_unless($this->access->isApprovedVersion($version) && $this->access->audienceAllows($version->content_snapshot ?? [], $current, $sites), 403);
                $fingerprint = hash('sha256', json_encode([$locked->id, $event->id, $data['hs_corrective_action_id'] ?? null, $event->site_id, $version->id, (int) $data['assigned_to_user_id'], $current->id, $data['note']], JSON_THROW_ON_ERROR));
                $existing = $reads->query(ProcedureReviewRequest::query())->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    if ($existing->fingerprint !== $fingerprint) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This request key belongs to different details. Review the request and try again.']);
                    }

                    $this->access->assertSource($existing, $current, $reads, $sites, true);

                    return $existing;
                }
                if ($version->id !== $locked->published_version_id) {
                    throw ValidationException::withMessages(['requested_version_id' => 'The published copy changed. Review the current instructions before requesting work.']);
                }
                $this->assertOwner((int) $data['assigned_to_user_id'], $locked, $reads, $sites, $event->id);
                $request = ProcedureReviewRequest::create(['safe_work_procedure_id' => $locked->id, 'hs_event_id' => $event->id, 'hs_corrective_action_id' => $data['hs_corrective_action_id'] ?? null, 'source_site_id' => $event->site_id, 'requested_version_id' => $version->id, 'assigned_to_user_id' => $data['assigned_to_user_id'], 'requested_by_user_id' => $current->id, 'note' => $data['note'], 'status' => 'requested', 'requested_at' => now(), 'lock_version' => 1, 'idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint]);
                $this->audit('procedure.review_requested', $locked, $current, $data['note'], $version, ['request_id' => $request->id, 'source_site_id' => $event->site_id, 'hs_event_id' => $event->id, 'hs_corrective_action_id' => $request->hs_corrective_action_id, 'assigned_to_user_id' => $request->assigned_to_user_id]);
                app(ProcedureReviewNotifications::class)->recordAssignment($request, $current);

                return $request;
            });
        });
    }

    public function reviewRequestCommand(SafeWorkProcedure $record, ProcedureReviewRequest $request, User $actor, string $command, array $data): ProcedureReviewRequest
    {
        Validator::make($data, ['expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();

        return $this->transaction(function () use ($record, $request, $actor, $command, $data) {
            $locked = SafeWorkProcedure::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $row = ProcedureReviewRequest::query()->where('safe_work_procedure_id', $locked->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($locked, $row, $command, $data) {
                $this->access->assertSource($row, $current, $reads, $sites);
                abort_unless($current->canDo('procedures.view'), 403);
                $requested = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $locked->id)->findOrFail($row->requested_version_id);
                abort_unless($this->access->isApprovedVersion($requested) && $this->access->audienceAllows($requested->content_snapshot ?? [], $current, $sites), 403);
                $fingerprint = hash('sha256', json_encode([$locked->id, $row->id, $row->hs_event_id, $row->hs_corrective_action_id, $row->source_site_id, $row->requested_version_id, $current->id, $command, (int) $data['expected_version'], $data['decision_note'] ?? null, $data['outcome'] ?? null, isset($data['result_version_id']) ? (int) $data['result_version_id'] : null, isset($data['assigned_to_user_id']) ? (int) $data['assigned_to_user_id'] : null], JSON_THROW_ON_ERROR));
                $receipt = $reads->query(ProcedureReviewCommandReceipt::query())->where('idempotency_key', $data['idempotency_key'])->first();
                if ($receipt) {
                    if ($receipt->fingerprint !== $fingerprint || (int) $receipt->user_id !== (int) $current->id || (int) $receipt->procedure_review_request_id !== (int) $row->id) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This command key belongs to different details. Review the request before retrying.']);
                    }
                    // Recover the same actor's confirmed result after current joint read authorization; this is not a new command.
                    $confirmed = clone $row;
                    $confirmed->setRawAttributes([...$row->getAttributes(), ...$receipt->confirmed_result], true);

                    return $confirmed;
                }
                $this->notArchived($locked);
                if ((int) $data['expected_version'] !== $row->lock_version) {
                    throw ValidationException::withMessages(['expected_version' => 'This request changed. Review its current state before retrying.']);
                }
                $can = $this->access->reviewRequestCan($locked, $row, $current);
                abort_unless($can[$command] ?? false, 403);
                $from = $row->status;
                $previousOwnerId = (int) $row->assigned_to_user_id;
                if ($command === 'start') {
                    $row->status = 'in_review';
                } elseif ($command === 'reassign') {
                    Validator::make($data, ['assigned_to_user_id' => ['required', 'integer', 'min:1']])->validate();
                    $this->assertOwner((int) $data['assigned_to_user_id'], $locked, $reads, $sites, $row->hs_event_id);
                    $row->assigned_to_user_id = $data['assigned_to_user_id'];
                } else {
                    $note = $this->requireNote($data, 'decision_note');
                    if ($command === 'resolve') {
                        Validator::make($data, ['outcome' => ['required', Rule::in(['no_change', 'revised'])], 'result_version_id' => ['required', 'integer', 'min:1']])->validate();
                        $result = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $locked->id)->findOrFail($data['result_version_id']);
                        if ($result->id !== $locked->published_version_id || ($data['outcome'] === 'no_change' && $result->id !== $row->requested_version_id) || ($data['outcome'] === 'revised' && ($result->id === $row->requested_version_id || $result->approved_at === null || $result->provenance !== 'controlled'))) {
                            throw ValidationException::withMessages(['result_version_id' => 'Select the current published result; a revised result needs an explicit new approval.']);
                        }
                        $row->forceFill(['status' => 'resolved', 'outcome' => $data['outcome'], 'result_version_id' => $result->id]);
                    } else {
                        $row->status = $command === 'decline' ? 'declined' : 'cancelled';
                    }
                    $row->forceFill(['decision_note' => $note, 'decided_by_user_id' => $current->id, 'decided_at' => now()]);
                }
                $row->lock_version++;
                $row->saveQuietly();
                $this->audit('procedure.review_request_'.$command, $locked, $current, $data['decision_note'] ?? null, $this->workingVersion($locked, $reads), ['idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint, 'request_id' => $row->id, 'from' => $from, 'to' => $row->status, 'result_version_id' => $row->result_version_id, 'outcome' => $row->outcome, 'assigned_to_user_id' => $row->assigned_to_user_id, 'source_site_id' => $row->source_site_id, 'hs_event_id' => $row->hs_event_id, 'hs_corrective_action_id' => $row->hs_corrective_action_id]);

                $audit = AuditLog::query()->where('auditable_type', $locked->getMorphClass())->where('auditable_id', $locked->id)->where('meta->idempotency_key', $data['idempotency_key'])->latest('id')->firstOrFail();
                ProcedureReviewCommandReceipt::create(['procedure_review_request_id' => $row->id, 'user_id' => $current->id, 'audit_log_id' => $audit->id, 'idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint, 'confirmed_result' => $row->only(['id', 'status', 'lock_version', 'assigned_to_user_id', 'result_version_id', 'outcome', 'decision_note', 'decided_by_user_id', 'decided_at'])]);
                if ($command === 'reassign' && (int) $row->assigned_to_user_id !== $previousOwnerId) {
                    app(ProcedureReviewNotifications::class)->recordAssignment($row, $current, $previousOwnerId);
                }

                return $row;
            });
        });
    }

    private function command(SafeWorkProcedure $record, User $actor, array $data, string $capability, callable $operation): mixed
    {
        Validator::make($data, ['expected_version' => ['required', 'integer', 'min:1'], 'expected_lock_version' => ['required', 'integer', 'min:1']])->validate();

        return $this->transaction(function () use ($record, $actor, $data, $capability, $operation) {
            $locked = SafeWorkProcedure::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($locked, $data, $capability, $operation) {
                $permission = $capability === 'approve' ? 'procedures.approve' : 'procedures.manage';
                abort_unless($current->canDo($permission) && $this->access->workingAllowed($locked, $current, $sites) && $this->access->allSitesAllowed($locked->applicable_sites ?? [], $sites, $current), 403);
                if ((int) $data['expected_version'] !== $locked->current_version || (int) $data['expected_lock_version'] !== $locked->lock_version) {
                    throw ValidationException::withMessages(['expected_version' => 'This procedure changed. Review its current revision before retrying.']);
                }

                return $operation($locked, $current, $reads, $sites);
            });
        });
    }

    private function content(array $data, ?SafeWorkProcedure $record, User $actor, CurrentAuthorizationReads $reads, array $sites): array
    {
        if (($data['review_date'] ?? null) instanceof \DateTimeInterface) {
            $data['review_date'] = $data['review_date']->format('Y-m-d');
        }
        $rules = ['title' => ['required', 'string', 'max:255'], 'reference_number' => ['required', 'string', 'max:100', Rule::unique('safe_work_procedures')->ignore($record?->id)], 'category' => ['required', Rule::in(self::CATEGORIES)], 'purpose' => ['nullable', 'string', 'max:10000'], 'scope' => ['nullable', 'string', 'max:10000'], 'steps' => ['nullable', 'array'], 'steps.*.description' => ['required', 'string', 'max:5000'], 'steps.*.safety_notes' => ['nullable', 'string', 'max:5000'], 'ppe_required' => ['nullable', 'array'], 'ppe_required.*' => ['string', 'max:255'], 'hazards_addressed' => ['nullable', 'array'], 'hazards_addressed.*' => ['string', 'max:255'], 'emergency_procedures' => ['nullable', function ($attribute, $value, $fail) {
            if (! is_string($value) && (! is_array($value) || collect($value)->contains(fn ($line) => ! is_string($line)))) {
                $fail('Emergency instructions must be text or a list of text lines.');
            }
        }], 'applicable_roles' => ['sometimes', 'nullable', 'array'], 'applicable_roles.*' => ['string', 'max:100'], 'applicable_sites' => ['sometimes', 'nullable', 'array'], 'applicable_sites.*' => ['integer', 'min:1'], 'related_training' => ['sometimes', 'nullable', 'array'], 'related_training.*' => ['integer', 'min:1'], 'review_date' => ['nullable', 'date_format:Y-m-d'], 'review_frequency_months' => ['nullable', 'integer', 'between:1,120'], 'owner_id' => ['nullable', 'integer', 'min:1']];
        $fields = Validator::make($data, $rules)->validate();
        foreach (self::CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                $fields[$field] = $record?->{$field};
            }
        }
        foreach (['steps', 'ppe_required', 'hazards_addressed'] as $field) {
            $fields[$field] = array_values($fields[$field] ?? []);
        }
        $fields['steps'] = collect($fields['steps'])->filter(fn ($step) => trim((string) ($step['description'] ?? '')) !== '')->values()->map(fn ($step, $i) => ['step_number' => $i + 1, 'description' => trim($step['description']), 'safety_notes' => trim($step['safety_notes'] ?? '')])->all();
        $fields['emergency_procedures'] = is_array($fields['emergency_procedures']) ? $fields['emergency_procedures'] : preg_split('/\r\n|\r|\n/', (string) ($fields['emergency_procedures'] ?? ''));
        $fields['emergency_procedures'] = array_values(array_filter(array_map('trim', $fields['emergency_procedures']), fn ($v) => $v !== ''));
        $fields['review_date'] = $fields['review_date'] instanceof \DateTimeInterface ? $fields['review_date']->format('Y-m-d') : $fields['review_date'];
        $fields['applicable_sites'] = $fields['applicable_sites'] === null ? null : array_values(array_unique(array_map('intval', $fields['applicable_sites'])));
        $fields['related_training'] = $fields['related_training'] === null ? null : array_values(array_unique(array_map('intval', $fields['related_training'])));
        if (! $this->access->allSitesAllowed($fields['applicable_sites'] ?? [], $sites, $actor)) {
            throw ValidationException::withMessages(['applicable_sites' => 'Choose only your current approved Sites.']);
        }
        if ($reads->query(Role::query())->whereIn('name', $fields['applicable_roles'] ?? [])->count() !== count(array_unique($fields['applicable_roles'] ?? []))) {
            throw ValidationException::withMessages(['applicable_roles' => 'Choose current application roles.']);
        }
        if ($reads->query(TrainingCourse::query()->active())->whereIn('id', $fields['related_training'] ?? [])->count() !== count($fields['related_training'] ?? [])) {
            throw ValidationException::withMessages(['related_training' => 'Choose current active training courses.']);
        }
        $draft = $record ? clone $record : new SafeWorkProcedure;
        $draft->forceFill($fields);
        if ($fields['owner_id'] !== null) {
            $this->assertOwner((int) $fields['owner_id'], $draft, $reads, $sites);
        }

        return $fields;
    }

    private function assertOwner(int $id, SafeWorkProcedure $record, CurrentAuthorizationReads $reads, array $actorSites, ?int $eventId = null): void
    {
        $candidate = $reads->query(User::query())->find($id);
        $valid = false;
        if ($candidate) {
            try {
                $valid = $this->access->withCurrentViewer($candidate, function ($owner, $ownerReads, $ownerSites) use ($record, $eventId, $actorSites) {
                    if (! $owner->canDo('procedures.manage') || ! $this->access->workingAllowed($record, $owner, $ownerSites) || ! $this->access->allSitesAllowed($record->applicable_sites ?? [], $ownerSites, $owner) || array_intersect($actorSites, $ownerSites) === []) {
                        return false;
                    }
                    if ($eventId) {
                        $this->access->assertSource(['hs_event_id' => $eventId], $owner, $ownerReads, $ownerSites);
                    }

                    return true;
                });
            } catch (HttpException) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw ValidationException::withMessages(['assigned_to_user_id' => 'Choose a named current procedure manager with access to the document and source.', 'owner_id' => 'Choose a current procedure manager with access to its audience.']);
        }
    }

    private function workingVersion(SafeWorkProcedure $record, CurrentAuthorizationReads $reads): ?SafeWorkProcedureVersion
    {
        return $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $record->id)->where('version_number', $record->current_version)->first();
    }

    private function manifest(SafeWorkProcedure $record, CurrentAuthorizationReads $reads): array
    {
        return array_map('intval', $this->workingVersion($record, $reads)?->document_manifest ?? []);
    }

    private function snapshot(SafeWorkProcedure $record, array $manifest, User $actor, string $note): SafeWorkProcedureVersion
    {
        $snapshot = $record->only(self::CONTENT_FIELDS);
        $snapshot['review_date'] = $record->review_date?->toDateString();

        return SafeWorkProcedureVersion::create(['safe_work_procedure_id' => $record->id, 'version_number' => $record->current_version, 'content_snapshot' => $snapshot, 'document_manifest' => array_values($manifest), 'provenance' => 'controlled', 'captured_at' => now(), 'content_digest' => hash('sha256', json_encode([$snapshot, array_values($manifest)], JSON_THROW_ON_ERROR)), 'change_summary' => $note, 'changed_by' => $actor->id]);
    }

    private function save(SafeWorkProcedure $record, User $actor): void
    {
        $record->forceFill(['updated_by' => $actor->id, 'lock_version' => $record->lock_version + 1])->saveQuietly();
    }

    private function audit(string $action, SafeWorkProcedure $record, User $actor, ?string $note = null, ?SafeWorkProcedureVersion $version = null, array $meta = []): void
    {
        $auditRequest = clone request();
        $auditRequest->setUserResolver(fn () => $actor);
        AuditLogger::logOrFail($action, $record, [...$meta, 'snapshot' => $version?->content_snapshot ?? $record->only(self::CONTENT_FIELDS), 'note' => $note, 'version_id' => $version?->id, 'version' => $version?->version_number, 'content_digest' => $version?->content_digest, 'actor_id' => $actor->id], $auditRequest);
    }

    private function requireNote(array $data, string $field): string
    {
        Validator::make($data, [$field => ['required', 'string', 'max:5000']])->validate();
        if (trim($data[$field]) === '') {
            throw ValidationException::withMessages([$field => 'Add an attributable note.']);
        }

        return $data[$field];
    }

    private function notArchived(SafeWorkProcedure $record): void
    {
        $this->status($record, ['draft', 'under_review', 'approved']);
    }

    private function status(SafeWorkProcedure $record, array $allowed): void
    {
        if (! in_array($record->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'This command is not available in the current procedure state.']);
        }
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062 && (str_contains($error->getMessage(), 'procedure_review_command_key_unique') || str_contains($error->getMessage(), 'procedure_review_requests_idempotency_key_unique'))) {
                throw ValidationException::withMessages(['idempotency_key' => 'This command key was already confirmed for different details. Review the current request.']);
            }
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_version' => 'Another change is in progress. Review the current procedure and retry.']);
            } throw $error;
        }
    }
}
