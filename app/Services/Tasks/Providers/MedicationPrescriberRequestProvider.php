<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationExternalProposal;
use App\Models\User;
use App\Services\Medication\Connected\ConnectedCareSettings;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\UserSiteAccessService;

/**
 * EA-025: an outside prescriber's request waits for a decision. Order
 * managers at the person's house see it until it is decided — only for
 * people they may read, and controlled requests only with controlled view.
 * Offered only while the outside prescriber portal runs (Settings ›
 * Connected services). A projection of the request itself; no task store.
 */
final class MedicationPrescriberRequestProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'med_prescriber_request';
    }

    public function label(): string
    {
        return 'Prescriber requests';
    }

    public function modelClass(): string
    {
        return MedicationExternalProposal::class;
    }

    public function canView(User $user): bool
    {
        return $user->approved_at !== null && $user->canDo('medications.orders.manage')
            && app(ConnectedCareSettings::class)->enabled(ConnectedCareSettings::PORTAL);
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = MedicationExternalProposal::query()->with(['client.site'])
            ->where('status', 'submitted')
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderBy('submitted_at')->orderBy('id');

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user), $query,
            function ($query, User $actor) {
                $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
                $candidates = (clone $query)->whereHas('client', fn ($c) => $c->whereIn('site_id', $siteIds))
                    ->pluck('client_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

                return $query->whereIn('client_id', app(MedicationRecordAccess::class)->readableClientIds($actor, $candidates))
                    ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled', false));
            },
            fn (MedicationExternalProposal $proposal) => new TaskItem(
                id: $this->sourceKey().'-'.$proposal->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                ref: null, title: 'Prescriber request waiting — '.$proposal->client->full_name,
                status: 'submitted', bucket: TaskItem::BUCKET_OPEN,
                severity: $proposal->kind === 'stop' ? 'high' : 'medium',
                client: ['id' => (int) $proposal->client_id, 'name' => $proposal->client->full_name],
                site: ['id' => (int) $proposal->client->site_id, 'name' => $proposal->client->site?->name],
                dueAt: null, createdAt: $proposal->submitted_at?->toIso8601String(),
                link: '/emar/connected-care?client_id='.$proposal->client_id.'#requests',
                type: match ($proposal->kind) {
                    'stop' => 'Request to stop a medicine',
                    'change' => 'Request to change a medicine',
                    default => 'Request to start a medicine',
                },
                actionLabel: 'Decide the request',
            ));
    }
}
