<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run can be reassigned to a specific user independently of its assignment
 * (the Schedule right-click "Reassign" action). Nullable — falls back to the
 * assignment's assignee when unset.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_checklist_runs', function (Blueprint $table) {
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->after('completed_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_checklist_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_user_id');
        });
    }
};
