<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peer-recognition interaction tables for the My HR "Shout-out" surface:
 *   - hr_kudos_reactions: one emoji reaction per (kudos, user, emoji) — toggled
 *     on/off; drives the reactor facepile + "You, X + N more reacted" summary.
 *   - hr_kudos_replies: the two-way reply thread on a kudos (giver ↔ receiver),
 *     so "Say thanks" closes the loop both ways.
 *
 * Both are additive (no changes to existing tables) and cascade-delete with the
 * parent kudos / user, so they leave nothing behind.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_kudos_reactions')) {
            Schema::create('hr_kudos_reactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('kudos_id')->constrained('hr_kudos')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('emoji', 16); // heart | party | hands
                $table->timestamps();

                // One reaction of a given emoji per person per kudos (toggle).
                $table->unique(['kudos_id', 'user_id', 'emoji']);
                $table->index(['tenant_id', 'kudos_id']);
            });
        }

        if (! Schema::hasTable('hr_kudos_replies')) {
            Schema::create('hr_kudos_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('kudos_id')->constrained('hr_kudos')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();

                $table->index(['kudos_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_kudos_replies');
        Schema::dropIfExists('hr_kudos_reactions');
    }
};
