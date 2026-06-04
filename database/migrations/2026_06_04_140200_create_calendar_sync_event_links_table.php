<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push idempotency: maps a local SiteCalendarEvent (and, for recurring series, a
 * specific occurrence) to the external event id created in the resource calendar,
 * so repeated saves/drag-resizes update the same external event instead of
 * duplicating it, and deletes can target the right external event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_sync_event_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // google | microsoft
            $table->foreignId('site_calendar_event_id')->constrained()->cascadeOnDelete();
            // '' for a non-recurring event; the occurrence date (Y-m-d) for a series
            // occurrence. Defaults to '' (not null) so the unique index matches on
            // upsert — MySQL treats NULLs as distinct.
            $table->string('occurrence_key')->default('');
            $table->string('external_event_id');
            $table->dateTime('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_calendar_event_id', 'provider', 'occurrence_key'], 'cal_sync_link_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_sync_event_links');
    }
};
