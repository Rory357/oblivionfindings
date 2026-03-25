<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('site_damages', 'checklist_run_id')) return;
        Schema::table('site_damages', function (Blueprint $table) {
            $table->foreignId('checklist_run_id')
                ->nullable()
                ->after('photos')
                ->constrained('site_checklist_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_damages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_run_id');
        });
    }
};
