<?php

namespace App\Domain\It\Presenters;

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Monitoring\Presenters\MonitoringIncidentEvidencePresenter;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\ItWorkTask;
use App\Models\User;
use App\Services\UserSiteAccessService;
use BackedEnum;
use Illuminate\Support\Facades\Gate;

final class ItTicketContextPresenter
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly UserSiteAccessService $siteAccess,
        private readonly MonitoringIncidentEvidencePresenter $incidentEvidence,
    ) {}

    /**
     * @return array{devices: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>, incident_evidence: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>, problems: array<int, array<string, mixed>>, changes: array<int, array<string, mixed>>, major_incidents: array<int, array<string, mixed>>}
     */
    public function present(ItTicket $ticket, User $viewer): array
    {
        if (! $this->workAccess->canView($viewer, $ticket)) {
            return [
                'devices' => [],
                'alerts' => [],
                'incident_evidence' => [],
                'tasks' => [],
                'problems' => [],
                'changes' => [],
                'major_incidents' => [],
            ];
        }

        $ticket->loadMissing('links.linkable');

        $deviceLinks = $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'affected_device'
                && $link->linkable instanceof Device
                && $this->deviceWithinViewerScope($link->linkable, $viewer));
        $devices = ($viewer->canDo('securityDevices.devices.view')
            ? $deviceLinks->map(fn (ItTicketLink $link): array => $this->presentDevice($link->linkable, $link, $ticket, $viewer))
            : ($deviceLinks->isNotEmpty() ? collect([$this->restrictedDevice()]) : collect()))
            ->values()
            ->all();

        $alertLinks = $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'source_alert'
                && $link->linkable instanceof ControlRoomAlert
                && $this->alertWithinViewerScope($link->linkable, $viewer));
        $alerts = ($viewer->canDo('controlRoom.alerts.view')
            ? $alertLinks->map(fn (ItTicketLink $link): array => $this->presentAlert($link->linkable))
            : ($alertLinks->isNotEmpty() ? collect([$this->restrictedAlert()]) : collect()))
            ->values()
            ->all();

        return [
            'devices' => $devices,
            'alerts' => $alerts,
            'incident_evidence' => $this->incidentEvidence->forTicket($ticket, $viewer),
            'tasks' => $this->presentTasks($ticket, $viewer),
            'problems' => $this->presentProblems($ticket, $viewer),
            'changes' => $this->presentChanges($ticket, $viewer),
            'major_incidents' => $this->presentMajorIncidents($ticket, $viewer),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function presentMajorIncidents(ItTicket $ticket, User $viewer): array
    {
        return $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'major_incident_member'
                && $link->linkable instanceof ItTicket
                && $link->linkable->work_type === 'major_incident'
                && Gate::forUser($viewer)->allows('view', $link->linkable))
            ->map(function (ItTicketLink $link) use ($viewer): ?array {
                $majorIncidentTicket = $link->linkable;
                $majorIncidentTicket->loadMissing('majorIncidentProfile');
                if (! $majorIncidentTicket->majorIncidentProfile) {
                    return null;
                }
                $profile = $majorIncidentTicket->majorIncidentProfile;

                return [
                    'id' => $profile->id,
                    'reference' => $majorIncidentTicket->reference,
                    'title' => $majorIncidentTicket->title,
                    'workflow_state' => $majorIncidentTicket->workflow_state,
                    'severity' => $profile->severity,
                    'impact_summary' => $profile->impact_summary,
                    'restored_at' => $profile->restored_at?->toIso8601String(),
                    'next_update_due_at' => $profile->next_update_due_at?->toIso8601String(),
                    'href' => $viewer->canDo('it.view')
                        ? "/it/major-incidents/{$profile->id}"
                        : null,
                    'workspace_access' => $this->workspaceAccess($viewer),
                    'ticket_href' => "/it/tickets/{$majorIncidentTicket->id}",
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function presentChanges(ItTicket $ticket, User $viewer): array
    {
        return $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'related_change'
                && $link->linkable instanceof ItTicket
                && $link->linkable->work_type === 'change'
                && Gate::forUser($viewer)->allows('view', $link->linkable))
            ->map(function (ItTicketLink $link) use ($viewer): ?array {
                $changeTicket = $link->linkable;
                $changeTicket->loadMissing('changeProfile');
                if (! $changeTicket->changeProfile) {
                    return null;
                }

                return [
                    'id' => $changeTicket->changeProfile->id,
                    'reference' => $changeTicket->reference,
                    'title' => $changeTicket->title,
                    'workflow_state' => $changeTicket->workflow_state,
                    'change_type' => $changeTicket->changeProfile->change_type,
                    'risk_level' => $changeTicket->changeProfile->risk_level,
                    'is_restricted' => $changeTicket->changeProfile->is_restricted,
                    'maintenance_starts_at' => $changeTicket->changeProfile->maintenance_starts_at?->toIso8601String(),
                    'maintenance_ends_at' => $changeTicket->changeProfile->maintenance_ends_at?->toIso8601String(),
                    'href' => $viewer->canDo('it.view')
                        ? "/it/changes/{$changeTicket->changeProfile->id}"
                        : null,
                    'workspace_access' => $this->workspaceAccess($viewer),
                    'ticket_href' => "/it/tickets/{$changeTicket->id}",
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function presentProblems(ItTicket $ticket, User $viewer): array
    {
        return $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'related_problem'
                && $link->linkable instanceof ItTicket
                && $link->linkable->work_type === 'problem'
                && Gate::forUser($viewer)->allows('view', $link->linkable))
            ->map(function (ItTicketLink $link) use ($viewer): ?array {
                $problemTicket = $link->linkable;
                $problemTicket->loadMissing('problemProfile');
                if (! $problemTicket->problemProfile) {
                    return null;
                }

                return [
                    'id' => $problemTicket->problemProfile->id,
                    'reference' => $problemTicket->reference,
                    'title' => $problemTicket->title,
                    'workflow_state' => $problemTicket->workflow_state,
                    'root_cause' => $problemTicket->problemProfile->root_cause,
                    'workaround' => $problemTicket->problemProfile->workaround,
                    'known_error_at' => $problemTicket->problemProfile->known_error_at?->toIso8601String(),
                    'href' => $viewer->canDo('it.view')
                        ? "/it/problems/{$problemTicket->problemProfile->id}"
                        : null,
                    'workspace_access' => $this->workspaceAccess($viewer),
                    'ticket_href' => "/it/tickets/{$problemTicket->id}",
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function presentTasks(ItTicket $ticket, User $viewer): array
    {
        if (! $this->workAccess->canWork($viewer, $ticket)) {
            return [];
        }

        return $ticket->tasks()
            ->with(['dependencies:id,title,status', 'team:id,name', 'assignee:id,name', 'completedBy:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ItWorkTask $task): bool => Gate::forUser($viewer)->allows('view', $task))
            ->map(fn (ItWorkTask $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'due_at' => $task->due_at?->toIso8601String(),
                'is_required' => $task->is_required,
                'evidence_required' => $task->evidence_required,
                'evidence' => $task->evidence,
                'completion_note' => $task->completion_note,
                'completed_at' => $task->completed_at?->toIso8601String(),
                'sort_order' => $task->sort_order,
                'team' => $task->team ? ['id' => $task->team->id, 'name' => $task->team->name] : null,
                'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
                'completed_by' => $task->completedBy
                    ? ['id' => $task->completedBy->id, 'name' => $task->completedBy->name]
                    : null,
                'dependencies' => $task->dependencies
                    ->map(fn (ItWorkTask $dependency): array => [
                        'id' => $dependency->id,
                        'title' => $dependency->title,
                        'status' => $dependency->status,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function deviceWithinViewerScope(Device $device, User $viewer): bool
    {
        return $this->deviceAccess->visibleDevices($viewer)
            ->whereKey($device->getKey())
            ->exists();
    }

    private function alertWithinViewerScope(ControlRoomAlert $alert, User $viewer): bool
    {
        $query = ControlRoomAlert::query()->whereKey($alert->getKey());
        $this->siteAccess->applyAlertScope($query, $viewer);

        return $query->exists();
    }

    /** @return array<string, mixed> */
    private function presentDevice(Device $device, ItTicketLink $link, ItTicket $ticket, User $viewer): array
    {
        $isMonitoringEvidence = (($link->context ?? [])['system_principal'] ?? null)
            === ItTicketLinkService::MONITORING_PRINCIPAL;

        return [
            'id' => $device->id,
            'uid' => $device->device_uid,
            'name' => $device->name,
            'domain' => $this->value($device->domain),
            'category' => $device->category,
            'status' => $this->value($device->status),
            'health_status' => $this->value($device->health_status),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'href' => route('security-devices.devices.show', $device),
            'access' => [
                'state' => 'available',
                'message' => null,
            ],
            'is_monitoring_evidence' => $isMonitoringEvidence,
            'can_unlink' => ! $isMonitoringEvidence
                && in_array($ticket->work_type, ItTicket::INTAKE_WORK_TYPES, true)
                && ! $ticket->isMerged()
                && in_array($ticket->status, ItTicket::OPEN_STATUSES, true)
                && $this->workAccess->canWork($viewer, $ticket),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAlert(ControlRoomAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'reference' => $alert->reference_number,
            'alert_type' => $alert->alert_type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'href' => route('control-room.alerts.show', $alert),
            'access' => [
                'state' => 'available',
                'message' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function restrictedDevice(): array
    {
        return [
            'id' => null,
            'uid' => null,
            'name' => null,
            'domain' => null,
            'category' => null,
            'status' => null,
            'health_status' => null,
            'last_seen_at' => null,
            'href' => null,
            'access' => [
                'state' => 'restricted',
                'message' => 'Security & Devices access is required to open this Device.',
            ],
            'is_monitoring_evidence' => false,
            'can_unlink' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function restrictedAlert(): array
    {
        return [
            'id' => null,
            'reference' => null,
            'alert_type' => null,
            'severity' => null,
            'status' => null,
            'triggered_at' => null,
            'href' => null,
            'access' => [
                'state' => 'restricted',
                'message' => 'Control Room access is required to open this alert.',
            ],
        ];
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /** @return array{state: 'available'|'restricted', message: string|null} */
    private function workspaceAccess(User $viewer): array
    {
        if ($viewer->canDo('it.view')) {
            return ['state' => 'available', 'message' => null];
        }

        return [
            'state' => 'restricted',
            'message' => 'IT workspace access is required to open this record.',
        ];
    }
}
