<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationReview;
use App\Models\User;
use App\Services\Medication\Reviews\MedicationReviewReader;
use App\Services\Medication\Reviews\MedicationReviewWorkflow;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Support\WorkerClock;

final class MedicationReviewProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'medication_review';
    }

    public function label(): string
    {
        return 'Medication reviews';
    }

    public function modelClass(): string
    {
        return MedicationReview::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view') && $user->canDo(MedicationReviewWorkflow::MANAGE);
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user)) {
            return [];
        }
        $query = app(MedicationReviewReader::class)->query($user)
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderBy('scheduled_date')->orderBy('id');
        if (empty($filters['include_done'])) {
            $query->whereIn('status', ['scheduled', 'overdue', 'in_progress']);
        }

        return $query->get()->map(function (MedicationReview $review): TaskItem {
            $open = in_array($review->status, ['scheduled', 'overdue', 'in_progress'], true);
            $overdue = $open && WorkerClock::daysUntil($review->scheduled_date) < 0;

            return new TaskItem(id: 'medication_review-'.$review->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                ref: 'R-'.$review->id, title: 'Medication review — '.$review->client->full_name,
                status: $open ? ($overdue ? 'overdue' : 'scheduled') : $review->status,
                bucket: $open ? TaskItem::BUCKET_OPEN : TaskItem::BUCKET_DONE, severity: $overdue ? 'high' : 'info',
                assignee: $review->owner ? ['id' => $review->owner->id, 'name' => $review->owner->name] : null,
                client: ['id' => $review->client_id, 'name' => $review->client->full_name],
                site: ['id' => $review->client->site_id, 'name' => $review->client->site?->name],
                dueAt: $review->scheduled_date ? WorkerClock::toUtc($review->scheduled_date->toDateString().' 23:59:59')->toIso8601String() : null,
                createdAt: $review->created_at?->toIso8601String(), link: '/emar/reviews?review='.$review->id,
                type: 'Medication review', actionLabel: $open ? 'Open review' : 'View review');
        })->all();
    }
}
