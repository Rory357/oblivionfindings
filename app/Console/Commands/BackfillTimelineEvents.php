<?php

namespace App\Console\Commands;

use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\TimelineEvent;
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
        Shift::query()->with('client')->chunk(200, function ($shifts) use (&$shiftCount) {
            foreach ($shifts as $s) {
                $s->touch(); // triggers observer updated()
                $shiftCount++;
            }
        });

        $noteCount = 0;
        ClientNote::query()->with('client')->chunk(200, function ($notes) use (&$noteCount) {
            foreach ($notes as $n) {
                $n->touch();
                $noteCount++;
            }
        });

        $this->info("Done. Shifts processed: {$shiftCount}. Notes processed: {$noteCount}.");
        $this->warn('Note: This uses model observers. If you disable observers, it will not create events.');

        return self::SUCCESS;
    }
}
