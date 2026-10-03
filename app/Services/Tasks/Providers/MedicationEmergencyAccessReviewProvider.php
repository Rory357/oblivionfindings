<?php

namespace App\Services\Tasks\Providers;

use App\Models\ClientBreakGlassAccess;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\UserSiteAccessService;

/** Projection of the canonical review obligation; no separate task/workflow store. */
class MedicationEmergencyAccessReviewProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'med_emergency_review';
    }

    public function label(): string
    {
        return 'Emergency access reviews';
    }

    public function modelClass(): string
    {
        return ClientBreakGlassAccess::class;
    }

    public function canView(User $user): bool
    {
        return $user->approved_at !== null && $user->canDo('medications.audit.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = ClientBreakGlassAccess::withTrashed()->with(['client.site'])
            ->whereNull('review_outcome')->where('user_id', '!=', $user->id)
            ->where(fn ($q) => $q->whereNull('co_signed_by')->orWhere('co_signed_by', '!=', $user->id))
            ->where(fn ($q) => $q->whereNotNull('ended_at')->orWhereNotNull('deleted_at')->orWhere('expires_at', '<=', now()))
            ->whereRaw('COALESCE(review_due_at, DATE_ADD(COALESCE(ended_at, deleted_at, expires_at), INTERVAL 2 DAY)) < ?', [now()])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']));

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user), $query,
            fn ($query, User $actor) => $query->whereHas('client', fn ($c) => $c->whereIn('site_id',
                app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['medications.audit.view']))),
            fn (ClientBreakGlassAccess $grant) => new TaskItem(
                id: $this->sourceKey().'-'.$grant->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                ref: 'EA-'.$grant->id, title: 'Review ended emergency access — '.$grant->client->full_name,
                status: 'awaiting_review', bucket: TaskItem::BUCKET_OPEN, severity: 'high',
                client: ['id' => (int) $grant->client_id, 'name' => $grant->client->full_name],
                site: ['id' => (int) $grant->client->site_id, 'name' => $grant->client->site?->name],
                dueAt: $grant->reviewDueTime()?->toIso8601String(), createdAt: $grant->created_at?->toIso8601String(),
                link: '/emar/emergency-access?view=review&grant='.$grant->id,
                type: 'Independent emergency access review', actionLabel: 'Review the grant',
            ));
    }
}
