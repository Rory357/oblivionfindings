<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_coverage_requirements', function (Blueprint $table) {
            if (! Schema::hasColumn('site_coverage_requirements', 'role_requirements')) {
                $table->json('role_requirements')->nullable()->after('minimum_staff');
            }

            if (! Schema::hasColumn('site_coverage_requirements', 'allow_overstaffing')) {
                $table->boolean('allow_overstaffing')->default(true)->after('role_requirements');
            }

            if (! Schema::hasColumn('site_coverage_requirements', 'preferred_client_id')) {
                $table->foreignId('preferred_client_id')
                    ->nullable()
                    ->after('service_context_id')
                    ->constrained('clients')
                    ->nullOnDelete();
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'coverage_roles')) {
                $table->json('coverage_roles')->nullable()->after('expected_break_minutes');
            }
        });

        Schema::table('shift_series', function (Blueprint $table) {
            if (! Schema::hasColumn('shift_series', 'coverage_roles')) {
                $table->json('coverage_roles')->nullable()->after('expected_break_minutes');
            }
        });

        Schema::table('shift_open_positions', function (Blueprint $table) {
            if (! Schema::hasColumn('shift_open_positions', 'coverage_roles')) {
                $table->json('coverage_roles')->nullable()->after('required_skills');
            }
        });

        Schema::create('coverage_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('coverage_requirement_id')->nullable()->constrained('site_coverage_requirements')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('shift_open_position_id')->nullable()->constrained('shift_open_positions')->cascadeOnDelete();
            $table->foreignId('reserved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reservation_token', 80)->unique();
            $table->string('status', 20)->default('active');
            $table->string('reason', 40)->default('quick_fill');
            $table->string('role_key', 50)->nullable();
            $table->dateTime('window_starts_at');
            $table->dateTime('window_ends_at');
            $table->dateTime('expires_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'coverage_requirement_id', 'window_starts_at', 'window_ends_at'], 'coverage_res_window_idx');
            $table->index(['status', 'expires_at'], 'coverage_res_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_reservations');

        Schema::table('shift_open_positions', function (Blueprint $table) {
            if (Schema::hasColumn('shift_open_positions', 'coverage_roles')) {
                $table->dropColumn('coverage_roles');
            }
        });

        Schema::table('shift_series', function (Blueprint $table) {
            if (Schema::hasColumn('shift_series', 'coverage_roles')) {
                $table->dropColumn('coverage_roles');
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'coverage_roles')) {
                $table->dropColumn('coverage_roles');
            }
        });

        Schema::table('site_coverage_requirements', function (Blueprint $table) {
            if (Schema::hasColumn('site_coverage_requirements', 'preferred_client_id')) {
                $table->dropConstrainedForeignId('preferred_client_id');
            }

            if (Schema::hasColumn('site_coverage_requirements', 'allow_overstaffing')) {
                $table->dropColumn('allow_overstaffing');
            }

            if (Schema::hasColumn('site_coverage_requirements', 'role_requirements')) {
                $table->dropColumn('role_requirements');
            }
        });
    }
};
