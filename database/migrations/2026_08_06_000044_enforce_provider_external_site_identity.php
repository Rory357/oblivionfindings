<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'integration_provider_external_site_unique';

    private const IDENTITY_GUARD = 'mapped_external_site_identity_guard';

    public function up(): void
    {
        $duplicate = DB::table('integration_site_configs')
            ->selectRaw('provider, TRIM(mapped_external_site_id) as normalized_external_site_id')
            ->whereNotNull('mapped_external_site_id')
            ->whereRaw("TRIM(mapped_external_site_id) <> ''")
            ->groupBy('provider', DB::raw('TRIM(mapped_external_site_id)'))
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException('Provider Site mappings contain an ambiguous external identity.');
        }

        DB::table('integration_site_configs')
            ->whereNotNull('mapped_external_site_id')
            ->update([
                'mapped_external_site_id' => DB::raw("NULLIF(TRIM(mapped_external_site_id), '')"),
            ]);

        Schema::table('integration_site_configs', function (Blueprint $table): void {
            $table->string(self::IDENTITY_GUARD)
                ->nullable()
                ->virtualAs("NULLIF(TRIM(`mapped_external_site_id`), '')")
                ->invisible();
            $table->unique(['provider', self::IDENTITY_GUARD], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('integration_site_configs', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
            $table->dropColumn(self::IDENTITY_GUARD);
        });
    }
};
