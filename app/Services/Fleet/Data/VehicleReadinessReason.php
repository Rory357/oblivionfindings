<?php

namespace App\Services\Fleet\Data;

final readonly class VehicleReadinessReason
{
    public function __construct(
        public string $code,
        public string $message,
        public string $scope = 'vehicle',
        public ?string $kind = null,
        public ?int $sourceId = null,
        public ?int $versionId = null,
        public bool $blocksDecision = true,
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code, 'message' => $this->message, 'scope' => $this->scope,
            'kind' => $this->kind, 'source_id' => $this->sourceId, 'version_id' => $this->versionId,
            'blocks_decision' => $this->blocksDecision,
        ];
    }
}
