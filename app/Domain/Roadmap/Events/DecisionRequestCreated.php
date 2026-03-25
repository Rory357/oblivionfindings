<?php

namespace App\Domain\Roadmap\Events;

use App\Domain\Roadmap\Models\DecisionRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DecisionRequestCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public DecisionRequest $request,
    ) {}
}
