<?php

namespace App\Services\Tasks\Providers;

use App\Models\ClientMedicationAdministration;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

/** Read-only dose projections disappear as soon as their canonical work is prepared. */
final class LegacyMedicationEffectProvider implements SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'medication-effect-source';
    }

    public function label(): string
    {
        return 'As-needed checks awaiting setup';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $work = app(MedicationFollowupService::class);
        $query = ClientMedicationAdministration::query()->with(['client.site', 'medication', 'administeredBy:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))->orderBy('id');

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user), $query,
            fn ($q, $actor) => $q->whereIn('id', $work->legacyEffectQuery($actor)->select('client_medication_administrations.id')),
            function (ClientMedicationAdministration $dose) use ($work, $user): TaskItem {
                $dto = $work->presentLegacyEffect($dose, $user);
                $overdue = $dto['due_at'] !== null && now()->gt($dto['due_at']);
                $link = $dto['can_prepare'] ? $dto['url'] : '/medication-followups?'.http_build_query([
                    'client_id' => $dose->client_id, 'type' => 'effect',
                ]);

                return new TaskItem(id: $this->sourceKey().'-'.$dose->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: null, title: 'As-needed effect check — '.$dto['client']['name'],
                    status: $overdue ? 'overdue' : ($dto['due_at'] === null ? 'unscheduled' : 'open'),
                    bucket: TaskItem::BUCKET_OPEN, severity: $overdue ? 'high' : 'medium',
                    assignee: $dto['owner'], client: $dto['client'], site: $dto['site'],
                    dueAt: $dto['due_at'], createdAt: $dose->created_at?->toIso8601String(), link: $link,
                    type: 'As-needed effect check', description: $dto['medication']['name'],
                    sourceContext: 'Existing dose — follow-up setup needed',
                    actionLabel: $dto['can_prepare'] ? 'Open effect check' : 'View effect checks');
            });
    }
}
