<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_medication_transit_logs')) {
            return;
        }
        Schema::create('fleet_medication_transit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('transport_id')->nullable();
            $table->unsignedBigInteger('outing_id')->nullable();
            $table->foreignId('client_id')->constrained('clients');
            $table->unsignedBigInteger('medication_id')->nullable(); // FK to client_medications
            $table->string('medication_name'); // denormalized
            $table->boolean('is_controlled_drug')->default(false);
            $table->string('packed_witness_name')->nullable();
            $table->foreignId('packed_by_user_id')->constrained('users');
            $table->timestamp('packed_at');
            $table->timestamp('administered_at')->nullable();
            $table->foreignId('administered_by_user_id')->nullable()->constrained('users');
            $table->foreignId('witnessed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('returned_to_house_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('client_id');
            $table->index('transport_id');
            $table->index('packed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_medication_transit_logs');
    }
};
