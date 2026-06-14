<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NCC-MERP severity grades a medication error by whether it reached the patient
 * and the harm caused; HDC open-disclosure expects families to be told of
 * adverse events. Capture those, plus a close-out note + audit when the error
 * is finally closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_errors', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_errors', 'reached_client')) {
                $table->string('reached_client', 16)->nullable()->after('severity'); // no | yes | unknown
            }
            if (! Schema::hasColumn('medication_errors', 'harm_level')) {
                $table->string('harm_level', 16)->nullable()->after('reached_client'); // NCC-MERP band: a_c | d_e | f_g | h_i
            }
            if (! Schema::hasColumn('medication_errors', 'open_disclosure')) {
                $table->string('open_disclosure', 16)->nullable()->after('harm_level'); // na | pending | done
            }
            if (! Schema::hasColumn('medication_errors', 'close_note')) {
                $table->text('close_note')->nullable()->after('preventive_actions');
            }
            if (! Schema::hasColumn('medication_errors', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('reviewed_at');
            }
            if (! Schema::hasColumn('medication_errors', 'closed_by')) {
                $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_errors', function (Blueprint $table) {
            if (Schema::hasColumn('medication_errors', 'closed_by')) {
                $table->dropConstrainedForeignId('closed_by');
            }
            foreach (['reached_client', 'harm_level', 'open_disclosure', 'close_note', 'closed_at'] as $col) {
                if (Schema::hasColumn('medication_errors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
