<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P11 B1 chunk 3: a medicine-rule change that names a controlled
 * medicine is flagged when written, so the change history can conceal its
 * label and wording from anyone without controlled-medicine access (EM-12).
 * The page also re-checks the rule when the history is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_setting_changes', function (Blueprint $table) {
            $table->boolean('controlled')->default(false)->after('loosens');
        });
    }

    public function down(): void
    {
        Schema::table('medication_setting_changes', function (Blueprint $table) {
            $table->dropColumn('controlled');
        });
    }
};
