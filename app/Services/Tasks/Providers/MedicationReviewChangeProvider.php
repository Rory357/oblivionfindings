<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationReviewItem;
use App\Models\User;
use App\Services\Medication\Reviews\MedicationReviewReader;
use App\Services\Medication\Reviews\MedicationReviewWorkflow;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

/** Recommendations have their own stable item ID; Tasks never invents an order action. */
final class MedicationReviewChangeProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'medication_review_change';
    }

    public function label(): string
    {
        return 'Medication review changes';
    }

    public function modelClass(): string
    {
        return MedicationReviewItem::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view') && ($user->canDo(MedicationReviewWorkflow::MANAGE) || $user->canDo('medications.orders.manage'));
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user)) {
            return [];
        }
        $query = MedicationReviewItem::query()->whereIn('client_id', app(MedicationReviewReader::class)->clientIds($user))
            ->whereHas('review', fn ($q) => $q->where('status', 'completed')->whereColumn('medication_reviews.client_id', 'medication_review_items.client_id'))
            ->where(fn ($q) => $q->whereNull('client_medication_id')->orWhereHas('medication', fn ($m) => $m->whereColumn('client_medications.client_id', 'medication_review_items.client_id')))
            ->whereIn('outcome', ['change', 'stop', 'swap', 'start', 'pending_controlled'])
            ->with(['review.client.site', 'review.owner', 'medication'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))->orderBy('id');
        if (! $user->canDo('medications.controlled.view')) {
            $query->where('controlled_snapshot', false)->where('classification_pending', false)->whereDoesntHave('medication', fn ($q) => $q->where('controlled_drug', true));
        }
        if (empty($filters['include_done'])) {
            $query->where('decision', '!=', 'not_agreed')->whereNull('linked_order_version_id');
        }
        if (! $user->canDo(MedicationReviewWorkflow::MANAGE)) {
            $query->where('decision', 'agreed');
        }

        return $query->get()->map(function (MedicationReviewItem $item): TaskItem {
            $done = $item->decision === 'not_agreed';
            $status = $item->outcome === 'pending_controlled' ? 'Outcome to add' : ($done ? 'Not agreed by the prescriber' : ($item->linked_order_version_id ? 'Entered in Orders — check status there' : ($item->decision === 'agreed' ? 'Agreed — to enter in Orders' : 'Waiting for the prescriber’s decision')));

            return new TaskItem(id: 'medication_review_change-'.$item->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                ref: 'R-'.$item->review_id, title: $item->name_snapshot.' — '.$item->review->client->full_name,
                status: $status, bucket: $done ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN, severity: 'info',
                assignee: $item->review->owner ? ['id' => $item->review->owner->id, 'name' => $item->review->owner->name] : null,
                client: ['id' => $item->client_id, 'name' => $item->review->client->full_name],
                site: ['id' => $item->review->client->site_id, 'name' => $item->review->client->site?->name],
                createdAt: $item->created_at?->toIso8601String(), link: '/emar/reviews?review='.$item->review_id.'&item='.$item->id,
                type: 'Medication change', description: null, actionLabel: 'Open recommendation');
        })->all();
    }
}
