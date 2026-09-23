<?php

namespace App\Services\Fleet\Data;

use Carbon\CarbonImmutable;

final readonly class VehicleReadinessAssessment
{
    /** @param list<VehicleReadinessReason> $reasons @param array<string,int> $complianceVersionIds @param list<int> $restrictionIds @param list<int> $checkRunIds */
    public function __construct(
        public string $status,
        public bool $canProceed,
        public array $reasons,
        public CarbonImmutable $assessedAt,
        public array $complianceVersionIds,
        public ?int $odometerObservationId,
        public ?float $odometerKm,
        public ?array $trackerEstimate,
        public array $restrictionIds,
        public array $checkRunIds,
        public string $inputFingerprint,
    ) {}

    /** @return list<VehicleReadinessReason> */
    public function blockingReasons(): array
    {
        return array_values(array_filter($this->reasons, fn (VehicleReadinessReason $reason): bool => $reason->blocksDecision));
    }

    public function toArray(bool $includeTracker = false): array
    {
        return [
            'status' => $this->status,
            'can_proceed' => $this->canProceed,
            'reasons' => array_map(fn (VehicleReadinessReason $r) => $r->toArray(), $this->reasons),
            'assessed_at' => $this->assessedAt->toIso8601String(),
            'compliance_version_ids' => $this->complianceVersionIds,
            'odometer_observation_id' => $this->odometerObservationId,
            'odometer_km' => $this->odometerKm,
            'tracker_estimate' => $includeTracker ? $this->trackerEstimate : null,
            'restriction_ids' => $this->restrictionIds,
            'check_run_ids' => $this->checkRunIds,
            'input_fingerprint' => $this->inputFingerprint,
        ];
    }
}
