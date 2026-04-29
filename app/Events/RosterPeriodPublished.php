<?php

namespace App\Events;

use App\Models\RosterPeriod;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RosterPeriodPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public RosterPeriod $period,
        public User $actor,
        public bool $republished = false,
    ) {
    }
}
