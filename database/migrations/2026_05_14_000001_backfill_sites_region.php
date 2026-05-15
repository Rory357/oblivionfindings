<?php

use App\Support\NzRegions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')
            ->where(function ($query) {
                $query->whereNull('region')->orWhere('region', '');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $derived = NzRegions::fromCity($row->city ?: $row->suburb);

                    if ($derived) {
                        DB::table('sites')
                            ->where('id', $row->id)
                            ->update(['region' => $derived]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally no-op: do not erase human-entered or backfilled region data.
    }
};
