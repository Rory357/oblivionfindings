<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\News2Band;

/**
 * The outcome of a NEWS2 computation: the aggregate score, the risk band, the
 * single-parameter red-flag (any parameter scoring 3), and the per-parameter
 * point breakdown (surfaced live in the observation wizard).
 */
final readonly class News2Result
{
    /**
     * @param  array<string, int>  $breakdown  per-parameter points
     */
    public function __construct(
        public int $score,
        public News2Band $band,
        public bool $redFlag,
        public array $breakdown,
    ) {}

    /**
     * @return array{score: int, band: string, band_label: string, red_flag: bool, advice: string, breakdown: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'band' => $this->band->value,
            'band_label' => $this->band->label(),
            'red_flag' => $this->redFlag,
            'advice' => $this->band->advice(),
            'breakdown' => $this->breakdown,
        ];
    }
}
