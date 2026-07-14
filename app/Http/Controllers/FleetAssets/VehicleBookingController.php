<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\FleetVehicleBooking;
use App\Notifications\Fleet\FleetBookingApprovedNotification;
use App\Notifications\Fleet\FleetBookingRejectedNotification;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class VehicleBookingController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function index(Request $request)
    {
        $query = FleetVehicleBooking::query()
            ->with(['asset:id,name,asset_tag', 'user:id,name']);

        // CSV export
        if ($request->input('export') === 'csv') {
            $all = (clone $query)->latest()->limit(5000)->get();
            return response()->streamDownload(function () use ($all) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Reference', 'Vehicle', 'User', 'Purpose', 'Start', 'End', 'Status']);
                foreach ($all as $b) {
                    $this->putCsv($handle, [
                        $b->reference_number ?? '',
                        $b->asset?->name ?? '', $b->user?->name ?? '', $b->purpose,
                        optional($b->starts_at)->format('Y-m-d H:i') ?? '',
                        optional($b->ends_at)->format('Y-m-d H:i') ?? '',
                        $b->status,
                    ]);
                }
                fclose($handle);
            }, 'bookings-export.csv');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Hero tile drill-down: checked-out bookings whose end time has passed.
        if ($request->boolean('overdue')) {
            $query->where('status', 'checked_out')->where('ends_at', '<', now());
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('starts_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('ends_at', '<=', $request->input('date_to'));
        }

        // Sorting
        $allowedSorts = ['starts_at', 'status', 'created_at'];
        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'created_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';
        $query->reorder()->orderBy($sort, $direction);

        $bookings = $query->paginate(25)->withQueryString();

        $mapBooking = fn ($b) => [
            'id' => $b->id,
            'reference_number' => $b->reference_number,
            'asset' => $b->asset ? ['id' => $b->asset->id, 'name' => $b->asset->name, 'asset_tag' => $b->asset->asset_tag] : null,
            'user' => $b->user ? ['id' => $b->user->id, 'name' => $b->user->name] : null,
            'purpose' => $b->purpose,
            'starts_at' => optional($b->starts_at)->toISOString(),
            'ends_at' => optional($b->ends_at)->toISOString(),
            'status' => $b->status,
            'created_at' => optional($b->created_at)->toISOString(),
        ];

        // Hero band stats — whole-table (not page-scoped) conditional aggregate.
        $now = now();
        $heroRow = FleetVehicleBooking::query()
            ->selectRaw(
                "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, " .
                "SUM(CASE WHEN status = 'approved' AND starts_at >= ? THEN 1 ELSE 0 END) as approved_upcoming, " .
                "SUM(CASE WHEN status = 'checked_out' THEN 1 ELSE 0 END) as checked_out, " .
                "SUM(CASE WHEN status = 'checked_out' AND ends_at < ? THEN 1 ELSE 0 END) as overdue",
                [$now->toDateTimeString(), $now->toDateTimeString()]
            )
            ->first();

        // Attention-strip escalations (org-wide, same definitions as the
        // fleet dashboard hero).
        $outingsPastReturn = Schema::hasTable('fleet_outings')
            ? FleetOuting::query()
                ->where('status', 'active')
                ->where('planned_return', '<', $now)
                ->count()
            : 0;
        $criticalAlertQuery = ControlRoomAlert::query()
            ->actionable()
            ->where('severity', 'critical');
        $this->siteAccess->applyAlertScope($criticalAlertQuery, $request->user(), ['fleet.manage']);
        $criticalAlerts = $criticalAlertQuery->count();

        $data = [
            'bookings' => [
                'data' => $bookings->getCollection()->map($mapBooking)->values(),
                'links' => $bookings->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'total' => $bookings->total(),
                ],
            ],
            'hero' => [
                'pending' => (int) ($heroRow->pending ?? 0),
                'approved_upcoming' => (int) ($heroRow->approved_upcoming ?? 0),
                'checked_out' => (int) ($heroRow->checked_out ?? 0),
                'overdue' => (int) ($heroRow->overdue ?? 0),
                'outings_past_return' => $outingsPastReturn,
                'critical_alerts' => $criticalAlerts,
            ],
            'filters' => $request->only(['status', 'asset_id', 'date_from', 'date_to', 'view', 'week_start', 'overdue']),
            // Book-vehicle wizard props. Options are heavy (vehicles + sites +
            // clients), so they are eager only when the modal opens on first
            // paint (?new=1) and otherwise fetched via partial reload. The
            // conflict-check trio mirrors the retired create-page endpoint
            // (check_asset_id / check_starts_at / check_ends_at) — closures are
            // no-ops when the params are absent.
            'booking_options' => $request->boolean('new')
                ? $this->bookingWizardOptions()
                : Inertia::optional(fn () => $this->bookingWizardOptions()),
            'booking_conflicts' => fn () => $this->bookingConflicts($request),
            'booking_vehicle_status' => fn () => $this->checkedVehicleStatus($request),
            'booking_vehicle_bookings' => fn () => $this->checkedVehicleBookings($request),
        ];

        // Calendar view: include all vehicles and week-scoped bookings
        if ($request->input('view') === 'calendar') {
            $weekStart = $request->filled('week_start')
                ? \Carbon\Carbon::parse($request->input('week_start'))->startOfDay()
                : now()->startOfWeek(\Carbon\Carbon::MONDAY);

            $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

            $calendarBookings = FleetVehicleBooking::query()
                ->with(['asset:id,name,asset_tag', 'user:id,name'])
                ->where('starts_at', '<=', $weekEnd)
                ->where('ends_at', '>=', $weekStart)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->get()
                ->map($mapBooking)
                ->values();

            $vehicles = Asset::vehicles()
                ->orderBy('name')
                ->get(['id', 'name', 'asset_tag'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'asset_tag' => $v->asset_tag,
                ]);

            $data['calendar_bookings'] = $calendarBookings;
            $data['vehicles'] = $vehicles;
            $data['week_start'] = $weekStart->toDateString();
        }

        return Inertia::render('fleet-assets/bookings/index', $data);
    }

    /**
     * Legacy GET /bookings/create shim — the booking form is now a WizardShell
     * modal on the index. Redirect there and open it via ?new=1.
     */
    public function create(Request $request)
    {
        // Preserve the caller's query (dashboard per-vehicle "Book" passes
        // asset_id) so the wizard can pre-select the vehicle.
        return redirect()->to('/fleet-assets/bookings?' . http_build_query(
            array_merge($request->query(), ['new' => 1]),
        ));
    }

    /**
     * Vehicle / site / client option lists for the Book-vehicle wizard
     * (previously the create-page payload — field set preserved exactly).
     *
     * @return array<string, mixed>
     */
    private function bookingWizardOptions(): array
    {
        $hasFleetFields = \Illuminate\Support\Facades\Schema::hasColumn('assets', 'home_site_id');
        $hasAccessibility = \Illuminate\Support\Facades\Schema::hasColumn('assets', 'has_wheelchair_ramp');

        $vehiclesQuery = Asset::vehicles()
            ->where('status', 'active')
            ->orderBy('name');

        if ($hasFleetFields) {
            $vehiclesQuery->with('homeSite');
        }

        $accessibilityColumns = $hasAccessibility ? [
            'has_wheelchair_ramp', 'has_hoist', 'has_child_seat_anchors',
            'has_medical_storage', 'seating_capacity',
        ] : [];

        $vehicles = $vehiclesQuery->get(['id', 'name', 'asset_tag', 'status', ...($hasFleetFields ? ['home_site_id'] : []), ...$accessibilityColumns]);

        $clients = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('clients', 'transport_needs')) {
            $clients = \App\Models\Client::query()
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'transport_needs'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    'transport_needs' => $c->transport_needs,
                ])->values();
        }

        return [
            'vehicles' => $vehicles->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'status' => $v->status,
                'home_site' => $hasFleetFields && $v->homeSite ? [
                    'id' => $v->homeSite->id,
                    'name' => $v->homeSite->name,
                ] : null,
                ...($hasAccessibility ? [
                    'has_wheelchair_ramp' => (bool) $v->has_wheelchair_ramp,
                    'has_hoist' => (bool) $v->has_hoist,
                    'has_child_seat_anchors' => (bool) $v->has_child_seat_anchors,
                    'has_medical_storage' => (bool) $v->has_medical_storage,
                    'seating_capacity' => $v->seating_capacity,
                ] : []),
            ])->values(),
            'sites' => \App\Models\Site::query()->orderBy('name')->get(['id', 'name']),
            'clients' => $clients,
        ];
    }

    /**
     * Overlapping-booking conflicts for the wizard's selected vehicle + range.
     * Query semantics identical to the retired create-page check.
     *
     * @return array<int, array<string, mixed>>|\Illuminate\Support\Collection
     */
    private function bookingConflicts(Request $request)
    {
        if (!$request->filled('check_asset_id') || !$request->filled('check_starts_at') || !$request->filled('check_ends_at')) {
            return [];
        }

        return FleetVehicleBooking::query()
            ->where('asset_id', (int) $request->input('check_asset_id'))
            ->whereNotIn('status', ['cancelled', 'rejected', 'returned'])
            ->where('starts_at', '<=', $request->input('check_ends_at'))
            ->where('ends_at', '>=', $request->input('check_starts_at'))
            ->with('user:id,name')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'user_name' => $b->user?->name ?? 'Unknown',
                'purpose' => $b->purpose,
                'starts_at' => optional($b->starts_at)->toISOString(),
                'ends_at' => optional($b->ends_at)->toISOString(),
                'status' => $b->status,
            ])
            ->values();
    }

    /** Status of the wizard's selected vehicle (maintenance warning etc.). */
    private function checkedVehicleStatus(Request $request): ?string
    {
        if (!$request->filled('check_asset_id')) {
            return null;
        }

        return Asset::find((int) $request->input('check_asset_id'), ['id', 'status'])?->status;
    }

    /**
     * All bookings for the selected vehicle in a 3-month window — feeds the
     * wizard's availability mini-calendar.
     *
     * @return array<int, array<string, mixed>>|\Illuminate\Support\Collection
     */
    private function checkedVehicleBookings(Request $request)
    {
        if (!$request->filled('check_asset_id')) {
            return [];
        }

        $monthStart = now()->startOfMonth()->subMonth();
        $monthEnd = now()->endOfMonth()->addMonth();

        return FleetVehicleBooking::query()
            ->where('asset_id', (int) $request->input('check_asset_id'))
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('starts_at', '<=', $monthEnd)
            ->where('ends_at', '>=', $monthStart)
            ->with('user:id,name')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'user_name' => $b->user?->name ?? 'Unknown',
                'purpose' => $b->purpose ?? 'Booking',
                'starts_at' => optional($b->starts_at)->toISOString(),
                'ends_at' => optional($b->ends_at)->toISOString(),
                'status' => $b->status,
            ])
            ->values();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'purpose' => ['required', 'string', 'max:500'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'destination' => ['nullable', 'string', 'max:255'],
            'passengers' => ['nullable', 'integer', 'min:0', 'max:50'],
            'pickup_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'return_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Verify the booking user has valid driver eligibility
        $eligibility = \App\Domain\Hr\Models\HrDriverEligibility::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'eligible')
            ->where('licence_expires_at', '>', now())
            ->first();

        if (!$eligibility) {
            return back()->withErrors([
                'driver' => 'You must have valid driver eligibility with a non-expired licence to book a vehicle.',
            ]);
        }

        // Server-side overlap prevention with atomic check-and-create
        $booking = DB::transaction(function () use ($data, $request) {
            $conflict = FleetVehicleBooking::query()
                ->where('asset_id', $data['asset_id'])
                ->whereIn('status', ['pending', 'approved', 'checked_out'])
                ->where('starts_at', '<', $data['ends_at'])
                ->where('ends_at', '>', $data['starts_at'])
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return null;
            }

            $data['user_id'] = $request->user()->id;
            $data['status'] = 'pending';
            return FleetVehicleBooking::create($data);
        });

        if (!$booking) {
            return back()->withErrors([
                'asset_id' => 'This vehicle is already booked for the selected time period.',
            ]);
        }

        AuditLogger::log('fleet.booking.create', $booking, [
            'asset_id' => $data['asset_id'],
        ]);

        return redirect()->route('fleet-assets.bookings.show', $booking)
            ->with('success', 'Booking request submitted.');
    }

    public function show(Request $request, FleetVehicleBooking $booking)
    {
        $booking->load(['asset:id,name,asset_tag', 'user:id,name,email']);

        return Inertia::render('fleet-assets/bookings/show', [
            'booking' => $booking,
            'can' => [
                'manage' => (bool) $request->user()?->canDo('fleet.manage'),
            ],
        ]);
    }

    public function approve(Request $request, FleetVehicleBooking $booking)
    {
        abort_if($booking->user_id === $request->user()->id, 403, 'Cannot approve your own booking.');
        abort_unless($booking->status === 'pending', 422, 'Only pending bookings can be approved.');

        $booking->update([
            'status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
        ]);

        AuditLogger::log('fleet.booking.approve', $booking, [
            'booking_id' => $booking->id,
        ]);

        $booking->load('asset:id,name');
        $booking->user->notify(new FleetBookingApprovedNotification($booking));

        return back()->with('success', 'Booking approved.');
    }

    public function reject(Request $request, FleetVehicleBooking $booking)
    {
        abort_unless($booking->status === 'pending', 422, 'Only pending bookings can be rejected.');

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $booking->update([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
        ]);

        AuditLogger::log('fleet.booking.reject', $booking, [
            'booking_id' => $booking->id,
            'reason' => $data['rejection_reason'],
        ]);

        $booking->load('asset:id,name');
        $booking->user->notify(new FleetBookingRejectedNotification($booking));

        return back()->with('success', 'Booking rejected.');
    }

    public function checkout(Request $request, FleetVehicleBooking $booking)
    {
        abort_unless($booking->status === 'approved', 422, 'Only approved bookings can be checked out.');

        $data = $request->validate([
            'odometer_out' => ['nullable', 'numeric', 'min:0'],
        ]);

        $booking->update([
            'status' => 'checked_out',
            'checked_out_at' => now(),
            'odometer_out' => $data['odometer_out'] ?? null,
            'checked_out_by' => $request->user()->id,
        ]);

        AuditLogger::log('fleet.booking.checkout', $booking, [
            'booking_id' => $booking->id,
        ]);

        return back()->with('success', 'Vehicle checked out.');
    }

    public function returnVehicle(Request $request, FleetVehicleBooking $booking)
    {
        abort_unless($booking->status === 'checked_out', 422, 'Only checked-out bookings can be returned.');

        $data = $request->validate([
            'odometer_in' => ['nullable', 'numeric', 'min:0'],
            'condition_on_return' => ['nullable', 'string', 'max:50'],
            'return_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking->update([
            'status' => 'returned',
            'returned_at' => now(),
            'odometer_in' => $data['odometer_in'] ?? null,
            'condition_on_return' => $data['condition_on_return'] ?? null,
            'return_notes' => $data['return_notes'] ?? null,
            'returned_by' => $request->user()->id,
        ]);

        AuditLogger::log('fleet.booking.return', $booking, [
            'booking_id' => $booking->id,
        ]);

        return back()->with('success', 'Vehicle returned.');
    }

    public function cancel(Request $request, FleetVehicleBooking $booking)
    {
        abort_unless(
            in_array($booking->status, ['pending', 'approved', 'checked_out']),
            422,
            'This booking cannot be cancelled in its current state.'
        );

        $booking->update([
            'status' => 'cancelled',
        ]);

        AuditLogger::log('fleet.booking.cancel', $booking, [
            'booking_id' => $booking->id,
        ]);

        return back()->with('success', 'Booking cancelled.');
    }
}
