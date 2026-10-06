<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Services\Medication\Downtime\DowntimeService;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

/** Named-person projections of immutable paper evidence, independent of PIN-2 nominations. */
abstract class MedicationPaperConfirmationProvider implements SiteScopedTaskProvider, TaskProvider
{
    abstract protected function kind(): string;

    public function sourceKey(): string
    {
        return 'med_paper_'.$this->kind();
    }

    public function label(): string
    {
        return $this->kind() === 'giver' ? 'Paper giver confirmations' : 'Paper second-person confirmations';
    }

    public function canView(User $user): bool
    {
        return $user->approved_at !== null && $user->canDo('medications.view')
            && ($user->canDo('medications.administer.record')
                || $user->hasRole('admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead'));
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user)) {
            return [];
        }

        // This owning-domain adapter applies named-person, current Site, canonical person/order
        // and saved/current controlled-view checks before projecting any paper evidence.
        return collect(app(DowntimeService::class)->pendingConfirmations($user))
            ->filter(fn ($row) => $row['kind'] === $this->kind()
                && (! isset($filters['id']) || (int) $filters['id'] === $row['paper_entry_id']))
            ->map(fn ($row) => new TaskItem(
                id: $this->sourceKey().'-'.$row['paper_entry_id'], source: $this->sourceKey(), sourceLabel: $this->label(),
                ref: 'Paper-'.$row['paper_entry_id'], title: $row['label'], status: 'confirmation_needed',
                bucket: TaskItem::BUCKET_OPEN, severity: 'medium',
                assignee: ['id' => (int) $user->id, 'name' => $user->name],
                client: ['id' => $row['client_id'], 'name' => $row['client_name']],
                site: ['id' => $row['site_id'], 'name' => $row['site_name']],
                createdAt: $row['entered_at'], link: $row['href'].'?paper_entry='.$row['paper_entry_id'],
                type: $this->label(), actionLabel: 'Confirm your paper record',
            ))->values()->all();
    }
}
