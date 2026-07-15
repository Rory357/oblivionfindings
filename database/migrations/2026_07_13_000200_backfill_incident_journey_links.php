<?php

use App\Models\ClientIncident;
use App\Models\HsEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze the direct journey links introduced after historic incidents already
 * had source-linked H&S events. Only an unambiguous active event is adopted;
 * conflicts and drafts remain untouched for later manual repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_incidents')
            ->where('status', '!=', 'draft')
            ->where(function ($query): void {
                $query->whereNull('hs_event_id')
                    ->orWhereNull('site_id');
            })
            ->select(['id', 'type', 'hs_event_id', 'site_id'])
            ->orderBy('id')
            ->chunkById(200, function ($incidents): void {
                foreach ($incidents as $incident) {
                    $category = $incident->type === 'near_miss'
                        ? HsEvent::CATEGORY_NEAR_MISS
                        : HsEvent::CATEGORY_INCIDENT;
                    $events = DB::table('hs_events')
                        ->where('source_type', ClientIncident::class)
                        ->where('source_id', $incident->id)
                        ->where('event_category', $category)
                        ->where('idempotency_key', HsEvent::buildIdempotencyKey(
                            ClientIncident::class,
                            $incident->id,
                            $category,
                        ))
                        ->whereNull('deleted_at')
                        ->orderBy('id')
                        ->limit(2)
                        ->get(['id', 'site_id']);

                    if ($events->count() !== 1) {
                        continue;
                    }

                    $event = $events->first();
                    if ($incident->hs_event_id !== null
                        && (int) $incident->hs_event_id !== (int) $event->id
                    ) {
                        continue;
                    }

                    $claimedByAnotherIncident = DB::table('client_incidents')
                        ->where('hs_event_id', $event->id)
                        ->where('id', '!=', $incident->id)
                        ->exists();
                    if ($claimedByAnotherIncident) {
                        continue;
                    }

                    $updates = [];
                    if ($incident->hs_event_id === null) {
                        $updates['hs_event_id'] = $event->id;
                    }
                    if ($incident->site_id === null && $event->site_id !== null) {
                        $updates['site_id'] = $event->site_id;
                    }

                    if ($updates !== []) {
                        DB::table('client_incidents')
                            ->where('id', $incident->id)
                            ->update($updates);
                    }

                    if ($event->site_id === null && $incident->site_id !== null) {
                        DB::table('hs_events')
                            ->where('id', $event->id)
                            ->whereNull('site_id')
                            ->update(['site_id' => $incident->site_id]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible.
    }
};
