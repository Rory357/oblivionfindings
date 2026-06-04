<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotent, additive backfill for the unified Site Calendar permissions.
 *
 * The six calendar.* permissions are declared in {@see RbacSeeder}, but deploys
 * skip seeders — so on an already-seeded server the newer
 * calendar.view / create / manage / approve / manage_recurring rows (added with
 * the unified Site Calendar) never reach the admin role. Admins then hit 403s on
 * the global calendar, its JSON items feed, the feed reset and approvals.
 *
 * Run post-deploy to backfill without re-syncing every role's permissions:
 *
 *   php artisan db:seed --class=SeedCalendarPermissionsSeeder --force
 *
 * The complete multi-role fix (team_lead, maintenance_coordinator, etc.) is to
 * re-run {@see RbacSeeder}; this seeder targets the admin role that the demo
 * signs in as, which is the usual reason the page looks empty/blocked on live.
 */
class SeedCalendarPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'calendar.viewAny' => 'View calendar',
            'calendar.view' => 'View calendars',
            'calendar.create' => 'Create calendar events',
            'calendar.approve' => 'Approve calendar events',
            'calendar.manage' => 'Edit and delete calendar events',
            'calendar.manage_recurring' => 'Manage recurring events',
        ];

        $ids = [];
        foreach ($permissions as $key => $description) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $description, 'group' => 'calendar', 'module' => 'Operations'],
            );
            $ids[] = $perm->id;
        }

        // Backfill the full set onto admin (meant to hold every permission)
        // without disturbing its other grants.
        $admin = Role::query()->where('name', 'admin')->first();
        if (! $admin) {
            $this->command?->warn('No admin role found — calendar permissions created but not assigned.');

            return;
        }

        $existing = $admin->permissions()->pluck('permissions.id')->all();
        $toAttach = array_diff($ids, $existing);
        if ($toAttach !== []) {
            $admin->permissions()->attach($toAttach);
        }

        $this->command?->info('Backfilled '.count($toAttach).' calendar permission(s) onto the admin role.');
    }
}
