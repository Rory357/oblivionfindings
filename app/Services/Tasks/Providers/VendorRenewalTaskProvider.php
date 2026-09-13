<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Models\VendorRenewalFollowup;
use App\Services\Sites\VendorCommercialAccess;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class VendorRenewalTaskProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string { return 'vendor_renewal'; }
    public function label(): string { return 'Vendor renewals'; }
    public function modelClass(): string { return VendorRenewalFollowup::class; }

    public function canView(User $user): bool
    {
        return Schema::hasTable('vendor_renewal_followups') && app(VendorCommercialAccess::class)->capable($user);
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user)) return [];
        $access = app(VendorCommercialAccess::class);
        $query = VendorRenewalFollowup::query()->with(['agreement.vendor:id,company_name,is_active', 'agreement.site:id,name', 'owner'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->when(empty($filters['include_done']), fn ($q) => $q->whereNotIn('status', ['reviewed', 'cancelled', 'unscheduled']))
            ->orderBy('id');

        $items = app(TaskProviderAuthorization::class)->siteScoped(
            $user, true, $query,
            fn ($q, User $actor) => $q->whereIn('agreement_id', $access->query($actor)->select('vendor_agreements.id')),
            function (VendorRenewalFollowup $followup) use ($access): TaskItem {
                $agreement = $followup->agreement;
                $owner = $followup->owner;
                $ownerAllowed = $owner && $access->query($owner)->whereKey($agreement->id)->exists();
                $done = in_array($followup->status, ['reviewed', 'cancelled'], true) || app(\App\Services\Sites\VendorAgreements::class)->cancelled($agreement);
                $due = $followup->due_on ? Carbon::parse($followup->due_on->toDateString(), config('app.worker_timezone'))->endOfDay()->toIso8601String() : null;
                return new TaskItem(
                    id: 'vendor_renewal-'.$followup->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: 'AGR-'.$agreement->id, title: 'Review vendor renewal',
                    status: $done ? 'completed' : ($ownerAllowed ? $followup->status : 'owner_unavailable'),
                    bucket: $done ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                    severity: 'medium',
                    assignee: $ownerAllowed ? ['id' => (int) $owner->id, 'name' => $owner->name] : null,
                    site: $agreement->site ? ['id' => (int) $agreement->site->id, 'name' => $agreement->site->name] : null,
                    dueAt: $due, createdAt: $followup->created_at?->toIso8601String(),
                    link: '/vendors/'.$agreement->vendor_id.'?agreement='.$agreement->id,
                    type: 'Commercial renewal review', restricted: true,
                    sourceContext: $agreement->vendor->company_name,
                    actionLabel: 'Review agreement',
                    displayState: $done ? ($followup->status === 'reviewed' ? 'Reviewed' : 'Cancelled') : ($ownerAllowed ? 'Renewal review' : 'Owner access needs review'),
                    actionHelp: $ownerAllowed ? null : 'A commercial manager must choose a current owner with access before this follow-up can be assigned.',
                );
            },
        );
        return empty($filters['include_done']) ? array_values(array_filter($items, fn (TaskItem $item) => $item->bucket !== TaskItem::BUCKET_DONE)) : $items;
    }
}
