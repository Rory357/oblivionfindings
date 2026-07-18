<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_major_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('ticket_id')->unique()->constrained('it_tickets')->cascadeOnDelete();
            $table->string('severity', 16)->default('sev2');
            $table->text('impact_summary')->nullable();
            $table->foreignId('commander_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('communications_lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('target_update_minutes')->default(30);
            $table->timestamp('declared_at');
            $table->timestamp('next_update_due_at')->nullable();
            $table->text('restoration_summary')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->longText('root_cause_summary')->nullable();
            $table->longText('review_summary')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'severity', 'next_update_due_at'], 'it_major_incidents_cadence_idx');
        });

        Schema::create('it_major_incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('major_incident_id')->constrained('it_major_incidents')->cascadeOnDelete();
            $table->string('update_kind', 48);
            $table->string('audience', 24);
            $table->longText('summary');
            $table->string('service_status', 48)->nullable();
            $table->timestamp('published_at');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'major_incident_id', 'published_at'], 'it_major_incident_updates_timeline_idx');
            $table->index(['tenant_id', 'audience', 'published_at'], 'it_major_incident_updates_audience_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_major_incident_updates');
        Schema::dropIfExists('it_major_incidents');
    }
};
