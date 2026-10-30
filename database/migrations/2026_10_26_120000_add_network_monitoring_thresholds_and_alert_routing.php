<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitoring_profiles')) {
            Schema::table('monitoring_profiles', function (Blueprint $table): void {
                if (! Schema::hasColumn('monitoring_profiles', 'warning_rising_threshold')) {
                    $table->decimal('warning_rising_threshold', 14, 6)->nullable()->after('falling_threshold');
                }
                if (! Schema::hasColumn('monitoring_profiles', 'warning_falling_threshold')) {
                    $table->decimal('warning_falling_threshold', 14, 6)->nullable()->after('warning_rising_threshold');
                }
                if (! Schema::hasColumn('monitoring_profiles', 'threshold_direction')) {
                    $table->string('threshold_direction', 16)->default('above')->after('warning_falling_threshold');
                }
            });
        }

        if (! Schema::hasTable('monitoring_threshold_rules')) {
            Schema::create('monitoring_threshold_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('scope_kind', 24);
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
                $table->foreignId('monitor_id')->nullable()->constrained('monitors')->cascadeOnDelete();
                $table->string('device_domain', 48)->nullable();
                $table->string('device_category', 64)->nullable();
                $table->string('monitor_kind', 48)->nullable();
                $table->string('metric_key', 64)->nullable();
                $table->string('threshold_direction', 16)->default('above');
                $table->decimal('warning_rising_threshold', 14, 6)->nullable();
                $table->decimal('warning_falling_threshold', 14, 6)->nullable();
                $table->decimal('critical_rising_threshold', 14, 6)->nullable();
                $table->decimal('critical_falling_threshold', 14, 6)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('deactivated_at')->nullable();
                $table->foreignId('deactivated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('deactivation_reason')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'scope_kind', 'site_id'], 'mtr_active_scope_site_idx');
                $table->index(['monitor_id', 'is_active'], 'mtr_monitor_active_idx');
                $table->index(['device_id', 'is_active'], 'mtr_device_active_idx');
            });
        }

        if (! Schema::hasTable('monitoring_alert_routes')) {
            Schema::create('monitoring_alert_routes', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('scope_kind', 24);
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
                $table->foreignId('monitor_id')->nullable()->constrained('monitors')->cascadeOnDelete();
                $table->string('device_domain', 48)->default('it_infrastructure');
                $table->string('device_category', 64)->nullable();
                $table->string('monitor_kind', 48)->nullable();
                $table->boolean('warning_notify_in_app')->default(true);
                $table->boolean('warning_notify_email')->default(false);
                $table->boolean('warning_open_it_ticket')->default(false);
                $table->boolean('critical_notify_in_app')->default(true);
                $table->boolean('critical_notify_email')->default(true);
                $table->boolean('critical_open_it_ticket')->default(true);
                $table->boolean('critical_escalate_control_room')->default(false);
                $table->string('recipient_role', 64)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('deactivated_at')->nullable();
                $table->foreignId('deactivated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('deactivation_reason')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'scope_kind', 'site_id'], 'mar_active_scope_site_idx');
                $table->index(['monitor_id', 'is_active'], 'mar_monitor_active_idx');
                $table->index(['device_id', 'is_active'], 'mar_device_active_idx');
            });
        }

        if (Schema::hasTable('signal_sources') && Schema::hasTable('signal_types') && Schema::hasTable('signal_rules')) {
            $sourceId = DB::table('signal_sources')->where('slug', 'security_devices')->value('id');
            $typeId = DB::table('signal_types')->where('code', 'device_monitor_failed')->value('id');
            if ($sourceId !== null && $typeId !== null) {
                $exists = DB::table('signal_rules')
                    ->where('name', 'Security & Devices: Technical Check Failed')
                    ->exists();
                if (! $exists) {
                    $now = now();
                    DB::table('signal_rules')->insert([
                        'name' => 'Security & Devices: Technical Check Failed',
                        'signal_type_id' => $typeId,
                        'signal_type_code' => 'device_monitor_failed',
                        'signal_source_id' => $sourceId,
                        'priority' => 56,
                        'conditions' => json_encode([]),
                        'output_severity' => 'medium',
                        'output_escalation_level' => 0,
                        'output_tier' => 2,
                        'deduplicate' => true,
                        'dedup_window_minutes' => 30,
                        'suppress_in_maintenance' => true,
                        'notify_roles' => json_encode([]),
                        'notify_users' => json_encode([]),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alert_routes');
        Schema::dropIfExists('monitoring_threshold_rules');

        if (Schema::hasTable('monitoring_profiles')) {
            Schema::table('monitoring_profiles', function (Blueprint $table): void {
                foreach (['threshold_direction', 'warning_falling_threshold', 'warning_rising_threshold'] as $column) {
                    if (Schema::hasColumn('monitoring_profiles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
