<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'securityDevices.accessControl.view' => 'View physical access credentials, schedules, and history',
        'securityDevices.accessControl.manage' => 'Issue and revoke physical access credentials and manage schedules',
    ];

    /** @var array<string, list<string>> */
    private const ROLE_GRANTS = [
        'admin' => ['securityDevices.accessControl.view', 'securityDevices.accessControl.manage'],
        'it_manager' => ['securityDevices.accessControl.view', 'securityDevices.accessControl.manage'],
        'facilities_manager' => ['securityDevices.accessControl.view', 'securityDevices.accessControl.manage'],
        'provider_manager' => ['securityDevices.accessControl.view'],
        'auditor' => ['securityDevices.accessControl.view'],
    ];

    public function up(): void
    {
        Schema::create('access_control_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('timezone', 64)->default('Pacific/Auckland');
            $table->json('days');
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'name'], 'access_schedules_site_name_unique');
            $table->index(['site_id', 'is_active'], 'access_schedules_site_active_idx');
        });

        Schema::create('access_control_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('access_schedule_id')->constrained('access_control_schedules')->restrictOnDelete();
            $table->string('label', 120);
            $table->string('holder_type', 20);
            $table->unsignedBigInteger('holder_id');
            // Safe provider alias/fingerprint only. Card numbers, PINs and secret material never belong here.
            $table->string('reference_key', 191);
            $table->string('status', 20)->default('active');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'reference_key'], 'access_credentials_site_reference_unique');
            $table->index(['site_id', 'status'], 'access_credentials_site_status_idx');
            $table->index(['holder_type', 'holder_id', 'status'], 'access_credentials_holder_status_idx');
        });

        Schema::create('access_control_credential_device', function (Blueprint $table): void {
            $table->foreignId('access_credential_id')->constrained('access_control_credentials')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['access_credential_id', 'device_id'], 'access_credential_device_pk');
            $table->index('device_id', 'access_credential_device_device_idx');
        });

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::PERMISSIONS as $key => $description) {
                DB::table('permissions')->insertOrIgnore([
                    'key' => $key,
                    'description' => $description,
                    'group' => 'security_devices',
                    'module' => 'Security & Devices',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id', 'key');
            $roleIds = DB::table('roles')->whereIn('name', array_keys(self::ROLE_GRANTS))->pluck('id', 'name');
            foreach (self::ROLE_GRANTS as $role => $keys) {
                foreach ($keys as $key) {
                    $roleId = $roleIds[$role] ?? null;
                    $permissionId = $permissionIds[$key] ?? null;
                    if ($roleId !== null && $permissionId !== null) {
                        DB::table('role_permission')->insertOrIgnore([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
            if (Schema::hasTable('role_permission')) {
                DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('access_control_credential_device');
        Schema::dropIfExists('access_control_credentials');
        Schema::dropIfExists('access_control_schedules');
    }
};
