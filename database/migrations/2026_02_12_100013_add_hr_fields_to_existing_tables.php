<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add HR-related fields to timesheets
        Schema::table('timesheets', function (Blueprint $table) {
            $table->decimal('mileage_km', 8, 2)->nullable()->after('break_minutes');
            $table->boolean('sleepover')->default(false)->after('mileage_km');
            $table->boolean('on_call')->default(false)->after('sleepover');
            $table->text('allowance_notes')->nullable()->after('on_call');
            $table->boolean('public_holiday')->default(false)->after('allowance_notes');
        });

        // Add HR confidentiality fields to client incidents
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->boolean('is_hr_confidential')->default(false);
            $table->unsignedBigInteger('hr_case_id')->nullable()->index();
        });

        // Add NZ-specific vetting fields to staff background checks
        Schema::table('staff_background_checks', function (Blueprint $table) {
            $table->string('nz_police_vetting_ref')->nullable();
            $table->datetime('consent_captured_at')->nullable();
            $table->string('consent_method')->nullable();
            $table->string('consent_document_path')->nullable();
            $table->string('approved_agency_ref')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn([
                'mileage_km',
                'sleepover',
                'on_call',
                'allowance_notes',
                'public_holiday',
            ]);
        });

        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropIndex(['hr_case_id']);
            $table->dropColumn([
                'is_hr_confidential',
                'hr_case_id',
            ]);
        });

        Schema::table('staff_background_checks', function (Blueprint $table) {
            $table->dropColumn([
                'nz_police_vetting_ref',
                'consent_captured_at',
                'consent_method',
                'consent_document_path',
                'approved_agency_ref',
            ]);
        });
    }
};
