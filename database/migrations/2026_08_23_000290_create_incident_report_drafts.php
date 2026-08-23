<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_report_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 24);
            $table->string('entry_context', 32);
            $table->longText('encrypted_payload');
            $table->char('payload_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('saved_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at'], 'incident_report_drafts_owner_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report_drafts');
    }
};
