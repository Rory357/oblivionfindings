<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_staff_requirements', function (Blueprint $table): void {
            // Nullable leaves every legacy choice visibly unresolved; no policy/count backfill.
            $table->foreignId('hr_compliance_requirement_id')->nullable()
                ->constrained('hr_compliance_requirements')->restrictOnDelete();
            $table->string('applicability_mode', 32)->nullable();
            $table->unsignedInteger('minimum_qualified_staff')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_staff_requirements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hr_compliance_requirement_id');
            $table->dropColumn(['applicability_mode', 'minimum_qualified_staff']);
        });
    }
};
