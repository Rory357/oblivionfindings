<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class IntuneVisibilitySync
{
    public function __construct(private readonly IntuneReadClient $client) {}

    public function sync(): int
    {
        return Cache::lock('endpoint-intune-visibility-sync', 3600)->block(1, function (): int {
            $started = now();
            // Capture mappings before the external request. A mapping changed during
            // the fetch must await a new sync, not inherit an earlier observation.
            $bindings = IntuneDeviceLink::query()->get(['id', 'device_id', 'managed_device_id']);
            if ($bindings->isEmpty()) {
                return 0;
            }
            try {
                // Do not partially replace snapshots if any page fails.
                $records = collect($this->client->devices())->keyBy('id');

                return DB::transaction(function () use ($records, $started, $bindings): int {
                    $count = 0;
                    foreach ($bindings as $binding) {
                        $link = $this->unchangedBinding($binding)->lockForUpdate()->first();
                        if (! $link) {
                            continue;
                        }
                        $snapshot = $records->get(strtolower($link->managed_device_id));
                        $link->last_attempt_at = $started;
                        $link->sync_status = $snapshot === null ? 'missing' : 'current';
                        if ($snapshot !== null) {
                            $link->snapshot = $snapshot;
                            $link->synced_at = now();
                            $count++;
                        }
                        $link->save();
                    }

                    return $count;
                });
            } catch (Throwable) {
                foreach ($bindings as $binding) {
                    $this->unchangedBinding($binding)->update(['last_attempt_at' => $started, 'sync_status' => 'failed']);
                }
                // Never copy upstream exceptions, response bodies or tokens into UI/logs.
                throw new \RuntimeException('Intune could not be refreshed. Previous observations remain available with their original timestamps.');
            }
        });
    }

    private function unchangedBinding(IntuneDeviceLink $binding): Builder
    {
        return IntuneDeviceLink::query()->whereKey($binding->id)
            ->where('device_id', $binding->device_id)
            ->where('managed_device_id', $binding->managed_device_id);
    }
}
