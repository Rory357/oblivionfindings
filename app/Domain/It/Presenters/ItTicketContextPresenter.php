<?php

namespace App\Domain\It\Presenters;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\ItWorkTask;
use App\Models\User;
use BackedEnum;

final class ItTicketContextPresenter
{
    /**
     * @return array{devices: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
     */
    public function present(ItTicket $ticket, User $viewer): array
    {
        $ticket->loadMissing('links.linkable');

        $devices = $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'affected_device'
                && $link->linkable instanceof Device
                && $this->canViewDevice($ticket, $link->linkable, $viewer))
            ->map(fn (ItTicketLink $link): array => $this->presentDevice($link->linkable))
            ->values()
            ->all();

        $alerts = $ticket->links
            ->filter(fn (ItTicketLink $link): bool => $link->relationship === 'source_alert'
                && $link->linkable instanceof ControlRoomAlert
                && $this->canViewAlert($ticket, $link->linkable, $viewer))
            ->map(fn (ItTicketLink $link): array => $this->presentAlert($link->linkable))
            ->values()
            ->all();

        return [
            'devices' => $devices,
            'alerts' => $alerts,
            'tasks' => $this->presentTasks($ticket, $viewer),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function presentTasks(ItTicket $ticket, User $viewer): array
    {
        if (! $viewer->canDo('it.view')) {
            return [];
        }

        return $ticket->tasks()
            ->with(['dependencies:id,title,status', 'team:id,name', 'assignee:id,name', 'completedBy:id,name'])
            ->get()
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

    private function canViewDevice(ItTicket $ticket, Device $device, User $viewer): bool
    {
        return $viewer->canDo('securityDevices.devices.view')
            && (int) $device->tenant_id === (int) $ticket->tenant_id;
    }

    private function canViewAlert(ItTicket $ticket, ControlRoomAlert $alert, User $viewer): bool
    {
        if (! $viewer->canDo('controlRoom.viewAny')) {
            return false;
        }

        $alert->loadMissing(['site:id,tenant_id', 'device.canonicalDevice:id,tenant_id']);
        $tenantId = is_numeric($alert->site?->tenant_id)
            ? (int) $alert->site->tenant_id
            : (is_numeric($alert->device?->canonicalDevice?->tenant_id)
                ? (int) $alert->device->canonicalDevice->tenant_id
                : null);

        return $tenantId !== null && $tenantId === (int) $ticket->tenant_id;
    }

    /** @return array<string, mixed> */
    private function presentDevice(Device $device): array
    {
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
        ];
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
