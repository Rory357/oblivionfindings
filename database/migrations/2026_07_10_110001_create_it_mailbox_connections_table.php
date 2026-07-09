<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E3 — org-level OAuth connection for the IT support mailbox (email-to-ticket).
 *
 * One row per (tenant, provider), mirroring calendar_sync_connections: an
 * Exchange/Gmail account whose delegated token can READ the dedicated
 * support@… mailbox. account_email is who consented; mailbox_email is the
 * mailbox the poller reads (defaults to the account itself). Distinct from the
 * calendar connection — mail scopes are consented separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_mailbox_connections', function (Blueprint $table) {
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
            $table->string('mailbox_email')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_mailbox_connections');
    }
};
