<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\EvvRecord;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\EvvService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class EvvController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly EvvService $evv,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.viewAny'), 403);

        $data = $request->validate([
            'verification_status' => ['nullable', 'string', 'in:pending,verified,flagged'],
            'status' => ['nullable', 'string', 'in:pending,verified,flagged'],
        ]);
        $status = $data['verification_status'] ?? $data['status'] ?? null;

        $baseQuery = $this->visibleRecordsQuery($auth);
        $query = clone $baseQuery;
        $query
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->when(! empty($status), fn ($q) => $q->where('verification_status', $status))
            ->orderByDesc('check_in_time');

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'verified' => (clone $baseQuery)->where('verification_status', 'verified')->count(),
            'pending' => (clone $baseQuery)->where('verification_status', 'pending')->count(),
            'flagged' => (clone $baseQuery)->where('verification_status', 'flagged')->count(),
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

        $record = $this->visibleRecordsQuery($auth)
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

        $shift = Shift::query()->with(['site', 'client:id,site_id'])->findOrFail($data['shift_id']);
        abort_unless(
            $shift->site_id
                && $shift->client_id
                && (int) $shift->client_id === (int) $data['client_id']
                && (int) $shift->client?->site_id === (int) $shift->site_id,
            422,
            'The Shift, Client and Site must match.',
        );
        $this->siteAccess->assertCanAccessSiteId($auth, (int) $shift->site_id, ['shifts.manageAny']);
        abort_unless(
            $auth->canDo('shifts.manageAny') || (int) $shift->user_id === (int) $auth->id,
            403,
        );

        $this->evv->processCheckIn(
            $shift,
            (int) $auth->id,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

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

        $record = $this->visibleRecordsQuery($auth)
            ->when(! $auth->canDo('shifts.manageAny'), fn ($q) => $q->where('user_id', $auth->id))
            ->when(! empty($data['record_id']), fn ($q) => $q->whereKey($data['record_id']))
            ->when(empty($data['record_id']), fn ($q) => $q->where('user_id', $auth->id)->whereNull('check_out_time'))
            ->with('shift.site')
            ->latest('check_in_time')
            ->firstOrFail();

        $this->evv->processCheckOut(
            $record,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        return redirect()->back()->with('success', 'Checked out.');
    }

    public function verify(Request $request, $record)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('evv.verify'), 403);

        $record = $this->visibleRecordsQuery($auth)
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

        return redirect()->back()->with('success', 'Record '.$data['verification_status'].'.');
    }

    private function visibleRecordsQuery(User $viewer): Builder
    {
        return EvvRecord::query()
            ->whereHas('shift', function (Builder $shiftQuery) use ($viewer): void {
                $this->siteAccess->applyShiftScope($shiftQuery, $viewer, ['shifts.manageAny']);
                $shiftQuery->whereColumn('shifts.client_id', 'evv_records.client_id');
            });
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
