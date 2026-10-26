<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_referral_settings_versions', function (Blueprint $table): void {
            $table->uuid('request_key')->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('care_referral_settings_versions', fn (Blueprint $table) => $table->dropColumn(['request_key', 'request_hash']));
    }
};
