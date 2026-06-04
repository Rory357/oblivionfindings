<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org-level OAuth connection for the admin calendar-sync feature (Part D).
 *
 * One row per (tenant, provider) — a Google Workspace or Microsoft 365 service/admin
 * connection whose token can read/write the org's *resource* calendars (Google
 * resource calendars / Outlook room-resource mailboxes). Distinct from the per-user
 * `calendar_syncs` (personal "add to my calendar") and from the device-oriented
 * `integration_tenant_secrets` — calendar sync is its own concern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_sync_connections', function (Blueprint $table) {
            $table->id();
            // Coalesced to 0 for single-tenant installs so the unique index behaves
            // (MySQL treats NULLs as distinct, which would allow duplicate rows).
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->string('provider'); // google | microsoft
            $table->string('status')->default('disconnected'); // connected | disconnected | error
            $table->text('access_token')->nullable();  // encrypted cast
            $table->text('refresh_token')->nullable(); // encrypted cast
            $table->dateTime('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('account_email')->nullable();
            $table->string('account_name')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_sync_connections');
    }
};
