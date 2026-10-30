<?php

namespace App\Domain\SecurityDevices\Drivers\Data;

use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;

final readonly class DriverMetadata
{
    /**
     * @param  list<string>  $models
     * @param  list<DriverCapability>  $capabilities
     * @param  list<array{label: string, url: string}>  $officialDocs
     */
    public function __construct(
        public string $key,
        public string $brand,
        public string $name,
        public DriverDeviceClass $deviceClass,
        public DriverSupportLevel $supportLevel,
        public string $transportSummary,
        public array $models,
        public array $capabilities,
        public string $summary,
        public array $officialDocs,
        public ?string $limitationsNote = null,
        public ?string $hardwareEvidence = null,
        public ?string $setupHref = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'brand' => $this->brand,
            'name' => $this->name,
            'device_class' => $this->deviceClass->value,
            'device_class_label' => $this->deviceClass->label(),
            'support_level' => $this->supportLevel->value,
            'support_level_label' => $this->supportLevel->label(),
            'support_badge_variant' => $this->supportLevel->badgeVariant(),
            'transport_summary' => $this->transportSummary,
            'models' => $this->models,
            'capabilities' => array_map(
                static fn (DriverCapability $capability): array => [
                    'key' => $capability->value,
                    'label' => $capability->label(),
                    'requires_confirmation' => $capability->requiresConfirmationAndAudit(),
                ],
                $this->capabilities,
            ),
            'summary' => $this->summary,
            'limitations_note' => $this->limitationsNote,
            'hardware_evidence' => $this->hardwareEvidence,
            'official_docs' => $this->officialDocs,
            'setup_href' => $this->setupHref,
        ];
    }
}
