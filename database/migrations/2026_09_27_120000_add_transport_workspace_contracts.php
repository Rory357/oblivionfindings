<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_transport_bookings', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('fleet_booking_id')->nullable()->unique()->constrained('fleet_vehicle_bookings')->restrictOnDelete();
            $table->dateTime('expected_return_at')->nullable();
            $table->string('pickup_location')->nullable();
            $table->unsignedSmallInteger('required_seats')->nullable();
            $table->boolean('wheelchair_required')->nullable();
            $table->string('workflow_state', 32)->default('assessment')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('assessed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('assessed_at')->nullable();
            $table->foreignId('escort_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('equipment_required')->nullable();
            $table->text('operational_notes')->nullable();
            $table->text('information_required')->nullable();
            $table->foreignId('key_pickup_room_id')->nullable()->constrained('site_rooms')->restrictOnDelete();
            $table->foreignId('key_return_room_id')->nullable()->constrained('site_rooms')->restrictOnDelete();
            $table->string('key_delivery_arrangement')->nullable();
            $table->uuid('creation_key')->nullable()->unique();
            $table->string('creation_fingerprint', 64)->nullable();
        });
        Schema::table('fleet_resident_transports', function (Blueprint $table): void {
            $table->foreignId('transport_request_id')->nullable()->unique()->constrained('client_transport_bookings')->restrictOnDelete();
            $table->dateTime('passengers_accounted_at')->nullable();
            $table->foreignId('passengers_accounted_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('transport_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transport_request_id')->constrained('client_transport_bookings')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->uuid('command_key');
            $table->string('fingerprint', 64);
            $table->unsignedInteger('version');
            $table->json('details');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'command_key']);
        });
        Schema::table('fleet_key_logs', function (Blueprint $table): void {
            $table->foreignId('site_room_id')->nullable()->constrained('site_rooms')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('fleet_shift_handovers', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->constrained('fleet_vehicle_bookings')->restrictOnDelete();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Operational links and immutable observations must survive an application rollback.
        // Removal requires a separately reviewed evidence-preserving data migration.
        throw new LogicException('Transport workspace evidence is retained. Roll back application code without dropping these additive columns.');
    }
};
