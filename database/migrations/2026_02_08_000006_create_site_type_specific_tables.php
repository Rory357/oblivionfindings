<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_house_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->date('assigned_from')->nullable();
            $table->date('assigned_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['site_id', 'is_active']);
            $table->unique(['site_id', 'name']);
        });

        Schema::create('site_house_room_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('site_house_rooms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->date('assigned_from');
            $table->date('assigned_until')->nullable();
            $table->foreignId('assigned_by_user_id')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('site_ho_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->enum('resource_type', ['boardroom', 'training_room', 'meeting_room', 'other']);
            $table->integer('capacity')->nullable();
            $table->text('amenities')->nullable();
            $table->string('calendar_email')->nullable();
            $table->string('calendar_sync_token')->nullable();
            $table->boolean('is_bookable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'resource_type', 'is_active']);
        });

        Schema::create('site_ho_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->text('visitor_sign_in_process')->nullable();
            $table->text('after_hours_procedures')->nullable();
            $table->json('it_network_details')->nullable();
            $table->timestamps();
        });

        Schema::create('site_facility_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('zone_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'zone_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_facility_zones');
        Schema::dropIfExists('site_ho_settings');
        Schema::dropIfExists('site_ho_resources');
        Schema::dropIfExists('site_house_room_history');
        Schema::dropIfExists('site_house_rooms');
    }
};
