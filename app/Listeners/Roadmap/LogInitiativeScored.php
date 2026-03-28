<?php

namespace App\Listeners\Roadmap;

use App\Domain\Roadmap\Events\InitiativeScored;
use Illuminate\Support\Facades\Log;

class LogInitiativeScored
{
    public function handle(InitiativeScored $event): void
    {
        Log::channel('daily')->info('Initiative scored', [
            'initiative_id' => $event->initiative->id ?? null,
            'title' => $event->initiative->title ?? null,
        ]);
    }
}
