<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P-S3 (stretch) — ticket approvals. Certain categories
 * (config('it.approval.categories')) need a manager's sign-off before an agent
 * resolves/fulfils. requires_approval flags such a ticket at creation;
 * it_ticket_approvals is the decision log — one live request at a time, kept
 * for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_ticket_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('it_ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['it_ticket_id', 'status']);
        });

        Schema::table('it_tickets', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });

        Schema::dropIfExists('it_ticket_approvals');
    }
};
