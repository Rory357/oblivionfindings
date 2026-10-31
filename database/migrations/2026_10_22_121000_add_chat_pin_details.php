<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A pin is shared with everyone in the conversation, so it records who pinned it and when.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('ops_messages', 'pinned_by')) {
                $table->foreignId('pinned_by')->nullable()->after('is_pinned')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('ops_messages', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('pinned_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ops_messages', function (Blueprint $table) {
            if (Schema::hasColumn('ops_messages', 'pinned_by')) {
                $table->dropConstrainedForeignId('pinned_by');
            }
            if (Schema::hasColumn('ops_messages', 'pinned_at')) {
                $table->dropColumn('pinned_at');
            }
        });
    }
};
