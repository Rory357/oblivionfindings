<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the review's boolean "incident report linked" with a real reference to
 * the linked incident (client_incidents.id). The legacy boolean column stays for
 * backward compatibility and is now derived from this id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_break_glass_accesses', function (Blueprint $table) {
            $table->unsignedBigInteger('incident_report_id')->nullable()->after('incident_report_linked');
        });
    }

    public function down(): void
    {
        Schema::table('client_break_glass_accesses', function (Blueprint $table) {
            $table->dropColumn('incident_report_id');
        });
    }
};
