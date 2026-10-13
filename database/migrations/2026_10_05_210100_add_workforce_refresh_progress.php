<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workforce_eligibility_rechecks', 'last_shift_id')) {
            Schema::table('workforce_eligibility_rechecks', fn (Blueprint $table) => $table->unsignedBigInteger('last_shift_id')->default(0));
        }
        if (! Schema::hasColumn('workforce_eligibility_observations', 'observed_versions')) {
            Schema::table('workforce_eligibility_observations', fn (Blueprint $table) => $table->json('observed_versions')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('workforce_eligibility_observations', fn (Blueprint $table) => $table->dropColumn('observed_versions'));
        Schema::table('workforce_eligibility_rechecks', fn (Blueprint $table) => $table->dropColumn('last_shift_id'));
    }
};
