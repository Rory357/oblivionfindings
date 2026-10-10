<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_qualification_requirements', function (Blueprint $table): void {
            $table->foreignId('hr_compliance_requirement_id')->nullable()->constrained('hr_compliance_requirements', indexName: 'staff_qualification_hr_requirement_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_qualification_requirements', function (Blueprint $table): void {
            $table->dropForeign('staff_qualification_hr_requirement_fk');
            $table->dropColumn('hr_compliance_requirement_id');
        });
    }
};
