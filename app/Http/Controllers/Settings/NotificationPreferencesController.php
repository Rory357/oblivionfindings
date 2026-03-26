<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\UserNotificationPreference;
use Illuminate\Http\Request;

class NotificationPreferencesController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $groups = (array) config('notification_events.groups', []);

        $userPrefs = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->get(['key', 'enabled', 'channel_inapp', 'channel_email', 'channel_push'])
            ->keyBy('key');

        // Role defaults are *display only* on the user page.
        $roleIds = $user->roles()->pluck('roles.id');
        $rolePrefs = $roleIds->isEmpty() ? collect() : RoleNotificationPreference::query()
            ->whereIn('role_id', $roleIds)
            ->get(['role_id', 'key', 'enabled', 'channel_inapp', 'channel_email', 'channel_push']);

        return inertia('settings/notifications', [
            'groups' => $groups,
            'userPrefs' => $userPrefs->map(fn($p) => [
                'enabled' => (bool) $p->enabled,
                'inapp' => (bool) $p->channel_inapp,
                'email' => (bool) $p->channel_email,
                'push' => (bool) $p->channel_push,
            ])->all(),
            'roleDefaults' => $rolePrefs->groupBy('key')->map(function ($items) {
                // Any role enables => enabled, otherwise disabled.
                $enabled = $items->contains(fn($p) => (bool) $p->enabled === true);
                $inapp = $items->contains(fn($p) => (bool) $p->channel_inapp === true);
                $email = $items->contains(fn($p) => (bool) $p->channel_email === true);
                $push = $items->contains(fn($p) => (bool) $p->channel_push === true);
                return [
                    'enabled' => $enabled,
                    'inapp' => $inapp,
                    'email' => $email,
                    'push' => $push,
                ];
            })->all(),
            'canManageRoleDefaults' => (bool) ($user->canDo('settings.access.manage')),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $data = $request->validate([
            'prefs' => ['required', 'array'],
            'prefs.*.enabled' => ['required', 'boolean'],
            'prefs.*.inapp' => ['required', 'boolean'],
            'prefs.*.email' => ['required', 'boolean'],
            'prefs.*.push' => ['required', 'boolean'],
        ]);

        $prefs = (array) $data['prefs'];

        foreach ($prefs as $key => $channels) {
            if (!is_array($channels)) continue;
            UserNotificationPreference::updateOrCreate([
                'user_id' => $user->id,
                'key' => (string) $key,
            ], [
                'enabled' => (bool) ($channels['enabled'] ?? true),
                'channel_inapp' => (bool) ($channels['inapp'] ?? true),
                'channel_email' => (bool) ($channels['email'] ?? false),
                'channel_push' => (bool) ($channels['push'] ?? false),
            ]);
        }

        return redirect()->back()->with('success', 'Notification preferences updated.');
    }

    public function roles(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $groups = (array) config('notification_events.groups', []);
        $allKeys = collect($groups)->flatten()->unique()->values();

        $roles = Role::query()->orderBy('label')->get(['id', 'name', 'label']);
        $existing = RoleNotificationPreference::query()
            ->whereIn('key', $allKeys)
            ->get(['role_id', 'key', 'enabled', 'channel_inapp', 'channel_email', 'channel_push']);

        $matrix = [];
        foreach ($roles as $role) {
            $row = [];
            foreach ($allKeys as $key) {
                $pref = $existing->firstWhere(fn($p) => (int) $p->role_id === (int) $role->id && $p->key === $key);
                // Default true/inapp if unset.
                $row[$key] = $pref ? [
                    'enabled' => (bool) $pref->enabled,
                    'inapp' => (bool) $pref->channel_inapp,
                    'email' => (bool) $pref->channel_email,
                    'push' => (bool) $pref->channel_push,
                ] : [
                    'enabled' => true,
                    'inapp' => true,
                    'email' => false,
                    'push' => false,
                ];
            }
            $matrix[$role->id] = $row;
        }

        return inertia('settings/notification-defaults', [
            'groups' => $groups,
            'roles' => $roles,
            'matrix' => $matrix,
        ]);
    }

    public function updateRoles(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'matrix' => ['required', 'array'],
        ]);

        $matrix = (array) $data['matrix'];

        foreach ($matrix as $roleId => $prefs) {
            if (!is_array($prefs)) continue;
            foreach ($prefs as $key => $channels) {
                if (!is_array($channels)) continue;
                RoleNotificationPreference::updateOrCreate([
                    'role_id' => (int) $roleId,
                    'key' => (string) $key,
                ], [
                    'enabled' => (bool) ($channels['enabled'] ?? true),
                    'channel_inapp' => (bool) ($channels['inapp'] ?? true),
                    'channel_email' => (bool) ($channels['email'] ?? false),
                    'channel_push' => (bool) ($channels['push'] ?? false),
                ]);
            }
        }

        return redirect()->back()->with('success', 'Role notification defaults updated.');
    }
}
