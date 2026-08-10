<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MonitoringObservationProvenanceReconciler
{
    public function __construct(
        private readonly CanonicalDeviceSiteResolver $deviceSiteResolver,
        private readonly MonitoringObservationScopeGuard $scopeGuard,
    ) {}

    /**
     * @return array{schema_ready: bool, scanned: int, valid: int, reconciled: int, partial: int, contradictory: int, unresolved: int, missing: int}
     */
    public function reconcile(int $chunkSize = 500): array
    {
        $counts = [
            'schema_ready' => Schema::hasColumns('monitor_observations', [
                'device_id',
                'site_id',
                'collector_id',
            ]),
            'scanned' => 0,
            'valid' => 0,
            'reconciled' => 0,
            'partial' => 0,
            'contradictory' => 0,
            'unresolved' => 0,
            'missing' => 0,
        ];

        if (! $counts['schema_ready']) {
            return $counts;
        }

        DB::table('monitor_observations')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $outcome = $this->reconcileOne((int) $row->id);
                    $counts['scanned']++;
                    $counts[$outcome]++;
                }
            });

        $counts['missing'] = DB::table('monitor_observations')
            ->where(fn ($query) => $query->whereNull('device_id')->orWhereNull('site_id'))
            ->count();

        Log::info('Monitoring observation provenance reconciliation completed.', $counts);

        return $counts;
    }

    private function reconcileOne(int $observationId): string
    {
        try {
            return DB::transaction(function () use ($observationId): string {
                $observation = DB::table('monitor_observations')
                    ->where('id', $observationId)
                    ->lockForUpdate()
                    ->first(['id', 'monitor_id', 'device_id', 'site_id', 'collector_id']);

                if ($observation === null) {
                    return 'unresolved';
                }

                $deviceMissing = $observation->device_id === null;
                $siteMissing = $observation->site_id === null;
                $fullyNull = $deviceMissing && $siteMissing && $observation->collector_id === null;

                if (! $fullyNull && ($deviceMissing || $siteMissing)) {
                    return 'partial';
                }

                $monitor = Monitor::query()
                    ->lockForUpdate()
                    ->find($observation->monitor_id);
                if ($monitor === null) {
                    return 'unresolved';
                }

                if ($monitor->collector_id !== null) {
                    $collector = MonitoringCollector::query()
                        ->lockForUpdate()
                        ->find($monitor->collector_id);
                    if ($collector === null) {
                        return 'unresolved';
                    }

                    $monitor->setRelation('collector', $collector);
                } else {
                    $monitor->setRelation('collector', null);
                }

                $siteId = $this->deviceSiteResolver->resolve((int) $monitor->device_id);
                $this->scopeGuard->assertCanonicalSite($monitor, $siteId);
                $expectedCollectorId = $monitor->collector_id === null ? null : (int) $monitor->collector_id;

                if ($fullyNull) {
                    $updated = DB::table('monitor_observations')
                        ->where('id', $observationId)
                        ->whereNull('device_id')
                        ->whereNull('site_id')
                        ->whereNull('collector_id')
                        ->update([
                            'device_id' => $monitor->device_id,
                            'site_id' => $siteId,
                            'collector_id' => $expectedCollectorId,
                        ]);

                    return $updated === 1 ? 'reconciled' : 'unresolved';
                }

                return (int) $observation->device_id === (int) $monitor->device_id
                    && (int) $observation->site_id === $siteId
                    && ($observation->collector_id === null ? null : (int) $observation->collector_id) === $expectedCollectorId
                        ? 'valid'
                        : 'contradictory';
            });
        } catch (Throwable) {
            return 'unresolved';
        }
    }
}
