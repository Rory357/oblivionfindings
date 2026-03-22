<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_document_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('document_id')->constrained('hr_documents')->cascadeOnDelete();
            $table->foreignId('signer_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('signature_data')->nullable(); // base64 SVG/PNG
            $table->datetime('signed_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('status')->default('pending'); // pending, signed, declined
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->datetime('requested_at');
            $table->text('declined_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['document_id', 'signer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_document_signatures');
    }
};
