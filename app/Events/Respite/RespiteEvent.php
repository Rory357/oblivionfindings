<?php

namespace App\Events\Respite;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RespiteEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $name,
        public array $payload,
        public string $version = 'v1',
    ) {
    }
}
