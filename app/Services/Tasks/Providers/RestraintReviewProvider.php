<?php

namespace App\Services\Tasks\Providers;

use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

/**
 * Restraint events awaiting their post-incident review (reviewed_at IS NULL)
 * — the genuinely actionable slice of the restraint register.
 */
class RestraintReviewProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'restraint_review';
    }

    public function label(): string
    {
        return 'Restraint Reviews';
    }

    public function modelClass(): string
    {
        return RestraintEvent::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors RestraintController::canReview() / routes/health-safety.php:
        // event review is gated by restraints.review|restraints.manage.
        return $user->canDo('restraints.review') || $user->canDo('restraints.manage');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = RestraintEvent::query()
            ->with(['client:id,first_name,last_name', 'site:id,name'])
            ->orderByDesc('started_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNull('reviewed_at');
        }

        return $query->get()->map(function (RestraintEvent $event) {
            $client = $event->client;

            return new TaskItem(
                id: 'restraint_review-'.$event->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $event->reference_number,
                title: 'Restraint awaiting review',
                status: $event->reviewed_at ? 'reviewed' : 'awaiting_review',
                bucket: $event->reviewed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                severity: TaskItem::normaliseSeverity($event->severity),
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                site: $event->site
                    ? ['id' => $event->site->id, 'name' => (string) $event->site->name]
                    : null,
                dueAt: null,
                createdAt: optional($event->created_at)->toIso8601String(),
                // Detail-as-modal deep link into the register.
                link: "/health-safety/restraints?event={$event->id}",
                type: 'Restraint review',
                description: $event->trigger_description
                    ? str($event->trigger_description)->limit(140)->toString()
                    : null,
            );
        })->all();
    }
}
