<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_announcements', function (Blueprint $table) {
            // Lifecycle: draft | scheduled | published | archived
            $table->string('status', 16)->default('published')->index()->after('priority');
            $table->softDeletes();

            // Mandatory-read deadline (optional) — drives the "Needs you" strip.
            $table->dateTime('ack_deadline')->nullable()->after('expires_at');

            // Recurring series.
            $table->string('recurrence', 16)->nullable()->after('ack_deadline'); // null|weekly|monthly
            $table->dateTime('recurrence_ends_at')->nullable()->after('recurrence');
            $table->unsignedBigInteger('recurrence_parent_id')->nullable()->after('recurrence_ends_at');

            // Idempotent link to the header-bell inbox announcement.
            $table->foreignId('inbox_announcement_id')->nullable()->after('recurrence_parent_id')
                ->constrained('announcements')->nullOnDelete();
        });

        // Audit the manager "mark acknowledged" override.
        Schema::table('hr_announcement_acknowledgements', function (Blueprint $table) {
            $table->foreignId('acknowledged_by')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill the status column from the legacy published_at semantics.
        DB::table('hr_announcements')->whereNull('published_at')->update(['status' => 'draft']);
        DB::table('hr_announcements')->whereNotNull('published_at')
            ->where('published_at', '>', now())->update(['status' => 'scheduled']);
        DB::table('hr_announcements')->whereNotNull('published_at')
            ->where('published_at', '<=', now())->update(['status' => 'published']);
    }

    public function down(): void
    {
        Schema::table('hr_announcement_acknowledgements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
        });

        Schema::table('hr_announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inbox_announcement_id');
            $table->dropColumn([
                'status',
                'deleted_at',
                'ack_deadline',
                'recurrence',
                'recurrence_ends_at',
                'recurrence_parent_id',
            ]);
        });
    }
};
