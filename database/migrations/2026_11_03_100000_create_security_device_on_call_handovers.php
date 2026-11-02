<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D drawing pg-sdr-13d: handover notes between the people on IT on call (Alerts › On call). Kept
 * apart from care shift handovers, which belong to a person supported and carry their privacy rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_device_on_call_handovers')) {
            return;
        }
        Schema::create('security_device_on_call_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('still_open')->nullable();
            $table->text('coming_up')->nullable();
            $table->text('good_to_know')->nullable();
            $table->timestamps();
            $table->index('created_at', 'sd_on_call_handovers_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_on_call_handovers');
    }
};
