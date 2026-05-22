<?php

namespace App\Services\Timeline;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\TimelineEvent;
use Illuminate\Database\Eloquent\Model;

class TimelineEmitter
{
    /**
     * Marker stored under `meta._projected` so the emitter can tell its own
     * auto-projected events apart from manually-recorded events. Without
     * this, a model's type-switch (e.g. ClientNote type='quick' → 'communication')
     * would delete a controller-written event that happens to share the same
     * source_type/source_id.
     */
    public const PROJECTED_META_KEY = '_projected';

    public function project(EmitsToTimeline&Model $source, ?string $forcedType = null): ?TimelineEvent
    {
        $payload = $source->toTimelineEvent();

        if ($payload === null) {
            $this->retract($source);

            return null;
        }

        $type = $forcedType ?? (string) ($payload['type'] ?? '');
        if ($type === '') {
            throw new \InvalidArgumentException('Timeline event payload must include a type.');
        }

        // Only delete other-type events that we previously projected.
        // Manual `record()` events for the same source survive.
        TimelineEvent::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->where('type', '!=', $type)
            ->where('meta->'.self::PROJECTED_META_KEY, true)
            ->delete();

        $meta = array_merge(
            (array) ($payload['meta'] ?? []),
            [self::PROJECTED_META_KEY => true],
        );

        return TimelineEvent::query()->updateOrCreate(
            [
                'type' => $type,
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
            ],
            [
                ...$payload,
                'type' => $type,
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'meta' => $meta,
            ],
        );
    }

    public function retract(Model $source): void
    {
        TimelineEvent::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->delete();
    }

    /**
     * Write a timeline event from a raw payload — for controllers/services
     * that do not have a single canonical source model (e.g. cross-cutting
     * activity logs like document uploads, photo additions, calendar
     * events). Prefer `project()` when an `EmitsToTimeline` model is
     * available — that path supports deduplication via source_type/source_id.
     */
    public function record(array $payload): TimelineEvent
    {
        if (! isset($payload['type']) || $payload['type'] === '') {
            throw new \InvalidArgumentException('Timeline event payload must include a type.');
        }

        return TimelineEvent::create($payload);
    }
}
