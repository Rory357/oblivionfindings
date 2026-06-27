<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurrence support for HR calendar events. A base event carries an `rrule`
     * (RFC-5545 subset: FREQ=DAILY|WEEKLY|MONTHLY, INTERVAL, UNTIL/COUNT) and the
     * aggregator expands occurrences within the requested range. Single-occurrence
     * overrides + "this & following" splits are stored as child rows
     * (recurrence_parent_id + is_exception).
     */
    public function up(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->string('rrule')->nullable()->after('is_all_day');
            $table->dateTime('recurrence_until')->nullable()->after('rrule');
            $table->foreignId('recurrence_parent_id')
                ->nullable()
                ->after('recurrence_until')
                ->constrained('hr_calendar_events')
                ->nullOnDelete();
            $table->boolean('is_exception')->default(false)->after('recurrence_parent_id');
            // Original occurrence date an exception/override stands in for.
            $table->date('exception_date')->nullable()->after('is_exception');
        });
    }

    public function down(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_parent_id');
            $table->dropColumn(['rrule', 'recurrence_until', 'is_exception', 'exception_date']);
        });
    }
};
