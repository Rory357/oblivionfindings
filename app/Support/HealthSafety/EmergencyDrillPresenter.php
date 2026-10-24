<?php

namespace App\Support\HealthSafety;

use App\Models\AuditLog;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillAttachment;
use App\Models\EmergencyDrillFailureDelivery;
use App\Models\EmergencyDrillFinding;
use App\Models\User;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\EmergencyDrillConnectionsService;
use Illuminate\Support\Carbon;

/** Source-owned drill facts; completion, attendance and remedy verification differ. */
final class EmergencyDrillPresenter
{
    private const TITLES = ['drill.scheduled' => 'Drill scheduled', 'drill.updated' => 'Drill planning updated',
        'drill.started' => 'Drill started', 'drill.completed' => 'Completion recorded', 'drill.cancelled' => 'Drill cancelled',
        'drill.participant_recorded' => 'Participant record updated', 'drill.finding_added' => 'Finding recorded',
        'drill.finding_updated' => 'Finding updated', 'drill.finding_resolved' => 'Finding resolution recorded',
        'drill.corrective_action_linked' => 'Required corrective remedy linked', 'drill.evidence_added' => 'Evidence added',
        'drill.evidence_removed' => 'Evidence removed', 'drill.next_scheduled' => 'Next drill scheduled'];

    public function __construct(private readonly EmergencyDrillAccessService $access, private readonly EmergencyDrillConnectionsService $connections) {}

    public function detail(EmergencyDrill $record, User $viewer, ?string $returnTo = null): array
    {
        $record = $this->access->recordForViewer($record, $viewer);
        $record->load(['site:id,name,region,city', 'conductor:id,name', 'creator:id,name', 'participants.user:id,name', 'findings.assignee:id,name', 'attachments.uploader:id,name']);
        $can = $this->access->commandCan($record, $viewer);
        $retainedEvidence = $record->findings->flatMap(fn ($finding) => is_array($finding->resolution_evidence_ids) ? $finding->resolution_evidence_ids : [])->map(fn ($id) => (int) $id)->all();
        $attachments = $record->attachments->sortBy('id')->map(fn ($attachment) => $this->attachment($record, $attachment, $can['remove_evidence'] && ! in_array((int) $attachment->id, $retainedEvidence, true)))->values()->all();
        $evidenceIds = array_column($attachments, 'id');
        $participants = $record->participants->sortBy('id')->map(fn ($participant) => ['id' => (int) $participant->id,
            'user_id' => (int) $participant->user_id, 'name' => $participant->user?->name ?? 'Name not available',
            'role' => $participant->role, 'attended' => (bool) $participant->attended, 'notes' => $participant->notes,
            'accommodations' => $participant->accommodations, 'can' => ['edit' => (bool) $can['add_participant']]])->values()->all();
        $findings = $record->findings->sortBy('id')->map(function ($finding) use ($record, $viewer, $returnTo, $evidenceIds) {
            $action = $this->connections->forFinding($record, $finding, $viewer, $returnTo);
            $can = $this->access->findingCan($record, $finding, $viewer);
            $blocked = $can['edit'] && $evidenceIds === [] ? 'Add supporting evidence before recording a finding resolution.' : null;
            if ($finding->hs_corrective_action_id && (! $action || ! in_array($action['status'], ['verified', 'closed'], true) || $action['effectiveness_confirmed'] !== true)) {
                $can['resolve'] = false;
                $blocked = $action ? 'The required remedy needs independent verification with positive recorded effectiveness.' : 'The required remedy is not available here.';
            }
            $resolutionIds = is_array($finding->resolution_evidence_ids) ? array_values(array_intersect($finding->resolution_evidence_ids, $evidenceIds)) : [];

            return ['id' => (int) $finding->id, 'finding_type' => $finding->finding_type, 'description' => $finding->description,
                'severity' => $finding->severity, 'status' => $finding->status, 'corrective_action' => $finding->corrective_action,
                'assigned_to' => $finding->assigned_to, 'assignee_name' => $finding->assignee?->name,
                'due_date' => $finding->due_date?->toDateString(), 'resolved_at' => $finding->resolved_at?->toDateString(),
                'resolution_notes' => $finding->resolution_notes, 'resolution_evidence_ids' => $resolutionIds,
                'is_overdue' => $this->findingOverdue($finding), 'corrective_action_record' => $action,
                'can' => $can, 'resolve_blocked_reason' => $blocked,
                'corrective_action_options_href' => ($can['create_action'] || $can['link_action']) ? EmergencyDrillReturn::REGISTER.'/'.$record->id.'/findings/'.$finding->id.'/corrective-action-options' : null];
        })->values()->all();
        $connections = $this->connections->forViewer($record, $viewer, $returnTo);
        $counts = ['assigned' => count($participants), 'attended' => count(array_filter($participants, fn ($row) => $row['attended'])),
            'not_attended' => count(array_filter($participants, fn ($row) => ! $row['attended'])),
            'total_recorded' => $record->total_participants, 'residents_evacuated' => $record->residents_evacuated,
            'findings_open' => count(array_filter($findings, fn ($row) => ! in_array($row['status'], ['resolved', 'closed', 'accepted'], true))),
            'findings_overdue' => count(array_filter($findings, fn ($row) => $row['is_overdue']))];

        return ['id' => (int) $record->id, 'reference' => 'DR-'.$record->id, 'title' => $record->title,
            'drill_type' => $record->drill_type, 'type_label' => $this->label($record->drill_type === 'fire' ? 'fire_evacuation' : $record->drill_type),
            'status' => $record->status, 'outcome' => $record->outcome, 'expected_version' => (int) $record->lock_version, 'lock_version' => (int) $record->lock_version,
            'scheduled_at' => $record->scheduled_at?->toIso8601String(), 'started_at' => $record->started_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(), 'duration_minutes' => $record->duration_minutes,
            'evacuation_time_seconds' => $record->evacuation_time_seconds, 'weather_conditions' => $record->weather_conditions,
            'total_participants' => $record->total_participants, 'residents_evacuated' => $record->residents_evacuated,
            'all_areas_checked' => (bool) $record->all_areas_checked, 'assembly_point_reached' => (bool) $record->assembly_point_reached,
            'roll_call_completed' => (bool) $record->roll_call_completed, 'scenario_description' => $record->scenario_description,
            'is_unannounced' => (bool) $record->is_unannounced, 'assembly_point' => $record->assembly_point, 'evacuation_scheme' => $record->evacuation_scheme,
            'observer_notes' => $record->observer_notes, 'improvements_identified' => $record->improvements_identified,
            'site' => $record->site ? ['id' => $record->site->id, 'name' => $record->site->name, 'region' => $record->site->region ?: $record->site->city] : null,
            'coordinator' => $this->actor($record->conductor), 'coordinator_name' => $record->conductor?->name, 'conducted_by' => $record->conducted_by,
            'created_by_name' => $record->creator?->name, 'created_at' => $record->created_at?->toIso8601String(),
            'cancelled_at' => $record->cancelled_at?->toIso8601String(), 'cancellation_reason' => $record->cancellation_reason,
            'participants' => $participants, 'findings' => $findings, 'attachments' => $attachments, 'counts' => $counts,
            'history' => $this->history($record, $connections, $findings, $participants, $evidenceIds), 'connections' => $connections,
            'source_delivery' => $this->sourceDelivery($record),
            'hs_event' => isset($connections['safety_reviews'][0]) ? [...$connections['safety_reviews'][0], 'url' => $connections['safety_reviews'][0]['href']] : null,
            'exports' => ['participants_href' => $can['export_participants'] ? EmergencyDrillReturn::REGISTER.'/'.$record->id.'/participants/export' : null,
                'evidence_href' => $can['export_evidence'] ? EmergencyDrillReturn::REGISTER.'/'.$record->id.'/evidence/export' : null],
            'options_href' => EmergencyDrillReturn::REGISTER.'/options?site_id='.$record->site_id.'&drill_id='.$record->id,
            'can' => $can];
    }

    public function row(EmergencyDrill $record, User $viewer, ?string $returnTo = null): array
    {
        $record = $this->access->recordForViewer($record, $viewer);
        $record->loadMissing('site');
        $participants = $record->participants()->count();
        $attended = $record->participants()->where('attended', true)->count();
        $findings = $record->findings()->get();
        $open = $findings->whereNotIn('status', ['resolved', 'closed', 'accepted'])->count();
        $overdue = $record->status === 'scheduled' && $record->scheduled_at?->isPast();

        return ['id' => $record->id, 'reference' => 'DR-'.$record->id, 'title' => $record->title, 'drill_type' => $record->drill_type,
            'type_label' => $this->label($record->drill_type === 'fire' ? 'fire_evacuation' : $record->drill_type),
            'site' => $record->site ? ['id' => $record->site->id, 'name' => $record->site->name] : null,
            'scheduled_at' => $record->scheduled_at?->toIso8601String(), 'completed_at' => $record->completed_at?->toIso8601String(),
            'status' => $overdue ? 'overdue' : $record->status, 'raw_status' => $record->status, 'outcome' => $record->outcome,
            'participants_count' => $participants, 'attended_count' => $attended, 'total_participants' => $record->total_participants,
            'people_label' => $record->status === 'completed' ? $attended.' attended' : $participants.' assigned',
            'findings_count' => $findings->count(), 'findings_open' => $open,
            'flags' => ['overdue' => $overdue, 'running' => $record->status === 'in_progress', 'finding_overdue' => $findings->contains(fn ($finding) => $this->findingOverdue($finding)), 'open_findings' => $open],
            'href' => EmergencyDrillReturn::href($record->id, $returnTo), 'can' => $this->access->commandCan($record, $viewer)];
    }

    public function pickers(User $viewer, ?EmergencyDrill $record = null): array
    {
        return [...$this->access->pickerOptions($viewer, $record),
            'finding_types' => ['observation', 'non_conformance', 'improvement', 'positive'],
            'severities' => ['critical', 'high', 'medium', 'low'], 'outcomes' => ['passed', 'passed_actions', 'failed']];
    }

    private function findingOverdue(EmergencyDrillFinding $finding): bool
    {
        return $finding->due_date !== null && $finding->due_date->toDateString() < now(config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC')->toDateString()
            && ! in_array($finding->status, ['resolved', 'closed', 'accepted'], true);
    }

    private function attachment(EmergencyDrill $record, EmergencyDrillAttachment $attachment, bool $remove): array
    {
        return ['id' => $attachment->id, 'original_name' => basename(str_replace(chr(92), '/', $attachment->original_name)),
            'mime' => $attachment->mime, 'size' => $attachment->size, 'kind' => $attachment->kind, 'notes' => $attachment->notes,
            'alt_text' => $attachment->alt_text, 'is_image' => $attachment->isImage(), 'uploaded_by_name' => $attachment->uploader?->name,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'url' => EmergencyDrillReturn::REGISTER.'/'.$record->id.'/attachments/'.$attachment->id.'/download', 'can' => ['remove' => $remove]];
    }

    private function history(EmergencyDrill $record, array $connections, array $findings, array $participants, array $evidenceIds): array
    {
        $allowedIds = ['finding_id' => array_column($findings, 'id'), 'participant_id' => array_column($participants, 'id'),
            'hs_corrective_action_id' => array_column($connections['corrective_actions'], 'id'), 'next_drill_id' => array_column($connections['next_drills'], 'id'),
            'previous_drill_id' => $connections['previous_drill'] ? [$connections['previous_drill']['id']] : []];
        $rows = [];
        $logs = AuditLog::query()->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->with('user:id,name')->orderBy('created_at')->orderBy('id')->get();
        foreach ($logs as $log) {
            $semantic = array_key_exists($log->action, self::TITLES);
            $legacy = ! $semantic;
            $meta = is_array($log->meta) ? $log->meta : [];
            $snapshot = $semantic ? $this->historySnapshot($meta['snapshot'] ?? []) : [];
            foreach ($allowedIds as $key => $ids) {
                $id = $meta[$key] ?? null;
                if ($semantic && is_numeric($id) && in_array((int) $id, $ids, true)) {
                    $snapshot[$key] = (int) $id;
                }
            }
            if ($semantic) {
                if (isset($snapshot['finding_id']) && is_array($meta['finding_snapshot'] ?? null)) {
                    $snapshot['finding'] = $this->childSnapshot($meta['finding_snapshot'], false, $evidenceIds);
                }
                if (isset($snapshot['participant_id']) && is_array($meta['participant_snapshot'] ?? null)) {
                    $snapshot['participant'] = $this->childSnapshot($meta['participant_snapshot'], true, $evidenceIds);
                }
                if (is_array($meta['resolution_evidence_ids'] ?? null)) {
                    $snapshot['resolution_evidence_ids'] = array_values(array_intersect(array_filter($meta['resolution_evidence_ids'], 'is_int'), $evidenceIds));
                }
                foreach (['from', 'to'] as $key) {
                    if (is_string($meta[$key] ?? null) && strlen($meta[$key]) <= 50) {
                        $snapshot[$key] = $meta[$key];
                    }
                }
                if (in_array($log->action, ['drill.evidence_added', 'drill.evidence_removed'], true) && is_array($meta['names'] ?? null)) {
                    $snapshot['names'] = array_values(array_filter(array_map(fn ($name) => is_string($name) ? basename(str_replace(chr(92), '/', $name)) : null, $meta['names'])));
                }
            }
            $rows[] = ['id' => 'audit-'.$log->id, 'action' => $log->action, 'title' => self::TITLES[$log->action] ?? 'Legacy drill activity — details incomplete',
                'actor' => $this->actor($log->user), 'occurred_at' => $log->created_at?->toIso8601String(),
                'note' => $semantic && is_string($meta['note'] ?? null) ? $meta['note'] : null, 'snapshot' => $snapshot, 'legacy' => $legacy];
        }

        return $rows;
    }

    private function childSnapshot(array $values, bool $participant, array $evidenceIds): array
    {
        $safe = [];
        $strings = $participant ? ['role', 'notes', 'accommodations'] : ['finding_type', 'description', 'severity', 'status', 'corrective_action', 'resolution_notes'];
        foreach ($strings as $key) {
            if (array_key_exists($key, $values) && (is_string($values[$key]) || $values[$key] === null)) {
                $safe[$key] = $values[$key];
            }
        }
        if ($participant && is_bool($values['attended'] ?? null)) {
            $safe['attended'] = $values['attended'];
        }
        if (! $participant) {
            foreach (['due_date', 'resolved_at'] as $key) {
                if (is_string($values[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $values[$key])) {
                    $safe[$key] = $values[$key];
                }
            }
            if (is_array($values['resolution_evidence_ids'] ?? null)) {
                $safe['resolution_evidence_ids'] = array_values(array_intersect(array_filter($values['resolution_evidence_ids'], 'is_int'), $evidenceIds));
            }
        }

        return $safe;
    }

    /** Recorded transport stages do not assert receipt, response or safety. */
    private function sourceDelivery(EmergencyDrill $record): ?array
    {
        $delivery = EmergencyDrillFailureDelivery::query()->where('emergency_drill_id', $record->id)->first();
        if (! $delivery) {
            return null;
        }
        $eventStatus = in_array($delivery->event_status, ['pending', 'failed', 'delivered', 'skipped'], true) ? $delivery->event_status : null;
        $signalStatus = in_array($delivery->signal_status, ['pending', 'failed', 'delivered', 'suppressed', 'skipped'], true) ? $delivery->signal_status : null;

        return ['event_status' => $eventStatus, 'signal_status' => $signalStatus,
            'event_delivered_at' => $delivery->event_delivered_at?->toIso8601String(), 'signal_delivered_at' => $delivery->signal_delivered_at?->toIso8601String(),
            'attempts' => max(0, (int) $delivery->attempts),
            'failure_code' => in_array($delivery->failure_code, ['source_inactive', 'event_delivery_failed', 'signal_delivery_failed', 'queue_unavailable'], true) ? $delivery->failure_code : null,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String()];
    }

    private function historySnapshot(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }
        $safe = [];
        foreach (['title', 'drill_type', 'outcome', 'scenario_description', 'assembly_point', 'evacuation_scheme', 'weather_conditions', 'observer_notes', 'improvements_identified'] as $key) {
            if (array_key_exists($key, $snapshot) && (is_string($snapshot[$key]) || $snapshot[$key] === null)) {
                $safe[$key] = $snapshot[$key];
            }
        }
        foreach (['scheduled_at', 'started_at', 'completed_at'] as $key) {
            if (is_string($snapshot[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $snapshot[$key])) {
                try {
                    $safe[$key] = Carbon::parse($snapshot[$key])->toIso8601String();
                } catch (\Throwable) {
                }
            }
        }
        foreach (['duration_minutes', 'evacuation_time_seconds', 'total_participants', 'residents_evacuated'] as $key) {
            if (array_key_exists($key, $snapshot) && (is_int($snapshot[$key]) || $snapshot[$key] === null)) {
                $safe[$key] = $snapshot[$key];
            }
        }
        foreach (['is_unannounced', 'all_areas_checked', 'assembly_point_reached', 'roll_call_completed'] as $key) {
            if (is_bool($snapshot[$key] ?? null)) {
                $safe[$key] = $snapshot[$key];
            }
        }

        return $safe;
    }

    private function actor(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', $value ?? 'Not recorded'));
    }
}
