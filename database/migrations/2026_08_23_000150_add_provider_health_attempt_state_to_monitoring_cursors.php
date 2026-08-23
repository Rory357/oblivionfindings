<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_provider_cursors', function (Blueprint $table): void {
            $table->timestamp('last_failed_at')->nullable()->after('last_completed_at');
            $table->timestamp('last_partial_at')->nullable()->after('last_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_provider_cursors', function (Blueprint $table): void {
            $table->dropColumn(['last_failed_at', 'last_partial_at']);
        });
    }
};
