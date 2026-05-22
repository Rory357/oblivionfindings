<?php

namespace App\Services\Timeline;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\TimelineEvent;
use Illuminate\Database\Eloquent\Model;

class TimelineEmitter
{
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

        TimelineEvent::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->where('type', '!=', $type)
            ->delete();

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
}
