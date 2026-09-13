<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Early local revision workspaces were installed before this optional field existed.
        // Keep the installed revision identity and its data; forward-fill only the missing column.
        if (! Schema::hasColumn('it_kb_articles', 'related_records')) {
            Schema::table('it_kb_articles', fn (Blueprint $table) => $table->json('related_records')->nullable());
        }
    }

    public function down(): void
    {
        // Other installations received this column in 000001. Never delete their retained links.
    }
};
