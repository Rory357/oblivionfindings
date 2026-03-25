<?php

namespace App\Domain\Roadmap\Events;

use App\Domain\Roadmap\Models\Initiative;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InitiativeScored
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Initiative $initiative,
        public array $breakdown,
    ) {}
}
