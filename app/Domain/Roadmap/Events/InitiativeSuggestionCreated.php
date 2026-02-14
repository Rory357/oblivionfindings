<?php

namespace App\Domain\Roadmap\Events;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InitiativeSuggestionCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public InitiativeSuggestion $suggestion,
    ) {}
}
