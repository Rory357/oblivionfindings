<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use Illuminate\Http\Request;

class PortalPreferenceController extends Controller
{
    private const PORTAL_PREFERENCES = [
        'portal.shift.arrival' => ['label' => 'Shift Arrival', 'description' => 'When a support worker arrives for a scheduled shift'],
        'portal.shift.completion' => ['label' => 'Shift Completed', 'description' => 'When a scheduled shift has been completed'],
        'portal.incident.reported' => ['label' => 'Incident Reported', 'description' => 'When a reviewed incident is shared with you'],
        'portal.visit.status_change' => ['label' => 'Visit Request Update', 'description' => 'When your visit request is approved or declined'],
        'portal.document.shared' => ['label' => 'Document Shared', 'description' => 'When a new document is shared with you by the care team'],
        'portal.message.received' => ['label' => 'New Message', 'description' => 'When you receive a new message from the care team'],
        'portal.photo.uploaded' => ['label' => 'Photo Uploaded', 'description' => 'When a new photo is uploaded to the gallery'],
        'portal.weekly_summary' => ['label' => 'Weekly Summary', 'description' => 'A weekly overview of activity and care updates'],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $keys = array_keys(self::PORTAL_PREFERENCES);

        $saved = UserNotificationPreference::where('user_id', $user->id)
            ->whereIn('key', $keys)
            ->get()
            ->keyBy('key');

        $current = collect(self::PORTAL_PREFERENCES)->map(fn ($meta, $key) => [
            'key' => $key,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'enabled' => $saved->has($key) ? (bool) $saved->get($key)->enabled : true,
        ])->values();

        return inertia('portal/preferences', [
            'preferences' => $current,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.key' => 'required|string|in:' . implode(',', array_keys(self::PORTAL_PREFERENCES)),
            'preferences.*.enabled' => 'required|boolean',
        ]);

        foreach ($validated['preferences'] as $pref) {
            UserNotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'key' => $pref['key'],
                ],
                [
                    'enabled' => $pref['enabled'],
                ]
            );
        }

        return redirect()->back()->with('success', 'Notification preferences updated.');
    }
}
