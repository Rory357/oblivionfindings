<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P-S4 (stretch) — email-to-ticket ingestion log. Every inbound message the
 * webhook accepts is recorded here (matched to a ticket, or unmatched/rejected)
 * so ingestion is auditable. The ticket's own `source` column gains 'email';
 * body is stored as a short preview only — no full message body (privacy §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('it_ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->string('from_email');
            $table->string('subject')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->text('body_preview')->nullable();
            $table->string('status')->default('processed'); // processed | unmatched | rejected
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_inbound_emails');
    }
};
