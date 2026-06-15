<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access-scope activity log: what a break-glass user did during an active grant
 * window (e.g. viewed a MAR chart, recorded a dose), keyed to the grant and
 * surfaced in the post-event review modal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_access_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('break_glass_access_id')->index();
            $table->string('action', 40);
            $table->string('detail', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_access_events');
    }
};
