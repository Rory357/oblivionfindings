<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\OpsNotification;
use Illuminate\Http\Request;

class OpsNotificationController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'read' => ['nullable', 'in:unread,read'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $base = fn () => OpsNotification::query()->where('user_id', $auth->id);

        $notifications = $base()
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->forType($type))
            ->when(($filters['read'] ?? null) === 'unread', fn ($q) => $q->where('is_read', false))
            ->when(($filters['read'] ?? null) === 'read', fn ($q) => $q->where('is_read', true))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $like = '%'.$search.'%';
                $inner->where('title', 'like', $like)
                    ->orWhere('body', 'like', $like);
            }))
            ->orderByRaw('read_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/notifications/Index', [
            'notifications' => $notifications,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'type' => $filters['type'] ?? null,
                'read' => $filters['read'] ?? null,
            ],
            // Header instruments — counted over the whole set regardless of
            // the active filters so the rail counts stay honest.
            'stats' => [
                'total' => $base()->count(),
                'unread' => $base()->where('is_read', false)->count(),
            ],
            'types' => $base()
                ->whereNotNull('type')
                ->distinct()
                ->orderBy('type')
                ->pluck('type')
                ->values(),
        ]);
    }

    public function markRead(Request $request, $notification)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $notification = OpsNotification::query()
            ->where('user_id', $auth->id)
            ->findOrFail($notification);

        $notification->update(['read_at' => now()]);

        return redirect()->back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        OpsNotification::query()
            ->where('user_id', $auth->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }
}
