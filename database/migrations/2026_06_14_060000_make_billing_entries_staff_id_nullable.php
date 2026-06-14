<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring charges (and other automated/funder billing) are not delivered by a
 * staff member, so a billing entry must be allowed to have no staff_id. The
 * timesheet-billing flow continues to set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_entries', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('billing_entries', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable(false)->change();
        });
    }
};
