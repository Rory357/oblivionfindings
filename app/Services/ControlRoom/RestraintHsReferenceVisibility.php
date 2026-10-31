<?php

namespace App\Services\ControlRoom;

use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * A restraint's H&S event keeps its reference, people and link to readers of that restraint.
 *
 * The Control Room alert stays visible as an operational response. What is withheld from everyone else is the link
 * between the restraint's H&S reference and the alert: reference search, the worklist and journey rows, and the
 * alert's close requirement. The alert record's own H&S panel already follows this rule (HsVisibilityService).
 */
final class RestraintHsReferenceVisibility
{
    /** @var array<string, bool> */
    private array $readable = [];

    public function __construct(private readonly HsEventAccessService $events) {}

    /** @return list<string> */
    public static function sourceTypes(): array
    {
        return array_values(array_unique([RestraintEvent::class, (new RestraintEvent)->getMorphClass()]));
    }

    /** Whether the viewer may see this event's reference, people and link next to its alert. */
    public function canSee(User $viewer, ?HsEvent $event): bool
    {
        if ($event === null || ! $this->isRestraintSourced($event)) {
            return true;
        }
        $key = $viewer->id.':'.$event->id;

        return $this->readable[$key] ??= in_array((int) $event->id, $this->events->readableRestraintEventIds($viewer, [(int) $event->id]), true);
    }

    /**
     * Match an H&S reference inside a correlated hsEvent search. A restraint's event matches only for readers of that
     * restraint; every other event matches as before.
     */
    public function constrainReferenceSearch(Builder $event, User $viewer, string $term): Builder
    {
        $types = self::sourceTypes();
        $restraintMatches = HsEvent::query()
            ->where('reference_number', 'like', $term)
            ->whereNotNull('control_room_alert_id')
            ->whereIn('source_type', $types)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $readable = $this->events->readableRestraintEventIds($viewer, $restraintMatches);

        return $event->where('hs_events.reference_number', 'like', $term)
            ->where(fn (Builder $sources) => $sources->whereNull('hs_events.source_type')
                ->orWhereNotIn('hs_events.source_type', $types)
                ->orWhereIn('hs_events.id', $readable));
    }

    /** A partly selected relation may lack source_type; read it rather than treat the event as ordinary. */
    private function isRestraintSourced(HsEvent $event): bool
    {
        $type = array_key_exists('source_type', $event->getAttributes())
            ? $event->getAttribute('source_type')
            : HsEvent::query()->withTrashed()->whereKey($event->id)->value('source_type');

        return is_string($type) && in_array($type, self::sourceTypes(), true);
    }
}
