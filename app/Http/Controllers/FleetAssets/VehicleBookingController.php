<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Models\FleetKeyLog;
use App\Models\FleetOuting;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\User;
use App\Notifications\Fleet\FleetBookingApprovedNotification;
use App\Notifications\Fleet\FleetBookingRejectedNotification;
use App\Services\AuditLogger;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\Fleet\MaintenanceFingerprint;
use App\Services\Fleet\MaintenanceLocalTime;
use App\Services\Fleet\VehicleBookingAccessService;
use App\Services\Fleet\VehicleOdometerService;
use App\Services\Fleet\VehicleReadinessService;
use App\Services\Fleet\VehicleStaffDirectory;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class VehicleBookingController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly VehicleBookingAccessService $bookingAccess,
        private readonly VehicleReadinessService $readiness,
        private readonly VehicleOdometerService $odometer,
        private readonly VehicleStaffDirectory $staff,
    ) {}

    public function index(Request $request)
    {
        $actor = $this->readActor($request);
        $query = $this->bookingAccess->accessibleBookings($actor)
            ->with(['asset:id,name,asset_tag', 'user:id,name']);

        // CSV export
        if ($request->input('export') === 'csv') {
            $exportQuery = (clone $query)->latest();

            return response()->streamDownload(function () use ($exportQuery) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Reference', 'Vehicle', 'User', 'Purpose', 'Start', 'End', 'Status']);
                foreach ($exportQuery->lazy(200) as $b) {
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
            $asset = $this->bookingAccess->vehicle($actor, (int) $request->input('asset_id'));
            abort_unless($asset, 404);
            $query->where('asset_id', $asset->id);
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
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'created_at';
        }
        if (! in_array($direction, ['asc', 'desc'])) {
            $direction = 'desc';
        }
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
        $heroRow = $this->bookingAccess->accessibleBookings($actor)
            ->selectRaw(
                "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, ".
                "SUM(CASE WHEN status = 'approved' AND starts_at >= ? THEN 1 ELSE 0 END) as approved_upcoming, ".
                "SUM(CASE WHEN status = 'checked_out' THEN 1 ELSE 0 END) as checked_out, ".
                "SUM(CASE WHEN status = 'checked_out' AND ends_at < ? THEN 1 ELSE 0 END) as overdue",
                [$now->toDateTimeString(), $now->toDateTimeString()]
            )
            ->first();

        // Attention-strip escalations (org-wide, same definitions as the
        // fleet dashboard hero).
        $outingsPastReturn = Schema::hasTable('fleet_outings')
            ? FleetOuting::query()
                ->whereIn('asset_id', $this->bookingAccess->authorizedVehicleIds($actor))
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
                ? $this->bookingWizardOptions($actor)
                : Inertia::optional(fn () => $this->bookingWizardOptions($actor)),
            'booking_conflicts' => fn () => $this->bookingConflicts($request),
            'booking_vehicle_status' => fn () => $this->checkedVehicleStatus($request),
            'booking_vehicle_bookings' => fn () => $this->checkedVehicleBookings($request),
        ];

        // Calendar view: include all vehicles and week-scoped bookings
        if ($request->input('view') === 'calendar') {
            // The week picker is a worker-local calendar date while booking
            // timestamps are stored in UTC. Convert the complete local week
            // to UTC before querying so Monday-morning bookings are not
            // incorrectly treated as belonging to the previous UTC day.
            $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
            $weekStart = $request->filled('week_start')
                ? Carbon::parse($request->input('week_start'), $timezone)->startOfDay()
                : Carbon::now($timezone)->startOfWeek(Carbon::MONDAY)->startOfDay();

            $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
            $weekStartUtc = $weekStart->copy()->utc();
            $weekEndUtc = $weekEnd->copy()->utc();

            $calendarBookings = $this->bookingAccess->accessibleBookings($actor)
                ->with(['asset:id,name,asset_tag', 'user:id,name'])
                ->where('starts_at', '<=', $weekEndUtc)
                ->where('ends_at', '>=', $weekStartUtc)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->get()
                ->map($mapBooking)
                ->values();

            $activeVehicles = $this->bookingAccess->activeVehicles($actor)->sortBy([['name', 'asc'], ['id', 'asc']]);
            $readiness = $this->readiness->projections($activeVehicles);
            $vehicles = $activeVehicles->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'readiness' => $readiness[(int) $v->id]->toArray(),
            ])->values();

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
        return redirect()->to('/fleet-assets/bookings?'.http_build_query(
            array_merge($request->query(), ['new' => 1]),
        ));
    }

    /**
     * Vehicle / site / client option lists for the Book-vehicle wizard
     * (previously the create-page payload — field set preserved exactly).
     *
     * @return array<string, mixed>
     */
    private function bookingWizardOptions(User $actor): array
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');
        $hasAccessibility = Schema::hasColumn('assets', 'has_wheelchair_ramp');

        $vehicles = $this->bookingAccess->activeVehicles($actor);
        if ($hasFleetFields) {
            $vehicles->load('homeSite:id,name');
        }

        $clients = [];
        if (Schema::hasColumn('clients', 'transport_needs')) {
            $clients = $this->bookingAccess->clients($actor)
                ->sortBy([['first_name', 'asc'], ['last_name', 'asc'], ['id', 'asc']])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
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
            'sites' => $this->bookingAccess->sites($actor),
            'clients' => $clients,
        ];
    }

    /**
     * Overlapping-booking conflicts for the wizard's selected vehicle + range.
     * Query semantics identical to the retired create-page check.
     *
     * @return array<int, array<string, mixed>>|Collection
     */
    private function bookingConflicts(Request $request)
    {
        if (! $request->filled('check_asset_id') || ! $request->filled('check_starts_at') || ! $request->filled('check_ends_at')) {
            return [];
        }

        $actor = $this->readActor($request);
        $asset = $this->bookingAccess->vehicle($actor, (int) $request->input('check_asset_id'));
        abort_unless($asset, 404);

        // The wizard sends Auckland wall time; bookings are stored in UTC.
        $checkStart = $this->checkTime((string) $request->input('check_starts_at'));
        $checkEnd = $this->checkTime((string) $request->input('check_ends_at'));
        if (! $checkStart || ! $checkEnd) {
            return [];
        }

        return $this->bookingAccess->accessibleBookings($actor)
            ->where('asset_id', $asset->id)
            ->whereNotIn('status', ['cancelled', 'rejected', 'returned'])
            ->where('starts_at', '<=', $checkEnd)
            ->where('ends_at', '>=', $checkStart)
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

    /** Auckland wall minute (datetime-local) or an absolute timestamp, as UTC. */
    private function checkTime(string $value): ?Carbon
    {
        try {
            return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)
                ? Carbon::parse(MaintenanceLocalTime::toUtc($value), 'UTC')
                : Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Status of the wizard's selected vehicle (maintenance warning etc.). */
    private function checkedVehicleStatus(Request $request): ?string
    {
        if (! $request->filled('check_asset_id')) {
            return null;
        }

        $asset = $this->bookingAccess->vehicle(
            $this->readActor($request),
            (int) $request->input('check_asset_id'),
        );
        abort_unless($asset, 404);

        return $asset->status;
    }

    /**
     * All bookings for the selected vehicle in a 3-month window — feeds the
     * wizard's availability mini-calendar.
     *
     * @return array<int, array<string, mixed>>|Collection
     */
    private function checkedVehicleBookings(Request $request)
    {
        if (! $request->filled('check_asset_id')) {
            return [];
        }

        $monthStart = now()->startOfMonth()->subMonth();
        $monthEnd = now()->endOfMonth()->addMonth();

        $actor = $this->readActor($request);
        $asset = $this->bookingAccess->vehicle($actor, (int) $request->input('check_asset_id'));
        abort_unless($asset, 404);

        return $this->bookingAccess->accessibleBookings($actor)
            ->where('asset_id', $asset->id)
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
        $actor = $this->readActor($request);

        // Validate identifier shapes first, then resolve every supplied
        // canonical object before validating the rest of the action payload.
        // Numeric missing and foreign IDs therefore converge on the same 404
        // without leaking unrelated purpose/date validation or side effects.
        $identifiers = $request->validate([
            'asset_id' => ['required', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'pickup_site_id' => ['nullable', 'integer'],
            'return_site_id' => ['nullable', 'integer'],
        ]);

        // Site(s) -> optional Client -> Asset -> payload -> overlapping
        // bookings. The Asset row is the serialization lock even when no
        // prior booking row exists, closing the empty-range double-booking
        // race.
        $booking = DB::transaction(function () use ($identifiers, $request, $actor) {
            $actor = $this->freshReadActor($actor);
            $assetPreview = $this->bookingAccess->vehicle($actor, (int) $identifiers['asset_id']);
            abort_unless($assetPreview, 404);
            $assetPreview->loadMissing('client:id,site_id');

            $client = null;
            if (isset($identifiers['client_id'])) {
                $client = $this->bookingAccess->client($actor, (int) $identifiers['client_id']);
                abort_unless($client, 404);
            }

            $siteIds = collect([
                $identifiers['pickup_site_id'] ?? null,
                $identifiers['return_site_id'] ?? null,
                $assetPreview->site_id,
                $assetPreview->site_id ? null : $assetPreview->home_site_id,
                (! $assetPreview->site_id && ! $assetPreview->home_site_id) ? $assetPreview->client?->site_id : null,
                $client?->site_id,
            ])->filter(fn (mixed $id): bool => $id !== null)
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            abort_unless($this->bookingAccess->lockSites($actor, $siteIds), 404);

            if ($client) {
                abort_unless($this->bookingAccess->client($actor, (int) $client->id, true), 404);
            }

            $asset = $this->bookingAccess->vehicle($actor, (int) $identifiers['asset_id'], true);
            abort_unless($asset, 404);

            $usesLocal = $request->filled('starts_local') || $request->filled('ends_local');
            $data = $request->validate([
                'asset_id' => ['required', 'integer'],
                'client_id' => ['nullable', 'integer'],
                'purpose' => ['required', 'string', 'max:255'],
                // Workspace forms send Auckland wall time; older callers send
                // an absolute starts_at / ends_at.
                'starts_local' => [$usesLocal ? 'required' : 'nullable', 'string'],
                'ends_local' => [$usesLocal ? 'required' : 'nullable', 'string'],
                'starts_offset' => ['nullable', 'string', 'max:6'],
                'ends_offset' => ['nullable', 'string', 'max:6'],
                'starts_at' => [$usesLocal ? 'nullable' : 'required', 'date'],
                'ends_at' => [$usesLocal ? 'nullable' : 'required', 'date', ...($usesLocal ? [] : ['after:starts_at'])],
                'destination' => ['nullable', 'string', 'max:255'],
                'passengers' => ['nullable', 'integer', 'min:0', 'max:50'],
                'pickup_site_id' => ['nullable', 'integer'],
                'return_site_id' => ['nullable', 'integer'],
                'pickup_arrangement' => ['nullable', 'string', 'max:255'],
                'driver_user_id' => ['nullable', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'approval_route' => ['nullable', 'in:required,not_required'],
                'approval_not_required_reason' => ['nullable', 'string', 'max:2000'],
                'approval_not_required_evidence' => ['nullable', 'string', 'max:255'],
                'readiness_acknowledged' => ['nullable', 'boolean'],
            ]);
            [$startsAt, $endsAt] = $this->window($data, $usesLocal);
            $requestKey = $this->requestKey($request);
            $fingerprint = MaintenanceFingerprint::of([
                'actor' => (int) $actor->id, 'asset' => (int) $asset->id, 'purpose' => trim($data['purpose']),
                'starts' => $startsAt->toIso8601String(), 'ends' => $endsAt->toIso8601String(),
                'driver' => $data['driver_user_id'] ?? null, 'route' => $data['approval_route'] ?? 'required',
                'reason' => $data['approval_not_required_reason'] ?? null,
                'evidence' => $data['approval_not_required_evidence'] ?? null,
            ]);
            if ($requestKey !== '') {
                $replayed = FleetVehicleBooking::query()->where('user_id', $actor->id)
                    ->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($replayed) {
                    abort_unless((int) $replayed->asset_id === (int) $asset->id
                        && hash_equals((string) $replayed->request_fingerprint, $fingerprint), 409,
                        'This request was already used for a different booking. Reload and try again.');

                    return [$replayed, null];
                }
            }
            if (! empty($data['driver_user_id']) && ! $this->staff->isCandidate($asset, (int) $data['driver_user_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'driver_user_id' => 'Choose a current staff member at this vehicle\'s site as the driver.',
                ]);
            }
            $approvalRoute = $data['approval_route'] ?? 'required';
            $noApprovalReason = trim((string) ($data['approval_not_required_reason'] ?? ''));
            $noApprovalEvidence = trim((string) ($data['approval_not_required_evidence'] ?? ''));
            // Anyone may ask for the approval-not-required route with a reason
            // or evidence; only a holder of booking authority can confirm it,
            // and only after explicitly reviewing readiness and the driver.
            $holdsAuthority = $actor->canDo('fleet.bookings.approve') || $actor->canDo('fleet.manage');
            if ($approvalRoute === 'not_required') {
                if ($noApprovalReason === '' && $noApprovalEvidence === '') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'approval_not_required_reason' => 'Add a reason or evidence for approval not required.',
                    ]);
                }
                if ($holdsAuthority && ! $request->boolean('readiness_acknowledged')) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'readiness_acknowledged' => 'Review readiness and driver authority for the approval-not-required path.',
                    ]);
                }
            }

            $overlapping = FleetVehicleBooking::query()->where('asset_id', $asset->id)
                ->whereIn('status', ['pending', 'approved', 'checked_out'])
                ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)
                ->lockForUpdate()->exists();
            if ($overlapping) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'asset_id' => 'This vehicle is already booked for the selected time period.',
                ]);
            }
            if (FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
                ->overlapping($startsAt, $endsAt)->lockForUpdate()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    ($usesLocal ? 'starts_local' : 'starts_at') => 'The vehicle is marked unavailable for part of this time. Choose another time.',
                ]);
            }

            // client_id is minimum-necessary compatibility input for the
            // accessibility picker only; the booking schema does not own a
            // Client relationship and must not persist that advisory choice.
            $bookingData = $data;
            unset($bookingData['client_id'], $bookingData['starts_local'], $bookingData['ends_local'],
                $bookingData['starts_offset'], $bookingData['ends_offset']);
            $bookingData['starts_at'] = $startsAt;
            $bookingData['ends_at'] = $endsAt;
            $bookingData['asset_id'] = $asset->id;
            $bookingData['user_id'] = $actor->id;
            $bookingData['status'] = 'pending';
            $bookingData['approval_route'] = $approvalRoute;
            $bookingData['lock_version'] = 1;
            $bookingData['request_key'] = $requestKey !== '' ? $requestKey : null;
            $bookingData['request_fingerprint'] = $requestKey !== '' ? $fingerprint : null;
            if ($approvalRoute === 'required') {
                unset($bookingData['approval_not_required_reason'], $bookingData['approval_not_required_evidence']);
            }
            unset($bookingData['readiness_acknowledged']);
            $created = FleetVehicleBooking::create($bookingData);

            $confirming = $approvalRoute === 'not_required' && $holdsAuthority;
            $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
                purpose: $confirming ? 'booking_confirmation' : 'booking_request',
                driverUserId: $created->driverUserId(),
                startsAt: $created->starts_at,
                endsAt: $created->ends_at,
                bookingId: (int) $created->id,
            ), true);
            // Readiness problems never confirm a booking; the request is kept
            // pending so it can be confirmed once they are resolved.
            if ($confirming && $assessment->canProceed) {
                $created->update([
                    'status' => 'approved',
                    'approval_authority_recorded_by' => $actor->id,
                    'approval_authority_recorded_at' => now(),
                    'approved_at' => now(),
                ]);
            }

            AuditLogger::logOrFail('fleet.booking.create', $created, [
                'asset_id' => $asset->id,
                'approval_route' => $approvalRoute,
                'readiness' => $this->decisionEvidence($assessment),
            ], $request);

            return [$created, $assessment];
        });
        [$booking, $assessment] = $booking;
        $blocking = $assessment?->blockingReasons() ?? [];
        $message = $booking->status === 'approved'
            ? 'Booking confirmed with approval-not-required authority recorded.'
            : ($blocking === [] ? 'Booking request submitted.'
                : 'Booking request submitted. It stays pending until this is resolved: '
                    .implode(' ', array_map(fn ($reason): string => $reason->message, array_slice($blocking, 0, 3))));

        if ($this->wantsJson($request)) {
            return response()->json(['booking' => $this->bookingResult($booking), 'message' => $message,
                'pending_reasons' => array_map(fn ($reason): string => $reason->message, array_slice($blocking, 0, 3))]);
        }
        $redirect = redirect()->route('fleet-assets.bookings.show', $booking);

        return $booking->status === 'approved' || $blocking === []
            ? $redirect->with('success', $message)
            : $redirect->with('warning', $message);
    }

    /**
     * Change a booking's times or details. A confirmed booking whose time
     * changes goes back to pending unless the approval-not-required authority
     * can confirm it again; readiness and conflicts are always rechecked.
     */
    public function update(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->readActor($request);
        $booking = DB::transaction(function () use ($request, $booking, $actor): array {
            $current = $this->freshReadActor($actor);
            $canonical = $this->lockBooking($current, (int) $booking->getKey());
            $manage = $current->canDo('fleet.manage');
            abort_unless($manage || ((int) $canonical->user_id === (int) $current->id && $canonical->status === 'pending'), 403);
            abort_unless(in_array($canonical->status, ['pending', 'approved'], true), 409,
                'Only pending or confirmed bookings can be changed.');
            $data = $request->validate([
                'expected_version' => ['required', 'integer', 'min:1'],
                'starts_local' => ['required', 'string'],
                'ends_local' => ['required', 'string'],
                'starts_offset' => ['nullable', 'string', 'max:6'],
                'ends_offset' => ['nullable', 'string', 'max:6'],
                'purpose' => ['required', 'string', 'max:255'],
                'destination' => ['nullable', 'string', 'max:255'],
                'passengers' => ['nullable', 'integer', 'min:0', 'max:50'],
                'pickup_arrangement' => ['nullable', 'string', 'max:255'],
                'driver_user_id' => ['nullable', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'reason' => ['required', 'string', 'max:2000'],
            ], ['reason.required' => 'Record the reason for this change.']);
            [$startsAt, $endsAt] = $this->window($data, true);
            $asset = $this->bookingAccess->vehicle($current, (int) $canonical->asset_id) ?? abort(404);
            // A retried save that already landed reports success.
            if ((int) $canonical->lock_version === (int) $data['expected_version'] + 1
                && $canonical->starts_at->equalTo($startsAt) && $canonical->ends_at->equalTo($endsAt)
                && $canonical->purpose === trim($data['purpose'])) {
                return [$canonical, null];
            }
            abort_unless((int) $canonical->lock_version === (int) $data['expected_version'], 409,
                'This booking changed while you were editing. Reload before saving.');
            if (! empty($data['driver_user_id']) && ! $this->staff->isCandidate($asset, (int) $data['driver_user_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'driver_user_id' => 'Choose a current staff member at this vehicle\'s site as the driver.',
                ]);
            }
            $conflict = FleetVehicleBooking::query()->where('asset_id', $asset->id)->whereKeyNot($canonical->id)
                ->whereIn('status', ['pending', 'approved', 'checked_out'])
                ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->lockForUpdate()->exists();
            if ($conflict) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'starts_local' => 'This vehicle is already booked for part of the new time.',
                ]);
            }
            if (FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
                ->overlapping($startsAt, $endsAt)->lockForUpdate()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'starts_local' => 'The vehicle is marked unavailable for part of this time. Choose another time.',
                ]);
            }

            $timesChanged = ! $canonical->starts_at->equalTo($startsAt) || ! $canonical->ends_at->equalTo($endsAt)
                || (int) ($canonical->driver_user_id ?? 0) !== (int) ($data['driver_user_id'] ?? 0);
            $before = $canonical->only(['starts_at', 'ends_at', 'purpose', 'destination', 'passengers',
                'pickup_arrangement', 'driver_user_id', 'notes', 'status']);
            $canonical->fill([
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'purpose' => trim($data['purpose']),
                'destination' => $data['destination'] ?? null, 'passengers' => $data['passengers'] ?? null,
                'pickup_arrangement' => $data['pickup_arrangement'] ?? null,
                'driver_user_id' => $data['driver_user_id'] ?? null, 'notes' => $data['notes'] ?? null,
                'lock_version' => (int) $canonical->lock_version + 1,
            ]);
            $assessment = null;
            if ($canonical->status === 'approved' && $timesChanged) {
                $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
                    purpose: 'booking_confirmation', driverUserId: $canonical->driverUserId(),
                    startsAt: $startsAt, endsAt: $endsAt, bookingId: (int) $canonical->id,
                ), true);
                $reconfirm = $canonical->approval_route === 'not_required'
                    && ($current->canDo('fleet.bookings.approve') || $manage) && $assessment->canProceed;
                if (! $reconfirm) {
                    $canonical->fill(['status' => 'pending', 'approved_by_user_id' => null, 'approved_at' => null,
                        'approval_authority_recorded_by' => null, 'approval_authority_recorded_at' => null]);
                }
            }
            $canonical->save();
            AuditLogger::logOrFail('fleet.booking.update', $canonical, [
                'booking_id' => $canonical->id,
                'before' => array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $before),
                'reason' => trim($data['reason']),
                'status' => $canonical->status,
                'readiness' => $assessment ? $this->decisionEvidence($assessment) : null,
            ], $request);

            return [$canonical, $assessment];
        });
        [$booking] = $booking;
        $message = $booking->status === 'pending'
            ? 'Booking changed. It is pending approval.'
            : 'Booking changed.';

        return $this->wantsJson($request)
            ? response()->json(['booking' => $this->bookingResult($booking), 'message' => $message])
            : back()->with('success', $message);
    }

    public function show(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->readActor($request);
        $booking = $this->bookingAccess->booking($actor, (int) $booking->getKey());
        abort_unless($booking, 404);
        $booking->load(['asset:id,name,asset_tag', 'user:id,name,email']);
        $maintenanceImpacts = DB::table('fleet_maintenance_booking_impacts as impact')
            ->join('fleet_maintenance_restrictions as restriction', 'restriction.id', '=', 'impact.restriction_id')
            ->join('fleet_work_orders as work', 'work.id', '=', 'impact.work_order_id')
            ->where('impact.booking_id', $booking->id)->where('impact.asset_id', $booking->asset_id)
            ->orderByDesc('impact.id')
            ->get(['impact.id', 'impact.followup_state', 'restriction.state as restriction_state',
                'work.id as work_order_id', 'work.reference_number']);

        return Inertia::render('fleet-assets/bookings/show', [
            'booking' => $booking,
            'maintenance_impacts' => $maintenanceImpacts,
            'can' => [
                'manage' => (bool) $request->user()?->canDo('fleet.manage'),
                'approve' => (bool) ($request->user()?->canDo('fleet.bookings.approve') || $request->user()?->canDo('fleet.manage')),
                'view_maintenance' => app(\App\Services\Fleet\MaintenanceAccessService::class)->canRead($actor),
            ],
        ]);
    }

    public function approve(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->approvalActor($request);
        $booking = DB::transaction(function () use ($request, $booking, $actor): FleetVehicleBooking {
            $currentActor = $this->freshApprovalActor($actor);
            $canonical = $this->lockBooking($currentActor, (int) $booking->getKey());
            $independent = $canonical->approval_route !== 'not_required';
            // Independent approval can never be self-approval. On the
            // approval-not-required route the authority holder confirms.
            abort_if($independent && $canonical->user_id === $currentActor->id, 403, 'Cannot approve your own booking.');
            if ($canonical->status === 'approved' && $this->wantsJson($request)) {
                return $canonical;
            }
            abort_unless($canonical->status === 'pending', 422, 'Only pending bookings can be approved.');
            $decision = $request->validate([
                'readiness_reviewed' => ['nullable', 'boolean'],
                'decision_notes' => ['nullable', 'string', 'max:2000'],
            ]);
            $asset = $this->bookingAccess->vehicle($currentActor, (int) $canonical->asset_id) ?? abort(404);
            $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
                purpose: 'booking_approval', driverUserId: $canonical->driverUserId(),
                startsAt: $canonical->starts_at, endsAt: $canonical->ends_at, bookingId: (int) $canonical->id,
            ), true);
            $this->readiness->assertCanProceed($assessment);

            $canonical->update(($independent ? [
                'status' => 'approved',
                'approved_by_user_id' => $currentActor->id,
                'approved_at' => now(),
            ] : [
                'status' => 'approved',
                'approval_authority_recorded_by' => $currentActor->id,
                'approval_authority_recorded_at' => now(),
                'approved_at' => now(),
            ]) + ['lock_version' => (int) $canonical->lock_version + 1]);

            AuditLogger::logOrFail('fleet.booking.approve', $canonical, [
                'booking_id' => $canonical->id,
                'approval_route' => $canonical->approval_route,
                'readiness' => $this->decisionEvidence($assessment),
                'readiness_reviewed' => (bool) ($decision['readiness_reviewed'] ?? false),
                'reason' => isset($decision['decision_notes']) ? trim((string) $decision['decision_notes']) : null,
            ], $request);

            return $canonical;
        });

        $booking->load(['asset:id,name', 'user']);
        if ($booking->wasChanged('status')) {
            $booking->user->notify(new FleetBookingApprovedNotification($booking));
        }
        $message = $booking->approval_route === 'not_required' ? 'Booking confirmed.' : 'Booking approved.';

        return $this->wantsJson($request)
            ? response()->json(['booking' => $this->bookingResult($booking), 'message' => $message])
            : back()->with('success', $message);
    }

    public function reject(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->approvalActor($request);
        $booking = DB::transaction(function () use ($request, $booking, $actor): FleetVehicleBooking {
            $canonical = $this->lockBooking($actor, (int) $booking->getKey());
            if ($canonical->status === 'rejected' && $this->wantsJson($request)
                && $canonical->rejection_reason === trim((string) $request->input('rejection_reason'))) {
                return $canonical;
            }
            abort_unless($canonical->status === 'pending', 422, 'Only pending bookings can be rejected.');

            // Validate action payload only after the scoped canonical row is
            // locked, preserving missing/foreign direct-ID concealment parity.
            $data = $request->validate([
                'rejection_reason' => ['required', 'string', 'max:500'],
            ]);

            $canonical->update([
                'status' => 'rejected',
                'rejection_reason' => trim($data['rejection_reason']),
                'lock_version' => (int) $canonical->lock_version + 1,
            ]);

            AuditLogger::logOrFail('fleet.booking.reject', $canonical, [
                'booking_id' => $canonical->id,
                'reason' => trim($data['rejection_reason']),
            ], $request);

            return $canonical;
        });

        $booking->load(['asset:id,name', 'user']);
        if ($booking->wasChanged('status')) {
            $booking->user->notify(new FleetBookingRejectedNotification($booking));
        }

        return $this->wantsJson($request)
            ? response()->json(['booking' => $this->bookingResult($booking), 'message' => 'Booking request declined.'])
            : back()->with('success', 'Booking rejected.');
    }

    public function checkout(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->managerActor($request);
        $booking = DB::transaction(function () use ($request, $booking, $actor): FleetVehicleBooking {
            $currentActor = $this->freshManagerActor($actor);
            $canonical = $this->lockBooking($currentActor, (int) $booking->getKey());
            if ($canonical->status === 'checked_out' && $this->wantsJson($request)
                && (float) $canonical->odometer_out === (float) $request->input('odometer_out')) {
                return $canonical;
            }
            abort_unless($canonical->status === 'approved', 422, 'Only approved bookings can be checked out.');

            $data = $request->validate([
                'odometer_out' => ['required', 'numeric', 'min:0'],
                'checkout_condition' => ['nullable', 'string', 'max:50'],
                'checkout_evidence_reference' => ['nullable', 'string', 'max:120'],
                'checkout_notes' => ['nullable', 'string', 'max:2000'],
                'keys_handed' => ['nullable', 'boolean'],
            ]);
            $asset = $this->bookingAccess->vehicle($currentActor, (int) $canonical->asset_id) ?? abort(404);
            $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
                purpose: 'checkout', driverUserId: $canonical->driverUserId(),
                startsAt: now(), endsAt: $canonical->ends_at,
                bookingId: (int) $canonical->id, candidateOdometerKm: (float) $data['odometer_out'],
            ), true);
            $this->readiness->assertCanProceed($assessment);
            $observation = $this->odometer->record($currentActor, (int) $canonical->asset_id, [
                'value_km' => $data['odometer_out'], 'observed_at' => now(),
                'source_kind' => 'booking_checkout', 'source_type' => 'fleet_vehicle_booking',
                'source_id' => (int) $canonical->id, 'source_reference' => $canonical->reference_number,
            ], 'booking-checkout-'.$canonical->id);
            $keyLog = ($data['keys_handed'] ?? false) ? $this->handOverKey($asset, $canonical) : null;

            $canonical->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
                'odometer_out' => $data['odometer_out'],
                'checked_out_by' => $currentActor->id,
                'checkout_condition' => $data['checkout_condition'] ?? null,
                'checkout_evidence_reference' => $data['checkout_evidence_reference'] ?? null,
                'checkout_notes' => $data['checkout_notes'] ?? null,
                'lock_version' => (int) $canonical->lock_version + 1,
            ]);

            AuditLogger::logOrFail('fleet.booking.checkout', $canonical, [
                'booking_id' => $canonical->id,
                'readiness' => $this->decisionEvidence($assessment),
                'odometer_observation_id' => $observation->id,
                'key_log_id' => $keyLog?->id,
                'reason' => $data['checkout_notes'] ?? null,
            ], $request);

            return $canonical;
        });

        return $this->wantsJson($request)
            ? response()->json(['booking' => $this->bookingResult($booking), 'message' => 'Vehicle checked out.'])
            : back()->with('success', 'Vehicle checked out.');
    }

    public function returnVehicle(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->managerActor($request);
        $booking = DB::transaction(function () use ($request, $booking, $actor): FleetVehicleBooking {
            $currentActor = $this->freshManagerActor($actor);
            $canonical = $this->lockBooking($currentActor, (int) $booking->getKey());
            if ($canonical->status === 'returned' && $this->wantsJson($request)
                && (float) $canonical->odometer_in === (float) $request->input('odometer_in')) {
                return $canonical;
            }
            abort_unless($canonical->status === 'checked_out', 422, 'Only checked-out bookings can be returned.');

            $data = $request->validate([
                'odometer_in' => ['nullable', 'numeric', 'min:0'],
                'condition_on_return' => ['nullable', 'string', 'max:50'],
                'return_notes' => ['nullable', 'string', 'max:1000'],
                'return_evidence_reference' => ['nullable', 'string', 'max:120'],
                'keys_received' => ['nullable', 'boolean'],
            ]);
            if (isset($data['odometer_in']) && $canonical->odometer_out !== null
                && (float) $data['odometer_in'] < (float) $canonical->odometer_out) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'odometer_in' => 'The return reading can\'t be lower than the checkout reading of '
                        .number_format((float) $canonical->odometer_out).' km.',
                ]);
            }

            $observation = isset($data['odometer_in'])
                ? $this->odometer->record($currentActor, (int) $canonical->asset_id, [
                    'value_km' => $data['odometer_in'], 'observed_at' => now(),
                    'source_kind' => 'booking_return', 'source_type' => 'fleet_vehicle_booking',
                    'source_id' => (int) $canonical->id, 'source_reference' => $canonical->reference_number,
                ], 'booking-return-'.$canonical->id)
                : null;

            $asset = $this->bookingAccess->vehicle($currentActor, (int) $canonical->asset_id) ?? abort(404);
            $keyLog = ($data['keys_received'] ?? false) ? $this->receiveKey($asset, $canonical) : null;

            $canonical->update([
                'status' => 'returned',
                'returned_at' => now(),
                'odometer_in' => $data['odometer_in'] ?? null,
                'condition_on_return' => $data['condition_on_return'] ?? null,
                'return_notes' => $data['return_notes'] ?? null,
                'return_evidence_reference' => $data['return_evidence_reference'] ?? null,
                'returned_by' => $currentActor->id,
                'lock_version' => (int) $canonical->lock_version + 1,
            ]);

            AuditLogger::logOrFail('fleet.booking.return', $canonical, [
                'booking_id' => $canonical->id,
                'odometer_observation_id' => $observation?->id,
                'keys_received' => (bool) ($data['keys_received'] ?? false),
                'key_log_id' => $keyLog?->id,
                'reason' => $data['return_notes'] ?? null,
            ], $request);

            return $canonical;
        });

        return $this->wantsJson($request)
            ? response()->json(['booking' => $this->bookingResult($booking), 'message' => 'Vehicle returned.'])
            : back()->with('success', 'Vehicle returned.');
    }

    public function cancel(Request $request, FleetVehicleBooking $booking)
    {
        $actor = $this->managerActor($request);
        $booking = DB::transaction(function () use ($request, $booking, $actor): FleetVehicleBooking {
            $canonical = $this->lockBooking($actor, (int) $booking->getKey());
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
            $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
            if ($canonical->status === 'cancelled' && $this->wantsJson($request)
                && $canonical->cancellation_reason === $reason) {
                return $canonical;
            }
            abort_unless(
                in_array($canonical->status, ['pending', 'approved', 'checked_out']),
                422,
                'This booking cannot be cancelled in its current state.'
            );

            $canonical->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason ?: null,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'lock_version' => (int) $canonical->lock_version + 1,
            ]);

            AuditLogger::logOrFail('fleet.booking.cancel', $canonical, [
                'booking_id' => $canonical->id,
                'reason' => $reason ?: null,
            ], $request);

            return $canonical;
        });

        return $this->wantsJson($request)
            ? response()->json(['booking' => $this->bookingResult($booking), 'message' => 'Booking cancelled.'])
            : back()->with('success', 'Booking cancelled.');
    }

    /**
     * Keys go to the booking's driver. Custody is taken from the latest key
     * record, as the Keys page does; keys already out stop the handover.
     */
    private function handOverKey(\App\Models\Asset $asset, FleetVehicleBooking $booking): FleetKeyLog
    {
        $siteId = (int) ($asset->site_id ?: $asset->home_site_id) ?: abort(409, 'This vehicle has no site for key custody.');
        $latest = FleetKeyLog::query()->where('asset_id', $asset->id)->where('site_id', $siteId)
            ->orderByDesc('id')->lockForUpdate()->first();
        if ($latest && $latest->action !== 'returned') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'keys_handed' => 'The key is recorded as out. Record its return on the Keys page before handing it over.',
            ]);
        }
        $log = FleetKeyLog::query()->create([
            'asset_id' => $asset->id, 'booking_id' => $booking->id, 'site_id' => $siteId,
            'user_id' => $booking->driverUserId(), 'action' => 'checked_out',
            'key_number' => $latest?->key_number, 'location' => 'with_driver',
            'notes' => 'Booking '.($booking->reference_number ?: '#'.$booking->id),
        ]);
        AuditLogger::logOrFail('fleet-assets.keys.checkout', $log, [
            'asset_id' => $asset->id, 'user_id' => $log->user_id, 'site_id' => $siteId, 'booking_id' => $booking->id,
        ]);

        return $log;
    }

    private function receiveKey(\App\Models\Asset $asset, FleetVehicleBooking $booking): ?FleetKeyLog
    {
        $siteId = (int) ($asset->site_id ?: $asset->home_site_id);
        if (! $siteId) {
            return null;
        }
        $latest = FleetKeyLog::query()->where('asset_id', $asset->id)->where('site_id', $siteId)
            ->orderByDesc('id')->lockForUpdate()->first();
        // Nothing to return when no handover was recorded; the receipt is kept in the audit.
        if (! $latest || $latest->action === 'returned') {
            return null;
        }
        $log = FleetKeyLog::query()->create([
            'asset_id' => $asset->id, 'booking_id' => $booking->id, 'site_id' => $siteId,
            'user_id' => $latest->transferred_to_user_id ?: $latest->user_id, 'action' => 'returned',
            'key_number' => $latest->key_number, 'location' => 'key_safe',
            'notes' => 'Returned with booking '.($booking->reference_number ?: '#'.$booking->id),
        ]);
        AuditLogger::logOrFail('fleet-assets.keys.return', $log, [
            'asset_id' => $asset->id, 'site_id' => $siteId, 'booking_id' => $booking->id,
        ]);

        return $log;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    private function requestKey(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 100);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{0:Carbon,1:Carbon}
     */
    private function window(array $data, bool $local): array
    {
        if ($local) {
            $starts = $this->localTime($data['starts_local'] ?? '', $data['starts_offset'] ?? null, 'starts_local');
            $ends = $this->localTime($data['ends_local'] ?? '', $data['ends_offset'] ?? null, 'ends_local');
            if (! $ends->greaterThan($starts)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ends_local' => 'The return must be after the pickup.']);
            }

            return [$starts, $ends];
        }

        return [Carbon::parse($data['starts_at'])->utc(), Carbon::parse($data['ends_at'])->utc()];
    }

    private function localTime(mixed $local, mixed $offset, string $field): Carbon
    {
        try {
            return Carbon::parse(MaintenanceLocalTime::toUtc((string) $local, $offset === null ? null : (string) $offset), 'UTC');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => collect($exception->errors())->flatten()->first()]);
        }
    }

    /** @return array<string,mixed> */
    private function bookingResult(FleetVehicleBooking $booking): array
    {
        return [
            'id' => (int) $booking->id,
            'reference' => $booking->reference_number,
            'status' => $booking->status,
            'lock_version' => (int) ($booking->lock_version ?? 1),
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
        ];
    }

    private function readActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        abort_unless($actor->canDo('fleet.viewAny') || $actor->canDo('assets.viewAny'), 403);

        return $actor;
    }

    private function approvalActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        abort_unless($actor->canDo('fleet.bookings.approve') || $actor->canDo('fleet.manage'), 403);

        return $actor;
    }

    private function managerActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->canDo('fleet.manage'), 403);

        return $actor;
    }

    private function freshApprovalActor(User $actor): User
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($current->canDo('fleet.bookings.approve') || $current->canDo('fleet.manage'), 403);

        return $current;
    }

    private function freshReadActor(User $actor): User
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($current->canDo('fleet.viewAny') || $current->canDo('assets.viewAny'), 403);

        return $current;
    }

    private function freshManagerActor(User $actor): User
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($current->canDo('fleet.manage'), 403);

        return $current;
    }

    /** @return array<string,mixed> */
    private function decisionEvidence(\App\Services\Fleet\Data\VehicleReadinessAssessment $assessment): array
    {
        return [
            'input_fingerprint' => $assessment->inputFingerprint,
            'compliance_version_ids' => $assessment->complianceVersionIds,
            'odometer_observation_id' => $assessment->odometerObservationId,
            'restriction_ids' => $assessment->restrictionIds,
            'check_run_ids' => $assessment->checkRunIds,
            'reason_codes' => array_map(fn ($reason) => $reason->code, $assessment->reasons),
        ];
    }

    /**
     * Re-resolve and lock canonical rows before any lifecycle or replay check.
     * Lock order is Asset -> booking; the route-bound model is an ID carrier
     * only and can never supply authoritative status or scope.
     */
    private function lockBooking(User $actor, int $bookingId): FleetVehicleBooking
    {
        $preview = $this->bookingAccess->booking($actor, $bookingId);
        abort_unless($preview, 404);

        abort_unless($this->bookingAccess->vehicle($actor, (int) $preview->asset_id, true), 404);

        $booking = $this->bookingAccess->booking($actor, $bookingId, true);
        abort_unless($booking, 404);

        return $booking;
    }
}
