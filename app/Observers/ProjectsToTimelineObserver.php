<?php

namespace App\Observers;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Database\Eloquent\Model;

class ProjectsToTimelineObserver
{
    public function __construct(private readonly TimelineEmitter $timeline) {}

    public function created(Model $model): void
    {
        if ($model instanceof EmitsToTimeline) {
            $this->timeline->project($model);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof EmitsToTimeline) {
            $this->timeline->project($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->timeline->retract($model);
    }
}
