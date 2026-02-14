<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_suggestions', function (Blueprint $table) {
            $table->text('triage_notes')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_suggestions', function (Blueprint $table) {
            $table->dropColumn('triage_notes');
        });
    }
};

