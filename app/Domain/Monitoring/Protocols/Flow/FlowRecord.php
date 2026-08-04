<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use InvalidArgumentException;

final readonly class FlowRecord
{
    public function __construct(
        public string $sourceIp,
        public string $destinationIp,
        public ?int $sourcePort,
        public ?int $destinationPort,
        public int $protocol,
        public int $bytes,
        public int $packets,
        public ?int $inputInterface,
        public ?int $outputInterface,
        public int $samplingRate = 1,
    ) {
        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false
            || filter_var($destinationIp, FILTER_VALIDATE_IP) === false
            || ($sourcePort !== null && ($sourcePort < 0 || $sourcePort > 65_535))
            || ($destinationPort !== null && ($destinationPort < 0 || $destinationPort > 65_535))
            || $protocol < 0 || $protocol > 255
            || $bytes < 0 || $packets < 0
            || ($inputInterface !== null && $inputInterface < 0)
            || ($outputInterface !== null && $outputInterface < 0)
            || $samplingRate < 1 || $samplingRate > 1_000_000_000) {
            throw new InvalidArgumentException('Flow record is invalid.');
        }
    }

    /** @return array<string, int|string|null> */
    public function payload(): array
    {
        return [
            'bytes' => $this->bytes,
            'destination_ip' => $this->destinationIp,
            'destination_port' => $this->destinationPort,
            'input_interface' => $this->inputInterface,
            'output_interface' => $this->outputInterface,
            'packets' => $this->packets,
            'protocol' => $this->protocol,
            'sampling_rate' => $this->samplingRate,
            'source_ip' => $this->sourceIp,
            'source_port' => $this->sourcePort,
        ];
    }
}
