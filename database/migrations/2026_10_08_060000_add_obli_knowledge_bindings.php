<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obli_ai_requests', function (Blueprint $table): void {
            $table->text('knowledge_bindings_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('obli_ai_requests', function (Blueprint $table): void {
            $table->dropColumn('knowledge_bindings_encrypted');
        });
    }
};
