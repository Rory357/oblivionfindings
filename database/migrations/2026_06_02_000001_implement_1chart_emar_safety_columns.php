<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_medication_administrations')) {
            Schema::table('client_medication_administrations', function (Blueprint $table) {
                if (! Schema::hasColumn('client_medication_administrations', 'reason_code')) {
                    $table->string('reason_code', 60)->nullable()->after('reason');
                }

                if (! Schema::hasColumn('client_medication_administrations', 'witnessed_at')) {
                    $table->timestamp('witnessed_at')->nullable()->after('witnessed_by');
                }

                if (! Schema::hasColumn('client_medication_administrations', 'witness_method')) {
                    $table->string('witness_method', 40)->nullable()->after('witnessed_at');
                }

                if (! Schema::hasColumn('client_medication_administrations', 'pulse_bpm')) {
                    $table->unsignedSmallInteger('pulse_bpm')->nullable()->after('blood_glucose_level');
                }

                if (! Schema::hasColumn('client_medication_administrations', 'blood_pressure_systolic')) {
                    $table->unsignedSmallInteger('blood_pressure_systolic')->nullable()->after('pulse_bpm');
                }

                if (! Schema::hasColumn('client_medication_administrations', 'blood_pressure_diastolic')) {
                    $table->unsignedSmallInteger('blood_pressure_diastolic')->nullable()->after('blood_pressure_systolic');
                }
            });
        }

        if (Schema::hasTable('client_medications')) {
            Schema::table('client_medications', function (Blueprint $table) {
                if (! Schema::hasColumn('client_medications', 'approval_status')) {
                    $table->string('approval_status', 40)->default('verified')->after('state');
                }

                if (! Schema::hasColumn('client_medications', 'verified_by')) {
                    $table->foreignId('verified_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('client_medications', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable()->after('verified_by');
                }

                if (! Schema::hasColumn('client_medications', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('verified_at');
                }
            });
        }

        if (! Schema::hasTable('medication_admin_rules')) {
            Schema::create('medication_admin_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->string('match_type', 60);
                $table->string('match_value', 255);
                $table->boolean('requires_countersign')->default(false);
                $table->json('required_observations')->nullable();
                $table->boolean('active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['site_id', 'active'], 'mar_rules_site_active_idx');
                $table->index(['match_type', 'match_value'], 'mar_rules_match_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_admin_rules');

        if (Schema::hasTable('client_medications')) {
            Schema::table('client_medications', function (Blueprint $table) {
                if (Schema::hasColumn('client_medications', 'verified_by')) {
                    $table->dropConstrainedForeignId('verified_by');
                }

                foreach (['rejection_reason', 'verified_at', 'approval_status'] as $column) {
                    if (Schema::hasColumn('client_medications', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('client_medication_administrations')) {
            Schema::table('client_medication_administrations', function (Blueprint $table) {
                foreach ([
                    'blood_pressure_diastolic',
                    'blood_pressure_systolic',
                    'pulse_bpm',
                    'witness_method',
                    'witnessed_at',
                    'reason_code',
                ] as $column) {
                    if (Schema::hasColumn('client_medication_administrations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
