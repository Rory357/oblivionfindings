<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safety fix round (Stephan, 7 Nov 2026: "Open just that alert"). Support workers and team leads
 * are pushed care and safety alerts but couldn't open them. This narrow key lets them open and
 * attend to ONLY the alerts sent to them, at their own Sites, and reach their own house's Overview
 * (pg-sdr-1c). It doesn't open the Alerts page, snooze, assign or any other Security & devices
 * page. Deploys skip seeders, so the key is created and granted here.
 */
return new class extends Migration
{
    private const KEY = 'securityDevices.careAlerts.attend';

    private const ROLES = ['support_worker', 'team_lead'];

    public function up(): void
    {
        $now = now();
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => self::KEY,
                'description' => 'Open and attend to the care and safety alerts sent to you, at your own Sites (no snooze, no other alerts)',
                'group' => 'security_devices',
                'module' => 'Security & Devices',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $roleIds = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');
        $already = DB::table('role_permission')->where('permission_id', $permissionId)->pluck('role_id');
        foreach ($roleIds->diff($already) as $roleId) {
            DB::table('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: removing access mid-release is an administrative decision.
    }
};
