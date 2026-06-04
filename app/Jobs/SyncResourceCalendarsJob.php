<?php

namespace App\Jobs;

use App\Models\CalendarSyncMapping;
use App\Services\Sites\Calendar\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cadence sync: catch-up push of manual events + (two-way) external busy pull for
 * every active, syncable house→resource-calendar mapping. Scheduled in
 * routes/console.php (default every 15 minutes).
 *
 * ShouldBeUnique so a manual "Sync now" can't run concurrently with the scheduled
 * cadence run (the scheduler's withoutOverlapping only guards scheduler-queued runs).
 */
class SyncResourceCalendarsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long the uniqueness lock is held (seconds) if the job never completes. */
    public int $uniqueFor = 600;

    /** Optionally limit to a single mapping (manual "Sync now"). */
    public function __construct(public ?int $mappingId = null) {}

    public function uniqueId(): string
    {
        return 'sync-resource-calendars:'.($this->mappingId ?? 'all');
    }

    public function handle(CalendarSyncService $service): void
    {
        $query = CalendarSyncMapping::query()->active();
        if ($this->mappingId !== null) {
            $query->whereKey($this->mappingId);
        }

        $mappings = $query->get()->filter(fn (CalendarSyncMapping $m) => $m->isSyncable());

        foreach ($mappings as $mapping) {
            try {
                $counts = $service->syncMapping($mapping);
                Log::info('Resource calendar synced', [
                    'mapping' => $mapping->id,
                    'site' => $mapping->site_id,
                    'provider' => $mapping->provider,
                    ...$counts,
                ]);
            } catch (\Throwable $e) {
                $mapping->forceFill(['last_error' => $e->getMessage()])->save();
                Log::warning('Resource calendar sync failed', ['mapping' => $mapping->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
