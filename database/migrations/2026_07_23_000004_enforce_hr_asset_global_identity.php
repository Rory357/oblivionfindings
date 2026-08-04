<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GLOBAL_UNIQUE = 'hr_assets_asset_tag_unique';

    private const LEGACY_UNIQUE = 'hr_assets_tenant_id_asset_tag_unique';

    public function up(): void
    {
        if (! Schema::hasTable('hr_assets')
            || ! Schema::hasColumns('hr_assets', ['tenant_id', 'asset_tag'])
        ) {
            return;
        }

        $hasCollision = DB::table('hr_assets')
            ->select('asset_tag')
            ->groupBy('asset_tag')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCollision) {
            throw new RuntimeException(
                'Global HR asset-tag collisions require reconciliation before migration.',
            );
        }

        if (! Schema::hasIndex('hr_assets', self::GLOBAL_UNIQUE)) {
            Schema::table('hr_assets', function (Blueprint $table): void {
                $table->unique('asset_tag', self::GLOBAL_UNIQUE);
            });
        }

        if (Schema::hasIndex('hr_assets', self::LEGACY_UNIQUE)) {
            Schema::table('hr_assets', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_assets')
            || ! Schema::hasColumns('hr_assets', ['tenant_id', 'asset_tag'])
        ) {
            return;
        }

        if (! Schema::hasIndex('hr_assets', self::LEGACY_UNIQUE)) {
            Schema::table('hr_assets', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'asset_tag'], self::LEGACY_UNIQUE);
            });
        }

        if (Schema::hasIndex('hr_assets', self::GLOBAL_UNIQUE)) {
            Schema::table('hr_assets', function (Blueprint $table): void {
                $table->dropUnique(self::GLOBAL_UNIQUE);
            });
        }
    }
};
