<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;

/**
 * The transparent outcome of a clinical assessment computation: the tool's
 * native total, the normalised risk band, a human-readable summary, the
 * recommended response, and a per-component breakdown so a clinician can see
 * exactly how the score was derived (never a black box). For IDDSI (a level
 * classification, not a score) `score`/`band` are null and `meta` carries the
 * food/drink levels.
 */
final readonly class ClinicalAssessmentResult
{
    /**
     * @param  list<array{key: string, label: string, detail: string, points: int|null}>  $breakdown
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public ClinicalAssessmentType $type,
        public ?int $score,
        public ?ClinicalRiskBand $band,
        public string $summary,
        public ?string $advice,
        public array $breakdown,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'type_short' => $this->type->shortLabel(),
            'tool_version' => $this->type->toolVersion(),
            'score' => $this->score,
            'band' => $this->band?->value,
            'band_label' => $this->band?->label(),
            'band_tone' => $this->band?->tone(),
            'needs_action' => $this->band?->needsAction() ?? false,
            'summary' => $this->summary,
            'advice' => $this->advice,
            'breakdown' => $this->breakdown,
            'meta' => $this->meta,
        ];
    }
}
