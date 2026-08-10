<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table) {
            $table->text('immediate_action_taken')->nullable()->after('notes');
        });

        Schema::table('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->text('immediate_action_taken')->nullable()->after('circumstances');
        });
    }

    public function down(): void
    {
        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table) {
            $table->dropColumn('immediate_action_taken');
        });

        Schema::table('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->dropColumn('immediate_action_taken');
        });
    }
};
