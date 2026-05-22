<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_purchase_requests')) {
            Schema::create('client_purchase_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('description');
                $table->decimal('amount', 10, 2);
                $table->string('category')->nullable();
                $table->string('status')->default('requested')->index();
                $table->timestamp('requested_at')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['client_id', 'status']);
                $table->index(['client_id', 'requested_at']);
            });
        }

        if (! Schema::hasTable('client_financial_discrepancies')) {
            Schema::create('client_financial_discrepancies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('description');
                $table->decimal('amount', 10, 2);
                $table->string('status')->default('open')->index();
                $table->timestamp('raised_at')->nullable();
                $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['client_id', 'status']);
                $table->index(['client_id', 'raised_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_financial_discrepancies');
        Schema::dropIfExists('client_purchase_requests');
    }
};
