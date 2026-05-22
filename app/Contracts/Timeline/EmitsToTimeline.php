<?php

namespace App\Contracts\Timeline;

interface EmitsToTimeline
{
    /**
     * @return array<string, mixed>|null
     */
    public function toTimelineEvent(): ?array;
}
