<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a lone worker session to its rostered shift (nullable — ad-hoc sessions
 * have no shift). A safety overlay over the roster: prefill worker/site/client/
 * expected-end/location from the shift and reuse its GPS, without merging the
 * payroll-critical Shift record into the safety lifecycle.
 *
 * See docs/lone-workers-redesign/INTEGRATION_AUDIT.md §3 (link, don't merge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lone_worker_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->after('client_id');
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('lone_worker_sessions', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropIndex(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
