<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured reference responses + a token-guarded public questionnaire link
 * (D3 / handover item 17). Additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_reference_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_reference_checks', 'responses')) {
                $table->json('responses')->nullable()->after('reference_notes');
            }
            if (! Schema::hasColumn('hr_reference_checks', 'response_token')) {
                $table->string('response_token', 64)->nullable()->unique()->after('responses');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_reference_checks', function (Blueprint $table) {
            if (Schema::hasColumn('hr_reference_checks', 'response_token')) {
                $table->dropUnique(['response_token']);
                $table->dropColumn('response_token');
            }
            if (Schema::hasColumn('hr_reference_checks', 'responses')) {
                $table->dropColumn('responses');
            }
        });
    }
};
