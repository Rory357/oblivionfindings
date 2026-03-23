<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mileage_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->date('claim_date');
            $table->string('origin');
            $table->string('destination');
            $table->decimal('distance_km', 8, 2);
            $table->decimal('rate_per_km', 6, 4)->default(0.95);
            $table->decimal('amount', 8, 2);
            $table->string('purpose')->nullable();
            $table->string('status')->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamps();

            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index('claim_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mileage_claims');
    }
};
