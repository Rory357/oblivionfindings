<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Site Type (the core differentiator)
            if (!Schema::hasColumn('sites', 'type')) {
                $table->enum('type', ['head_office', 'house', 'facility'])
                    ->default('house')
                    ->after('name')
                    ->index();
            }

            // Multi-tenant readiness (optional, behind feature flag)
            if (!Schema::hasColumn('sites', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->after('id')->index();
            }

            // Geographic/Operational grouping
            if (!Schema::hasColumn('sites', 'region')) {
                $table->string('region')->nullable()->after('country')->index();
            }

            // GPS coordinates (optional pin on map)
            if (!Schema::hasColumn('sites', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable()->after('region');
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }

            // Access instructions (permission-protected)
            if (!Schema::hasColumn('sites', 'access_instructions')) {
                $table->text('access_instructions')->nullable()->after('longitude');
            }

            // Risk Flags
            if (!Schema::hasColumn('sites', 'is_high_risk')) {
                $table->boolean('is_high_risk')->default(false)->after('access_instructions');
                $table->boolean('is_high_needs')->default(false)->after('is_high_risk');
                $table->text('risk_notes')->nullable()->after('is_high_needs');
                $table->date('risk_review_date')->nullable()->after('risk_notes');
            }

            // Soft deletes for archive
            if (!Schema::hasColumn('sites', 'deleted_at')) {
                $table->softDeletes();
            }

            // Onboarding tracking
            if (!Schema::hasColumn('sites', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable();
                $table->json('onboarding_progress')->nullable();
            }

            // Primary contacts (denormalized for quick access)
            if (!Schema::hasColumn('sites', 'primary_contact_user_id')) {
                $table->foreignId('primary_contact_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        // Composite indexes for common queries
        Schema::table('sites', function (Blueprint $table) {
            $table->index(['type', 'region', 'is_active']);
            $table->index(['is_high_risk', 'is_high_needs']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $columns = [
                'type', 'tenant_id', 'region', 'latitude', 'longitude',
                'access_instructions', 'is_high_risk', 'is_high_needs',
                'risk_notes', 'risk_review_date', 'deleted_at',
                'onboarding_completed_at', 'onboarding_progress',
                'primary_contact_user_id',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
