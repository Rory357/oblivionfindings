<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * PR O: Retire the dual-key permission fallback introduced in PR B.
 *
 * During the UniFi migration (PR B), provider pages accepted either
 * `securityDevices.integrations.manage` OR the legacy
 * `integrations.manage_tenant_secrets` so existing operators kept access.
 *
 * This migration grants the new permission to every role that currently
 * has the legacy one but is missing the new one, so removing the
 * fallback in controllers / route middleware does not strip access from
 * anyone on upgrade.
 *
 * Idempotent via `syncWithoutDetaching`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = Permission::where('key', 'integrations.manage_tenant_secrets')->first();
        $modern = Permission::where('key', 'securityDevices.integrations.manage')->first();

        if (! $legacy || ! $modern) {
            // Nothing to reconcile; both permissions must exist for the
            // mapping to be meaningful.
            return;
        }

        $rolesWithLegacy = $legacy->roles()->pluck('roles.id')->all();
        if (empty($rolesWithLegacy)) {
            return;
        }

        foreach ($rolesWithLegacy as $roleId) {
            $role = Role::find($roleId);
            if (! $role) {
                continue;
            }
            $role->permissions()->syncWithoutDetaching([$modern->id]);
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: leaving the modern permission
        // granted is safe if we ever re-run the fallback removal.
        // Revoking would risk locking operators out mid-release.
    }
};
