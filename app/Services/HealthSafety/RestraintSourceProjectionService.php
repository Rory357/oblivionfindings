<?php

namespace App\Services\HealthSafety;

use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit source DTOs; no query, authority, directory record or private command metadata escapes. */
class RestraintSourceProjectionService
{
    private const EVENT_FIELDS = ['id', 'reference_number', 'client_id', 'site_id', 'lock_version', 'source_revision', 'started_at', 'ended_at', 'duration_minutes', 'restraint_type', 'severity', 'trigger_description', 'de_escalation_attempted', 'restraint_description', 'person_response', 'post_incident_support', 'injury_occurred', 'injury_details', 'within_support_plan', 'deviation_reason', 'reviewed_at', 'review_notes', 'lessons_learned', 'review_due_at', 'review_assignment_revision', 'reviewed_source_revision', 'created_at', 'updated_at'];

    private const FACT_FIELDS = ['kind', 'reviewed_at', 'review_notes', 'lessons_learned', 'severity', 'post_incident_support', 'reason', 'due_at', 'assignment_revision', 'observed_at', 'condition', 'response', 'next_check_at', 'status', 'occurred_at', 'actual_participants', 'person_voice', 'communication_support', 'advocacy_support', 'cultural_preferences', 'contact_outcome', 'learning', 'outcome', 'notes', 'completed_at', 'prior_values'];

    private const FOLLOWUP_FIELDS = ['id', 'notes', 'assignment_revision', 'due_at', 'completed_at', 'completion_outcome', 'completion_evidence_ids', 'cancelled_at', 'cancellation_reason', 'created_at', 'updated_at'];

    public function __construct(private readonly RestraintAccessService $access, private readonly RestraintConnectionsService $connections) {}

    public function event(RestraintEvent $event, User $viewer, bool $reviewWorkspace = false): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($event, $reviewWorkspace): array {
            $row = $reads->query(RestraintEvent::query())->findOrFail($event->id);
            $this->access->assertEvent($row, $current, $reads, $sites, $reviewWorkspace ? 'review' : 'view');
            // Scalar labels are reused only inside this one current-authority callback.
            $labels = [];
            $staff = function ($id, ?array $snapshot = null) use ($row, $reads, &$labels): ?array {
                if (! $id) {
                    return null;
                }
                $key = (int) $id;
                if (! array_key_exists($key, $labels)) {
                    try {
                        $person = $this->access->eligibleStaff($key, (int) $row->site_id, $reads);
                        $labels[$key] = ['id' => (int) $person->id, 'name' => $person->name, 'visibility' => 'current'];
                    } catch (HttpException $error) {
                        if (! in_array($error->getStatusCode(), [403, 404], true)) {
                            throw $error;
                        }
                        $labels[$key] = ['id' => null, 'name' => null, 'visibility' => 'identity_retained'];
                    }
                }
                $label = $labels[$key];
                if ($label['visibility'] === 'current' && is_string($snapshot['name'] ?? null)) {
                    $label['recorded_name'] = $snapshot['name'];
                }

                return $label;
            };
            $links = $this->connections->project($row, $current, $reads, $sites);
            $facts = $this->attributes($row, self::EVENT_FIELDS);
            $client = $row->getRelation('client');
            $site = $row->site_id === null ? null : $reads->query(Site::query())->findOrFail($row->site_id);
            $facts['client'] = ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)];
            $facts['site'] = $site ? ['id' => (int) $site->id, 'name' => $site->name] : null;
            $facts['needs_review'] = $row->needsReview();
            $facts['source_provenance'] = $row->source_schema_version === 1 ? 'recorded' : 'legacy_uncertainty';
            $facts['plan_authorization'] = ['state' => $row->plan_authorization_state ?? 'legacy_unknown', 'explanation' => $row->plan_authorization_snapshot['explanation'] ?? null];
            $facts['staff_involved'] = $this->staffMembers($row->staff_involved, $staff);
            foreach (['authorised_by', 'reviewed_by', 'review_owner_id', 'created_by', 'updated_by'] as $field) {
                $facts[$field] = $staff($row->{$field});
            }
            $facts['reviews'] = $row->reviews()->orderBy('id')->get()->map(fn ($fact) => $this->history($fact, $staff))->all();
            $facts['observations'] = $row->observations()->orderBy('id')->get()->map(fn ($fact) => $this->history($fact, $staff))->all();
            $facts['debriefs'] = $row->debriefs()->orderBy('id')->get()->map(fn ($fact) => $this->history($fact, $staff))->all();
            $facts['followups'] = $reads->query($row->followups()->getQuery())->with('history')->orderBy('id')->get()->map(function ($child) use ($staff, $row, $current, $reads, $sites): array {
                $dto = $this->followup($child->attributesToArray(), $staff);
                $open = ! $child->completed_at && ! $child->cancelled_at;
                $canReview = $current->canDo('restraints.review') || $current->canDo('restraints.manage');
                $canComplete = false;
                if ($open && (int) $child->assigned_to_user_id === (int) $current->id) {
                    $this->access->assertEvent($row, $current, $reads, $sites, 'complete_followup');
                    $canComplete = true;
                }
                $dto['can'] = ['complete' => $canComplete, 'reassign' => $open && $canReview, 'cancel' => $open && $canReview, 'correct' => (bool) $child->completed_at && ! $child->cancelled_at && $canReview];
                $dto['history'] = $child->history->sortBy('id')->map(fn ($fact) => $this->history($fact, $staff))->values()->all();

                return $dto;
            })->all();
            $facts['attachments'] = $row->attachments()->orderBy('id')->get()->map(function ($a) use ($staff): array {
                $available = ! $a->removed_at && $a->disk === 'private' && is_string($a->path) && str_starts_with($a->path, 'restraint_attachments/') && ! str_contains($a->path, '..') && Storage::disk('private')->exists($a->path);

                return [...$this->attributes($a, ['id', 'original_name', 'mime', 'mime_type', 'size', 'category', 'notes', 'sha256', 'removed_at', 'removal_reason', 'created_at']), 'uploader' => $staff($a->uploaded_by, $a->uploader_snapshot), 'removed_by' => $staff($a->removed_by), 'availability' => $a->removed_at ? 'removed' : ($available ? 'available' : 'unavailable'), 'download_url' => $available ? $a->url() : null];
            })->all();
            $facts['deliveries'] = $row->deliveries()->orderBy('created_at')->get()->map(fn ($d) => $this->attributes($d, ['purpose', 'status', 'failure_code', 'attempts', 'next_attempt_at', 'delivered_at', 'created_at']))->all();

            return ['event' => $facts, 'connections' => $links, 'can' => ['review' => $current->canDo('restraints.review') || $current->canDo('restraints.manage'), 'manage' => $current->canDo('restraints.manage'), 'capture' => $current->canDo('restraints.create') || $current->canDo('restraints.manage')]];
        });
    }

    private function history(Model $fact, callable $staff): array
    {
        $dto = $this->attributes($fact, ['id', 'source_revision', 'supersedes_id', 'occurred_at', 'created_at', 'evidence_ids']);
        $dto['recorder'] = $staff($fact->recorder_id, $fact->actor_snapshot);
        $dto['actual_actor'] = $staff($fact->actual_actor_id, $fact->actual_actor_snapshot);
        $dto['facts'] = Arr::only($fact->facts ?? [], self::FACT_FIELDS);
        foreach (['reviewed_by', 'observed_by', 'owner_id'] as $field) {
            if (array_key_exists($field, $fact->facts ?? [])) {
                $dto['facts'][$field] = $staff($fact->facts[$field]);
            }
        }
        if (is_array($fact->facts['before'] ?? null)) {
            $before = $fact->facts['before'];
            $dto['facts']['before'] = Arr::only($before, ['review_due_at', 'review_assignment_revision']);
            $dto['facts']['before']['review_owner'] = $staff($before['review_owner_id'] ?? null);
        }
        if (is_array($fact->facts['followup'] ?? null)) {
            $dto['facts']['followup'] = $this->followup($fact->facts['followup'], $staff);
        }
        if (is_array($fact->facts['command_values'] ?? null)) {
            $dto['facts']['command_values'] = Arr::only($fact->facts['command_values'], self::FACT_FIELDS);
        }
        if (is_array($fact->facts['observed_values'] ?? null)) {
            $dto['facts']['observed_values'] = Arr::only($fact->facts['observed_values'], array_diff(self::EVENT_FIELDS, ['client_id', 'site_id']));
            if (array_key_exists('staff_involved', $fact->facts['observed_values'])) {
                $dto['facts']['observed_values']['staff_involved'] = $this->staffMembers($fact->facts['observed_values']['staff_involved'], $staff);
            }
            if (array_key_exists('authorised_by', $fact->facts['observed_values'])) {
                $dto['facts']['observed_values']['authorised_by'] = $staff($fact->facts['observed_values']['authorised_by']);
            }
        }

        return $dto;
    }

    private function followup(array $facts, callable $staff): array
    {
        $dto = Arr::only($facts, self::FOLLOWUP_FIELDS);
        $dto['creator'] = $staff($facts['created_by'] ?? null, $facts['creator_snapshot'] ?? null);
        $dto['assignee'] = $staff($facts['assigned_to_user_id'] ?? null);
        $dto['completed_by'] = $staff($facts['completed_by_user_id'] ?? null);
        $dto['cancelled_by'] = $staff($facts['cancelled_by'] ?? null);

        return $dto;
    }

    private function staffMembers(mixed $recorded, callable $staff): array
    {
        $values = is_array($recorded) ? $recorded : ($recorded === null ? [] : [$recorded]);

        return array_map(function ($value) use ($staff): ?array {
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
                return $staff((int) $value);
            }
            if (is_string($value)) {
                return ['kind' => 'historical_text', 'text' => $value];
            }

            return ['kind' => 'historical_unknown', 'visibility' => 'fact_retained'];
        }, array_values($values));
    }

    private function attributes(Model $row, array $fields): array
    {
        return Arr::only($row->attributesToArray(), $fields);
    }
}
