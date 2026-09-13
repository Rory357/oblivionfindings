<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCalendarJob;
use App\Models\CalendarSync;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarSyncController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $status = (string) $request->get('status', 'all');
        if (! in_array($status, ['all', 'active', 'paused', 'stale'], true)) {
            $status = 'all';
        }

        $base = fn () => CalendarSync::query()->where('user_id', $auth->id);
        // Stale = active but with no successful sync in the last 24 hours
        // (a paused connection not syncing is expected, not noteworthy).
        $stale = fn ($query) => $query
            ->where('is_active', true)
            ->where(function ($inner) {
                $inner->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', now()->subDay());
            });

        $syncs = $base()
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'paused', fn ($q) => $q->where('is_active', false))
            ->when($status === 'stale', $stale)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $lastSynced = $base()->max('last_synced_at');

        return inertia('operations/calendar-sync/Index', [
            'connections' => $syncs,
            'filters' => ['status' => $status],
            // Header instruments — counted over the whole set regardless of
            // the active view so the rail counts stay honest on every tab.
            'summary' => [
                'total' => $base()->count(),
                'active' => $base()->where('is_active', true)->count(),
                'paused' => $base()->where('is_active', false)->count(),
                'stale' => $stale($base())->count(),
                'providers' => $base()->distinct()->count('provider'),
                'last_synced_at' => $lastSynced
                    ? Carbon::parse($lastSynced)->toISOString()
                    : null,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        return inertia('operations/calendar-sync/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $data = $request->validate([
            'provider' => ['required', 'string', 'in:google,outlook,ical'],
            'calendar_id' => ['nullable', 'string', 'max:255'],
            'sync_direction' => ['required', 'string', 'in:push,pull,both'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        CalendarSync::create([
            'user_id' => $auth->id,
            'organization_id' => $auth->organization_id,
            'provider' => $data['provider'],
            'calendar_id' => $data['calendar_id'] ?? null,
            'sync_direction' => $data['sync_direction'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Calendar sync created.');
    }

    public function destroy(Request $request, $sync)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $sync = CalendarSync::query()
            ->where('user_id', $auth->id)
            ->findOrFail($sync);

        $sync->delete();

        return redirect()->back()->with('success', 'Calendar sync removed.');
    }

    public function triggerSync(Request $request, $sync)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $sync = CalendarSync::query()
            ->where('user_id', $auth->id)
            ->findOrFail($sync);

        SyncCalendarJob::dispatch($sync);

        return redirect()->back()->with('success', 'Calendar sync triggered.');
    }
}
