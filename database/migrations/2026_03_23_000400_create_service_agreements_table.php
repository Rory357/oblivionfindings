<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_agreements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('title');
            $table->string('reference_number')->nullable();
            $table->string('status')->default('draft');
            $table->string('agreement_type');
            $table->string('funding_body')->nullable();
            $table->string('funding_reference')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->decimal('total_budget', 12, 2)->default(0);
            $table->decimal('budget_used', 12, 2)->default(0);
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->decimal('daily_rate', 8, 2)->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['organization_id', 'reference_number']);
            $table->index(['client_id', 'status']);
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_agreements');
    }
};
