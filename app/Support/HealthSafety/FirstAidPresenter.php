<?php

namespace App\Support\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidConnectionsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Factual source treatment and outcomes; no implied contact or clinical clearance. */
final class FirstAidPresenter
{
    private const TITLES = ['first_aid.created' => 'Treatment recorded', 'first_aid.updated' => 'Treatment record updated',
        'first_aid.archived' => 'Record archived', 'first_aid.incident_linked' => 'Incident linked', 'first_aid.followup_added' => 'Follow-up added',
        'first_aid.followup_updated' => 'Follow-up updated', 'first_aid.followup_completed' => 'Follow-up completion recorded',
        'first_aid.evidence_added' => 'Evidence added', 'first_aid.evidence_removed' => 'Evidence removed'];

    private const TREATMENT_TEXT = ['treated_person_type', 'treated_person_name', 'treatment_date', 'injury_illness_type', 'injury_illness_description', 'body_part', 'treatment_given', 'treatment_outcome', 'first_aider_notes'];

    public function __construct(private readonly FirstAidAccessService $access, private readonly FirstAidConnectionsService $connections) {}

    public function detail(FirstAidRecord $source, User $viewer, ?string $returnTo = null): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($source, $returnTo) {
            $record = $this->access->readableQuery($current, $reads, $sites, true)->findOrFail($source->id);
            $this->access->assertSource($record, $current, $reads, $sites);
            $record->load(['site:id,name', 'treatedPerson:id,name', 'firstAider:id,name', 'creator:id,name', 'updater:id,name', 'attachments.uploader:id,name', 'followups.assignedTo:id,name', 'followups.creator:id,name']);
            $can = $this->access->commandCan($record, $current);
            $client = $this->readableClient($record, $current, $reads);
            $connections = $this->connections->forViewer($record, $current, $returnTo);
            $retained = $record->followups->flatMap(fn ($row) => is_array($row->completion_evidence_ids) ? $row->completion_evidence_ids : [])->map(fn ($id) => (int) $id)->all();
            $attachments = $record->attachments->sortBy('id')->map(fn ($row) => $this->attachment($record, $row, ! $record->trashed() && (bool) ($can['remove_evidence'] ?? false) && ! in_array((int) $row->id, $retained, true)))->values()->all();
            $followups = $record->followups->sortBy('id')->map(fn ($row) => $this->followup($record, $row, $current, $attachments, $returnTo))->values()->all();
            $base = $this->base($record, $client, $returnTo);

            return [...$base, 'created_by_name' => $record->creator?->name, 'updated_by_name' => $record->updater?->name,
                'first_aider_evidence' => ['state' => $record->first_aider_id ? 'recorded' : 'not_recorded', 'provenance' => 'source_treatment_attribution'],
                'related_incident' => $connections['incident'], 'incident_link_state' => $connections['incident'] ? 'available' : ($record->related_incident_id ? 'unavailable' : 'not_recorded'),
                'followups' => $followups, 'attachments' => $attachments,
                'history' => $this->history($record, $current, $reads, $sites, $connections, $attachments, $followups),
                'source_delivery' => $this->sourceDelivery($record), 'connections' => $connections, 'can' => [...$can, 'manage' => ! $record->trashed() && $current->canDo('hazards.manage')]];
        });
    }

    public function row(FirstAidRecord $source, User $viewer, ?string $returnTo = null): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($source, $returnTo) {
            $record = $this->access->readableQuery($current, $reads, $sites, true)->findOrFail($source->id);
            $this->access->assertSource($record, $current, $reads, $sites);
            $record->load(['site:id,name', 'firstAider:id,name', 'treatedPerson:id,name']);
            $record->loadCount(['attachments', 'followups as open_followups_count' => fn ($query) => $query->whereNull('completed_at')]);
            $base = $this->base($record, $this->readableClient($record, $current, $reads), $returnTo);
            // Keep register/export factual scalar fields, without copying treatment narrative.
            unset($base['injury_illness_description'], $base['body_part'], $base['treatment_given'], $base['first_aider_notes']);

            return [...$base, 'site_name' => $record->site?->name, 'first_aider_name' => $record->firstAider?->name,
                'attachments_count' => (int) $record->attachments_count, 'open_followups_count' => (int) $record->open_followups_count,
                'can' => $this->access->commandCan($record, $current)];
        });
    }

    public function pickers(User $viewer, ?FirstAidRecord $record = null, ?int $siteId = null, ?int $clientId = null): array
    {
        $options = $this->access->pickerOptions($viewer, $record, $siteId, $clientId);
        $people = fn (array $rows) => collect($rows)->groupBy('id')->map(fn ($group) => ['id' => (int) $group->first()['id'], 'name' => $group->first()['name'],
            'site_ids' => $group->pluck('site_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()])->values()->all();

        return ['sites' => collect($options['sites'] ?? [])->map(fn ($row) => ['id' => (int) $row['id'], 'name' => $row['name']])->values()->all(),
            'staff' => $people($options['staff'] ?? []),
            'first_aiders' => array_map(fn ($row) => [...$row, 'eligibility_state' => 'current_hr_flag'], $people($options['first_aiders'] ?? [])),
            'followup_assignees' => $people($options['assignees'] ?? []),
            'clients' => collect($options['clients'] ?? [])->map(fn ($row) => ['id' => (int) $row['id'], 'name' => $row['name'], 'site_id' => (int) $row['site_id']])->values()->all(),
            'incidents' => collect($options['incidents'] ?? [])->map(fn ($row) => ['id' => (int) $row['id'], 'reference' => $row['reference'], 'title' => $row['title'] ?? null, 'label' => $row['label'] ?? ($row['reference'] ?: 'Incident '.$row['id']), 'status' => $row['status'] ?? null, 'href' => $row['href']])->values()->all(),
            'person_types' => $this->options(['staff', 'client', 'visitor', 'contractor']),
            'injury_types' => $this->options(['cut', 'burn', 'bruise', 'sprain', 'fracture', 'fall', 'head_injury', 'eye_injury', 'allergic_reaction', 'breathing_difficulty', 'chest_pain', 'seizure', 'fainting', 'nausea', 'sting', 'choking', 'other']),
            'outcomes' => $this->options(['returned_to_activity', 'sent_home', 'medical_centre', 'sent_to_hospital', 'ongoing_monitoring', 'refused_treatment', 'other']),
            'attachment_kinds' => $this->options(['photo', 'document', 'other'])];
    }

    /** Display only: legacy blank names never acquire an inferred account identity. */
    public static function recordedPersonLabel(?string $name): string
    {
        return $name !== null && trim($name) !== '' ? $name : 'Person not recorded';
    }

    private function base(FirstAidRecord $record, ?Client $client, ?string $returnTo): array
    {
        $clientSource = $record->treated_person_type === 'client';

        return ['id' => (int) $record->id, 'reference' => $record->reference_number ?: 'FA-'.$record->id,
            'lock_version' => (int) $record->lock_version, 'expected_version' => (int) $record->lock_version,
            'status' => $record->trashed() ? 'archived' : 'recorded', 'archived_at' => $this->instant($record->deleted_at),
            'created_at' => $this->instant($record->created_at), 'updated_at' => $this->instant($record->updated_at), 'href' => FirstAidReturn::href($record->id, $returnTo),
            'treated_person_type' => $record->treated_person_type,
            'treated_person_name' => $clientSource ? ($client ? self::recordedPersonLabel(trim($client->first_name.' '.$client->last_name)) : 'Person not available to view') : self::recordedPersonLabel($record->treated_person_name),
            'treated_person_id' => ! $clientSource && $record->treated_person_id ? (int) $record->treated_person_id : null,
            'client_id' => $client ? (int) $client->id : null, 'treated_person' => $clientSource ? null : $this->actor($record->treatedPerson),
            'client' => $client ? ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)] : null,
            'site_id' => $record->site_id ? (int) $record->site_id : null, 'site' => $record->site ? ['id' => (int) $record->site->id, 'name' => $record->site->name] : null,
            'treatment_date' => $this->instant($record->treatment_date), 'injury_illness_type' => $record->injury_illness_type,
            'injury_illness_description' => $record->injury_illness_description, 'body_part' => $record->body_part, 'treatment_given' => $record->treatment_given,
            'treatment_outcome' => $record->treatment_outcome, 'ambulance_called' => (bool) $record->ambulance_called,
            'first_aider_id' => $record->first_aider_id ? (int) $record->first_aider_id : null, 'first_aider' => $this->actor($record->firstAider),
            'first_aider_notes' => $record->first_aider_notes, 'incident_reported' => (bool) $record->incident_reported];
    }

    private function readableClient(FirstAidRecord $record, User $viewer, CurrentAuthorizationReads $reads): ?Client
    {
        if ($record->treated_person_type !== 'client' || ! $record->client_id) {
            return null;
        }
        $client = $reads->query(Client::query())->find($record->client_id);

        return $client && (int) $client->site_id === (int) $record->site_id && app(ClientPolicy::class)->viewFromCurrentEvidence($viewer, $client, $reads) ? $client : null;
    }

    private function attachment(FirstAidRecord $record, FirstAidAttachment $row, bool $remove): array
    {
        $availability = 'unknown';
        try {
            $availability = $row->disk === 'private' && is_string($row->path) && $row->path !== '' && Storage::disk('private')->exists($row->path) ? 'available' : 'missing';
        } catch (\Throwable) { /* No raw storage failure reaches the record projection. */
        }
        $href = $availability === 'available' ? FirstAidReturn::REGISTER.'/'.$record->id.'/attachments/'.$row->id.'/download' : null;

        return ['id' => (int) $row->id, 'original_name' => basename(str_replace(chr(92), '/', (string) $row->original_name)),
            'kind' => $row->kind, 'notes' => $row->notes, 'alt_text' => $row->alt_text, 'mime' => $row->mime, 'size' => $row->size,
            'is_image' => $row->isImage(), 'uploaded_by' => $this->actor($row->uploader), 'uploaded_by_name' => $row->uploader?->name,
            'created_at' => $this->instant($row->created_at), 'availability' => $availability, 'download_href' => $href, 'url' => $href,
            'can' => ['remove' => $remove]];
    }

    private function followup(FirstAidRecord $record, FirstAidFollowup $row, User $viewer, array $attachments, ?string $returnTo): array
    {
        $ids = is_array($row->completion_evidence_ids) ? $row->completion_evidence_ids : [];
        $completedBy = $row->completed_by_user_id ? User::query()->select('id', 'name')->find($row->completed_by_user_id) : null;

        return ['id' => (int) $row->id, 'notes' => $row->notes, 'assigned_to' => $this->actor($row->assignedTo), 'assigned_to_name' => $row->assignedTo?->name,
            'due_at' => $this->instant($row->due_at), 'completed_at' => $this->instant($row->completed_at), 'completed_by' => $this->actor($completedBy),
            'completion_notes' => $row->completion_notes, 'completion_outcome' => $this->outcome($row->completion_outcome),
            'completion_evidence' => array_values(array_filter($attachments, fn ($attachment) => in_array($attachment['id'], $ids, true))),
            'created_by' => $this->actor($row->creator), 'created_by_name' => $row->creator?->name, 'created_at' => $this->instant($row->created_at),
            'legacy' => $row->completed_at !== null && ! $row->completed_by_user_id,
            'href' => FirstAidReturn::href($record->id, $returnTo, 'followups', $row->id), 'can' => $this->access->followupCan($record, $row, $viewer)];
    }

    private function history(FirstAidRecord $record, User $current, CurrentAuthorizationReads $reads, array $sites, array $connections, array $attachments, array $followups): array
    {
        $logs = AuditLog::query()->whereIn('auditable_type', array_unique([FirstAidRecord::class, $record->getMorphClass()]))->where('auditable_id', $record->id)->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->get();
        $modernCreation = $logs->contains(fn ($log) => $log->action === 'first_aid.created');
        $entries = [];
        foreach ($logs as $log) {
            if ($modernCreation && $log->action === 'firstaidrecord.create') {
                continue;
            }
            $semantic = isset(self::TITLES[$log->action]);
            $meta = is_array($log->meta) ? $log->meta : [];
            $historicalValues = $semantic ? ($meta['snapshot'] ?? []) : ($meta['after'] ?? $meta['before'] ?? []);
            try {
                $historical = $this->historicalSource($historicalValues, $current, $reads, $sites);
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                // A known denied historical subject never leaks through notes, labels or a count.
                continue;
            }
            $complete = $semantic && $historical !== null;
            $snapshot = $complete ? $this->snapshot($historicalValues, $historical->client) : [];
            $followupId = $meta['followup_id'] ?? ($meta['followup_snapshot']['id'] ?? null);
            if ($complete && is_int($followupId) && in_array($followupId, array_column($followups, 'id'), true)) {
                $snapshot['followup'] = $this->followupSnapshot($meta['followup_snapshot'] ?? [], array_column($attachments, 'id'));
            }
            if ($complete && ($connections['incident']['id'] ?? null) === ($meta['incident_id'] ?? null) && isset($meta['incident_id'])) {
                $snapshot['incident_id'] = $meta['incident_id'];
            }
            if ($complete && in_array($log->action, ['first_aid.evidence_added', 'first_aid.evidence_removed'], true) && is_array($meta['names'] ?? null)) {
                $snapshot['names'] = array_values(array_filter(array_map(fn ($name) => is_string($name) ? basename(str_replace(chr(92), '/', $name)) : null, $meta['names'])));
            }
            $note = $complete && is_string($meta['note'] ?? null) ? $meta['note'] : null;
            $entries[] = ['id' => 'audit-'.$log->id, 'action' => $log->action, 'title' => $complete ? self::TITLES[$log->action] : 'Legacy treatment activity - details incomplete',
                'actor' => $this->actor($log->user), 'occurred_at' => $this->instant($log->created_at), 'note' => $note, 'snapshot' => $snapshot, 'legacy' => ! $complete];
        }

        return $entries;
    }

    /** Missing historical source evidence is unknown, never the current model's tuple. */
    private function historicalSource(mixed $value, User $current, CurrentAuthorizationReads $reads, array $sites): ?FirstAidRecord
    {
        if (! is_array($value) || ! is_int($value['site_id'] ?? null) || $value['site_id'] <= 0 || ! is_string($value['treated_person_type'] ?? null)) {
            return null;
        }
        foreach (['client_id', 'treated_person_id'] as $field) {
            if (! array_key_exists($field, $value) || ($value[$field] !== null && (! is_int($value[$field]) || $value[$field] <= 0))) {
                return null;
            }
        }
        $historical = new FirstAidRecord;
        $historical->forceFill(array_intersect_key($value, array_flip(['site_id', 'treated_person_type', 'client_id', 'treated_person_id'])));
        $this->access->assertSource($historical, $current, $reads, $sites);

        return $historical;
    }

    private function snapshot(mixed $value, ?Client $client): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach (self::TREATMENT_TEXT as $field) {
            if (array_key_exists($field, $value) && (is_string($value[$field]) || $value[$field] === null)) {
                $out[$field] = $value[$field];
            }
        }
        foreach (['site_id', 'treated_person_id', 'first_aider_id'] as $field) {
            if (array_key_exists($field, $value) && (is_int($value[$field]) || $value[$field] === null)) {
                $out[$field] = $value[$field];
            }
        }
        foreach (['ambulance_called', 'incident_reported'] as $field) {
            if (is_bool($value[$field] ?? null)) {
                $out[$field] = $value[$field];
            }
        }
        if (($value['treated_person_type'] ?? null) === 'client') {
            unset($out['treated_person_id']);
            if (! $client || ($value['client_id'] ?? null) !== (int) $client->id) {
                unset($out['treated_person_name']);
            } else {
                $out['client_id'] = (int) $client->id;
            }
        }

        return $out;
    }

    private function followupSnapshot(mixed $value, array $evidenceIds): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach (['notes', 'due_at', 'completed_at', 'completion_notes'] as $field) {
            if (array_key_exists($field, $value) && (is_string($value[$field]) || $value[$field] === null)) {
                $out[$field] = $value[$field];
            }
        }
        foreach (['id', 'assigned_to_user_id', 'completed_by_user_id'] as $field) {
            if (array_key_exists($field, $value) && (is_int($value[$field]) || $value[$field] === null)) {
                $out[$field] = $value[$field];
            }
        }
        $out['completion_outcome'] = $this->outcome($value['completion_outcome'] ?? null);
        $out['completion_evidence_ids'] = array_values(array_intersect(array_filter(is_array($value['completion_evidence_ids'] ?? null) ? $value['completion_evidence_ids'] : [], 'is_int'), $evidenceIds));

        return $out;
    }

    private function outcome(mixed $value): ?array
    {
        if (! is_array($value) || ! in_array($value['kind'] ?? null, ['action', 'contact', 'claim_followup', 'observation'], true)
            || ! in_array($value['result'] ?? null, ['recorded', 'contacted', 'attempted', 'unable_to_contact', 'not_required', 'other'], true)) {
            return null;
        }

        return ['kind' => $value['kind'], 'result' => $value['result'], 'occurred_at' => is_string($value['occurred_at'] ?? null) ? $value['occurred_at'] : null,
            'note' => is_string($value['note'] ?? null) ? $value['note'] : null];
    }

    private function sourceDelivery(FirstAidRecord $record): ?array
    {
        if (! method_exists($record, 'sourceDelivery')) {
            return null;
        }
        $row = $record->sourceDelivery;
        if (! $row || ! in_array($row->status, ['pending', 'failed', 'delivered', 'suppressed', 'skipped'], true)) {
            return null;
        }

        return ['status' => $row->status, 'attempts' => max(0, (int) $row->attempts),
            'failure_code' => in_array($row->failure_code, ['queue_unavailable', 'delivery_failed', 'source_inactive', 'source_changed', 'authority_changed', 'assignment_inactive', 'preference_suppressed'], true) ? $row->failure_code : null,
            'next_attempt_at' => $this->instant($row->next_attempt_at), 'delivered_at' => $this->instant($row->delivered_at)];
    }

    private function actor(?User $user): ?array
    {
        return $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;
    }

    private function instant(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    private function options(array $values): array
    {
        return array_map(fn ($value) => ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))], $values);
    }
}
