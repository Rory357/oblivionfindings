<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obli_ai_requests', function (Blueprint $table): void {
            $table->string('context_kind', 16)->default('personal');
            $table->longText('investigation_context_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('obli_ai_requests', fn (Blueprint $table) => $table->dropColumn(['context_kind', 'investigation_context_encrypted']));
    }
};
