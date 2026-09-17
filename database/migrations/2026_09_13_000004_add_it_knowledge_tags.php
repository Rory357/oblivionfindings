<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('it_kb_articles') && ! Schema::hasColumn('it_kb_articles', 'tags')) {
            Schema::table('it_kb_articles', fn (Blueprint $table) => $table->json('tags')->nullable());
        }
    }

    public function down(): void
    {
        // Published tags and their immutable revisions are retained on rollback.
    }
};
