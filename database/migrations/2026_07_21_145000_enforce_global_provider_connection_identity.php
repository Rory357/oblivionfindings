<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasCollision = DB::table('integration_tenant_secrets')
            ->select('provider')
            ->groupBy('provider')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCollision) {
            throw new RuntimeException(
                'Duplicate provider connections require reconciliation before global identity can be enforced.',
            );
        }

        Schema::table('integration_tenant_secrets', function (Blueprint $table): void {
            $table->dropUnique('integration_tenant_secrets_tenant_id_provider_unique');
            $table->unique('provider', 'integration_provider_connections_provider_uq');
        });
    }

    public function down(): void
    {
        Schema::table('integration_tenant_secrets', function (Blueprint $table): void {
            $table->dropUnique('integration_provider_connections_provider_uq');
            $table->unique(['tenant_id', 'provider'], 'integration_tenant_secrets_tenant_id_provider_unique');
        });
    }
};
