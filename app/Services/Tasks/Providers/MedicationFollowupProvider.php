<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationFollowup;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

final class MedicationFollowupProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'medication-followup';
    }

    public function label(): string
    {
        return 'Medication follow-ups';
    }

    public function modelClass(): string
    {
        return MedicationFollowup::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $work = app(MedicationFollowupService::class);
        $query = MedicationFollowup::query()->with(['client.site', 'medication', 'owner', 'originalOwner'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->when(empty($filters['include_done']), fn ($q) => $q->whereNull('completed_at'))
            ->orderBy('due_at')->orderBy('id');

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user), $query,
            fn ($q, $actor) => $q->whereIn('id', $work->visibleQuery($actor)->select('medication_followups.id')),
            function (MedicationFollowup $row) use ($work, $user): TaskItem {
                $dto = $work->present($row, $user);

                return new TaskItem(
                    id: 'medication-followup-'.$row->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: null, title: $dto['label'].' — '.$dto['client']['name'], status: $dto['state'],
                    bucket: $row->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                    severity: $dto['state'] === 'overdue' ? 'high' : 'medium',
                    assignee: $dto['owner'], client: $dto['client'], site: $dto['site'],
                    dueAt: $dto['due_at'], createdAt: $row->created_at?->toIso8601String(),
                    link: $dto['url'], type: $dto['label'],
                    description: $dto['medication']['name'] ?? null,
                    sourceContext: 'Medication follow-up', actionLabel: 'Open medication follow-up',
                );
            });
    }
}
