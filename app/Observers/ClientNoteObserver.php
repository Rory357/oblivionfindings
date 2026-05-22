<?php

namespace App\Observers;

use App\Models\ClientNote;
use App\Services\Timeline\TimelineEmitter;

class ClientNoteObserver
{
    public function __construct(private readonly TimelineEmitter $timeline) {}

    public function created(ClientNote $note): void
    {
        $this->timeline->project($note);
    }

    public function updated(ClientNote $note): void
    {
        $this->timeline->project($note);
    }

    public function deleted(ClientNote $note): void
    {
        $this->timeline->retract($note);
    }
}
