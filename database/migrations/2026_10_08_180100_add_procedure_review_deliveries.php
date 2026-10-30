<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_review_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('procedure_review_request_id')->constrained('procedure_review_requests', indexName: 'swp_delivery_request_fk')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users', indexName: 'swp_delivery_recipient_fk')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users', indexName: 'swp_delivery_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('assignment_revision');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['procedure_review_request_id', 'assignment_revision'], 'swp_delivery_assignment_unique');
            $table->index(['status', 'next_attempt_at'], 'swp_delivery_recovery_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('procedure_review_deliveries')->exists()) {
            throw new RuntimeException('Preserve procedure review delivery evidence before rollback.');
        }
        Schema::dropIfExists('procedure_review_deliveries');
    }
};
