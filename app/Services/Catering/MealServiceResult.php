<?php

namespace App\Services\Catering;

final readonly class MealServiceResult
{
    public function __construct(
        public string $status,
        public int $movementCount = 0,
    ) {}
}
