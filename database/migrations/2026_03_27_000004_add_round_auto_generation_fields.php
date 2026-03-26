<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_round_templates', function (Blueprint $table) {
            $table->foreignId('default_assigned_to')->nullable()->after('active')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('medication_rounds', function (Blueprint $table) {
            $table->foreignId('round_template_id')->nullable()->after('id')
                ->constrained('medication_round_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medication_rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('round_template_id');
        });

        Schema::table('medication_round_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_assigned_to');
        });
    }
};
