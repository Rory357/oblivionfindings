<?php

namespace App\Console\Commands;

use App\Models\ClientNote;
use App\Models\ProgressNote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditClientNoteConsolidation extends Command
{
    protected $signature = 'oblivion:audit-client-note-consolidation';

    protected $description = 'Verify that every archived progress note has one canonical ClientNote and no legacy timeline projection.';

    public function handle(): int
    {
        $legacyTotal = DB::table('progress_notes')->count();
        $linkedTotal = DB::table('client_notes')
            ->whereNotNull('legacy_progress_note_id')
            ->count();
        $missing = DB::table('progress_notes as legacy')
            ->leftJoin(
                'client_notes as canonical',
                'canonical.legacy_progress_note_id',
                '=',
                'legacy.id',
            )
            ->whereNull('canonical.id')
            ->count();
        $duplicates = DB::table('client_notes')
            ->whereNotNull('legacy_progress_note_id')
            ->groupBy('legacy_progress_note_id')
            ->havingRaw('COUNT(*) > 1')
            ->get(['legacy_progress_note_id'])
            ->count();
        $orphaned = DB::table('client_notes as canonical')
            ->leftJoin(
                'progress_notes as legacy',
                'legacy.id',
                '=',
                'canonical.legacy_progress_note_id',
            )
            ->whereNotNull('canonical.legacy_progress_note_id')
            ->whereNull('legacy.id')
            ->count();
        $jsonOnlyMarkers = DB::table('client_notes')
            ->whereNull('legacy_progress_note_id')
            ->whereNotNull('attachments')
            ->whereRaw("JSON_EXTRACT(attachments, '$.legacy_progress_note_id') IS NOT NULL")
            ->count();
        $legacyTimelineEvents = DB::table('timeline_events')
            ->where('source_type', ProgressNote::class)
            ->count();
        $canonicalTimelineEvents = DB::table('timeline_events')
            ->where('source_type', ClientNote::class)
            ->count();

        $this->table(['Check', 'Count'], [
            ['Legacy progress notes (including soft-deleted)', $legacyTotal],
            ['Canonical notes linked to legacy IDs', $linkedTotal],
            ['Legacy rows missing a canonical note', $missing],
            ['Duplicate canonical legacy IDs', $duplicates],
            ['Canonical links without a legacy source', $orphaned],
            ['Old JSON markers missing the explicit source key', $jsonOnlyMarkers],
            ['Timeline events still bound to ProgressNote', $legacyTimelineEvents],
            ['Timeline events bound to ClientNote', $canonicalTimelineEvents],
        ]);

        if (
            $missing > 0
            || $duplicates > 0
            || $orphaned > 0
            || $jsonOnlyMarkers > 0
            || $legacyTimelineEvents > 0
        ) {
            $this->error('Client note consolidation is not reconciled.');

            return self::FAILURE;
        }

        $this->info('Client note consolidation is reconciled.');

        return self::SUCCESS;
    }
}
