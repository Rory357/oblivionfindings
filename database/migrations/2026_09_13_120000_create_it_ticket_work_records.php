<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_ticket_work_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('it_tickets')->cascadeOnDelete();
            $table->longText('details')->nullable(); // encrypted contact, diagnosis and follow-up context
            $table->timestamps();
        });
        Schema::create('it_ticket_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('technician_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->uuid('group_uuid');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('requested');
            $table->longText('details')->nullable();
            $table->timestamps();
            $table->index(['technician_user_id', 'status', 'starts_at'], 'it_booking_availability_idx');
        });
        Schema::create('it_ticket_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('it_ticket_comments')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('it_ticket_bookings')->restrictOnDelete();
            $table->foreignId('technician_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('minutes');
            $table->string('work_type', 20);
            $table->boolean('after_hours');
            $table->unsignedInteger('hourly_rate_cents')->nullable();
            $table->string('approval_status', 20)->default('not_required');
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['technician_user_id', 'starts_at'], 'it_time_overlap_idx');
        });
        Schema::create('it_ticket_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->date('incurred_on');
            $table->unsignedInteger('quantity_hundredths');
            $table->unsignedInteger('unit_cost_cents');
            $table->unsignedBigInteger('total_cents');
            $table->string('approval_status', 20)->default('not_required');
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->longText('details');
            $table->timestamps();
        });
        Schema::create('it_ticket_work_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('record_type', 30);
            $table->unsignedBigInteger('record_id');
            $table->string('action', 40);
            $table->longText('evidence'); // encrypted previous/new values and required reason
            $table->timestamps();
            $table->index(['ticket_id', 'record_type', 'record_id'], 'it_work_revision_record_idx');
        });
    }

    public function down(): void
    {
        foreach (['it_ticket_work_revisions', 'it_ticket_costs', 'it_ticket_time_entries', 'it_ticket_bookings', 'it_ticket_work_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
