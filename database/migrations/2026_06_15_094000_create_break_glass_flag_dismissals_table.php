<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewer acknowledgements of derived break-glass misuse signals. A signal is
 * suppressed while a dismissal exists whose `dismissed_through` is at/after the
 * signal's latest activity — so it re-surfaces automatically when newer activity
 * appears. One row per (organisation, signal_type, signal_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_flag_dismissals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('signal_type', 40);
            $table->string('signal_key', 100);
            $table->unsignedBigInteger('dismissed_by')->index();
            $table->string('reason', 500)->nullable();
            $table->timestamp('dismissed_through');
            $table->timestamps();
            // Short custom name — the default would exceed MySQL's 64-char limit.
            $table->unique(['organization_id', 'signal_type', 'signal_key'], 'bg_flag_dismissal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_flag_dismissals');
    }
};
