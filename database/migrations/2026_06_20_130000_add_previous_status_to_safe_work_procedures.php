<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archive ↔ restore round-trip: remember the status a procedure held before it
     * was archived so "Restore" returns it to exactly that state (not a guess).
     */
    public function up(): void
    {
        Schema::table('safe_work_procedures', function (Blueprint $table) {
            $table->string('previous_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('safe_work_procedures', function (Blueprint $table) {
            $table->dropColumn('previous_status');
        });
    }
};
