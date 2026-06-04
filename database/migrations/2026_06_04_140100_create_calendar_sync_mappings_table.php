<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-house → resource-calendar mapping for the admin calendar-sync feature.
 *
 * Each row maps one site/house to the external resource calendar it syncs with
 * (a Google resource calendar id or an Outlook room-mailbox UPN), plus the sync
 * direction, which event sources to push, and a secret per-house iCal feed token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_sync_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // google | microsoft
            $table->string('external_calendar_id')->nullable();   // resource calendar id / room mailbox UPN
            $table->string('external_calendar_name')->nullable();
            $table->string('sync_direction')->default('one_way'); // one_way | two_way
            $table->json('sources')->nullable();                  // source keys to push (null = all)
            $table->string('ical_feed_token', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_sync_mappings');
    }
};
