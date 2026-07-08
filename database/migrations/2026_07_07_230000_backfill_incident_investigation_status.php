<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill client_incidents.investigation_status from the H&S investigations
 * that live on each incident's governance HsEvent.
 *
 * The column drives the incident register's "investigation" filter and the
 * high-severity close guardrail, but nothing synced it when an H&S
 * investigation progressed — so completed investigations never unlocked
 * closing a high-severity incident. HsInvestigationService now mirrors the
 * lifecycle; this recovers the rows written before that fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        $statusMap = [
            'draft' => 'pending',
            'in_progress' => 'in_progress',
            'findings_recorded' => 'in_progress',
            'under_review' => 'in_progress',
            'completed' => 'completed',
        ];

        DB::table('hs_events')
            ->join('hs_investigations', 'hs_investigations.hs_event_id', '=', 'hs_events.id')
            ->where('hs_events.source_type', 'App\\Models\\ClientIncident')
            ->whereNotNull('hs_events.source_id')
            ->orderBy('hs_investigations.id')
            ->select([
                'hs_events.source_id as incident_id',
                'hs_investigations.status as investigation_status',
            ])
            ->chunk(200, function ($rows) use ($statusMap) {
                foreach ($rows as $row) {
                    $mapped = $statusMap[$row->investigation_status] ?? null;

                    if ($mapped === null) {
                        continue;
                    }

                    // Later investigations win within the chunk walk (ordered by id),
                    // and a completed investigation always wins overall.
                    $query = DB::table('client_incidents')->where('id', $row->incident_id);

                    if ($mapped !== 'completed') {
                        $query->where(function ($q) {
                            $q->whereNull('investigation_status')
                                ->orWhere('investigation_status', '!=', 'completed');
                        });
                    }

                    $query->update(['investigation_status' => $mapped]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill — nothing sensible to reverse.
    }
};
