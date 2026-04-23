<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->default(1)->index();
            }
        });

        DB::table('shifts')
            ->whereNull('organization_id')
            ->orderBy('id')
            ->chunkById(500, function ($shifts): void {
                $organizationByClient = DB::table('clients')
                    ->whereIn('id', $shifts->pluck('client_id')->filter()->unique()->values())
                    ->pluck('organization_id', 'id');

                foreach ($shifts as $shift) {
                    DB::table('shifts')
                        ->where('id', $shift->id)
                        ->update([
                            'organization_id' => $organizationByClient[$shift->client_id] ?? 1,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'organization_id')) {
                $table->dropColumn('organization_id');
            }
        });
    }
};
