<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->foreignId('expired_by')
                ->nullable()
                ->after('portal_expires_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('expiry_reason')->nullable()->after('expired_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expired_by');
            $table->dropColumn('expiry_reason');
        });
    }
};
