<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_calendar_event_links', function (Blueprint $table) {
            $table->id();
            // Retain ownership after source deletion so the remote event can be removed.
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('shift_id')->index();
            $table->string('provider', 20);
            $table->string('mailbox', 254);
            $table->uuid('operation_id')->unique();
            $table->string('external_id', 512)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('ends_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'user_id', 'shift_id', 'mailbox'], 'work_calendar_owner_shift_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_calendar_event_links');
    }
};
