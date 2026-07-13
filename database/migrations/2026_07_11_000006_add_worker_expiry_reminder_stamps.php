<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_driver_eligibility', function (Blueprint $table) {
            $table->timestamp('licence_expiry_reminder_sent_at')
                ->nullable()
                ->after('licence_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_driver_eligibility', function (Blueprint $table) {
            $table->dropColumn('licence_expiry_reminder_sent_at');
        });
    }
};
