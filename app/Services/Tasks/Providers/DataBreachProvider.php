<?php

namespace App\Services\Tasks\Providers;

use App\Models\DataBreachLog;
use App\Models\User;
use App\Services\Tasks\Contracts\ExplicitlyGlobalTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

class DataBreachProvider implements ExplicitlyGlobalTaskProvider, HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'breach';
    }

    public function label(): string
    {
        return 'Privacy Breaches';
    }

    public function modelClass(): string
    {
        return DataBreachLog::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('privacy.reportBreaches');
    }

    public function globalViewPermissions(): array
    {
        return ['privacy.reportBreaches'];
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = DataBreachLog::query()
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('discovered_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['resolved']);
        }

        return app(TaskProviderAuthorization::class)->explicitlyGlobal(
            $user,
            $this->globalViewPermissions(),
            $query,
            function (DataBreachLog $breach) {
                return new TaskItem(
                    id: 'breach-'.$breach->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $breach->breach_reference,
                    title: ucfirst(str_replace('_', ' ', (string) $breach->breach_type)),
                    status: (string) $breach->status,
                    bucket: match ($breach->status) {
                        'resolved' => TaskItem::BUCKET_DONE,
                        'under_investigation', 'contained', 'notified' => TaskItem::BUCKET_IN_PROGRESS,
                        default => TaskItem::BUCKET_OPEN,
                    },
                    severity: TaskItem::normaliseSeverity($breach->severity),
                    dueAt: null,
                    createdAt: optional($breach->created_at)->toIso8601String(),
                    link: '/privacy/breaches',
                    type: 'Data breach',
                    description: $breach->nature_of_breach ? str($breach->nature_of_breach)->limit(140)->toString() : null,
                );
            },
        );
    }
}
