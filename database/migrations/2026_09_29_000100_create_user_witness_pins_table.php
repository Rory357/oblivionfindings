<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal 6-digit witness PIN (eMAR P00 v5, Stephan's option B, 29 Sep 2026).
 *
 * A second person confirms a controlled-drug witness, a restricted-competency
 * co-sign, a verbal-order read-back or a verification waiver by typing this
 * PIN, never their login password. Only a hash is stored; nobody can read or
 * set another person's PIN. Wrong attempts are counted per owner across every
 * screen and lock the PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_witness_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pin_hash');
            $table->timestamp('set_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            // An administrator reset: the owner must choose a new PIN before
            // they can witness or co-sign again. The old hash stays unusable.
            $table->boolean('must_change')->default(false);
            $table->foreignId('reset_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_witness_pins');
    }
};
