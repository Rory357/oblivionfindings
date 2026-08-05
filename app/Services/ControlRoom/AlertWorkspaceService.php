<?php

namespace App\Services\ControlRoom;

use App\Domain\Monitoring\Presenters\MonitoringIncidentEvidencePresenter;
use App\Models\AuditLog;
use App\Models\ControlRoom\ConfigOption;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HealthSafety\HsVisibilityService;
use App\Services\Incidents\IncidentJourneyPresenter;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\UserSiteAccessService;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;
use Illuminate\Support\Collection;

/**
 * Assembles the full workspace payload behind the Alert Workspace dialog —
 * shared by the modal-over-list (`?alert=` on every Control Room surface) and
 * the `/control-room/alerts/{id}` deep link. Returns null when the alert is
 * missing or the user has no site access, so list pages can no-op gracefully.
 */
class AlertWorkspaceService
{
    public function __construct(
        private ControlRoomAlertAccessService $access,
        private UserSiteAccessService $siteAccess,
        private HsVisibilityService $hsVisibility,
        private ControlRoomAlertProvenanceService $provenance,
        private MonitoringIncidentEvidencePresenter $monitoringIncidentEvidence,
        private LinkedOperationalEvidencePresenter $linkedEvidence,
        private ControlRoomAlertLifecycleService $lifecycle,
        private IncidentJourneyService $journeys,
        private IncidentJourneyPresenter $journeyPresenter,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(User $user, int $alertId): ?array
    {
        $alert = $this->access->findVisible($user, $alertId);
        if (! $alert) {
            return null;
        }

        $alert->load([
            'asset:id,name,asset_tag,site_id,home_site_id,client_id',
            'asset.client:id,site_id',
            'fleetSignal.asset:id,site_id,home_site_id,client_id',
            'fleetSignal.asset.client:id,site_id',
            'assignedTo:id,name,email',
            'acknowledgedBy:id,name',
            'resolvedBy:id,name',
            'closedBy:id,name',
            'escalatedBy:id,name',
            'assignedBy:id,name',
            'createdBy:id,name',
            'snoozedBy:id,name',
            'playbookRun.playbook',
            'playbookRun.steps.step',
            'evidencePacks.evidenceItems',
            'communications',
            'sla.slaDefinition',
            'client:id,first_name,last_name,site_id',
            'clientIncident:id,reference_number,control_room_alert_id,hs_event_id,status,severity,client_id,site_id,title',
            'device:id,type,latitude,longitude,location_description,site_id,client_id,asset_id',
            'tasks' => fn ($q) => $q->whereNull('parent_task_id')->orderBy('sort_order')->with(['assignedTo:id,name', 'subtasks.assignedTo:id,name']),
            'discussions' => fn ($q) => $q->whereNull('parent_id')->orderBy('created_at', 'asc')->with(['user:id,name', 'replies' => fn ($r) => $r->orderBy('created_at', 'asc')->with('user:id,name')]),
            'watchers.user:id,name',
            'timeEntries' => fn ($q) => $q->orderBy('created_at', 'desc')->with('user:id,name'),
        ]);

        $auditLogs = AuditLog::query()
            ->where('auditable_type', ControlRoomAlert::class)
            ->where('auditable_id', $alert->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'meta' => $log->meta,
                'created_at' => $log->created_at->toISOString(),
            ]);

        AuditLogger::log('controlRoom.alert.view', $alert, [
            'alert_id' => $alert->id,
        ]);

        $safeClient = $this->provenance->safeClient($alert);
        $safeAsset = $this->provenance->safeAsset($alert);
        $safeAssignedTo = $this->provenance->safeAssignedTo($alert, $user);
        $safeFleetSignal = $alert->fleetSignal
            && $this->provenance->fleetSignalMatchesAlert($alert, $alert->fleetSignal)
            ? $alert->fleetSignal
            : null;
        $safeDevice = $alert->device && $this->provenance->deviceMatchesAlert($alert, $alert->device)
            ? $alert->device
            : null;
        $unsafeFleetReference = ($alert->asset_id !== null && $safeAsset === null)
            || ($alert->fleet_signal_id !== null && $safeFleetSignal === null);
        $unsafeDeviceReference = $alert->device_id !== null && $safeDevice === null;
        $safeContext = $this->provenance->sanitiseContextForRead($alert);

        $linkedIncident = $this->journeys->incidentForAlert($alert);
        $linkedHsEvent = $linkedIncident
            ? $this->journeys->journeyForIncident($linkedIncident)->hsEvent
            : $alert->hsEvent()->first();
        $immediateControls = OperatorNote::query()
            ->where('alert_id', $alert->id)
            ->where('purpose', OperatorNote::PURPOSE_IMMEDIATE_CONTROLS)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $canViewIncident = $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');
        $monitoringContext = $this->monitoringIncidentEvidence->forAlert($alert, $user);
        $canOpenIncident = $linkedIncident !== null
            && $canViewIncident
            && $user->can('view', $linkedIncident);
        $canOpenHs = $linkedHsEvent !== null && $user->canDo('hazards.view');
        $resolveGate = $this->lifecycle->resolveGate($alert)->toArray();
        $closeGate = $this->lifecycle->closeGate($alert)->toArray();
        $closeGate['requirements'] = collect($closeGate['requirements'])
            ->map(function (array $requirement) use ($canOpenIncident, $canOpenHs): array {
                $canOpen = match ($requirement['key']) {
                    'incident_closed' => $canOpenIncident,
                    'health_safety_closed' => $canOpenHs,
                    default => true,
                };
                if (! $canOpen) {
                    $requirement['href'] = null;
                }

                return $requirement;
            })
            ->values()
            ->all();
        $capabilities = $this->access->capabilitiesForScopedAlert($user);
        $linkedOperationalEvidence = $this->linkedEvidence->present(
            $alert,
            $user,
            fn ($item): string => "/control-room/evidence/items/{$item->id}/download",
        );

        return [
            'alert' => [
                'id' => $alert->id,
                'reference_number' => $alert->reference_number,
                'source' => $alert->source,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'asset_id' => $safeAsset?->id,
                'asset' => $safeAsset ? [
                    'id' => $safeAsset->id,
                    'name' => $safeAsset->name,
                    'asset_tag' => $safeAsset->asset_tag,
                ] : null,
                'fleet_signal_id' => $safeFleetSignal?->id,
                'fleet_signal' => $safeFleetSignal ? [
                    'id' => $safeFleetSignal->id,
                    'signal_type' => $safeFleetSignal->signal_type,
                    'severity_hint' => $safeFleetSignal->severity_hint,
                    'occurred_at' => optional($safeFleetSignal->occurred_at)->toISOString(),
                    'payload' => $safeFleetSignal->payload,
                ] : null,
                'fleet_context' => $unsafeFleetReference
                    ? null
                    : ($safeContext['fleet_context']
                        ?? $safeContext['normalized_data']['fleet_context']
                        ?? null),
                'assigned_to_user_id' => $safeAssignedTo?->id,
                'assigned_to' => $safeAssignedTo ? [
                    'id' => $safeAssignedTo->id,
                    'name' => $safeAssignedTo->name,
                    'email' => $safeAssignedTo->email,
                ] : null,
                'acknowledged_by' => $alert->acknowledgedBy ? [
                    'id' => $alert->acknowledgedBy->id,
                    'name' => $alert->acknowledgedBy->name,
                ] : null,
                'resolved_by' => $alert->resolvedBy ? [
                    'id' => $alert->resolvedBy->id,
                    'name' => $alert->resolvedBy->name,
                ] : null,
                'closed_by' => $alert->closedBy ? [
                    'id' => $alert->closedBy->id,
                    'name' => $alert->closedBy->name,
                ] : null,
                'escalated_by' => $alert->escalatedBy ? [
                    'id' => $alert->escalatedBy->id,
                    'name' => $alert->escalatedBy->name,
                ] : null,
                'assigned_by' => $alert->assignedBy ? [
                    'id' => $alert->assignedBy->id,
                    'name' => $alert->assignedBy->name,
                ] : null,
                'created_by' => $alert->createdBy ? [
                    'id' => $alert->createdBy->id,
                    'name' => $alert->createdBy->name,
                ] : null,
                'triggered_at' => optional($alert->triggered_at)->toISOString(),
                'acknowledged_at' => optional($alert->acknowledged_at)->toISOString(),
                'resolved_at' => optional($alert->resolved_at)->toISOString(),
                'closed_at' => optional($alert->closed_at)->toISOString(),
                'escalated_at' => optional($alert->escalated_at)->toISOString(),
                'assigned_at' => optional($alert->assigned_at)->toISOString(),
                'escalation_level' => $alert->escalation_level,
                'context' => $safeContext,
                'notes' => $alert->notes,
                'priority' => $alert->priority,
                'due_at' => optional($alert->due_at)->toISOString(),
                'category' => $alert->category,
                'resolution_code' => $alert->resolution_code,
                'is_snoozed' => $alert->isSnoozed(),
                'snoozed_until' => optional($alert->snoozed_until)->toISOString(),
                'snoozed_by' => $alert->snoozedBy ? [
                    'id' => $alert->snoozedBy->id,
                    'name' => $alert->snoozedBy->name,
                ] : null,
                'created_at' => optional($alert->created_at)->toISOString(),
                'updated_at' => optional($alert->updated_at)->toISOString(),
            ],
            'playbook_run' => $alert->playbookRun ? [
                'id' => $alert->playbookRun->id,
                'status' => $alert->playbookRun->status,
                'current_step' => $alert->playbookRun->current_step,
                // Derived from the loaded steps so historical runs whose stored
                // counter drifted still display the true progress.
                'completed_steps' => $alert->playbookRun->steps->where('status', 'completed')->count(),
                'total_steps' => $alert->playbookRun->total_steps,
                'playbook' => [
                    'id' => $alert->playbookRun->playbook->id,
                    'name' => $alert->playbookRun->playbook->name,
                    'category' => $alert->playbookRun->playbook->category,
                ],
                'steps' => $alert->playbookRun->steps->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => ($s->step?->title ?: null) ?? 'Step '.((int) $s->order + 1),
                    'instructions' => $s->step?->instructions,
                    'status' => $s->status,
                    'notes' => $s->notes,
                    'completed_at' => optional($s->completed_at)->toISOString(),
                ])->values(),
            ] : null,
            'available_playbooks' => $alert->playbookRun
                ? []
                : Playbook::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'category', 'description'])
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'category' => $p->category,
                        'description' => $p->description,
                    ])
                    ->values(),
            'evidence_packs' => $alert->evidencePacks->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'item_count' => $p->item_count,
                'items' => $p->evidenceItems->map(fn ($i) => [
                    'id' => $i->id,
                    'type' => $i->type,
                    'title' => $i->title,
                    // Note items keep their text here — without it the row reads
                    // just "Note" and the content is unreadable after adding.
                    'description' => $i->description,
                    'download_url' => $i->storage_path ? "/control-room/evidence/items/{$i->id}/download" : null,
                    'created_at' => optional($i->created_at)->toISOString(),
                ])->values(),
            ])->values(),
            'communications' => $alert->communications->map(fn ($c) => [
                'id' => $c->id,
                'channel' => $c->channel,
                'direction' => $c->direction,
                'purpose' => $c->purpose,
                'status' => $c->status,
                'content' => $c->content,
                'target_user_name' => $c->user?->name,
                'sent_at' => optional($c->sent_at)->toISOString(),
                'created_at' => optional($c->created_at)->toISOString(),
            ])->values(),
            'sla' => $alert->sla?->isApplicable() ? [
                'acknowledge_deadline' => optional($alert->sla->acknowledge_deadline)->toISOString(),
                'response_deadline' => optional($alert->sla->response_deadline)->toISOString(),
                'resolution_deadline' => optional($alert->sla->resolution_deadline)->toISOString(),
                'acknowledge_breached' => $alert->sla->acknowledge_breached,
                'response_breached' => $alert->sla->response_breached,
                'resolution_breached' => $alert->sla->resolution_breached,
            ] : null,
            'client' => $safeClient ? [
                'id' => $safeClient->id,
                'name' => trim($safeClient->first_name.' '.$safeClient->last_name),
            ] : null,
            'location' => $safeDevice && $safeDevice->latitude ? [
                'lat' => (float) $safeDevice->latitude,
                'lng' => (float) $safeDevice->longitude,
                'description' => $safeDevice->location_description,
            ] : null,
            'audit_logs' => $auditLogs,
            'can' => [
                ...$capabilities,
                'view_incident' => $canViewIncident,
                'view_health_safety' => $user->canDo('hazards.view'),
            ],
            'staff' => $this->assignableStaff($user),
            'tasks' => $alert->tasks->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_at' => $t->due_at?->toISOString(),
                'completed_at' => $t->completed_at?->toISOString(),
                'estimated_minutes' => $t->estimated_minutes,
                'actual_minutes' => $t->actual_minutes,
                'sort_order' => $t->sort_order,
                'assigned_to' => $t->assignedTo ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name] : null,
                'created_by_name' => $t->createdBy?->name,
                'subtasks' => $t->subtasks->map(fn ($st) => [
                    'id' => $st->id, 'title' => $st->title, 'status' => $st->status,
                    'assigned_to' => $st->assignedTo ? ['id' => $st->assignedTo->id, 'name' => $st->assignedTo->name] : null,
                ])->values(),
                'created_at' => $t->created_at->toISOString(),
            ])->values(),
            'discussions' => $alert->discussions->map(fn ($d) => [
                'id' => $d->id,
                'type' => $d->type,
                'content' => $d->content,
                'is_internal' => $d->is_internal,
                'attachments' => $d->attachments ?? [],
                'mentions' => $d->mentions ?? [],
                'user' => ['id' => $d->user->id, 'name' => $d->user->name],
                'edited_at' => $d->edited_at?->toISOString(),
                'created_at' => $d->created_at->toISOString(),
                'replies' => $d->replies->map(fn ($r) => [
                    'id' => $r->id, 'type' => $r->type, 'content' => $r->content,
                    'is_internal' => $r->is_internal, 'attachments' => $r->attachments ?? [],
                    'user' => ['id' => $r->user->id, 'name' => $r->user->name],
                    'edited_at' => $r->edited_at?->toISOString(),
                    'created_at' => $r->created_at->toISOString(),
                ])->values(),
            ])->values(),
            'watchers' => $alert->watchers->map(fn ($w) => [
                'id' => $w->id,
                'user_id' => $w->user_id,
                'user_name' => $w->user->name,
            ])->values(),
            'time_entries' => $alert->timeEntries->map(fn ($te) => [
                'id' => $te->id,
                'user_name' => $te->user->name,
                'user_id' => $te->user_id,
                'started_at' => $te->started_at?->toISOString(),
                'ended_at' => $te->ended_at?->toISOString(),
                'duration_minutes' => $te->duration_minutes,
                'description' => $te->description,
                'task_id' => $te->task_id,
                'is_running' => $te->started_at && ! $te->ended_at,
                'created_at' => $te->created_at->toISOString(),
            ])->values(),
            'time_spent_minutes' => $alert->time_spent_minutes ?? 0,
            'is_watching' => $alert->watchers->contains('user_id', $user->id),
            'config_options' => [
                'categories' => ConfigOption::forGroup('category'),
                'resolution_codes' => ConfigOption::forGroup('resolution_code'),
            ],
            'incident_defaults' => [
                'immediate_action_taken' => $immediateControls?->content ?? '',
                'source_note' => $immediateControls ? [
                    'id' => $immediateControls->id,
                    'user_name' => $immediateControls->user?->name,
                    'created_at' => optional($immediateControls->created_at)->toISOString(),
                ] : null,
            ],
            'linked_incident' => $linkedIncident ? [
                'id' => $linkedIncident->id,
                'reference_number' => $linkedIncident->reference_number,
                'status' => $linkedIncident->status,
                'severity' => $linkedIncident->severity,
                'title' => $linkedIncident->title,
                'href' => $canViewIncident
                    ? '/incidents?incident='.$linkedIncident->id
                    : null,
            ] : null,
            'linked_hs_event' => $this->hsVisibility->forControlRoomAlert($alert, $user),
            'linked_it_work' => $monitoringContext['linked_it_work'],
            'monitoring_incident_evidence' => $monitoringContext['incident_evidence'],
            'linked_operational_evidence' => $linkedOperationalEvidence,
            'resolve_gate' => $resolveGate,
            'close_gate' => $closeGate,
            'journey_state' => $this->journeyPresenter->journeyState(
                $linkedIncident,
                $alert,
                $linkedHsEvent,
            ),
        ];
    }

    private function assignableStaff(User $user): Collection
    {
        if (! $user->canDo('controlRoom.alerts.assign') && ! $user->canDo('controlRoom.alerts.manage')) {
            return collect();
        }

        $staffQuery = User::staff()->orderBy('name');
        $this->siteAccess->applyControlRoomAssigneeScope(
            $staffQuery,
            $user,
            ['reports.viewAny'],
        );

        return $staffQuery->get(['id', 'name', 'email']);
    }
}
