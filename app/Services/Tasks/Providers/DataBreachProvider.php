<?php

namespace App\Services\Tasks\Providers;

use App\Models\DataBreachLog;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class DataBreachProvider implements TaskProvider, HasModelClass
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

    public function tasks(User $user, array $filters = []): array
    {
        $query = DataBreachLog::query()
            ->orderByDesc('discovered_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['resolved']);
        }

        return $query->get()->map(function (DataBreachLog $breach) {
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
        })->all();
    }
}
