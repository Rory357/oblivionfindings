<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\RespiteStay;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintDebrief;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\RestraintEventReview;
use App\Models\RestraintFollowup;
use App\Models\RestraintFollowupHistory;
use App\Models\RestraintMonitoringObservation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\References\ReferenceNumberGenerator;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Source-owned factual commands; capture/review never grants clinical authority. */
class RestraintService
{
    public function __construct(private readonly RestraintAccessService $access, private readonly BehaviourSupportPlanService $plans, private readonly RestraintConnectionsService $connections, private readonly RestraintSourceDeliveryService $delivery, private readonly RespiteStayScope $stays) {}

    public function create(User $actor, array $input): array
    {
        return $this->capture($actor, $input, null);
    }

    public function createForStay(User $actor, RespiteStay $stay, array $input): array
    {
        return $this->capture($actor, $input, $stay);
    }

    private function capture(User $actor, array $input, ?RespiteStay $entry): array
    {
        return $this->transaction(fn () => $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($input, $entry): array {
            abort_unless($entry ? $current->canDo('respite.stays.manage') : ($current->canDo('restraints.create') || $current->canDo('restraints.manage')), 403);
            $values = $this->eventValues($input, true);
            $key = $values['idempotency_key'];
            $fingerprint = RestraintCommandValues::digest(['event', 'create', $current->id, $entry?->id, Arr::except($values, ['idempotency_key'])]);
            $accepted = $reads->query(RestraintCommandReceipt::query())->where('idempotency_key', $key)->first();
            if ($accepted) {
                if ($accepted->source_type !== 'event') {
                    throw ValidationException::withMessages(['idempotency_key' => 'This command key belongs to different saved work.']);
                }
                $row = RestraintEvent::withTrashed()->whereKey($accepted->source_id)->lockForUpdate()->firstOrFail();
                $accepted = RestraintCommandReceipt::whereKey($accepted->id)->lockForUpdate()->firstOrFail();
                $this->access->assertEvent($row, $current, $reads, $sites, $entry ? 'respite_capture' : 'capture');
                if ($row->related_incident_id) {
                    $this->connections->incidentForCapture($row, (int) $row->related_incident_id, $current, $reads, $sites, $entry ? 'respite_capture' : 'capture');
                }

                return $this->replay($accepted, $row, $current, 'create', $fingerprint, $reads);
            }
            $stay = null;
            if ($entry || ! empty($values['stay_id'])) {
                $request = clone request();
                $request->setUserResolver(fn () => $current);
                $stay = $entry ? $this->stays->resolveAuthorizedStay($request, (int) $entry->id, true, $reads) : $this->stays->resolveAuthorizedHealthSafetyStay($request, (int) $values['stay_id'], true, $reads);
                if ($entry && array_key_exists('stay_id', $values)) {
                    abort_unless($values['stay_id'] !== null && (int) $values['stay_id'] === (int) $stay->id, 404);
                }
                $this->stays->assertSubmittedClient($stay, $values['client_id'], null);
                if (array_key_exists('site_id', $values)) {
                    $this->stays->assertSubmittedSite($stay, $values['site_id'], null);
                }
                $client = $reads->query(Client::query())->findOrFail($stay->client_id);
                $siteId = $this->stays->siteId($stay);
            } else {
                $client = $reads->query(Client::query())->whereKey($values['client_id'])->lockForUpdate()->firstOrFail();
                $siteId = (int) $client->site_id;
                abort_unless($siteId > 0 && (! isset($values['site_id']) || (int) $values['site_id'] === $siteId), 404);
            }
            $this->access->assertClient($client, $current, $reads, $sites, $entry ? 'respite_capture' : 'capture');
            $row = new RestraintEvent;
            $row->forceFill([...Arr::except($values, ['idempotency_key', 'behaviour_support_plan_version_id']), 'client_id' => $client->id, 'site_id' => $siteId, 'stay_id' => $stay?->id, 'recorded_client_id' => $client->id, 'recorded_site_id' => $siteId, 'recorded_stay_id' => $stay?->id, 'source_schema_version' => 1, 'lock_version' => 1, 'source_revision' => 1, 'review_assignment_revision' => 0, 'creation_key' => $key, 'creation_fingerprint' => $fingerprint, 'created_by' => $current->id, 'updated_by' => $current->id]);
            $this->choices($row, $current, $reads, $sites, $values, null, $entry ? 'respite_capture' : 'capture');
            $this->resolvePlan($row, $current, $reads, $sites, $values);
            $row->reference_number = app(ReferenceNumberGenerator::class)->next(RestraintEvent::REFERENCE_PREFIX);
            $row->saveQuietly();
            $result = $this->confirm($row, $current, 'create', $key, $fingerprint, null, ['capture_entry' => $entry ? 'respite' : 'event']);
            $this->delivery->stageSource($row, $current, $entry ? 'respite_capture' : 'capture');

            return $result;
        }));
    }

    public function correct(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'correct', $input, fn ($values) => $this->eventValues($values, false), function ($row, $current, $reads, $sites, $values): array {
            $old = clone $row;
            foreach (['client_id', 'site_id', 'stay_id'] as $field) {
                if (array_key_exists($field, $values) && $values[$field] != $row->{$field}) {
                    throw ValidationException::withMessages([$field => 'A factual correction cannot transfer retained source history.']);
                }
            }
            $row->forceFill(Arr::except($values, ['reason', 'idempotency_key', 'expected_version', 'behaviour_support_plan_version_id']));
            $this->choices($row, $current, $reads, $sites, $values, $old);
            if (array_intersect(array_keys($values), ['started_at', 'within_support_plan', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id'])) {
                $this->resolvePlan($row, $current, $reads, $sites, $values);
            }
            $row->source_revision++;

            return ['reason' => $values['reason'], 'before' => $old->attributesToArray(), 'after' => $row->attributesToArray()];
        });
    }

    public function review(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'review', $input, function ($input): array {
            $v = RestraintCommandValues::validate($input, ['reviewed_by' => ['nullable', 'integer', 'min:1'], 'reviewed_at' => ['required', 'string'], 'review_notes' => ['nullable', 'string', 'max:20000'], 'lessons_learned' => ['nullable', 'string', 'max:20000'], 'severity' => ['sometimes', 'required', Rule::in(['low', 'medium', 'high', 'critical'])], 'post_incident_support' => ['nullable', 'string', 'max:20000'], 'evidence_ids' => ['nullable', 'array'], 'evidence_ids.*' => ['integer', 'min:1', 'distinct'], 'reason' => ['nullable', 'string', 'max:2000']]);
            $v['reviewed_at'] = RestraintCommandValues::instant($v['reviewed_at'], 'reviewed_at');

            return $v;
        }, function ($row, $current, $reads, $sites, $v): array {
            $reviewer = isset($v['reviewed_by']) ? $this->access->assertStaffId((int) $v['reviewed_by'], $current, $reads, $sites, 'reviewer', $row) : $current;
            if ($row->reviewed_at && blank($v['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'Record why this review evidence is being corrected.']);
            }
            $this->evidence($row, $v['evidence_ids'] ?? []);
            $changes = Arr::only($v, ['severity', 'post_incident_support']);
            if (array_filter($changes, fn ($value, $field) => $value !== $row->{$field}, ARRAY_FILTER_USE_BOTH)) {
                $row->source_revision++;
            }
            $row->forceFill([...$changes, ...Arr::only($v, ['review_notes', 'lessons_learned'])]);
            $previous = RestraintEventReview::where('restraint_event_id', $row->id)->whereNotNull('occurred_at')->orderByDesc('id')->lockForUpdate()->first();
            $history = RestraintEventReview::create([...$this->fact($row, $current, $reviewer, $v['reviewed_at'], $v, $v['idempotency_key']), 'supersedes_id' => $previous?->id])->persisted();
            if (! $row->reviewed_at) {
                $row->reviewed_by = $reviewer->id;
                $row->reviewed_at = $v['reviewed_at'];
            }
            $row->reviewed_source_revision = $row->source_revision;

            return ['review_id' => $history->id, 'supersedes_id' => $previous?->id, 'actual_reviewer_id' => $reviewer->id, 'reviewed_source_revision' => $row->source_revision];
        });
    }

    public function assignReview(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'assign_review', $input, fn ($v) => RestraintCommandValues::validate($v, ['review_owner_id' => ['nullable', 'integer', 'min:1'], 'review_due_at' => ['nullable', 'string'], 'reason' => ['required', 'string', 'max:2000']]), function ($row, $current, $reads, $sites, $v): array {
            $owner = isset($v['review_owner_id']) ? $this->recipient((int) $v['review_owner_id'], $row, 'review') : null;
            $old = $row->only(['review_owner_id', 'review_due_at', 'review_assignment_revision']);
            $row->review_owner_id = $owner?->id;
            $row->review_due_at = RestraintCommandValues::instant($v['review_due_at'] ?? null, 'review_due_at', false);
            $row->review_assignment_revision++;
            RestraintEventReview::create($this->fact($row, $current, null, null, ['kind' => 'ownership', 'before' => $old, 'owner_id' => $owner?->id, 'due_at' => $row->review_due_at?->toIso8601String(), 'assignment_revision' => $row->review_assignment_revision, 'reason' => $v['reason']], $v['idempotency_key']))->persisted();

            return ['review_owner_id' => $owner?->id, 'assignment_revision' => $row->review_assignment_revision];
        });
    }

    public function linkIncident(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'link_incident', $input, fn ($v) => RestraintCommandValues::validate($v, ['related_incident_id' => ['required', 'integer', 'min:1']]), function ($row, $current, $reads, $sites, $v): array {
            if ($row->related_incident_id) {
                throw ValidationException::withMessages(['related_incident_id' => 'Unlink the existing incident explicitly before selecting another.']);
            }
            $incident = $this->connections->incidentForLink($row, (int) $v['related_incident_id'], $current, $reads);
            $row->related_incident_id = $incident->id;

            return ['incident_id' => $incident->id];
        });
    }

    public function unlinkIncident(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'unlink_incident', $input, fn ($v) => RestraintCommandValues::validate($v, ['reason' => ['required', 'string', 'max:2000']]), function ($row, $current, $reads, $sites, $v): array {
            if (! $row->related_incident_id) {
                throw ValidationException::withMessages(['incident' => 'This source has no incident link to remove.']);
            }
            $old = $row->related_incident_id;
            $row->related_incident_id = null;

            return ['unlinked_incident_id' => $old, 'reason' => $v['reason']];
        });
    }

    public function addObservation(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->observation($event, $actor, $input, false);
    }

    public function correctObservation(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->observation($event, $actor, $input, true);
    }

    private function observation(RestraintEvent $event, User $actor, array $input, bool $correction): array
    {
        return $this->command($event, $actor, $correction ? 'correct_observation' : 'add_observation', $input, function ($v) use ($correction): array {
            $rules = ['observed_by' => ['nullable', 'integer', 'min:1'], 'observed_at' => ['required', 'string'], 'condition' => ['required', 'string', 'max:10000'], 'response' => ['nullable', 'string', 'max:10000'], 'next_check_at' => ['nullable', 'string'], 'evidence_ids' => ['nullable', 'array'], 'evidence_ids.*' => ['integer', 'min:1', 'distinct']];
            if ($correction) {
                $rules += ['observation_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']];
            }
            $v = RestraintCommandValues::validate($v, $rules);
            $v['observed_at'] = RestraintCommandValues::instant($v['observed_at'], 'observed_at');
            $v['next_check_at'] = RestraintCommandValues::instant($v['next_check_at'] ?? null, 'next_check_at', false);

            return $v;
        }, function ($row, $current, $reads, $sites, $v) use ($correction): array {
            $old = $correction ? RestraintMonitoringObservation::where('restraint_event_id', $row->id)->whereKey($v['observation_id'])->lockForUpdate()->firstOrFail() : null;
            if ($old && RestraintMonitoringObservation::where('supersedes_id', $old->id)->exists()) {
                throw ValidationException::withMessages(['observation_id' => 'Correct the latest observation evidence.']);
            }
            $retainObserver = $old !== null && ! isset($v['observed_by']);
            $observer = $retainObserver ? null : (isset($v['observed_by']) ? $this->access->assertStaffId((int) $v['observed_by'], $current, $reads, $sites, 'participant', $row) : $current);
            $this->evidence($row, $v['evidence_ids'] ?? []);
            $facts = $retainObserver ? [...$v, 'observed_by' => $old->actual_actor_id] : $v;
            $attributes = $this->fact($row, $current, $observer, $v['observed_at'], $facts, $v['idempotency_key']);
            if ($retainObserver) {
                // Retained factual attribution does not assert current staff eligibility or refresh historical identity.
                $attributes['actual_actor_id'] = $old->actual_actor_id;
                $attributes['actual_actor_snapshot'] = $old->actual_actor_snapshot;
            }
            $fact = RestraintMonitoringObservation::create([...$attributes, 'supersedes_id' => $old?->id])->persisted();

            return ['observation_id' => $fact->id, 'supersedes_id' => $old?->id];
        });
    }

    public function recordDebrief(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->debrief($event, $actor, $input, false);
    }

    public function correctDebrief(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->debrief($event, $actor, $input, true);
    }

    private function debrief(RestraintEvent $event, User $actor, array $input, bool $correction): array
    {
        return $this->command($event, $actor, $correction ? 'correct_debrief' : 'record_debrief', $input, function ($v) use ($correction): array {
            $rules = ['status' => ['required', Rule::in(['completed', 'not_yet_done', 'declined', 'not_required'])], 'occurred_at' => ['nullable', 'string'], 'actual_participants' => ['nullable', 'array'], 'actual_participants.*' => ['string', 'max:255'], 'person_voice' => ['nullable', 'string', 'max:10000'], 'communication_support' => ['nullable', 'string', 'max:5000'], 'advocacy_support' => ['nullable', 'string', 'max:5000'], 'cultural_preferences' => ['nullable', 'string', 'max:5000'], 'contact_outcome' => ['nullable', 'string', 'max:10000'], 'learning' => ['nullable', 'string', 'max:10000'], 'reason' => ['nullable', 'string', 'max:2000'], 'evidence_ids' => ['nullable', 'array'], 'evidence_ids.*' => ['integer', 'min:1', 'distinct']];
            if ($correction) {
                $rules['debrief_id'] = ['required', 'integer', 'min:1'];
                $rules['reason'] = ['required', 'string', 'max:2000'];
            }
            $v = RestraintCommandValues::validate($v, $rules);
            if ($v['status'] === 'completed' && (blank($v['occurred_at'] ?? null) || blank($v['contact_outcome'] ?? null))) {
                throw ValidationException::withMessages(['contact_outcome' => 'Record the actual contact outcome and time.']);
            }
            if ($v['status'] !== 'completed' && blank($v['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'Record why this debrief has not occurred or is not required.']);
            }
            $v['occurred_at'] = RestraintCommandValues::instant($v['occurred_at'] ?? null, 'occurred_at');

            return $v;
        }, function ($row, $current, $reads, $sites, $v) use ($correction): array {
            $old = $correction ? RestraintDebrief::where('restraint_event_id', $row->id)->whereKey($v['debrief_id'])->lockForUpdate()->firstOrFail() : null;
            if ($old && RestraintDebrief::where('supersedes_id', $old->id)->exists()) {
                throw ValidationException::withMessages(['debrief_id' => 'Correct the latest debrief evidence.']);
            }
            $this->evidence($row, $v['evidence_ids'] ?? []);
            $fact = RestraintDebrief::create([...$this->fact($row, $current, null, $v['occurred_at'], $v, $v['idempotency_key']), 'supersedes_id' => $old?->id])->persisted();

            return ['debrief_id' => $fact->id, 'status' => $v['status'], 'supersedes_id' => $old?->id];
        });
    }

    public function addFollowup(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'add_followup', $input, fn ($v) => RestraintCommandValues::validate($v, ['notes' => ['required', 'string', 'max:10000'], 'assigned_to_user_id' => ['nullable', 'integer', 'min:1'], 'due_at' => ['nullable', 'string']]), function ($row, $current, $reads, $sites, $v): array {
            $owner = isset($v['assigned_to_user_id']) ? $this->recipient((int) $v['assigned_to_user_id'], $row, 'complete_followup') : null;
            $child = RestraintFollowup::create(['restraint_event_id' => $row->id, 'notes' => $v['notes'], 'created_by' => $current->id, 'creator_snapshot' => $this->actorSnapshot($current), 'assigned_to_user_id' => $owner?->id, 'assignment_revision' => 1, 'due_at' => RestraintCommandValues::instant($v['due_at'] ?? null, 'due_at', false)]);
            if (! $child->id || ! RestraintFollowup::whereKey($child->id)->exists()) {
                throw new LogicException('Named follow-up was not persisted.');
            }
            $this->followupHistory($row, $child, $current, $v, 'created');

            return ['followup_id' => $child->id, 'assignment_revision' => 1];
        });
    }

    public function reassignFollowup(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'reassign_followup', $input, fn ($v) => RestraintCommandValues::validate($v, ['followup_id' => ['required', 'integer', 'min:1'], 'assigned_to_user_id' => ['nullable', 'integer', 'min:1'], 'due_at' => ['nullable', 'string'], 'reason' => ['required', 'string', 'max:2000']]), function ($row, $current, $reads, $sites, $v): array {
            $child = $this->followup($row, (int) $v['followup_id']);
            $this->openFollowup($child);
            $owner = isset($v['assigned_to_user_id']) ? $this->recipient((int) $v['assigned_to_user_id'], $row, 'complete_followup') : null;
            $child->assigned_to_user_id = $owner?->id;
            $child->due_at = RestraintCommandValues::instant($v['due_at'] ?? null, 'due_at', false);
            $child->assignment_revision++;
            $child->save();
            $this->followupHistory($row, $child, $current, $v, 'reassigned');

            return ['followup_id' => $child->id, 'assignment_revision' => $child->assignment_revision];
        });
    }

    public function completeFollowup(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'complete_followup', $input, fn ($v) => $this->completionValues($v, false), function ($row, $current, $reads, $sites, $v): array {
            $child = $this->followup($row, (int) $v['followup_id']);
            $this->openFollowup($child);
            abort_unless((int) $child->assigned_to_user_id === (int) $current->id, 403);
            $this->evidence($row, $v['evidence_ids'] ?? []);
            $child->completed_at = $v['completed_at'];
            $child->completed_by_user_id = $current->id;
            $child->completion_outcome = Arr::only($v, ['outcome', 'notes']);
            $child->completion_evidence_ids = RestraintCommandValues::ids($v['evidence_ids'] ?? []);
            $child->save();
            $this->followupHistory($row, $child, $current, $v, 'completed');

            return ['followup_id' => $child->id, 'completed_by_user_id' => $current->id];
        });
    }

    public function correctFollowup(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'correct_followup', $input, fn ($v) => $this->completionValues($v, true), function ($row, $current, $reads, $sites, $v): array {
            $child = $this->followup($row, (int) $v['followup_id']);
            if (! $child->completed_at || $child->cancelled_at) {
                throw ValidationException::withMessages(['followup_id' => 'Only an actual completed outcome can be corrected.']);
            }
            $this->evidence($row, $v['evidence_ids'] ?? []);
            $child->completed_at = $v['completed_at'];
            $child->completion_outcome = Arr::only($v, ['outcome', 'notes']);
            $child->completion_evidence_ids = RestraintCommandValues::ids($v['evidence_ids'] ?? []);
            $child->save();
            $this->followupHistory($row, $child, $current, $v, 'corrected');

            return ['followup_id' => $child->id, 'completed_by_user_id' => $child->completed_by_user_id];
        });
    }

    public function cancelFollowup(RestraintEvent $event, User $actor, array $input): array
    {
        return $this->command($event, $actor, 'cancel_followup', $input, fn ($v) => RestraintCommandValues::validate($v, ['followup_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]), function ($row, $current, $reads, $sites, $v): array {
            $child = $this->followup($row, (int) $v['followup_id']);
            $this->openFollowup($child);
            $child->cancelled_at = now()->utc();
            $child->cancelled_by = $current->id;
            $child->cancellation_reason = $v['reason'];
            $child->save();
            $this->followupHistory($row, $child, $current, $v, 'cancelled');

            return ['followup_id' => $child->id];
        });
    }

    /** Internal evidence seam; request code never supplies the verb/normalizer/operation. */
    public function command(RestraintEvent $event, User $actor, string $verb, array $input, callable $normalize, callable $operation): array
    {
        return $this->transaction(function () use ($event, $actor, $verb, $input, $normalize, $operation): array {
            $row = RestraintEvent::withTrashed()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $verb, $input, $normalize, $operation): array {
                $op = match ($verb) {
                    'add_evidence' => 'capture','remove_evidence' => 'remove_evidence','complete_followup' => 'complete_followup',default => 'review'
                };
                $this->access->assertEvent($row, $current, $reads, $sites, $op);
                if ($verb === 'remove_evidence') {
                    abort_unless($current->canDo('restraints.manage'), 403);
                }
                $v = $normalize($input);
                $key = $v['idempotency_key'];
                if ($verb === 'link_incident') {
                    $this->connections->incidentForLink($row, (int) $v['related_incident_id'], $current, $reads);
                }
                if ($verb === 'complete_followup') {
                    $child = $this->followup($row, (int) $v['followup_id']);
                    abort_unless((int) $child->assigned_to_user_id === (int) $current->id, 403);
                }
                $fingerprint = RestraintCommandValues::digest(['event', $row->id, $current->id, $verb, $v['expected_version'], Arr::except($v, ['idempotency_key', 'expected_version'])]);
                $receipt = RestraintCommandReceipt::where('idempotency_key', $key)->lockForUpdate()->first();
                if ($receipt) {
                    return $this->replay($receipt, $row, $current, $verb, $fingerprint, $reads);
                }
                if ($row->trashed() || $row->lock_version !== $v['expected_version']) {
                    throw ValidationException::withMessages(['expected_version' => 'The source changed or is archived. Review current work; your entries were not saved.']);
                }
                $meta = $operation($row, $current, $reads, $sites, $v);
                $row->lock_version++;
                $row->updated_by = $current->id;
                $row->saveQuietly();
                if ((int) $row->fresh()->lock_version !== (int) $row->lock_version) {
                    throw new LogicException('Parent source revision was not persisted.');
                }
                $result = $this->confirm($row, $current, $verb, $key, $fingerprint, $v['expected_version'], $meta);
                if (in_array($verb, ['correct', 'review', 'link_incident', 'unlink_incident'], true)) {
                    $this->delivery->stageSource($row, $current);
                }
                if ($verb === 'assign_review') {
                    $this->delivery->stageReviewAssignment($row, $current);
                }
                if (in_array($verb, ['add_followup', 'reassign_followup'], true)) {
                    $this->delivery->stageFollowupAssignment($row, $this->followup($row, $meta['followup_id']), $current);
                }

                return $result;
            });
        });
    }

    public function confirmationForViewer(User $viewer, mixed $candidate): ?array
    {
        if (! is_array($candidate) || ! is_int($candidate['event_id'] ?? null) || ! is_string($candidate['idempotency_key'] ?? null) || ! Str::isUuid($candidate['idempotency_key'])) {
            return null;
        }
        try {
            return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($candidate): ?array {
                $row = RestraintEvent::withTrashed()->find($candidate['event_id']);
                $receipt = RestraintCommandReceipt::where('source_type', 'event')->where('source_id', $candidate['event_id'])->where('user_id', $current->id)->where('idempotency_key', strtolower($candidate['idempotency_key']))->first();
                if (! $row || ! $receipt) {
                    return null;
                }
                $confirmation = $this->receiptConfirmation($receipt, $row, $current, $reads);
                if ($confirmation === null) {
                    return null;
                }
                $op = $receipt->command === 'create' ? (($receipt->accepted_result['capture_entry'] ?? null) === 'respite' ? 'respite_capture' : 'capture') : ($receipt->command === 'complete_followup' ? 'complete_followup' : ($receipt->command === 'remove_evidence' ? 'remove_evidence' : ($receipt->command === 'add_evidence' ? 'capture' : 'review')));
                $this->access->assertEvent($row, $current, $reads, $sites, $op);
                if ($receipt->command === 'create' && $row->related_incident_id) {
                    $this->connections->incidentForCapture($row, (int) $row->related_incident_id, $current, $reads, $sites, $op);
                }
                if ($receipt->command === 'link_incident') {
                    $this->connections->incidentForLink($row, (int) ($receipt->accepted_result['incident_id'] ?? 0), $current, $reads);
                }
                if ($receipt->command === 'complete_followup') {
                    $child = $this->followup($row, $receipt->accepted_result['followup_id']);
                    abort_unless((int) $child->assigned_to_user_id === (int) $current->id, 403);
                }

                return [...$confirmation, 'replayed' => false];
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return null;
            } throw $error;
        }
    }

    /** Reconstruct acceptance from immutable stored identity and its exact attributable audit. */
    private function receiptConfirmation(RestraintCommandReceipt $receipt, RestraintEvent $source, User $current, CurrentAuthorizationReads $reads): ?array
    {
        $result = $receipt->accepted_result;
        $commands = ['create', 'correct', 'review', 'assign_review', 'link_incident', 'unlink_incident',
            'add_observation', 'correct_observation', 'record_debrief', 'correct_debrief', 'add_followup',
            'reassign_followup', 'complete_followup', 'correct_followup', 'cancel_followup', 'add_evidence', 'remove_evidence'];
        if (! is_array($result) || ! in_array($receipt->command, $commands, true)
            || $receipt->source_type !== 'event' || (int) $receipt->source_id !== (int) $source->id || (int) $receipt->user_id !== (int) $current->id
            || ! is_string($receipt->idempotency_key) || ! Str::isUuid($receipt->idempotency_key)
            || $receipt->idempotency_key !== strtolower($receipt->idempotency_key)
            || ($result['accepted'] ?? null) !== true || ($result['event_id'] ?? null) !== (int) $source->id
            || ($result['command'] ?? null) !== $receipt->command || ($result['idempotency_key'] ?? null) !== $receipt->idempotency_key
            || ! is_int($result['version'] ?? null) || ! is_int($result['source_revision'] ?? null)
            || $result['source_revision'] < 1 || $result['version'] < 1
            || $result['source_revision'] > (int) $source->source_revision || $result['version'] > (int) $source->lock_version
            || ($receipt->command === 'create' ? ($receipt->expected_version !== null || $result['version'] !== 1)
                : ($receipt->expected_version === null || $receipt->expected_version < 1 || $result['version'] !== $receipt->expected_version + 1))
            || ! is_string($result['recorded_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/D', $result['recorded_at'])) {
            return null;
        }
        try {
            RestraintCommandValues::instant($result['recorded_at'], 'recorded_at');
        } catch (ValidationException) {
            return null;
        }
        $audit = $reads->query(AuditLog::query())->find($receipt->audit_log_id);
        if (! $audit || (int) $audit->user_id !== (int) $current->id || $audit->auditable_type !== $source->getMorphClass()
            || (int) $audit->auditable_id !== (int) $source->id || $audit->action !== 'restraints.event.'.$receipt->command
            || ($audit->meta['actor_id'] ?? null) !== (int) $current->id || ($audit->meta['idempotency_key'] ?? null) !== $receipt->idempotency_key
            || ($audit->meta['source_revision'] ?? null) !== $result['source_revision']
            || ($audit->meta['snapshot']['lock_version'] ?? null) !== $result['version']
            || ($audit->meta['snapshot']['source_revision'] ?? null) !== $result['source_revision']) {
            return null;
        }
        $keys = ['review_id', 'observation_id', 'debrief_id', 'followup_id', 'attachment_id', 'incident_id',
            'assignment_revision', 'review_owner_id', 'reviewed_source_revision', 'completed_by_user_id', 'supersedes_id', 'status', 'capture_entry'];
        foreach ($keys as $key) {
            $saved = array_key_exists($key, $result);
            $audited = array_key_exists($key, $audit->meta['result'] ?? []);
            if ($saved !== $audited || ($saved && $result[$key] !== $audit->meta['result'][$key])) {
                return null;
            }
        }

        return Arr::only($result, ['accepted', 'event_id', 'idempotency_key', 'command', 'version', 'source_revision', 'recorded_at', ...$keys]);
    }

    private function confirm(RestraintEvent $row, User $current, string $verb, string $key, string $fingerprint, ?int $expected, array $meta): array
    {
        $request = clone request();
        $request->setUserResolver(fn () => $current);
        $event = 'restraints.event.'.$verb;
        AuditLogger::logOrFail($event, $row, ['actor_id' => $current->id, 'idempotency_key' => $key, 'source_revision' => $row->source_revision, 'snapshot' => $row->attributesToArray(), 'result' => $meta], $request);
        $audits = AuditLog::where('user_id', $current->id)->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', $event)->where('meta->idempotency_key', $key)->lockForUpdate()->get();
        if ($audits->count() !== 1) {
            throw new LogicException('An accepted restraint command requires exactly one attributable audit.');
        }
        $safe = Arr::only($meta, ['review_id', 'observation_id', 'debrief_id', 'followup_id', 'attachment_id', 'incident_id', 'assignment_revision', 'review_owner_id', 'reviewed_source_revision', 'completed_by_user_id', 'supersedes_id', 'status', 'capture_entry']);
        $result = ['accepted' => true, 'event_id' => (int) $row->id, 'idempotency_key' => $key, 'command' => $verb, 'version' => (int) $row->lock_version, 'source_revision' => (int) $row->source_revision, 'recorded_at' => now()->utc()->toIso8601String(), ...$safe];
        RestraintCommandReceipt::create(['source_type' => 'event', 'source_id' => $row->id, 'user_id' => $current->id, 'actor_snapshot' => $this->actorSnapshot($current), 'audit_log_id' => $audits->sole()->id, 'command' => $verb, 'expected_version' => $expected, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'accepted_result' => $result, 'created_at' => now()->utc()])->persisted();

        return [...$result, 'replayed' => false];
    }

    private function replay(RestraintCommandReceipt $receipt, RestraintEvent $row, User $actor, string $verb, string $fingerprint, CurrentAuthorizationReads $reads): array
    {
        if ($receipt->source_type !== 'event' || (int) $receipt->source_id !== (int) $row->id || (int) $receipt->user_id !== (int) $actor->id || $receipt->command !== $verb || ! hash_equals($receipt->fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This command key belongs to different saved work.']);
        }

        $confirmation = $this->receiptConfirmation($receipt, $row, $actor, $reads);
        if ($confirmation === null) {
            throw ValidationException::withMessages(['idempotency_key' => 'The saved acceptance evidence is unavailable. This retry was not accepted.']);
        }

        return [...$confirmation, 'replayed' => true];
    }

    private function eventValues(array $input, bool $create): array
    {
        $needed = $create ? 'required' : 'sometimes';
        $rules = ['client_id' => [$needed, 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'], 'stay_id' => ['nullable', 'integer', 'min:1'], 'behaviour_support_plan_id' => ['nullable', 'integer', 'min:1'], 'behaviour_support_plan_version_id' => ['nullable', 'integer', 'min:1'], 'started_at' => [$needed, 'string'], 'ended_at' => ['nullable', 'string'], 'duration_minutes' => ['nullable', 'integer', 'min:0'], 'restraint_type' => [$needed, Rule::in(['physical', 'chemical', 'mechanical', 'seclusion', 'environmental'])], 'severity' => [$needed, Rule::in(['low', 'medium', 'high', 'critical'])], 'trigger_description' => [$needed, 'string', 'max:20000'], 'de_escalation_attempted' => [$needed, 'string', 'max:20000'], 'restraint_description' => [$needed, 'string', 'max:20000'], 'staff_involved' => ['nullable', 'array'], 'staff_involved.*' => ['integer', 'min:1', 'distinct'], 'person_response' => ['nullable', 'string', 'max:20000'], 'post_incident_support' => ['nullable', 'string', 'max:20000'], 'injury_occurred' => ['sometimes', 'boolean'], 'injury_details' => ['nullable', 'string', 'max:20000'], 'within_support_plan' => ['sometimes', 'boolean'], 'deviation_reason' => ['nullable', 'string', 'max:20000'], 'authorised_by' => ['nullable', 'integer', 'min:1']];
        if ($create) {
            $rules['related_incident_id'] = ['nullable', 'integer', 'min:1'];
        } else {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        $v = RestraintCommandValues::validate($input, $rules, $create);
        if ($create) {
            $v += ['injury_occurred' => false, 'within_support_plan' => true, 'staff_involved' => []];
        }
        foreach (['injury_occurred', 'within_support_plan'] as $field) {
            if (array_key_exists($field, $v)) {
                $v[$field] = (bool) $v[$field];
            }
        }
        foreach (['client_id', 'site_id', 'stay_id', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id', 'authorised_by', 'related_incident_id', 'duration_minutes'] as $field) {
            if (isset($v[$field])) {
                $v[$field] = (int) $v[$field];
            }
        }
        if (array_key_exists('staff_involved', $v)) {
            $v['staff_involved'] = RestraintCommandValues::ids($v['staff_involved'] ?? []);
        }
        foreach (['started_at', 'ended_at'] as $field) {
            if (array_key_exists($field, $v)) {
                $v[$field] = RestraintCommandValues::instant($v[$field], $field);
            }
        }

        return $v;
    }

    private function choices(RestraintEvent $row, User $current, CurrentAuthorizationReads $reads, array $sites, array $v, ?RestraintEvent $old, string $captureOperation = 'capture'): void
    {
        if ($row->ended_at) {
            if ($row->ended_at->lte($row->started_at)) {
                throw ValidationException::withMessages(['ended_at' => 'End must follow actual start.']);
            }
            $minutes = (int) floor($row->started_at->diffInSeconds($row->ended_at) / 60);
            if (isset($v['duration_minutes']) && $v['duration_minutes'] !== $minutes) {
                throw ValidationException::withMessages(['duration_minutes' => 'Duration must equal elapsed whole minutes, rounded down from actual instants.']);
            } $row->duration_minutes = $minutes;
        } else {
            if (isset($v['duration_minutes'])) {
                throw ValidationException::withMessages(['duration_minutes' => 'Ongoing events have no completed elapsed duration.']);
            } $row->duration_minutes = null;
        }
        if ($row->injury_occurred && blank($row->injury_details)) {
            throw ValidationException::withMessages(['injury_details' => 'Record the factual injury details.']);
        }
        if (! $row->within_support_plan && blank($row->deviation_reason)) {
            throw ValidationException::withMessages(['deviation_reason' => 'Record the factual out-of-plan explanation.']);
        }
        $staff = is_array($row->staff_involved) ? $row->staff_involved : ($row->staff_involved === null ? [] : [$row->staff_involved]);
        $priorStaff = is_array($old?->staff_involved) ? $old->staff_involved : ($old?->staff_involved === null ? [] : [$old->staff_involved]);
        foreach (array_diff($staff, $priorStaff) as $id) {
            $this->access->assertStaffId((int) $id, $current, $reads, $sites, 'participant', $row);
        }
        if ($row->authorised_by && (! $old || $row->authorised_by !== $old->authorised_by)) {
            $this->access->assertStaffId((int) $row->authorised_by, $current, $reads, $sites, 'participant', $row);
        }
        if ($row->related_incident_id && ! $old) {
            $this->connections->incidentForCapture($row, (int) $row->related_incident_id, $current, $reads, $sites, $captureOperation);
        }
    }

    private function resolvePlan(RestraintEvent $row, User $current, CurrentAuthorizationReads $reads, array $sites, array $v): void
    {
        $client = $reads->query(Client::query())->findOrFail($row->client_id);
        $resolved = $this->plans->resolveForEvent($client, (int) $row->site_id, $row->stay_id, $row->started_at, (bool) $row->within_support_plan, array_key_exists('behaviour_support_plan_id', $v) ? $v['behaviour_support_plan_id'] : $row->behaviour_support_plan_id, array_key_exists('behaviour_support_plan_version_id', $v) ? $v['behaviour_support_plan_version_id'] : $row->behaviour_support_plan_version_id, $current, $reads, $sites);
        $row->behaviour_support_plan_id = $resolved['plan_id'];
        $row->behaviour_support_plan_version_id = $resolved['plan_version_id'];
        $row->plan_authorization_state = $resolved['authorization_state'];
        $row->plan_authorization_snapshot = $resolved;
    }

    public function evidence(RestraintEvent $row, array $ids): void
    {
        $ids = RestraintCommandValues::ids($ids);
        if ($ids && RestraintEventAttachment::where('restraint_event_id', $row->id)->whereIn('id', $ids)->whereNull('removed_at')->where('disk', 'private')->lock('for share nowait')->count() !== count($ids)) {
            abort(404);
        }
    }

    private function recipient(int $id, RestraintEvent $row, string $operation): User
    {
        $candidate = User::findOrFail($id);

        return $this->access->withCurrentViewer($candidate, function ($current, $reads, $sites) use ($row, $operation): User {
            $this->access->eligibleStaff((int) $current->id, (int) $row->site_id, $reads);
            $this->access->assertEvent($row, $current, $reads, $sites, $operation);

            return $current;
        });
    }

    private function completionValues(array $input, bool $correction): array
    {
        $rules = ['followup_id' => ['required', 'integer', 'min:1'], 'completed_at' => ['required', 'string'], 'outcome' => ['required', Rule::in(['recorded', 'contacted', 'attempted', 'unable_to_contact', 'declined', 'not_required', 'other'])], 'notes' => ['required', 'string', 'max:10000'], 'evidence_ids' => ['nullable', 'array'], 'evidence_ids.*' => ['integer', 'min:1', 'distinct']];
        if ($correction) {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        $v = RestraintCommandValues::validate($input, $rules);
        $v['completed_at'] = RestraintCommandValues::instant($v['completed_at'], 'completed_at');

        return $v;
    }

    private function followup(RestraintEvent $row, int $id): RestraintFollowup
    {
        return RestraintFollowup::where('restraint_event_id', $row->id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function openFollowup(RestraintFollowup $child): void
    {
        if ($child->completed_at || $child->cancelled_at) {
            throw ValidationException::withMessages(['followup_id' => 'This follow-up already has a retained outcome.']);
        }
    }

    private function followupHistory(RestraintEvent $row, RestraintFollowup $child, User $current, array $v, string $kind): void
    {
        RestraintFollowupHistory::create([...$this->fact($row, $current, null, $child->completed_at?->format('Y-m-d H:i:s.u'), ['kind' => $kind, 'command_values' => Arr::except($v, ['idempotency_key', 'expected_version']), 'followup' => $child->toArray(), 'retained_completed_by_user_id' => $child->completed_by_user_id], $v['idempotency_key']), 'followup_id' => $child->id])->persisted();
    }

    private function fact(RestraintEvent $row, User $current, ?User $actual, ?string $instant, array $facts, string $key): array
    {
        return ['restraint_event_id' => $row->id, 'source_revision' => $row->source_revision, 'recorder_id' => $current->id, 'actor_snapshot' => $this->actorSnapshot($current), 'actual_actor_id' => $actual?->id, 'actual_actor_snapshot' => $actual ? $this->actorSnapshot($actual) : null, 'occurred_at' => $instant, 'facts' => Arr::except($facts, ['idempotency_key', 'expected_version']), 'evidence_ids' => RestraintCommandValues::ids($facts['evidence_ids'] ?? []), 'command_key' => $key];
    }

    public function actorSnapshot(User $actor): array
    {
        return ['id' => (int) $actor->id, 'name' => $actor->name, 'legacy_role' => $actor->role, 'roles' => $actor->roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->values()->all()];
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (\Illuminate\Database\DeadlockException) {
                // Inside a transaction Laravel raises a deadlock (1213) as DeadlockException, not QueryException.
                throw ValidationException::withMessages(['expected_version' => 'Source or authority changed concurrently. Review current work; nothing was partially saved.']);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_version' => 'Source or authority changed concurrently. Review current work; nothing was partially saved.']);
            } throw $error;
        }
    }
}
