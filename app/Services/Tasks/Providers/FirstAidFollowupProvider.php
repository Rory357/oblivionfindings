<?php

namespace App\Services\Tasks\Providers;

use App\Models\FirstAidFollowup;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class FirstAidFollowupProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'first_aid_followup';
    }

    public function label(): string
    {
        return 'First Aid Follow-ups';
    }

    public function modelClass(): string
    {
        return FirstAidFollowup::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/health-safety.php: the first-aid register is gated by hazards.view.
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = FirstAidFollowup::query()
            ->with([
                'record:id,reference_number,client_id,site_id',
                'record.client:id,first_name,last_name',
                'record.site:id,name',
                'assignedTo:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNull('completed_at');
        }

        return $query->get()->map(function (FirstAidFollowup $followup) {
            $record = $followup->record;
            $client = $record?->client;

            return new TaskItem(
                id: 'first_aid_followup-'.$followup->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $record?->reference_number,
                title: 'First aid follow-up',
                status: $followup->completed_at ? 'completed' : 'open',
                bucket: $followup->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                severity: 'medium',
                assignee: $followup->assignedTo
                    ? ['id' => $followup->assignedTo->id, 'name' => (string) $followup->assignedTo->name]
                    : null,
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                site: $record?->site
                    ? ['id' => $record->site->id, 'name' => (string) $record->site->name]
                    : null,
                dueAt: optional($followup->due_at)->toIso8601String(),
                createdAt: optional($followup->created_at)->toIso8601String(),
                link: "/health-safety/first-aid/{$followup->first_aid_record_id}",
                type: 'First aid follow-up',
                description: $followup->notes ? str($followup->notes)->limit(140)->toString() : null,
            );
        })->all();
    }
}
