<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enhance client_medications table with enterprise fields
        Schema::table('client_medications', function (Blueprint $table) {
            // High-risk medication flag
            if (!Schema::hasColumn('client_medications', 'high_risk')) {
                $table->boolean('high_risk')->default(false)->after('controlled_drug');
            }
            // Witness required flag (separate from controlled drugs)
            if (!Schema::hasColumn('client_medications', 'witness_required')) {
                $table->boolean('witness_required')->default(false)->after('high_risk');
            }
            // Indication for the medication
            if (!Schema::hasColumn('client_medications', 'indication')) {
                $table->text('indication')->nullable()->after('instructions');
            }
            // Dose structure
            if (!Schema::hasColumn('client_medications', 'dose_amount')) {
                $table->decimal('dose_amount', 10, 4)->nullable()->after('dosage');
            }
            if (!Schema::hasColumn('client_medications', 'dose_unit')) {
                $table->string('dose_unit', 50)->nullable()->after('dose_amount');
            }
            // Structured frequency
            if (!Schema::hasColumn('client_medications', 'frequency_code')) {
                $table->string('frequency_code', 50)->nullable()->after('frequency');
            }
            // Version tracking for audit trail
            if (!Schema::hasColumn('client_medications', 'version')) {
                $table->integer('version')->default(1)->after('state');
            }
            if (!Schema::hasColumn('client_medications', 'superseded_by')) {
                $table->foreignId('superseded_by')->nullable()->after('version')->constrained('client_medications')->nullOnDelete();
            }
            if (!Schema::hasColumn('client_medications', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->after('superseded_by');
            }
            // Soft deletes for non-destructive edits
            if (!Schema::hasColumn('client_medications', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Create medication_order_versions table for full history
        Schema::create('medication_order_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->integer('version_number');
            
            // Snapshot of all medication fields
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->decimal('dose_amount', 10, 4)->nullable();
            $table->string('dose_unit', 50)->nullable();
            $table->string('frequency')->nullable();
            $table->string('frequency_code', 50)->nullable();
            $table->json('dose_times')->nullable();
            $table->string('route', 100)->nullable();
            $table->string('form', 100)->nullable();
            $table->text('instructions')->nullable();
            $table->text('indication')->nullable();
            $table->boolean('is_prn')->default(false);
            $table->string('prn_reason')->nullable();
            $table->string('max_per_day')->nullable();
            $table->boolean('controlled_drug')->default(false);
            $table->boolean('high_risk')->default(false);
            $table->boolean('witness_required')->default(false);
            $table->string('prescriber')->nullable();
            $table->string('pharmacy')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('ceased_at')->nullable();
            $table->string('ceased_reason')->nullable();
            $table->string('state', 20)->default('active');
            $table->timestamp('paused_at')->nullable();
            $table->boolean('active')->default(true);
            
            // Change metadata
            $table->string('change_reason')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            
            $table->timestamps();
            
            // Use short index names to avoid MySQL 64-character limit
            $table->index(['client_medication_id', 'version_number'], 'mov_client_med_id_ver_idx');
            $table->index(['client_id', 'changed_at'], 'mov_client_id_changed_idx');
        });

        // Create medication_allergies table
        Schema::create('medication_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('allergen');
            $table->string('reaction', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('severity', 50)->nullable(); // mild, moderate, severe, life_threatening
            $table->date('identified_date')->nullable();
            $table->string('identified_by')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['client_id', 'allergen'], 'mall_client_allergen_idx');
        });

        // Create medication_interactions table (reference)
        Schema::create('medication_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('medication_a', 255);
            $table->string('medication_b', 255);
            $table->string('severity', 50); // minor, moderate, major, contraindicated
            $table->text('description');
            $table->text('clinical_effects')->nullable();
            $table->text('management')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            $table->index(['medication_a', 'medication_b'], 'mint_med_a_b_idx');
            $table->index(['medication_b', 'medication_a'], 'mint_med_b_a_idx');
        });

        // Enhance controlled drug entries with more transaction types
        Schema::table('client_controlled_drug_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('client_controlled_drug_entries', 'disposal_method')) {
                $table->string('disposal_method')->nullable()->after('reason');
            }
            if (!Schema::hasColumn('client_controlled_drug_entries', 'disposal_authorisation')) {
                $table->string('disposal_authorisation')->nullable()->after('disposal_method');
            }
            if (!Schema::hasColumn('client_controlled_drug_entries', 'batch_number')) {
                $table->string('batch_number')->nullable()->after('unit');
            }
            if (!Schema::hasColumn('client_controlled_drug_entries', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('batch_number');
            }
        });

        // Create medication_scheduled_stock_counts table
        Schema::create('medication_scheduled_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->string('status', 50)->default('pending'); // pending, completed, overdue, skipped
            $table->integer('expected_quantity')->nullable();
            $table->integer('actual_quantity')->nullable();
            $table->integer('discrepancy')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('witnessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['client_medication_id', 'scheduled_date'], 'mssc_med_id_date_idx');
            $table->index(['client_id', 'status'], 'mssc_client_status_idx');
        });

        // Create medication_mar_attachments table
        Schema::create('medication_mar_attachments', function (Blueprint $table) {
            $table->id();
            // Use shorter constraint name to avoid MySQL 64-char limit
            $table->foreignId('client_medication_administration_id')->constrained('client_medication_administrations', 'id', 'mmar_cmai_fk')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients', 'id', 'mmar_client_fk')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->integer('file_size')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            
            $table->index(['client_medication_administration_id'], 'mmar_admin_id_idx');
        });

        // Enhance administrations with more clinical detail
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medication_administrations', 'late_minutes')) {
                $table->integer('late_minutes')->nullable()->after('administered_at');
            }
            if (!Schema::hasColumn('client_medication_administrations', 'early_minutes')) {
                $table->integer('early_minutes')->nullable()->after('late_minutes');
            }
            if (!Schema::hasColumn('client_medication_administrations', 'outcome')) {
                $table->string('outcome', 50)->nullable()->after('status'); // effective, ineffective, adverse_reaction
            }
            if (!Schema::hasColumn('client_medication_administrations', 'site')) {
                $table->string('site', 100)->nullable()->after('dose_given'); // injection site
            }
        });

        // Create medication_dashboard_alerts table
        Schema::create('medication_dashboard_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
            $table->string('alert_type', 100); // overdue, prn_near_limit, controlled_discrepancy, expiring, high_risk
            $table->string('severity', 50); // info, warning, critical
            $table->text('message');
            $table->string('status', 50)->default('active'); // active, acknowledged, resolved
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['client_id', 'status'], 'mda_client_status_idx');
            $table->index(['alert_type', 'severity'], 'mda_type_sev_idx');
            $table->index(['status', 'created_at'], 'mda_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_dashboard_alerts');
        Schema::dropIfExists('medication_mar_attachments');
        Schema::dropIfExists('medication_scheduled_stock_counts');
        Schema::dropIfExists('medication_interactions');
        Schema::dropIfExists('medication_allergies');
        Schema::dropIfExists('medication_order_versions');

        Schema::table('client_controlled_drug_entries', function (Blueprint $table) {
            $table->dropColumnIfExists(['disposal_method', 'disposal_authorisation', 'batch_number', 'expiry_date']);
        });

        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->dropColumnIfExists(['late_minutes', 'early_minutes', 'outcome', 'site']);
        });

        Schema::table('client_medications', function (Blueprint $table) {
            $table->dropColumnIfExists([
                'high_risk', 'witness_required', 'indication',
                'dose_amount', 'dose_unit', 'frequency_code',
                'version', 'superseded_by', 'superseded_at', 'deleted_at'
            ]);
        });
    }
};
