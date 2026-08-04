<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SnmpEngineReplayGuard
{
    public function accept(int $siteId, string $senderAddress, SnmpTrap $trap): void
    {
        if ($trap->version !== 'v3' || $trap->engineId === null
            || $trap->engineBoots === null || $trap->engineTime === null) {
            return;
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('SNMPv3 replay protection is unavailable.');
        }
        $senderHash = hash_hmac('sha256', $senderAddress, $key);
        $engineHash = hash_hmac('sha256', $trap->engineId, $key);
        $window = (int) config('monitoring.snmp.traps.timeliness_window_seconds', 150);
        if ($window < 30 || $window > 900) {
            throw new RuntimeException('SNMPv3 replay protection is unavailable.');
        }

        DB::transaction(function () use ($siteId, $senderHash, $engineHash, $trap, $window): void {
            $state = DB::table('monitoring_snmp_engine_states')
                ->where('site_id', $siteId)
                ->where('sender_address_hash', $senderHash)
                ->where('engine_id_hash', $engineHash)
                ->lockForUpdate()
                ->first();
            $now = now();
            if ($state === null) {
                DB::table('monitoring_snmp_engine_states')->insert([
                    'site_id' => $siteId,
                    'sender_address_hash' => $senderHash,
                    'engine_id_hash' => $engineHash,
                    'engine_boots' => $trap->engineBoots,
                    'engine_time' => $trap->engineTime,
                    'received_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }

            $storedBoots = (int) $state->engine_boots;
            $storedTime = (int) $state->engine_time;
            if ($trap->engineBoots < $storedBoots
                || ($trap->engineBoots === $storedBoots && $trap->engineTime < $storedTime)) {
                throw new RuntimeException('SNMPv3 trap replay was rejected.');
            }
            if ($trap->engineBoots === $storedBoots && $trap->engineTime > $storedTime) {
                $elapsed = max(0, $now->diffInSeconds($state->received_at));
                if ($trap->engineTime > $storedTime + $elapsed + $window) {
                    throw new RuntimeException('SNMPv3 trap timeliness check failed.');
                }
            }

            DB::table('monitoring_snmp_engine_states')
                ->where('id', $state->id)
                ->update([
                    'engine_boots' => max($storedBoots, $trap->engineBoots),
                    'engine_time' => $trap->engineBoots > $storedBoots
                        ? $trap->engineTime
                        : max($storedTime, $trap->engineTime),
                    'received_at' => $now,
                    'updated_at' => $now,
                ]);
        }, 3);
    }
}
