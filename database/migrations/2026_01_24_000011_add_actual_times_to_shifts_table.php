<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dateTime('actual_starts_at')->nullable()->after('ends_at');
            $table->dateTime('actual_ends_at')->nullable()->after('actual_starts_at');
            $table->foreignId('started_by')->nullable()->after('actual_ends_at')->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->after('started_by')->constrained('users')->nullOnDelete();

            $table->index(['status', 'actual_starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['status', 'actual_starts_at']);
            $table->dropConstrainedForeignId('completed_by');
            $table->dropConstrainedForeignId('started_by');
            $table->dropColumn(['actual_starts_at', 'actual_ends_at']);
        });
    }
};
