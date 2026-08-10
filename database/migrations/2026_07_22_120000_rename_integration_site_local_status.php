<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_site_configs')
            ->where('status', 'tenant_only')
            ->update(['status' => 'local_only']);

        Schema::table('integration_site_configs', function (Blueprint $table): void {
            $table->string('status')->default('local_only')->change();
        });
    }

    public function down(): void
    {
        DB::table('integration_site_configs')
            ->where('status', 'local_only')
            ->update(['status' => 'tenant_only']);

        Schema::table('integration_site_configs', function (Blueprint $table): void {
            $table->string('status')->default('tenant_only')->change();
        });
    }
};
