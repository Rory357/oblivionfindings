<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
use App\Models\HsEventReviewFlag;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\TaskItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * H&S events a person must look at again (job 3, Stephan 26 Oct): the source was corrected after the WorkSafe decision
 * was signed, or its severity rose after the event was closed. Same readable events and Site rule as the H&S event
 * list, for people who manage H&S; closed events are included because a flag can land on one.
 */
final class HsEventReviewFlagProvider extends HsEventProvider
{
    public function sourceKey(): string
    {
        return 'hs_event_review';
    }

    public function label(): string
    {
        return 'H&S reviews';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view') && $user->canDo('hazards.manage');
    }

    // Usually no flag is open: one indexed existence check before the H&S event read (these rows also feed the nav
    // badge). authorizedTasks() reaches these through HsEventProvider's own read context.
    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        return $this->anyOpen() ? parent::authorizedTasksInContext($viewer, $filters, $context) : [];
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        return $this->anyOpen() ? parent::authorizedNavigationTasksInContext($viewer, $context) : [];
    }

    private function anyOpen(): bool
    {
        return HsEventReviewFlag::query()->open()->exists();
    }

    protected function scopeEvents(Builder $query): Builder
    {
        return $query->whereHas('reviewFlags', fn (Builder $flags) => $flags->whereNull('resolved_at'));
    }

    protected function includesClosedEvents(): bool
    {
        return true;
    }

    protected function present(TaskItem $item, HsEvent $event, bool $navigation): TaskItem
    {
        $item->id = $this->sourceKey().'-'.$event->id;
        $item->bucket = TaskItem::BUCKET_OPEN;
        if ($navigation) {
            return $item;
        }
        $reasons = HsEventReviewFlag::query()->where('hs_event_id', $event->id)->open()->pluck('reason')->unique()->values()->all();
        $item->title = in_array(HsEventReviewFlag::REASON_WORKSAFE_REVIEW, $reasons, true)
            ? 'Check the WorkSafe decision again' : 'Severity rose after the event was closed';
        $item->type = 'H&S review';
        $item->displayState = 'Needs review';
        $item->description = implode(' ', array_filter([
            in_array(HsEventReviewFlag::REASON_WORKSAFE_REVIEW, $reasons, true)
                ? 'The source record was corrected after the WorkSafe decision was signed. The decision has not been changed.' : null,
            in_array(HsEventReviewFlag::REASON_ESCALATED_AFTER_CLOSE, $reasons, true)
                ? 'A correction raised the severity after the event was closed. The event stays closed.' : null,
        ]));
        $item->actionLabel = 'Review the change';

        return $item;
    }
}
