<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shift_handovers', 'acknowledged_by')) {
            return;
        }

        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shift_handovers', 'acknowledged_by')) {
            return;
        }

        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by']);
            $table->dropColumn('acknowledged_by');
        });
    }
};
