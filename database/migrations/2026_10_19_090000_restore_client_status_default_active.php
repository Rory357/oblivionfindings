<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Restore the established default for omitted-status inserts. The service-commencement
        // gate applies to referral-originated people (or everyone, when the organisation opts in)
        // in Client::save; respite, portal and import paths keep creating active people.
        Schema::table('clients', fn (Blueprint $table) => $table->string('status')->default('active')->change());
    }

    public function down(): void
    {
        // Keep the established default on rollback; never relabel existing people.
    }
};
