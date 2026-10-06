<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Null follows the organisation default. Leave the legacy chart field intact.
            $table->unsignedTinyInteger('medication_review_interval_months')->nullable();
        });
        Schema::table('medication_reviews', function (Blueprint $table): void {
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('booking_request_uuid')->nullable()->unique();
            $table->string('completed_time', 5)->nullable();
            $table->timestamp('happened_at')->nullable();
            $table->string('reviewer_registration_number', 100)->nullable();
            $table->string('trigger_code', 30)->nullable();
            $table->date('appointment_date')->nullable();
            $table->string('appointment_time', 5)->nullable();
            $table->string('appointment_location', 255)->nullable();
            $table->string('clinician_practice', 255)->nullable();
            $table->string('review_location', 30)->nullable();
            $table->json('participants')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_mime', 100)->nullable();
            $table->unsignedInteger('source_size')->nullable();
            $table->index(['client_id', 'status', 'review_type', 'scheduled_date'], 'med_review_open_dates');
        });
        Schema::create('medication_review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('medication_reviews')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->restrictOnDelete();
            $table->foreignId('order_version_id')->nullable()->constrained('medication_order_versions')->restrictOnDelete();
            $table->string('name_snapshot');
            $table->boolean('controlled_snapshot')->default(false);
            $table->boolean('classification_pending')->default(false);
            $table->string('outcome', 30);
            $table->text('recommendation')->nullable();
            $table->text('watch_text')->nullable();
            $table->date('watch_until')->nullable();
            $table->string('decision', 20)->default('waiting');
            $table->string('prescriber_name')->nullable();
            $table->date('decision_date')->nullable();
            $table->string('decision_time', 5)->nullable();
            $table->timestamp('decision_at')->nullable();
            $table->string('decision_method', 20)->nullable();
            $table->text('decision_note')->nullable();
            $table->string('decision_source_path')->nullable();
            $table->string('decision_source_name')->nullable();
            $table->string('decision_source_mime', 100)->nullable();
            $table->unsignedInteger('decision_source_size')->nullable();
            $table->unsignedBigInteger('followup_id')->nullable()->index();
            $table->foreignId('linked_order_version_id')->nullable()->constrained('medication_order_versions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['review_id', 'client_medication_id']);
            $table->index(['client_id', 'outcome', 'decision']);
        });
        Schema::create('medication_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->nullable()->constrained('medication_reviews')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('details');
            $table->timestamp('created_at');
            $table->index(['review_id', 'id']);
        });
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            DB::transaction(function (): void {
                $created = DB::table('permissions')->insertOrIgnore([
                    'key' => 'medications.reviews.manage',
                    'description' => 'Book and record medication reviews and prescriber recommendations',
                    'group' => 'medications', 'module' => 'Clinical',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // Respect a deliberately revoked grant on a re-run.
                if ($created === 0) {
                    return;
                }
                $id = DB::table('permissions')->where('key', 'medications.reviews.manage')->value('id');
                foreach (DB::table('roles')->whereIn('name', ['admin', 'team_lead', 'clinical_lead', 'coordinator', 'provider_manager'])->pluck('id') as $roleId) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
                }
            });
        }
    }

    public function down(): void
    {
        // Review evidence is clinical history: a rollback must not silently erase it.
        if (DB::table('medication_review_events')->exists() || DB::table('medication_review_items')->exists()) {
            throw new RuntimeException('Preserve medication review evidence before reverting this migration.');
        }
        Schema::dropIfExists('medication_review_events');
        Schema::dropIfExists('medication_review_items');
        Schema::table('medication_reviews', function (Blueprint $table): void {
            $table->dropIndex('med_review_open_dates');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['revision', 'booking_request_uuid', 'completed_time', 'happened_at', 'reviewer_registration_number', 'trigger_code', 'appointment_date', 'appointment_time', 'appointment_location', 'clinician_practice', 'review_location', 'participants', 'source_path', 'source_name', 'source_mime', 'source_size']);
        });
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('medication_review_interval_months'));
    }
};
