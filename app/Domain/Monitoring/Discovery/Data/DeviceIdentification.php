<?php

namespace App\Domain\Monitoring\Discovery\Data;

final readonly class DeviceIdentification
{
    public function __construct(
        public string $domain,
        public string $category,
        public ?string $subcategory,
        public string $typeLabel,
        public ?string $manufacturer,
        public ?string $model,
        public ?string $macVendor,
        public string $suggestedName,
        public int $confidence,
        public string $confidenceBand,
        public string $reason,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'type_label' => $this->typeLabel,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'mac_vendor' => $this->macVendor,
            'suggested_name' => $this->suggestedName,
            'confidence' => $this->confidence,
            'confidence_band' => $this->confidenceBand,
            'reason' => $this->reason,
        ];
    }
}
