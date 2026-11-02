<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsClosureException;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\TaskItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Board M11 (G3, approved by Stephan 1 Nov 2026; K6): "close anyway" requests waiting for a decision, for the people
 * who approve them — never the person who asked. Same readable events and Site rule as the H&S event list; the
 * decision itself still goes through HsEventClosureService::decideAuthority().
 */
final class HsClosureExceptionProvider extends HsEventProvider
{
    private ?int $viewerId = null;

    public function sourceKey(): string
    {
        return 'hs_closure_exception';
    }

    public function label(): string
    {
        return 'Close-anyway requests';
    }

    /**
     * The approve key is checked on the person before the H&S read; inside the read context only the event read's own
     * keys answer, so there canView() keeps to hazards.view (as the H&S event list does).
     */
    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view')
            && ($this->viewerId === (int) $user->id || $user->canDo('healthSafety.closureExceptions.approve'));
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        if (! $this->approves($viewer) || ! $this->anyWaiting()) {
            return [];
        }
        $this->viewerId = (int) $viewer->id;

        return parent::authorizedTasksInContext($viewer, $filters, $context);
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        if (! $this->approves($viewer) || ! $this->anyWaiting()) {
            return [];
        }
        $this->viewerId = (int) $viewer->id;

        return parent::authorizedNavigationTasksInContext($viewer, $context);
    }

    private function approves(User $viewer): bool
    {
        return $viewer->canDo('hazards.view') && $viewer->canDo('healthSafety.closureExceptions.approve');
    }

    /** Usually none is waiting: one indexed check before the H&S event read. */
    private function anyWaiting(): bool
    {
        return HsClosureException::query()->whereDoesntHave('decisions')->where('expires_at', '>', now())->exists();
    }

    protected function scopeEvents(Builder $query): Builder
    {
        $viewerId = $this->viewerId;

        return $query->whereHas('closureExceptions', fn (Builder $requests) => $requests
            ->whereDoesntHave('decisions')
            ->where('expires_at', '>', now())
            ->where('review_at', '>', now())
            ->when($viewerId !== null, fn (Builder $mine) => $mine->where('requested_by_user_id', '!=', $viewerId)));
    }

    protected function present(TaskItem $item, HsEvent $event, bool $navigation): TaskItem
    {
        $item->id = $this->sourceKey().'-'.$event->id;
        $item->bucket = TaskItem::BUCKET_OPEN;
        if ($navigation) {
            return $item;
        }
        $request = HsClosureException::query()->where('hs_event_id', $event->id)->whereDoesntHave('decisions')
            ->where('expires_at', '>', now())->with('requester:id,name')->orderBy('requested_at')->first();
        $item->title = 'Decide: close anyway?';
        $item->type = 'Close-anyway request';
        $item->displayState = 'Waiting for a decision';
        $item->description = $request
            ? ($request->requester?->name ?? 'Someone').' asked to close this safety review with one item still open.'
            : 'A request to close this safety review with one item still open.';
        $item->actionLabel = 'Decide';

        return $item;
    }
}
