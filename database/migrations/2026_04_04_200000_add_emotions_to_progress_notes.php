<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_notes', function (Blueprint $table) {
            $table->json('emotions')->nullable()->after('mood_rating');
        });
    }

    public function down(): void
    {
        Schema::table('progress_notes', function (Blueprint $table) {
            $table->dropColumn('emotions');
        });
    }
};
