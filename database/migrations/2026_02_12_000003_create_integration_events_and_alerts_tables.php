<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integration_events')) {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('site_rooms')->nullOnDelete();
            $table->foreignId('hardware_id')->nullable()->constrained('location_hardware')->nullOnDelete();

            $table->string('provider');
            $table->string('source_app')->nullable();
            $table->string('source_event_id')->nullable();

            $table->dateTime('occurred_at');
            $table->dateTime('received_at');

            $table->string('severity')->default('info');
            $table->string('event_type');

            $table->json('tags')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'site_id', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
            $table->unique(
                ['tenant_id', 'provider', 'source_event_id'],
                'integration_events_tenant_provider_source_event_unique'
            );
        });
        }

        if (!Schema::hasTable('integration_alerts')) {
        Schema::create('integration_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('site_rooms')->nullOnDelete();
            $table->foreignId('hardware_id')->nullable()->constrained('location_hardware')->nullOnDelete();
            $table->foreignId('integration_event_id')->nullable()->constrained('integration_events')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('severity')->default('info');
            $table->string('status')->default('new');

            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_reason')->nullable();

            $table->unsignedBigInteger('incident_id')->nullable();

            $table->string('provider')->nullable();
            $table->string('source_event_id')->nullable();

            $table->json('tags')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['site_id', 'status']);
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_alerts');
        Schema::dropIfExists('integration_events');
    }
};
