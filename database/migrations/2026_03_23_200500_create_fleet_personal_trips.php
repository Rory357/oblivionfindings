<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_personal_trips')) {
            return;
        }
        Schema::create('fleet_personal_trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->date('date');
            $table->string('start_location');
            $table->string('end_location');
            $table->decimal('distance_km', 8, 1);
            $table->string('purpose');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->decimal('rate_per_km', 5, 2)->default(0.95); // NZ IRD rate
            $table->decimal('total_amount', 8, 2);
            $table->string('status')->default('pending'); // pending, approved, rejected, paid
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_personal_trips');
    }
};
