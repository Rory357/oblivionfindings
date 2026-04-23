<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_breach_logs', function (Blueprint $table) {
            $table->string('breach_type')->nullable()->after('breach_reference');
            $table->string('severity')->nullable()->after('breach_type');
        });
    }

    public function down(): void
    {
        Schema::table('data_breach_logs', function (Blueprint $table) {
            $table->dropColumn(['breach_type', 'severity']);
        });
    }
};
