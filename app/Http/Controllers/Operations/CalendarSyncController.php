<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CalendarSync;
use Illuminate\Http\Request;

class CalendarSyncController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $syncs = CalendarSync::query()
            ->where('user_id', $auth->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/calendar-sync/Index', [
            'connections' => $syncs,
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

        \App\Jobs\SyncCalendarJob::dispatch($sync);

        return redirect()->back()->with('success', 'Calendar sync triggered.');
    }
}
