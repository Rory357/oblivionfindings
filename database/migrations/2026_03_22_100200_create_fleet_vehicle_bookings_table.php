<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose');
            $table->text('destination')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('odometer_out', 10, 1)->nullable();
            $table->decimal('odometer_in', 10, 1)->nullable();
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('return_notes')->nullable();
            $table->string('condition_on_return')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicle_bookings');
    }
};
