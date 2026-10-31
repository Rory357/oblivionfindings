<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjurySourceProjectionService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class WorkplaceInjuryProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'injury';
    }

    public function label(): string
    {
        return 'Workplace Injuries';
    }

    public function modelClass(): string
    {
        return WorkplaceInjury::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view') || $user->canDo('hr.wellbeing.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        // Fresh source authority decides access, including grants restored after this caller was loaded.
        return $this->readTasks($user, $filters);
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $this->readTasks($viewer, $filters, $context);
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $this->readTasks($viewer, [], $context);
    }

    private function readTasks(User $user, array $filters, ?HealthSafetyReadContext $context = null): array
    {
        // Callback returns only scalar source facts. No TaskItem/model/builder leaves Access authority.
        $rows = app(InjurySourceProjectionService::class)->taskRows($user, $filters, $context);
        return array_map(fn (array $injury): TaskItem => new TaskItem(
            id: 'injury-'.$injury['id'],
            source: $this->sourceKey(),
            sourceLabel: $this->label(),
            ref: $injury['reference'],
            title: 'Injury — '.$injury['worker']['name'],
            status: $injury['status'],
            bucket: match ($injury['status']) {
                'recovered', 'closed' => TaskItem::BUCKET_DONE,
                'under_treatment', 'return_to_work' => TaskItem::BUCKET_IN_PROGRESS,
                default => TaskItem::BUCKET_OPEN,
            },
            severity: $injury['severity'] === 'serious' ? 'high' : TaskItem::normaliseSeverity($injury['severity']),
            assignee: $injury['coordinator'],
            site: $injury['site'],
            dueAt: $injury['due_at'],
            createdAt: $injury['created_at'],
            link: $injury['href'],
            type: 'Injury',
            restricted: true, // Purpose-fixed facts: drawer must not hydrate private audit/follower context.
            description: $injury['description'] ? str($injury['description'])->limit(140)->toString() : null,
        ), $rows);
    }
}
