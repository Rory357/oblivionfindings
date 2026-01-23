<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend client_notes for care delivery (shift notes, progress notes, handover, portal-visible notes)
        Schema::table('client_notes', function (Blueprint $table) {
            if (!Schema::hasColumn('client_notes', 'type')) {
                $table->string('type')->default('note')->after('user_id');
            }
            if (!Schema::hasColumn('client_notes', 'subject')) {
                $table->string('subject')->nullable()->after('type');
            }
            if (!Schema::hasColumn('client_notes', 'occurred_at')) {
                $table->dateTime('occurred_at')->nullable()->after('body');
            }
            if (!Schema::hasColumn('client_notes', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('client_id')->constrained('shifts')->nullOnDelete();
            }
            if (!Schema::hasColumn('client_notes', 'goal')) {
                $table->string('goal')->nullable()->after('subject');
            }
            if (!Schema::hasColumn('client_notes', 'visibility')) {
                $table->string('visibility')->default('internal')->after('body');
            }
            if (!Schema::hasColumn('client_notes', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->after('visibility');
            }
        });

        // Extend timeline_events so it can power shift/client care logs directly.
        Schema::table('timeline_events', function (Blueprint $table) {
            if (!Schema::hasColumn('timeline_events', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('client_id')->constrained('shifts')->nullOnDelete();
            }
            if (!Schema::hasColumn('timeline_events', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->after('visibility');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timeline_events', function (Blueprint $table) {
            if (Schema::hasColumn('timeline_events', 'is_pinned')) {
                $table->dropColumn('is_pinned');
            }
            if (Schema::hasColumn('timeline_events', 'shift_id')) {
                $table->dropConstrainedForeignId('shift_id');
            }
        });

        Schema::table('client_notes', function (Blueprint $table) {
            if (Schema::hasColumn('client_notes', 'is_pinned')) {
                $table->dropColumn('is_pinned');
            }
            if (Schema::hasColumn('client_notes', 'visibility')) {
                $table->dropColumn('visibility');
            }
            if (Schema::hasColumn('client_notes', 'goal')) {
                $table->dropColumn('goal');
            }
            if (Schema::hasColumn('client_notes', 'shift_id')) {
                $table->dropConstrainedForeignId('shift_id');
            }
            if (Schema::hasColumn('client_notes', 'occurred_at')) {
                $table->dropColumn('occurred_at');
            }
            if (Schema::hasColumn('client_notes', 'subject')) {
                $table->dropColumn('subject');
            }
            if (Schema::hasColumn('client_notes', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
