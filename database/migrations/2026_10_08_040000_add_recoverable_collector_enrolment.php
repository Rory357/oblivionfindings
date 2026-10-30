<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->longText('recovery_response')->nullable();
            $table->char('recovery_identity_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->dropColumn(['recovery_response', 'recovery_identity_fingerprint']);
        });
    }
};
