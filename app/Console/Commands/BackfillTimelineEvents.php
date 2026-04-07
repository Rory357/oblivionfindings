<?php

namespace App\Console\Commands;

use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Observers\ClientNoteObserver;
use App\Services\ShiftTimelineService;
use Illuminate\Console\Command;

class BackfillTimelineEvents extends Command
{
    protected $signature = 'timeline:backfill {--force : Rebuild even if events already exist}';
    protected $description = 'Backfill timeline_events from shifts and client_notes';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info('Backfilling timeline events...');

        if ($force) {
            TimelineEvent::query()
                ->whereIn('source_type', [Shift::class, ClientNote::class])
                ->delete();
            $this->warn('Existing shift/note timeline events deleted (force mode).');
        }

        $shiftCount = 0;
        $timeline = app(ShiftTimelineService::class);
        Shift::query()->with(['client', 'client.portalUsers:id', 'staff:id,name', 'serviceContext:id,name,type'])->chunk(200, function ($shifts) use (&$shiftCount, $timeline) {
            foreach ($shifts as $s) {
                $timeline->syncSnapshot($s);

                if ($s->status === 'in_progress') {
                    $timeline->recordStarted($s, null, $s->actual_starts_at ?? $s->starts_at ?? now(), false);
                }

                if ($s->status === 'completed') {
                    $timeline->recordStarted($s, null, $s->actual_starts_at ?? $s->starts_at ?? now(), false);
                    $timeline->recordCompleted($s, null, $s->actual_ends_at ?? $s->ends_at ?? now(), [], false);
                }

                if ($s->status === 'cancelled') {
                    $timeline->recordCancelled($s);
                }

                $shiftCount++;
            }
        });

        $noteCount = 0;
        $noteObserver = app(ClientNoteObserver::class);
        ClientNote::query()->with('client')->chunk(200, function ($notes) use (&$noteCount, $noteObserver) {
            foreach ($notes as $n) {
                $noteObserver->updated($n);
                $noteCount++;
            }
        });

        $this->info("Done. Shifts processed: {$shiftCount}. Notes processed: {$noteCount}.");
        $this->warn('Shift events are rebuilt directly from operational data. Client notes still use the note observer mapping.');

        return self::SUCCESS;
    }
}
