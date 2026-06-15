<?php

use App\Models\ClientBreakGlassAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organisation break-glass policy. One row per org overrides the
 * ClientBreakGlassAccess constants (which remain the fallback when no row
 * exists). Only the enforceable controls are stored — duration caps, whether a
 * reason is required, and the repeat-misuse flag threshold/window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique();
            $table->unsignedSmallInteger('default_minutes')->default(ClientBreakGlassAccess::DEFAULT_MINUTES);
            $table->unsignedSmallInteger('max_minutes')->default(ClientBreakGlassAccess::MAX_MINUTES);
            $table->unsignedSmallInteger('extend_minutes')->default(ClientBreakGlassAccess::EXTEND_MINUTES);
            $table->boolean('reason_required')->default(true);
            $table->unsignedSmallInteger('repeat_threshold_count')->default(4);
            $table->unsignedSmallInteger('repeat_window_days')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_policies');
    }
};
