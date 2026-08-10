<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasCollision = DB::table('calendar_sync_connections')
            ->select('provider')
            ->groupBy('provider')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCollision) {
            throw new RuntimeException(
                'Duplicate calendar provider connection identities require reconciliation before global identity can be enforced.',
            );
        }

        $hasActiveMappingCollision = DB::table('calendar_sync_mappings')
            ->select('site_id')
            ->where('is_active', true)
            ->groupBy('site_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasActiveMappingCollision) {
            throw new RuntimeException(
                'Duplicate active calendar mappings require reconciliation before global identity can be enforced.',
            );
        }

        // Install the stronger identity first. If it cannot be added, the historical
        // indexes remain intact and the migration can fail without weakening writes.
        if (! Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_provider_uq')) {
            Schema::table('calendar_sync_connections', function (Blueprint $table): void {
                $table->unique('provider', 'calendar_sync_connections_provider_uq');
            });
        }

        if (Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_tenant_id_provider_unique')) {
            Schema::table('calendar_sync_connections', function (Blueprint $table): void {
                $table->dropUnique('calendar_sync_connections_tenant_id_provider_unique');
            });
        }
        if (Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_tenant_id_index')) {
            Schema::table('calendar_sync_connections', function (Blueprint $table): void {
                $table->dropIndex('calendar_sync_connections_tenant_id_index');
            });
        }
    }

    public function down(): void
    {
        // Restore the exact compatibility shape before removing the stronger
        // provider identity, so a partial rollback never leaves writes unguarded.
        if (! Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_tenant_id_index')) {
            Schema::table('calendar_sync_connections', function (Blueprint $table): void {
                $table->index('tenant_id', 'calendar_sync_connections_tenant_id_index');
            });
        }
        if (! Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_tenant_id_provider_unique')) {
            Schema::table('calendar_sync_connections', function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'provider'],
                    'calendar_sync_connections_tenant_id_provider_unique',
                );
            });
        }
        if (Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_provider_uq')) {
            Schema::table('calendar_sync_connections', function (Blueprint $table): void {
                $table->dropUnique('calendar_sync_connections_provider_uq');
            });
        }
    }
};
