<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CD loss reports — NZ Medsafe / Misuse of Drugs governance fields. Records the
 * CD Accountable Officer who oversees the loss and any regulator (Medsafe /
 * Ministry of Health) notification, alongside the existing Police / pharmacy
 * escalation. All nullable — existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->string('accountable_officer_name', 255)->nullable()->after('circumstances');
            $table->boolean('reported_to_regulator')->default(false)->after('pharmacy_name');
            $table->string('regulator_name', 255)->nullable()->after('reported_to_regulator');
            $table->string('regulator_reference', 100)->nullable()->after('regulator_name');
            $table->timestamp('regulator_notified_at')->nullable()->after('regulator_reference');
        });
    }

    public function down(): void
    {
        Schema::table('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->dropColumn([
                'accountable_officer_name',
                'reported_to_regulator',
                'regulator_name',
                'regulator_reference',
                'regulator_notified_at',
            ]);
        });
    }
};
