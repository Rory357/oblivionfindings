<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_checklist_template_items', function (Blueprint $table) {
            $table->enum('failure_risk_level', ['ordinary', 'critical'])
                ->default('ordinary')
                ->after('failure_creates_damage');
        });
    }

    public function down(): void
    {
        Schema::table('site_checklist_template_items', function (Blueprint $table) {
            $table->dropColumn('failure_risk_level');
        });
    }
};
