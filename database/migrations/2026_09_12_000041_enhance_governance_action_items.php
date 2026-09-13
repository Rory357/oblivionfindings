<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_items', function (Blueprint $table) {
            if (! Schema::hasColumn('action_items', 'title')) {
                $table->string('title')->nullable()->after('action_reference');
            }
            if (! Schema::hasColumn('action_items', 'version_number')) {
                $table->unsignedInteger('version_number')->default(1)->after('progress_notes');
            }
            if (! Schema::hasColumn('action_items', 'completion_receipt')) {
                $table->string('completion_receipt')->nullable()->after('completion_notes');
            }
            if (! Schema::hasColumn('action_items', 'follow_up_key')) {
                $table->string('follow_up_key')->nullable()->after('source_id');
                $table->index(['source_type', 'source_id', 'follow_up_key'], 'idx_act_source_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('action_items', function (Blueprint $table) {
            if (Schema::hasColumn('action_items', 'follow_up_key')) {
                $table->dropIndex('idx_act_source_key');
                $table->dropColumn('follow_up_key');
            }
            if (Schema::hasColumn('action_items', 'completion_receipt')) {
                $table->dropColumn('completion_receipt');
            }
            if (Schema::hasColumn('action_items', 'version_number')) {
                $table->dropColumn('version_number');
            }
            if (Schema::hasColumn('action_items', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};
