<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bill_approval_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_id')->constrained('fin_bills')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('snapshot', 64);
            $table->foreignId('journal_id')->constrained('fin_journals')->restrictOnDelete();
            $table->json('result');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['bill_id', 'actor_id', 'snapshot'], 'fin_bill_receipt_identity');
        });
        Schema::create('fin_bill_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_id')->constrained('fin_bills')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('name');
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('path');
            $table->string('state', 32)->default('reserved');
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->unique(['bill_id', 'request_key'], 'fin_bill_document_request');
        });
        Schema::table('fleet_finance_review_requests', function (Blueprint $table): void {
            $table->unsignedSmallInteger('expected_file_count')->default(0);
            $table->timestamp('evidence_ready_at')->nullable();
            $table->json('decision_evidence')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('response_note')->nullable();
            $table->unsignedInteger('submission_count')->default(1);
            $table->index(['status', 'due_on'], 'fleet_fin_reviews_due');
        });
        Schema::table('fleet_finance_review_request_events', function (Blueprint $table): void {
            $table->json('evidence')->nullable();
        });
        Schema::create('fleet_finance_review_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_request_id')->constrained('fleet_finance_review_requests', 'id', 'fin_review_notice_request')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('dedupe_key', 160)->unique();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestamps();
            $table->index(['delivered_at', 'available_at'], 'fin_review_notice_pending');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Finance approval receipts, evidence and review history are retained. Use an audited forward migration.');
    }
};
