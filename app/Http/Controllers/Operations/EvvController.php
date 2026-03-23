<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\EvvRecord;
use App\Models\Site;
use Illuminate\Http\Request;

class EvvController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.viewAny'), 403);

        $data = $request->validate([
            'verification_status' => ['nullable', 'string', 'in:pending,verified,flagged'],
        ]);

        $query = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['staff:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->when(!empty($data['verification_status']), fn ($q) => $q->where('verification_status', $data['verification_status']))
            ->orderByDesc('check_in_time');

        $stats = [
            'total' => EvvRecord::where('organization_id', $auth->organization_id)->count(),
            'verified' => EvvRecord::where('organization_id', $auth->organization_id)->where('verification_status', 'verified')->count(),
            'pending' => EvvRecord::where('organization_id', $auth->organization_id)->where('verification_status', 'pending')->count(),
            'flagged' => EvvRecord::where('organization_id', $auth->organization_id)->where('verification_status', 'flagged')->count(),
        ];

        $records = $query->paginate(20)->withQueryString();

        return inertia('operations/evv/Index', [
            'records' => $records,
            'stats' => $stats,
            'filters' => $request->only(['verification_status']),
        ]);
    }

    public function show(Request $request, $record)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.view'), 403);

        $record = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['staff:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at,site_id', 'shift.site:id,name'])
            ->findOrFail($record);

        return inertia('operations/evv/Show', [
            'record' => $record,
        ]);
    }

    public function checkIn(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.checkIn'), 403);

        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // Calculate distance from client site if available
        $distance = null;
        $shift = \App\Models\Shift::with('site')->find($data['shift_id']);
        if ($shift && $shift->site && $shift->site->latitude && $shift->site->longitude) {
            $distance = $this->calculateDistance(
                $data['latitude'],
                $data['longitude'],
                $shift->site->latitude,
                $shift->site->longitude
            );
        }

        $record = EvvRecord::create([
            'organization_id' => $auth->organization_id,
            'shift_id' => $data['shift_id'],
            'client_id' => $data['client_id'],
            'staff_id' => $auth->id,
            'check_in_time' => now(),
            'check_in_latitude' => $data['latitude'],
            'check_in_longitude' => $data['longitude'],
            'check_in_distance' => $distance,
            'verification_status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Checked in.');
    }

    public function checkOut(Request $request, $record)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.checkOut'), 403);

        $record = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($record);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $record->update([
            'check_out_time' => now(),
            'check_out_latitude' => $data['latitude'],
            'check_out_longitude' => $data['longitude'],
        ]);

        return redirect()->back()->with('success', 'Checked out.');
    }

    public function verify(Request $request, $record)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.verify'), 403);

        $record = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($record);

        $data = $request->validate([
            'verification_status' => ['required', 'string', 'in:verified,flagged'],
            'verification_notes' => ['nullable', 'string'],
        ]);

        $record->update([
            'verification_status' => $data['verification_status'],
            'verification_notes' => $data['verification_notes'] ?? null,
            'verified_by' => $auth->id,
            'verified_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Record ' . $data['verification_status'] . '.');
    }

    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }
}
