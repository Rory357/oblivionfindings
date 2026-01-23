<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementInboxController extends Controller
{
    public function markRead(Request $request, Announcement $announcement)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Ensure the user is allowed to see it before marking read
        abort_unless(
            Announcement::query()->active()->visibleTo($user)->whereKey($announcement->id)->exists(),
            404
        );

        $announcement->markReadFor($user);

        return back()->with('success', 'Announcement marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $items = Announcement::query()->active()->visibleTo($user)->get(['id']);
        foreach ($items as $a) {
            $a->markReadFor($user);
        }

        return back()->with('success', 'All announcements marked as read.');
    }
}
