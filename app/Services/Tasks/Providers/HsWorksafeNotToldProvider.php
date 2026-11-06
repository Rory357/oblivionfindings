<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\TaskItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * O3 (Stephan, 6 Nov 2026: "Daily reminder + task"): a /tasks card for each open safety review marked WorkSafe-notifiable
 * where WorkSafe hasn't been told, until the notification is recorded. Same readable reviews and Site rule as the H&S
 * review list, for people who manage H&S.
 */
final class HsWorksafeNotToldProvider extends HsEventProvider
{
    public function sourceKey(): string
    {
        return 'hs_worksafe_not_told';
    }

    public function label(): string
    {
        return 'WorkSafe';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view') && $user->canDo('hazards.manage');
    }

    // Usually none is waiting: one indexed existence check before the H&S review read (these rows also feed the badge).
    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        return $this->anyWaiting() ? parent::authorizedTasksInContext($viewer, $filters, $context) : [];
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        return $this->anyWaiting() ? parent::authorizedNavigationTasksInContext($viewer, $context) : [];
    }

    private function anyWaiting(): bool
    {
        return HsEvent::query()->worksafeNotTold()->exists();
    }

    protected function scopeEvents(Builder $query): Builder
    {
        return $query->worksafeNotTold();
    }

    protected function present(TaskItem $item, HsEvent $event, bool $navigation): TaskItem
    {
        $item->id = $this->sourceKey().'-'.$event->id;
        $item->bucket = TaskItem::BUCKET_OPEN;
        if ($navigation) {
            return $item;
        }
        $item->title = 'Tell WorkSafe';
        $item->type = 'WorkSafe';
        $item->displayState = 'WorkSafe not told';
        $item->severity = 'critical';
        $item->description = 'This safety review is a notifiable event and WorkSafe hasn’t been told yet. The law says as soon as possible.';
        $item->link = "/health-safety/events/{$event->id}?action=worksafe-notify";
        $item->actionLabel = 'Record that WorkSafe was told';

        return $item;
    }
}
