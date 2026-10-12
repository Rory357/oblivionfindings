<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-104 (Stephan, 10 Oct: "make it a setting"): an outside prescriber's
 * registration check can be recorded as structured evidence — the council,
 * registration number, scope of practice and the date it was checked on the
 * public register — with a yearly re-check. Existing free-text records stay
 * as they are (registration_check null = recorded before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_external_clinicians') || Schema::hasColumn('medication_external_clinicians', 'registration_check')) {
            return;
        }
        Schema::table('medication_external_clinicians', function (Blueprint $table): void {
            $table->string('registration_check', 16)->nullable()->after('registration_number');
            $table->string('registration_scope', 255)->nullable()->after('registration_check');
            $table->date('register_checked_at')->nullable()->after('registration_scope');
            $table->foreignId('register_checked_by')->nullable()->after('register_checked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('medication_external_clinicians') || ! Schema::hasColumn('medication_external_clinicians', 'registration_check')) {
            return;
        }
        Schema::table('medication_external_clinicians', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('register_checked_by');
            $table->dropColumn(['registration_check', 'registration_scope', 'register_checked_at']);
        });
    }
};
