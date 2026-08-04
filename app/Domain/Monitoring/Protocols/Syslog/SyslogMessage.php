<?php

namespace App\Domain\Monitoring\Protocols\Syslog;

use Carbon\CarbonImmutable;

final readonly class SyslogMessage
{
    /** @param array<string, array<string, string>> $structuredData */
    public function __construct(
        public string $format,
        public int $facility,
        public int $severityCode,
        public CarbonImmutable $occurredAt,
        public ?string $hostname,
        public ?string $app,
        public ?string $processId,
        public ?string $messageId,
        public array $structuredData,
        public string $message,
        public string $rawHash,
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = array_filter([
            'app' => $this->app,
            'facility' => $this->facility,
            'format' => $this->format,
            'hostname' => $this->hostname,
            'message' => $this->message,
            'message_id' => $this->messageId,
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'process_id' => $this->processId,
            'raw_hash' => $this->rawHash,
            'severity_code' => $this->severityCode,
            'structured_data' => $this->structuredData === [] ? null : $this->structuredData,
        ], fn (mixed $value): bool => $value !== null);
        ksort($payload, SORT_STRING);

        return $payload;
    }
}
