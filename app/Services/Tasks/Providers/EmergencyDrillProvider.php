<?php

namespace App\Services\Tasks\Providers;

use App\Models\EmergencyDrill;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\EmergencyDrillReturn;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Canonical source work only; Tasks cannot assign, complete or verify this record. */
class EmergencyDrillProvider implements ContextualHealthSafetyTaskProvider, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'emergency_drill';
    }

    public function label(): string
    {
        return 'Emergency drills';
    }

    public function canView(User $viewer): bool
    {
        return $viewer->canDo('hazards.view');
    }

    public function authorizedTasks(User $viewer, array $filters = []): array
    {
        return $this->readTasks($viewer, $filters);
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->readTasks($viewer, $filters, $context);
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->readTasks($viewer, [], $context, true);
    }

    private function readTasks(User $viewer, array $filters, ?HealthSafetyReadContext $context = null, bool $navigation = false): array
    {
        $access = app(EmergencyDrillAccessService::class);
        try {
            $operation = function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($access, $filters, $navigation) {
                if (! $this->canView($current)) {
                    return [];
                }
                $records = $reads->query($access->scopeForSites(EmergencyDrill::query(), $sites))
                    ->where('conducted_by', $current->id)
                    ->whereIn('status', empty($filters['include_done']) ? ['scheduled', 'in_progress'] : ['scheduled', 'in_progress', 'completed', 'cancelled'])
                    ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
                    ->with($navigation ? [] : ['site:id,name'])->orderBy('scheduled_at')->orderBy('id')->get();
                $items = [];
                foreach ($records as $record) {
                    if ($navigation) {
                        $items[] = new TaskItem(id: 'emergency_drill-'.$record->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                            ref: null, title: '', status: $record->status,
                            bucket: $record->status === 'scheduled' ? TaskItem::BUCKET_OPEN : TaskItem::BUCKET_IN_PROGRESS,
                            severity: 'info', assignee: ['id' => $current->id], dueAt: $record->scheduled_at?->toIso8601String());

                        continue;
                    }
                    $items[] = new TaskItem(id: 'emergency_drill-'.$record->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                        ref: 'DR-'.$record->id, title: $record->title, status: $record->status,
                        bucket: match ($record->status) {
                            'scheduled' => TaskItem::BUCKET_OPEN, 'in_progress' => TaskItem::BUCKET_IN_PROGRESS, default => TaskItem::BUCKET_DONE
                        },
                        severity: 'info', assignee: ['id' => $current->id, 'name' => $current->name],
                        site: $record->site ? ['id' => $record->site->id, 'name' => $record->site->name] : null,
                        dueAt: $record->scheduled_at?->toIso8601String(), createdAt: $record->created_at?->toIso8601String(),
                        link: EmergencyDrillReturn::href($record->id, $this->origin($filters), 'run'), type: 'Emergency drill',
                        actionLabel: 'Open drill', displayState: match ($record->status) {
                            'scheduled' => 'Scheduled', 'in_progress' => 'Drill in progress', 'completed' => 'Completion recorded', default => 'Cancelled'
                        },
                        actionHelp: 'The drill owns planning and completion. Findings and corrective remedy verification remain separate.');
                }

                return $items;
            };

            return $context === null
                ? $access->withCurrentViewer($viewer, $operation)
                : $access->withReadContext($context, $operation);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return [];
        }
    }

    private function origin(array $filters): string
    {
        $origin = EmergencyDrillReturn::leaf($filters['return_to'] ?? null);

        return $origin !== null && in_array(parse_url($origin, PHP_URL_PATH), ['/tasks', '/my-day'], true)
            ? $origin : (request()->is('my-day') ? '/my-day' : '/tasks');
    }
}
