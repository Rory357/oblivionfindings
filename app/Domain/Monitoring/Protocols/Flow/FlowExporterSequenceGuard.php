<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FlowExporterSequenceGuard
{
    public function accept(
        int $siteId,
        string $exporterAddress,
        FlowDatagram $datagram,
        string $datagramHash,
    ): FlowSequenceHealth {
        $key = config('app.key');
        if ($siteId < 1 || $exporterAddress !== $datagram->exporterAddress
            || ! is_string($key) || $key === ''
            || preg_match('/^[a-f0-9]{64}$/', $datagramHash) !== 1) {
            throw new RuntimeException('Flow exporter sequence protection is unavailable.');
        }
        $exporterHash = hash_hmac('sha256', $exporterAddress, $key);

        return DB::transaction(function () use ($siteId, $exporterHash, $datagram, $datagramHash): FlowSequenceHealth {
            $state = DB::table('monitoring_flow_exporter_states')
                ->where('site_id', $siteId)
                ->where('exporter_hash', $exporterHash)
                ->where('family', $datagram->family)
                ->where('source_id', $datagram->sourceId)
                ->lockForUpdate()
                ->first();
            $now = now();
            if ($state === null) {
                DB::table('monitoring_flow_exporter_states')->insert([
                    ...$this->stateValues($datagram, $datagramHash, $now),
                    'site_id' => $siteId,
                    'exporter_hash' => $exporterHash,
                    'created_at' => $now,
                ]);

                return new FlowSequenceHealth('first', null, $datagram->sequence);
            }
            if (hash_equals((string) $state->last_datagram_hash, $datagramHash)) {
                return new FlowSequenceHealth('duplicate', (int) $state->last_sequence, $datagram->sequence);
            }
            $storedUptime = $state->last_uptime_ms === null ? null : (int) $state->last_uptime_ms;
            if ($storedUptime !== null && $datagram->uptimeMillis !== null
                && $datagram->uptimeMillis < $storedUptime) {
                $health = new FlowSequenceHealth('reset', null, $datagram->sequence);
            } else {
                $increment = in_array($datagram->family, ['netflow-v5', 'ipfix'], true)
                    ? (int) $state->last_record_count
                    : 1;
                $expected = ((int) $state->last_sequence + $increment) % 4_294_967_296;
                $distance = ($datagram->sequence - $expected + 4_294_967_296) % 4_294_967_296;
                $health = match (true) {
                    $datagram->sequence === $expected => new FlowSequenceHealth('ok', $expected, $datagram->sequence),
                    $distance > 0 && $distance < 2_147_483_648 => new FlowSequenceHealth('gap', $expected, $datagram->sequence, $distance),
                    default => new FlowSequenceHealth('out_of_order', $expected, $datagram->sequence),
                };
            }
            if ($health->status !== 'out_of_order') {
                DB::table('monitoring_flow_exporter_states')
                    ->where('id', $state->id)
                    ->update($this->stateValues($datagram, $datagramHash, $now));
            }

            return $health;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function stateValues(FlowDatagram $datagram, string $datagramHash, mixed $now): array
    {
        return [
            'family' => $datagram->family,
            'source_id' => $datagram->sourceId,
            'last_sequence' => $datagram->sequence,
            'last_uptime_ms' => $datagram->uptimeMillis,
            'last_record_count' => count($datagram->records),
            'last_datagram_hash' => $datagramHash,
            'last_exported_at' => $datagram->exportedAt,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
    }
}
