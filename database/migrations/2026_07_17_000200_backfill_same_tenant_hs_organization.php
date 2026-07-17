<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHUNK_SIZE = 200;

    public function up(): void
    {
        $this->backfillSafeguardingConcernOrganizations();
        $this->backfillHistoricalEventOrganizations();
    }

    public function down(): void
    {
        // Tenant provenance is intentionally retained on rollback. This
        // migration fills only null organization IDs after the independent
        // site and subject/client records agree on one tenant.
    }

    private function backfillSafeguardingConcernOrganizations(): void
    {
        DB::table('safeguarding_concerns')
            ->whereNull('organization_id')
            ->whereNotNull('site_id')
            ->where('subject_type', Client::class)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($concerns): void {
                $this->backfillSafeguardingConcernChunk(
                    $concerns->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            });
    }

    /**
     * @param  list<int>  $concernIds
     */
    private function backfillSafeguardingConcernChunk(array $concernIds): void
    {
        DB::transaction(function () use ($concernIds): void {
            $concerns = DB::table('safeguarding_concerns')
                ->whereIn('id', $concernIds)
                ->whereNull('organization_id')
                ->whereNotNull('site_id')
                ->where('subject_type', Client::class)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'site_id', 'subject_type', 'subject_id']);
            if ($concerns->isEmpty()) {
                return;
            }

            $sites = DB::table('sites')
                ->whereIn('id', $concerns->pluck('site_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'tenant_id'])
                ->keyBy('id');
            $clients = DB::table('clients')
                ->whereIn('id', $concerns->pluck('subject_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'organization_id'])
                ->keyBy('id');

            foreach ($concerns as $concern) {
                $site = $sites->get($concern->site_id);
                if ($site === null || $site->tenant_id === null) {
                    continue;
                }

                $subject = $clients->get($concern->subject_id);
                if (
                    $subject === null
                    || (int) $subject->organization_id !== (int) $site->tenant_id
                ) {
                    continue;
                }

                DB::table('safeguarding_concerns')
                    ->where('id', $concern->id)
                    ->where('site_id', $concern->site_id)
                    ->where('subject_type', $concern->subject_type)
                    ->where('subject_id', $concern->subject_id)
                    ->whereNull('organization_id')
                    ->update([
                        'organization_id' => $site->tenant_id,
                    ]);
            }
        });
    }

    private function backfillHistoricalEventOrganizations(): void
    {
        DB::table('hs_events')
            ->whereNull('organization_id')
            ->whereNotNull('site_id')
            ->whereNotNull('client_id')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($events): void {
                $this->backfillHistoricalEventChunk(
                    $events->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            });
    }

    /**
     * @param  list<int>  $eventIds
     */
    private function backfillHistoricalEventChunk(array $eventIds): void
    {
        DB::transaction(function () use ($eventIds): void {
            $events = DB::table('hs_events')
                ->whereIn('id', $eventIds)
                ->whereNull('organization_id')
                ->whereNotNull('site_id')
                ->whereNotNull('client_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'site_id', 'client_id']);
            if ($events->isEmpty()) {
                return;
            }

            $sites = DB::table('sites')
                ->whereIn('id', $events->pluck('site_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'tenant_id'])
                ->keyBy('id');
            $clients = DB::table('clients')
                ->whereIn('id', $events->pluck('client_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'organization_id'])
                ->keyBy('id');

            foreach ($events as $event) {
                $site = $sites->get($event->site_id);
                $client = $clients->get($event->client_id);
                if (
                    $site === null
                    || $site->tenant_id === null
                    || $client === null
                    || (int) $client->organization_id !== (int) $site->tenant_id
                ) {
                    continue;
                }

                DB::table('hs_events')
                    ->where('id', $event->id)
                    ->where('site_id', $event->site_id)
                    ->where('client_id', $event->client_id)
                    ->whereNull('organization_id')
                    ->update([
                        'organization_id' => $site->tenant_id,
                    ]);
            }
        });
    }
};
