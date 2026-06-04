<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way sync (Part D): the external busy blocks pulled from a house's mapped
 * resource calendar (Google/Outlook), so the site calendar can surface them as a
 * read-only "external" source layer and the create dialog can count them as
 * clashes when the conflict policy is `external_busy_counts`.
 *
 * Refreshed each cadence run by {@see CalendarSyncService::pullBusy()} — rows are
 * upserted by (site, provider, external_event_id) and pruned when they vanish from
 * the source calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_sync_busy_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // google | microsoft
            $table->string('external_event_id');
            $table->string('title')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            // showAs busy/tentative/oof (Graph) or opaque (Google) → true; free → false.
            $table->boolean('is_busy')->default(true);
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'provider', 'external_event_id'], 'cal_sync_busy_unique');
            $table->index(['site_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_sync_busy_blocks');
    }
};
