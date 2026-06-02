<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                if (! Schema::hasColumn('clients', 'suppress_med_admin_alerts')) {
                    $table->boolean('suppress_med_admin_alerts')->default(false)->after('seizure_duration_escalation_seconds');
                }

                if (! Schema::hasColumn('clients', 'med_alerts_suppressed_reason')) {
                    $table->string('med_alerts_suppressed_reason', 500)->nullable()->after('suppress_med_admin_alerts');
                }

                if (! Schema::hasColumn('clients', 'med_alerts_suppressed_by')) {
                    $table->foreignId('med_alerts_suppressed_by')->nullable()->after('med_alerts_suppressed_reason')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('clients', 'med_alerts_suppressed_at')) {
                    $table->timestamp('med_alerts_suppressed_at')->nullable()->after('med_alerts_suppressed_by');
                }

                if (! Schema::hasColumn('clients', 'chart_review_interval_months')) {
                    $table->unsignedTinyInteger('chart_review_interval_months')->default(3)->after('med_alerts_suppressed_at');
                }

                if (! Schema::hasColumn('clients', 'next_chart_review_date')) {
                    $table->date('next_chart_review_date')->nullable()->after('chart_review_interval_months');
                }

                if (! Schema::hasColumn('clients', 'care_level')) {
                    $table->string('care_level', 60)->nullable()->after('funding_type');
                }
            });
        }

        if (Schema::hasTable('client_medications')) {
            Schema::table('client_medications', function (Blueprint $table) {
                if (! Schema::hasColumn('client_medications', 'pharmac_therapeutic_group')) {
                    $table->string('pharmac_therapeutic_group')->nullable()->after('pharmacy');
                }

                if (! Schema::hasColumn('client_medications', 'pharmac_subgroup')) {
                    $table->string('pharmac_subgroup')->nullable()->after('pharmac_therapeutic_group');
                }
            });
        }

        if (! Schema::hasTable('client_medication_alerts')) {
            Schema::create('client_medication_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('type', 80);
                $table->string('title');
                $table->text('detail')->nullable();
                $table->boolean('prompt_on_open')->default(false);
                $table->boolean('enabled')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['client_id', 'enabled', 'resolved_at'], 'cmal_client_enabled_idx');
                $table->index(['type', 'enabled'], 'cmal_type_enabled_idx');
            });
        }

        if (! Schema::hasTable('client_inr_records')) {
            Schema::create('client_inr_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
                $table->decimal('inr_value', 3, 1);
                $table->decimal('target_range_low', 3, 1)->nullable();
                $table->decimal('target_range_high', 3, 1)->nullable();
                $table->decimal('dose_mg', 6, 2)->nullable();
                $table->date('tested_on');
                $table->date('next_test_date')->nullable();
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('disabled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['client_id', 'tested_on'], 'inr_client_tested_idx');
                $table->index('next_test_date', 'inr_next_test_idx');
            });
        }

        if (! Schema::hasTable('medication_syringe_drivers')) {
            Schema::create('medication_syringe_drivers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 40)->default('running');
                $table->timestamp('commenced_at');
                $table->foreignId('commenced_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('witnessed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('witnessed_at')->nullable();
                $table->string('witness_method', 40)->nullable();
                $table->string('rate', 80)->nullable();
                $table->string('rate_unit', 40)->nullable();
                $table->decimal('duration_hours', 5, 2)->nullable();
                $table->json('contents');
                $table->string('site_of_insertion')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['client_id', 'status'], 'syringe_driver_client_status_idx');
                $table->index('commenced_at', 'syringe_driver_commenced_idx');
            });
        }

        if (! Schema::hasTable('medication_syringe_driver_checks')) {
            Schema::create('medication_syringe_driver_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('medication_syringe_driver_id');
                $table->timestamp('checked_at');
                $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
                $table->boolean('infusion_running')->default(true);
                $table->string('site_condition')->nullable();
                $table->string('volume_remaining')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table
                    ->foreign('medication_syringe_driver_id', 'msdc_driver_fk')
                    ->references('id')
                    ->on('medication_syringe_drivers')
                    ->cascadeOnDelete();
                $table->index(['medication_syringe_driver_id', 'checked_at'], 'syringe_check_driver_time_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_syringe_driver_checks');
        Schema::dropIfExists('medication_syringe_drivers');
        Schema::dropIfExists('client_inr_records');
        Schema::dropIfExists('client_medication_alerts');

        if (Schema::hasTable('client_medications')) {
            Schema::table('client_medications', function (Blueprint $table) {
                foreach (['pharmac_subgroup', 'pharmac_therapeutic_group'] as $column) {
                    if (Schema::hasColumn('client_medications', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                if (Schema::hasColumn('clients', 'med_alerts_suppressed_by')) {
                    $table->dropConstrainedForeignId('med_alerts_suppressed_by');
                }

                foreach ([
                    'care_level',
                    'next_chart_review_date',
                    'chart_review_interval_months',
                    'med_alerts_suppressed_at',
                    'med_alerts_suppressed_reason',
                    'suppress_med_admin_alerts',
                ] as $column) {
                    if (Schema::hasColumn('clients', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
