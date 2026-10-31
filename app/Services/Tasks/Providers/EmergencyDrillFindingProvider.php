<?php

namespace App\Services\Tasks\Providers;

use App\Models\EmergencyDrillFinding;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\EmergencyDrillFindingTaskItem;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\EmergencyDrillReturn;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Canonical source work only; Tasks cannot assign, complete or verify this record. */
class EmergencyDrillFindingProvider implements ContextualHealthSafetyTaskProvider, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'emergency_drill_finding';
    }

    public function label(): string
    {
        return 'Drill findings';
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
                $records = $reads->query(EmergencyDrillFinding::query())
                    ->where('assigned_to', $current->id)
                    ->whereHas('emergencyDrill', fn ($q) => $access->scopeForSites($q, $sites))
                    ->when(empty($filters['include_done']), fn ($q) => $q->whereNotIn('status', ['resolved', 'closed', 'accepted'])->whereHas('emergencyDrill', fn ($q) => $q->where('status', '!=', 'cancelled')))
                    ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
                    ->with($navigation ? [] : ['emergencyDrill.site:id,name'])->orderBy('due_date')->orderBy('id')->get();
                $items = [];
                foreach ($records as $record) {
                    if ($navigation) {
                        // Open-only source query excludes cancelled parents and terminal findings.
                        $items[] = new TaskItem(id: 'emergency_drill_finding-'.$record->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                            ref: null, title: '', status: $record->status,
                            bucket: $record->status === 'in_progress' ? TaskItem::BUCKET_IN_PROGRESS : TaskItem::BUCKET_OPEN,
                            severity: 'info', assignee: ['id' => $current->id],
                            dueAt: $record->due_date ? Carbon::parse($record->due_date->toDateString().' 23:59:59', config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String() : null);

                        continue;
                    }
                    $drill = $record->emergencyDrill;
                    if (! $drill) {
                        continue;
                    }
                    $done = in_array($record->status, ['resolved', 'closed', 'accepted'], true) || $drill->status === 'cancelled';
                    $items[] = new EmergencyDrillFindingTaskItem(id: 'emergency_drill_finding-'.$record->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                        ref: 'DR-'.$drill->id, title: $record->description, status: $record->status,
                        bucket: $done ? TaskItem::BUCKET_DONE : ($record->status === 'in_progress' ? TaskItem::BUCKET_IN_PROGRESS : TaskItem::BUCKET_OPEN),
                        severity: TaskItem::normaliseSeverity($record->severity), assignee: ['id' => $current->id, 'name' => $current->name],
                        site: $drill->site ? ['id' => $drill->site->id, 'name' => $drill->site->name] : null,
                        dueAt: $record->due_date ? Carbon::parse($record->due_date->toDateString().' 23:59:59', config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String() : null,
                        createdAt: $record->created_at?->toIso8601String(),
                        link: EmergencyDrillReturn::href($drill->id, $this->origin($filters), 'findings', $record->id), type: 'Drill finding',
                        actionLabel: 'Open finding', displayState: $drill->status === 'cancelled' && ! in_array($record->status, ['resolved', 'closed', 'accepted'], true) ? 'Drill cancelled — finding retained' : ucfirst(str_replace('_', ' ', $record->status)),
                        actionHelp: 'Finding resolution requires recorded evidence. A required corrective remedy has its own independent verification.');
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
