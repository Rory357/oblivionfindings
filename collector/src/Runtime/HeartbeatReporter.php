<?php

namespace Oblivion\Collector\Runtime;

use DateTimeImmutable;
use Oblivion\Collector\Contracts\CentralApi;
use Oblivion\Collector\Spool\EncryptedSpool;

final readonly class HeartbeatReporter
{
    public function __construct(
        private CentralApi $central,
        private string $collectorId,
        private EncryptedSpool $spool,
    ) {}

    /** @param array<string, int|string|bool|null> $runtime */
    public function report(array $runtime = [], ?DateTimeImmutable $at = null): void
    {
        $at ??= new DateTimeImmutable('now');
        $spool = $this->spool->status($at);
        $this->central->heartbeat($this->collectorId, [
            'reported_at' => $at->format(DATE_ATOM),
            'state' => $spool['state'],
            'spool_items' => $spool['items'],
            'spool_bytes' => $spool['bytes'],
            'oldest_spool_item_at' => $spool['oldest_at'],
            'corrupted_frames' => $spool['corrupted_frames'],
            'acknowledged_source_sequence' => $spool['acknowledged_source_sequence'],
            'highest_seen_source_sequence' => $spool['highest_seen_source_sequence'],
            'runtime' => array_slice($runtime, 0, 16, true),
        ]);
    }
}
