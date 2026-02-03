<?php

namespace App\Events;

use App\Models\FleetSignal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FleetSignalEmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public FleetSignal $signal)
    {
    }
}
