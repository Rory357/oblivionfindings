<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_remote_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_remote_session_id')->unique()->constrained('endpoint_remote_sessions')->cascadeOnDelete();
            $table->uuid('offer_uuid');
            $table->uuid('viewer_uuid')->nullable();
            $table->text('offer_sdp');
            $table->text('answer_sdp')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_remote_signals');
    }
};
