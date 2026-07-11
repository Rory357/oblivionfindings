<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->index(['organization_id', 'created_at'], 'audit_logs_organization_created_index');
        });

        DB::table('audit_logs')
            ->whereNull('organization_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $userOrganizations = DB::table('users')
                    ->whereIn('id', $logs->pluck('user_id')->filter()->unique())
                    ->pluck('organization_id', 'id');
                $clientOrganizations = DB::table('clients')
                    ->whereIn('id', $logs->pluck('client_id')->filter()->unique())
                    ->pluck('organization_id', 'id');

                foreach ($logs as $log) {
                    $organizationId = $userOrganizations[$log->user_id] ?? null;
                    $organizationId ??= $clientOrganizations[$log->client_id] ?? null;

                    if (is_numeric($organizationId)) {
                        DB::table('audit_logs')
                            ->where('id', $log->id)
                            ->update(['organization_id' => (int) $organizationId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_organization_created_index');
            $table->dropColumn('organization_id');
        });
    }
};
