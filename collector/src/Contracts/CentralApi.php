<?php

namespace Oblivion\Collector\Contracts;

interface CentralApi
{
    /** @return array<string, mixed> */
    public function enrol(string $oneTimeToken, string $collectorId, string $collectorPublicKey): array;

    public function configuration(string $collectorId, int $afterSequence): string;

    /** @param list<array<string, mixed>> $items @return array{acknowledged_ids: list<string>, acknowledged_source_sequence: int} */
    public function upload(string $collectorId, array $items): array;

    /** @param array<string, mixed> $status */
    public function heartbeat(string $collectorId, array $status): void;
}
