<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_calls', function (Blueprint $table): void {
            $table->string('routing_mode', 20)->default('invited');
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_calls', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn('routing_mode');
        });
    }
};
