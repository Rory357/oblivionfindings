<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\UserNotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
            'delivery' => [
                'dnd_enabled' => (bool) $user->dnd_enabled,
                'dnd_until' => optional($user->dnd_until)->toISOString(),
                'desktop_notifications_enabled' => (bool) $user->desktop_notifications_enabled,
                'notification_sounds_enabled' => (bool) ($user->notification_sounds_enabled ?? true),
                'email_digest_frequency' => $user->email_digest_frequency ?? 'instant',
            ],
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
        ]);

        $prefs = (array) $data['prefs'];

        foreach ($prefs as $key => $channels) {
            $channels = $this->normalizeChannels($channels, 'prefs.' . $key);

            UserNotificationPreference::updateOrCreate([
                'user_id' => $user->id,
                'key' => (string) $key,
            ], [
                'enabled' => $channels['enabled'],
                'channel_inapp' => $channels['inapp'],
                'channel_email' => $channels['email'],
                'channel_push' => $channels['push'],
            ]);
        }

        return redirect()->back()->with('success', 'Notification preferences updated.');
    }

    /**
     * Persist delivery-level preferences: DND, desktop notifications, sound
     * playback, and email digest frequency. Separate endpoint from the
     * per-event preferences matrix so the two UIs can save independently.
     */
    public function updateDelivery(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $data = $request->validate([
            'dnd_enabled' => ['nullable', 'boolean'],
            'dnd_until' => ['nullable', 'date'],
            'desktop_notifications_enabled' => ['nullable', 'boolean'],
            'notification_sounds_enabled' => ['nullable', 'boolean'],
            'email_digest_frequency' => ['nullable', 'string', 'in:instant,daily,weekly,off'],
        ]);

        $user->fill(array_intersect_key($data, array_flip([
            'dnd_enabled',
            'dnd_until',
            'desktop_notifications_enabled',
            'notification_sounds_enabled',
            'email_digest_frequency',
        ])))->save();

        return redirect()->back()->with('success', 'Delivery preferences updated.');
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
                $channels = $this->normalizeChannels($channels, 'matrix.' . $roleId . '.' . $key);

                RoleNotificationPreference::updateOrCreate([
                    'role_id' => (int) $roleId,
                    'key' => (string) $key,
                ], [
                    'enabled' => $channels['enabled'],
                    'channel_inapp' => $channels['inapp'],
                    'channel_email' => $channels['email'],
                    'channel_push' => $channels['push'],
                ]);
            }
        }

        return redirect()->back()->with('success', 'Role notification defaults updated.');
    }

    /**
     * @return array{enabled: bool, inapp: bool, email: bool, push: bool}
     */
    private function normalizeChannels(mixed $channels, string $errorKey): array
    {
        if (is_bool($channels)) {
            return [
                'enabled' => $channels,
                'inapp' => $channels,
                'email' => false,
                'push' => false,
            ];
        }

        if (! is_array($channels)) {
            throw ValidationException::withMessages([
                $errorKey => 'The notification preference must be a boolean or channel configuration.',
            ]);
        }

        $validator = Validator::make($channels, [
            'enabled' => ['required', 'boolean'],
            'inapp' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'push' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                $messages[$errorKey . '.' . $field] = $fieldMessages;
            }

            throw ValidationException::withMessages($messages);
        }

        return [
            'enabled' => $this->toBoolean($channels['enabled']),
            'inapp' => $this->toBoolean($channels['inapp']),
            'email' => $this->toBoolean($channels['email']),
            'push' => $this->toBoolean($channels['push']),
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
