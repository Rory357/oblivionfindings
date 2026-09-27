<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\SiteGeocodingController;
use App\Models\AppSetting;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\Fleet\MaintenanceLocalTime;
use App\Services\Fleet\TransportRequestService;
use App\Services\Fleet\TransportWorkspacePresenter;
use App\Services\Fleet\VehicleBookingAccessService;
use App\Services\Fleet\VehicleCalendarService;
use App\Services\Fleet\VehicleReadinessService;
use App\Services\Fleet\VehicleStaffDirectory;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransportWorkspaceController extends Controller
{
    public function __construct(private readonly TransportRequestService $requests,
        private readonly TransportWorkspacePresenter $presenter, private readonly VehicleBookingAccessService $access) {}

    public function index(Request $request, string $view = 'overview')
    {
        $filters = $this->filters($request);
        $calendarAnchor = $request->input('day', $filters['from']);
        if ($view === 'calendar') {
            $calendarView = $request->validate(['queue' => 'nullable|in:month,week,day,agenda,timeline'])['queue'] ?? 'week';
            $day = CarbonImmutable::parse($calendarAnchor, 'Pacific/Auckland');
            // Match the shared calendar's Sunday-first grid, including its six-week month range.
            $start = match ($calendarView) {
                'day' => $day,
                'week' => $day->startOfWeek(CarbonImmutable::SUNDAY),
                default => $day->startOfMonth()->startOfWeek(CarbonImmutable::SUNDAY),
            };
            $filters['from'] = $start->toDateString();
            $filters['to'] = $start->addDays(match ($calendarView) {
                'day' => 0, 'week' => 6, default => 41,
            })->toDateString();
        }
        $actor = $request->user();
        $rows = $this->presenter->records($actor, [...$filters, 'calendar' => $view === 'calendar']);
        $calendar = [];
        if ($view === 'calendar') {
            $start = CarbonImmutable::parse($filters['from'], 'Pacific/Auckland')->startOfDay()->utc();
            $end = CarbonImmutable::parse($filters['to'], 'Pacific/Auckland')->addDay()->startOfDay()->utc();
            foreach ($this->access->activeVehicles($actor) as $vehicle) {
                if ($filters['site'] !== 'all' && (int) ($vehicle->site_id ?: $vehicle->home_site_id) !== (int) $filters['site']) {
                    continue;
                }
                foreach (app(VehicleCalendarService::class)->events($actor, $vehicle, $start, $end) as $event) {
                    $linked = collect($rows)->first(fn ($row) => ($row['booking']['id'] ?? null) === ($event['recordId'] ?? null) && ($event['kind'] ?? '') === 'booking');
                    if ($filters['search'] !== '' && ! $linked && ! str_contains(mb_strtolower($vehicle->name.' '.$event['title']), mb_strtolower($filters['search']))) {
                        continue;
                    }
                    $calendar[] = [...$event, 'id' => $vehicle->id.'-'.$event['id'], 'vehicle_id' => $vehicle->id, 'vehicle_name' => $vehicle->name];
                }
            }
        }

        return Inertia::render('fleet-assets/transports/workspace', ['view' => $view, 'records' => $rows,
            'filters' => $filters, 'sites' => $this->access->sites($actor), 'calendar' => $calendar,
            'canManage' => $actor->canDo('fleet.manage'), 'generatedAt' => now()->toIso8601String(), 'calendarAnchor' => $calendarAnchor]);
    }

    public function show(Request $request, int $transportRequest)
    {
        $record = $this->presenter->row($request->user(), $this->requests->find($request->user(), $transportRequest), true);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($record)->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('fleet-assets/transports/record', ['record' => $record]);
    }

    public function store(Request $request)
    {
        $request->merge(['command_key' => $request->input('command_key') ?: $request->header('Idempotency-Key')]);
        $row = $this->requests->create($request->user(), $request->all());

        return response()->json(['id' => $row->id, 'message' => 'Transport requested. It is ready for assessment.']);
    }

    public function command(Request $request, int $transportRequest)
    {
        $request->merge(['command_key' => $request->input('command_key') ?: $request->header('Idempotency-Key')]);
        $row = $this->requests->command($request->user(), $transportRequest, $request->all());

        return response()->json(['id' => $row->id, 'version' => $row->lock_version, 'message' => 'Transport record saved.']);
    }

    public function options(Request $request)
    {
        $actor = $request->user();
        if (! $request->filled('transport_request_id')) {
            return response()->json(['clients' => $this->access->clients($actor)->map(fn ($c) => ['id' => $c->id,
                'name' => $c->full_name, 'site_id' => $c->site_id, 'can_request' => $actor->canDo('fleet.manage') || $actor->can('update', $c)]),
                'locations' => Site::whereIn('id', $this->access->sites($actor)->pluck('id'))->orderBy('name')
                    ->get(['id', 'name', 'address_line_1', 'address_line_2', 'suburb', 'city', 'postcode'])
                    ->map(fn ($site) => ['id' => $site->id, 'name' => $site->name,
                        'address' => implode(', ', array_filter([$site->address_line_1, $site->address_line_2, $site->suburb, $site->city, $site->postcode]))]),
            ])->header('Cache-Control', 'no-store, private');
        }
        $row = $this->requests->find($actor, $request->integer('transport_request_id'));
        $data = $request->validate(['starts_local' => 'required|string', 'ends_local' => 'required|string',
            'starts_offset' => 'nullable|string', 'ends_offset' => 'nullable|string', 'driver_user_id' => 'nullable|integer',
            'asset_id' => 'nullable|integer', 'escort_user_id' => 'nullable|integer', 'search' => 'nullable|string|max:120']);
        $start = CarbonImmutable::parse(MaintenanceLocalTime::toUtc($data['starts_local'], $data['starts_offset'] ?? null), 'UTC');
        $end = CarbonImmutable::parse(MaintenanceLocalTime::toUtc($data['ends_local'], $data['ends_offset'] ?? null), 'UTC');
        abort_unless($end->gt($start) && $start->diffInDays($end) <= 31, 422, 'Choose a transport window of at most 31 days.');
        $vehicles = $this->access->activeVehicles($actor)->filter(fn ($asset) => (int) ($asset->site_id ?: $asset->home_site_id) === (int) $row->client->site_id
            && (! $asset->client_id || (int) $asset->client_id === (int) $row->client_id));
        if ($request->filled('asset_id')) {
            abort_unless($vehicles->contains('id', $request->integer('asset_id')), 404);
        }

        return response()->json(['vehicles' => $vehicles->map(function ($asset) use ($row, $start, $end, $data) {
            $assessment = app(VehicleReadinessService::class)->assess($asset, new VehicleReadinessContext(
                purpose: 'booking_request', driverUserId: $data['driver_user_id'] ?? null, startsAt: $start, endsAt: $end, bookingId: $row->fleet_booking_id));

            return ['id' => $asset->id, 'name' => $asset->name, 'registration' => $asset->registration_number,
                'seats' => $asset->seating_capacity, 'wheelchair' => (bool) $asset->has_wheelchair_ramp,
                'fits' => $row->required_seats && $asset->seating_capacity >= $row->required_seats && $row->wheelchair_required !== null && (! $row->wheelchair_required || $asset->has_wheelchair_ramp),
                'readiness' => $assessment->toArray(),
                'staff' => isset($data['asset_id']) && (int) $data['asset_id'] === (int) $asset->id
                    ? app(VehicleStaffDirectory::class)->candidates($asset, $data['search'] ?? null, array_filter([$row->driver_id, $row->escort_user_id, $data['driver_user_id'] ?? null, $data['escort_user_id'] ?? null])) : []];
        })->values(), 'rooms' => SiteRoom::where('site_id', $row->client->site_id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function addressSearch(Request $request)
    {
        $data = $request->validate(['client_id' => 'required|integer', 'q' => 'required|string|min:3|max:200']);
        $authorize = function () use ($request, $data): void {
            $actor = $request->user()->fresh() ?? abort(403);
            $client = $this->access->client($actor, (int) $data['client_id']) ?? abort(404);
            abort_unless($actor->canDo('fleet.manage') || $actor->can('update', $client), 403);
        };
        $authorize();
        try {
            // Only the explicitly submitted place query reaches the shared provider.
            // Client identity, passenger needs and transport notes never leave the app.
            $lookup = Request::create('/search', 'GET', ['q' => $data['q']]);
            $response = app(SiteGeocodingController::class)->search($lookup, true);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Address search is unavailable. Try again, choose a saved site, or enter the location manually.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }
        $authorize();

        return $response->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $selection = $request->validate(['view' => 'nullable|in:overview,requests,planner,calendar,journeys,returns', 'queue' => 'nullable|string|max:40', 'stages' => 'nullable|string|max:240']);
        $rows = $request->filled('request_id')
            ? [$this->presenter->row($request->user(), $this->requests->find($request->user(), $request->integer('request_id')), true)]
            : $this->presenter->records($request->user(), [...$filters, 'calendar' => ($selection['view'] ?? '') === 'calendar']);
        if (! $request->filled('request_id')) {
            $rows = $this->presenter->selected($rows, $selection['view'] ?? 'overview', $selection['queue'] ?? 'all', array_values(array_filter(explode(',', $selection['stages'] ?? ''))));
        }
        $filters['selection'] = $request->filled('request_id') ? $rows[0]['reference'].' · Full record'
            : ucfirst($selection['view'] ?? 'Transport').' · '.str_replace('_', ' ', $selection['queue'] ?? 'all');
        $filters['view'] = $request->filled('request_id') ? 'record' : ($selection['view'] ?? 'overview');
        $filters['site_name'] = $filters['site'] === 'all' ? 'All permitted sites'
            : collect($this->access->sites($request->user()))->firstWhere('id', (int) $filters['site'])['name'] ?? 'Selected site';
        $name = AppSetting::where('key', 'branding.name')->value('value');
        $response = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])
            ->loadView('pdf.transport-workspace', ['records' => $rows, 'filters' => $filters,
                'brand' => is_string($name) ? $name : config('app.name'), 'generatedBy' => $request->user()->name,
                'generatedAt' => now()->timezone('Pacific/Auckland')->format('j M Y, g:i a')])
            ->setPaper('a4', 'landscape')->download('transport-'.$filters['from'].'.pdf');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'day' => 'nullable|date_format:Y-m-d', 'site' => ['nullable', 'regex:/^(all|[1-9][0-9]*)$/'], 'search' => 'nullable|string|max:200']);
        $from = $data['from'] ?? $data['day'] ?? now('Pacific/Auckland')->toDateString();
        $to = $data['to'] ?? $from;
        abort_unless($to >= $from && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) <= 92, 422, 'Choose a date range of up to 93 days.');
        $site = $data['site'] ?? 'all';
        if ($site !== 'all') {
            abort_unless(in_array((int) $site, $this->access->accessibleSiteIds($request->user()), true), 404);
        }

        return ['from' => $from, 'to' => $to, 'site' => $site, 'search' => $data['search'] ?? ''];
    }
}
