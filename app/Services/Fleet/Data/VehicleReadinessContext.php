<?php

namespace App\Services\Fleet\Data;

use Carbon\CarbonInterface;

final readonly class VehicleReadinessContext
{
    /** @param list<int> $releaseRestrictionIds @param list<int> $resolvedCheckRunIds */
    public function __construct(
        public string $purpose = 'projection',
        public ?int $driverUserId = null,
        public ?CarbonInterface $startsAt = null,
        public ?CarbonInterface $endsAt = null,
        public ?int $bookingId = null,
        public ?float $candidateOdometerKm = null,
        public array $releaseRestrictionIds = [],
        public array $resolvedCheckRunIds = [],
    ) {}

    public function isUseDecision(): bool
    {
        return in_array($this->purpose, ['booking_request', 'booking_confirmation', 'booking_approval', 'checkout', 'maintenance_release'], true);
    }
}
