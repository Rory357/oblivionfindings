<?php

namespace App\Console\Commands;

use App\Jobs\ReverseGeocodeFleetTelemetryEvent;
use App\Models\FleetTelemetryEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class FleetReverseGeocodeBackfill extends Command
{
    protected $signature = 'fleet:reverse-geocode:backfill
        {--device= : Device ID to backfill}
        {--client= : Client ID whose active tracker device should be backfilled}
        {--limit=500 : Maximum rows to queue}
        {--retry-failed : Include rows previously marked reverse_geocode_failed_at}
        {--dry-run : Count eligible rows without queueing jobs}';

    protected $description = 'Queue reverse geocoding jobs for fleet telemetry events missing addresses';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $events = $this->eligibleEvents()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id']);

        $this->line('Eligible rows: '.$events->count());

        if ($this->option('dry-run')) {
            $this->line('Dry run: no jobs queued');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            ReverseGeocodeFleetTelemetryEvent::dispatch($event->id);
        }

        $this->line('Queued rows: '.$events->count());

        if ($events->isNotEmpty()) {
            $this->line('First queued event ID: '.$events->first()->id);
            $this->line('Last queued event ID: '.$events->last()->id);
        }

        return self::SUCCESS;
    }

    private function eligibleEvents(): Builder
    {
        $query = FleetTelemetryEvent::query()
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNull('address')
            ->whereNull('reverse_geocoded_at');

        if (! $this->option('retry-failed')) {
            $query->whereNull('reverse_geocode_failed_at');
        }

        if ($device = $this->option('device')) {
            $query->where(function (Builder $query) use ($device): void {
                $query->where('device_id', $device)
                    ->orWhereHas('device', function (Builder $deviceQuery) use ($device): void {
                        $deviceQuery->where('device_uid', $device)
                            ->orWhere('imei', $device);
                    });
            });
        }

        if ($client = $this->option('client')) {
            $query->whereHas('asset', function (Builder $assetQuery) use ($client): void {
                $assetQuery->where('client_id', $client)
                    ->where('status', 'active');
            });
        }

        return $query;
    }
}
