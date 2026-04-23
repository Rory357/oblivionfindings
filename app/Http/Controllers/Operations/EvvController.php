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
            'status' => ['nullable', 'string', 'in:pending,verified,flagged'],
        ]);
        $status = $data['verification_status'] ?? $data['status'] ?? null;

        $query = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->when(!empty($status), fn ($q) => $q->where('verification_status', $status))
            ->orderByDesc('check_in_time');

        $stats = [
            'total' => EvvRecord::where('organization_id', $auth->organization_id)->count(),
            'verified' => EvvRecord::where('organization_id', $auth->organization_id)->where('verification_status', 'verified')->count(),
            'pending' => EvvRecord::where('organization_id', $auth->organization_id)->where('verification_status', 'pending')->count(),
            'flagged' => EvvRecord::where('organization_id', $auth->organization_id)->where('verification_status', 'flagged')->count(),
        ];

        $records = $query->paginate(20)
            ->through(fn (EvvRecord $record) => $this->serializeRecord($record))
            ->withQueryString();

        return inertia('operations/evv/Index', [
            'records' => $records,
            'stats' => $stats,
            'filters' => ['status' => $status],
        ]);
    }

    public function show(Request $request, $record)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('evv.view') || $auth->canDo('evv.viewAny')), 403);

        $record = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at,site_id', 'shift.site:id,name'])
            ->findOrFail($record);

        return inertia('operations/evv/Show', [
            'record' => $this->serializeRecord($record, true),
        ]);
    }

    public function checkIn(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('evv.checkIn') || $auth->canDo('evv.record')), 403);

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
            'user_id' => $auth->id,
            'check_in_time' => now(),
            'check_in_latitude' => $data['latitude'],
            'check_in_longitude' => $data['longitude'],
            'distance_from_site_in' => $distance,
            'verification_status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Checked in.');
    }

    public function checkOut(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('evv.checkOut') || $auth->canDo('evv.record')), 403);

        $data = $request->validate([
            'record_id' => ['nullable', 'integer', 'exists:evv_records,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $record = EvvRecord::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->when(!empty($data['record_id']), fn ($q) => $q->whereKey($data['record_id']))
            ->when(empty($data['record_id']), fn ($q) => $q->where('user_id', $auth->id)->whereNull('check_out_time'))
            ->with('shift.site')
            ->latest('check_in_time')
            ->firstOrFail();

        $distance = null;
        if ($record->shift?->site?->latitude && $record->shift?->site?->longitude) {
            $distance = $this->calculateDistance(
                $data['latitude'],
                $data['longitude'],
                $record->shift->site->latitude,
                $record->shift->site->longitude
            );
        }

        $record->update([
            'check_out_time' => now(),
            'check_out_latitude' => $data['latitude'],
            'check_out_longitude' => $data['longitude'],
            'distance_from_site_out' => $distance,
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
            'flagged_reason' => $data['verification_status'] === 'flagged' ? ($data['verification_notes'] ?? null) : null,
            'notes' => $data['verification_notes'] ?? $record->notes,
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

    private function serializeRecord(EvvRecord $record, bool $includeDetails = false): array
    {
        $payload = [
            'id' => $record->id,
            'status' => $record->verification_status,
            'verification_status' => $record->verification_status,
            'check_in_time' => optional($record->check_in_time)->toIso8601String(),
            'check_out_time' => optional($record->check_out_time)->toIso8601String(),
            'gps_verified' => (bool) ($record->geofence_check_in ?? false),
            'has_issues' => $record->verification_status === 'flagged' || filled($record->flagged_reason),
            'issue_description' => $record->flagged_reason,
            'worker' => $record->user ? [
                'id' => $record->user->id,
                'name' => $record->user->name,
            ] : null,
            'client' => $record->client ? [
                'id' => $record->client->id,
                'first_name' => $record->client->first_name,
                'last_name' => $record->client->last_name,
            ] : null,
            'shift_date' => optional($record->shift?->starts_at ?? $record->check_in_time)->toDateString(),
        ];

        if ($includeDetails) {
            $payload += [
                'check_in_latitude' => $record->check_in_latitude,
                'check_in_longitude' => $record->check_in_longitude,
                'check_out_latitude' => $record->check_out_latitude,
                'check_out_longitude' => $record->check_out_longitude,
                'distance_from_site_in' => $record->distance_from_site_in,
                'distance_from_site_out' => $record->distance_from_site_out,
                'notes' => $record->notes,
                'shift' => $record->shift ? [
                    'id' => $record->shift->id,
                    'starts_at' => optional($record->shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($record->shift->ends_at)->toIso8601String(),
                    'site' => $record->shift->site ? [
                        'id' => $record->shift->site->id,
                        'name' => $record->shift->site->name,
                    ] : null,
                ] : null,
            ];
        }

        return $payload;
    }
}
