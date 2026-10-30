<?php

namespace App\Services\Medication\Audit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class MedicationEventData
{
    public function __construct(
        public int $siteId,
        public string $kind,
        public string $subjectType,
        public string $subjectId,
        public ?int $actorId,
        public CarbonImmutable $occurredAt,
        public string $summary,
        public array $facts = [],
        public ?int $clientId = null,
        public bool $controlled = false,
        public ?int $correctsEventId = null,
    ) {
        if ($siteId < 1 || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $kind)
            || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $subjectType)
            || $subjectId === '' || strlen($subjectId) > 100 || trim($summary) === '' || mb_strlen($summary) > 255) {
            throw new InvalidArgumentException('A medication event needs a canonical Site, kind, subject and concise summary.');
        }
    }
}
