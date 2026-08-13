<?php

namespace App\Services\Tasks\Providers;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetServiceSchedule;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\Tasks\Contracts\ProvidesTaskSourceAliases;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

/**
 * Fleet maintenance backlog: work orders that are due (or about to be) and
 * recurring service schedules falling due within the week.
 *
 * Visibility mirrors the maintenance read routes exactly
 * (routes/fleet-assets.php: permission:fleet.viewAny|assets.viewAny) — the
 * same gate FleetIncidentProvider uses.
 */
class FleetMaintenanceProvider implements ProvidesTaskSourceAliases, SiteScopedTaskProvider, TaskProvider
{
    /** How far ahead a due date counts as actionable. */
    private const HORIZON_DAYS = 7;

    public function sourceKey(): string
    {
        return 'fleet_maintenance';
    }

    public function label(): string
    {
        return 'Fleet Maintenance';
    }

    public function sourceAliases(): array
    {
        return [
            'fleet_work_order',
            'fleet_service_schedule',
        ];
    }

    public function legacySourceAliasForId(User $user, int $id): ?string
    {
        $assetIds = app(SecurityDevicesAccessService::class)->authorizedAssetIds($user);

        // Historical provider order was work orders first, then schedules.
        // Resolve by record existence, never by due horizon or lifecycle state.
        if (FleetWorkOrder::query()->whereKey($id)->whereIn('asset_id', $assetIds)->exists()) {
            return 'fleet_work_order';
        }

        if (FleetServiceSchedule::query()->whereKey($id)->whereIn('asset_id', $assetIds)->exists()) {
            return 'fleet_service_schedule';
        }

        return null;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('fleet.viewAny') || $user->canDo('assets.viewAny');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        return array_merge(
            $this->workOrders($user, $filters),
            $this->serviceSchedules($user, $filters),
        );
    }

    /**
     * Open/overdue work orders with a due date past or inside the horizon.
     *
     * @return TaskItem[]
     */
    private function workOrders(User $user, array $filters): array
    {
        $query = FleetWorkOrder::query()
            ->with(['asset:id,name', 'assignedTo:id,name'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays(self::HORIZON_DAYS))
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderBy('due_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => $scoped->whereIn(
                'asset_id',
                app(SecurityDevicesAccessService::class)->authorizedAssetIds($actor),
            ),
            function (FleetWorkOrder $order) {
                $title = $order->title ?: 'Work order';

                if ($order->asset) {
                    $title .= ' — '.$order->asset->name;
                }

                return new TaskItem(
                    id: 'fleet_work_order-'.$order->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $order->reference_number,
                    title: $title,
                    status: (string) $order->status,
                    bucket: match ($order->status) {
                        'completed', 'cancelled' => TaskItem::BUCKET_DONE,
                        'in_progress' => TaskItem::BUCKET_IN_PROGRESS,
                        default => TaskItem::BUCKET_OPEN, // open / on_hold
                    },
                    severity: match ($order->priority) {
                        'urgent' => 'critical',
                        'high' => 'high',
                        'medium' => 'medium',
                        default => 'low',
                    },
                    assignee: $order->assignedTo
                        ? ['id' => $order->assignedTo->id, 'name' => (string) $order->assignedTo->name]
                        : null,
                    dueAt: optional($order->due_at)->toIso8601String(),
                    createdAt: optional($order->created_at)->toIso8601String(),
                    link: "/fleet-assets/maintenance/work-orders/{$order->id}",
                    type: 'Work order',
                    description: $order->description ? str($order->description)->limit(140)->toString() : null,
                );
            },
        );
    }

    /**
     * Active service schedules due within the horizon (or overdue). These
     * have no "done" state of their own — completing the service rolls
     * next_due_at forward, which drops the row out of this list.
     *
     * @return TaskItem[]
     */
    private function serviceSchedules(User $user, array $filters): array
    {
        $query = FleetServiceSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', now()->addDays(self::HORIZON_DAYS))
            ->with('asset:id,name')
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderBy('next_due_at')
            ->limit(300);

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => $scoped->whereIn(
                'asset_id',
                app(SecurityDevicesAccessService::class)->authorizedAssetIds($actor),
            ),
            function (FleetServiceSchedule $schedule) {
                $title = 'Service due';

                if ($schedule->asset) {
                    $title .= ' — '.$schedule->asset->name;
                }
                if ($schedule->name) {
                    $title .= ' ('.$schedule->name.')';
                }

                $overdue = $schedule->next_due_at->isPast();

                return new TaskItem(
                    id: 'fleet_service_schedule-'.$schedule->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: null,
                    title: $title,
                    status: $overdue ? 'overdue' : 'due',
                    bucket: TaskItem::BUCKET_OPEN,
                    severity: $overdue ? 'high' : 'medium',
                    assignee: null,
                    dueAt: $schedule->next_due_at->toIso8601String(),
                    createdAt: optional($schedule->created_at)->toIso8601String(),
                    link: '/fleet-assets/maintenance/schedules',
                    type: 'Service schedule',
                );
            },
        );
    }
}
