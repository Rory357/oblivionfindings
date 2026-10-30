<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Repair previously saved counts without rewriting their immutable evidence.
        DB::table('asset_stocktakes')->select(['id', 'entries', 'activity'])->chunkById(100, function ($counts): void {
            foreach ($counts as $count) {
                $entries = collect(json_decode($count->entries, true, flags: JSON_THROW_ON_ERROR));
                $activity = collect(json_decode($count->activity, true, flags: JSON_THROW_ON_ERROR));
                $ids = $entries->pluck('asset_id')->merge($activity->pluck('asset_id'))
                    ->merge($activity->pluck('previous.asset_id'))
                    ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                    ->map(fn ($id) => (int) $id)->unique();
                $rows = $ids->map(fn ($id) => ['asset_stocktake_id' => $count->id, 'asset_id' => $id])->all();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('asset_stocktake_asset_refs')->insertOrIgnore($chunk);
                }
            }
        });
    }

    public function down(): void
    {
        // Removing these references would expose retained history after access changes.
    }
};
