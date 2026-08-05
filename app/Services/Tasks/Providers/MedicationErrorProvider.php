<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationError;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class MedicationErrorProvider implements HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'med_error';
    }

    public function label(): string
    {
        return 'Medication Errors';
    }

    public function modelClass(): string
    {
        return MedicationError::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = MedicationError::query()
            ->with('client:id,first_name,last_name')
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('reported_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('status', ['reported', 'investigating']);
        }

        return $query->get()->map(function (MedicationError $error) {
            $client = $error->client;

            $title = ucfirst(str_replace('_', ' ', (string) $error->error_type));

            if ($client) {
                $title .= ' — '.trim($client->first_name.' '.$client->last_name);
            }

            return new TaskItem(
                id: 'med_error-'.$error->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $error->reference_number,
                title: $title,
                status: (string) $error->status,
                bucket: match ($error->status) {
                    'resolved', 'closed' => TaskItem::BUCKET_DONE,
                    'investigating' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($error->severity),
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                dueAt: null,
                createdAt: optional($error->created_at)->toIso8601String(),
                link: '/emar/errors',
                type: 'Medication error',
                description: $error->description ? str($error->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
