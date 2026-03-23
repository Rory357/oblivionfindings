<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetVehicleBooking;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InspectionController extends Controller
{
    /**
     * List all vehicle inspections (checklist runs whose template name contains 'inspection').
     */
    public function index(Request $request)
    {
        $query = FleetChecklistRun::query()
            ->with(['template:id,name,type', 'asset:id,name,registration_number', 'user:id,name'])
            ->whereHas('template', function ($q) {
                $q->where('name', 'like', '%inspection%')
                    ->orWhere('type', 'inspection');
            });

        // Filters
        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', $request->input('vehicle_id'));
        }

        if ($request->filled('result')) {
            $query->where('passed', $request->input('result') === 'pass');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('completed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('completed_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $inspections = $query
            ->latest('completed_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'type' => $this->resolveInspectionType($r),
                'asset' => $r->asset ? [
                    'id' => $r->asset->id,
                    'name' => $r->asset->name,
                    'registration_number' => $r->asset->registration_number ?? null,
                ] : null,
                'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                'passed' => $r->passed,
                'notes' => $r->notes,
                'odometer' => $r->responses['odometer'] ?? null,
                'overall_condition' => $r->responses['overall_condition'] ?? null,
                'completed_at' => optional($r->completed_at)->toISOString(),
                'created_at' => optional($r->created_at)->toISOString(),
            ])
            ->values();

        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
            ->values();

        return Inertia::render('fleet-assets/inspections/index', [
            'inspections' => $inspections,
            'vehicles' => $vehicles,
            'filters' => $request->only(['search', 'vehicle_id', 'result', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Show the inspection creation form.
     */
    public function create(Request $request)
    {
        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name', 'registration_number'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'registration_number' => $a->registration_number ?? null,
            ])
            ->values();

        // If booking_id provided, load pre-trip inspection for comparison
        $preTrip = null;
        $booking = null;
        if ($request->filled('booking_id')) {
            $booking = FleetVehicleBooking::with('asset:id,name,registration_number')->find($request->input('booking_id'));
            if ($booking) {
                $preTrip = FleetChecklistRun::query()
                    ->where('asset_id', $booking->asset_id)
                    ->whereHas('template', fn ($q) => $q->where('type', 'inspection'))
                    ->whereJsonContains('responses->inspection_type', 'pre-trip')
                    ->where('completed_at', '>=', $booking->checked_out_at ?? $booking->starts_at)
                    ->latest('completed_at')
                    ->first();
            }
        }

        return Inertia::render('fleet-assets/inspections/create', [
            'vehicles' => $vehicles,
            'preselected_asset_id' => $request->input('asset_id') ?? $booking?->asset_id,
            'preselected_type' => $request->input('type', 'pre-trip'),
            'booking_id' => $request->input('booking_id'),
            'booking' => $booking ? [
                'id' => $booking->id,
                'asset_id' => $booking->asset_id,
                'purpose' => $booking->purpose,
            ] : null,
            'pre_trip_results' => $preTrip ? [
                'id' => $preTrip->id,
                'passed' => $preTrip->passed,
                'odometer' => $preTrip->responses['odometer'] ?? null,
                'overall_condition' => $preTrip->responses['overall_condition'] ?? null,
                'completed_at' => optional($preTrip->completed_at)->toISOString(),
                'checklist' => collect($preTrip->responses ?? [])
                    ->except(['odometer', 'overall_condition', 'inspection_type'])
                    ->toArray(),
            ] : null,
        ]);
    }

    /**
     * Store a new inspection as a FleetChecklistRun.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'inspection_type' => ['required', 'string', 'in:pre-trip,post-trip'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'overall_condition' => ['required', 'string', 'in:good,fair,poor'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'checklist' => ['required', 'array'],
            'checklist.*.result' => ['required', 'string', 'in:pass,fail,na'],
            'checklist.*.notes' => ['nullable', 'string', 'max:1000'],
            'booking_id' => ['nullable', 'integer', 'exists:fleet_vehicle_bookings,id'],
            'fuel_level_return' => ['nullable', 'string'],
            'items_left' => ['nullable', 'string', 'max:1000'],
            'new_damage' => ['nullable', 'string', 'max:2000'],
        ]);

        // Find or create the inspection template
        $template = FleetChecklistTemplate::firstOrCreate(
            ['type' => 'inspection', 'name' => 'Vehicle Inspection'],
            [
                'items' => $this->defaultInspectionItems(),
                'is_active' => true,
            ]
        );

        // Determine pass/fail: fail if any checklist item is 'fail'
        $hasFail = collect($data['checklist'])->contains(fn ($item) => ($item['result'] ?? '') === 'fail');

        // Build responses: merge checklist items + meta fields
        $responses = $data['checklist'];
        $responses['odometer'] = $data['odometer'] ?? null;
        $responses['overall_condition'] = $data['overall_condition'];
        $responses['inspection_type'] = $data['inspection_type'];
        if (isset($data['booking_id'])) {
            $responses['booking_id'] = $data['booking_id'];
        }
        if (isset($data['fuel_level_return'])) {
            $responses['fuel_level_return'] = $data['fuel_level_return'];
        }
        if (isset($data['items_left'])) {
            $responses['items_left'] = $data['items_left'];
        }
        if (isset($data['new_damage'])) {
            $responses['new_damage'] = $data['new_damage'];
        }

        $run = FleetChecklistRun::create([
            'template_id' => $template->id,
            'asset_id' => $data['asset_id'],
            'user_id' => $request->user()->id,
            'responses' => $responses,
            'notes' => $data['notes'] ?? null,
            'passed' => !$hasFail,
            'completed_at' => now(),
        ]);

        AuditLogger::log('fleet.inspection.create', $run, [
            'asset_id' => $data['asset_id'],
            'type' => $data['inspection_type'],
            'passed' => !$hasFail,
        ]);

        return redirect()
            ->route('fleet-assets.inspections.show', $run)
            ->with('success', 'Inspection submitted successfully.');
    }

    /**
     * Show a completed inspection.
     */
    public function show(FleetChecklistRun $run)
    {
        $run->load(['template:id,name,type', 'asset:id,name,registration_number', 'user:id,name']);

        $responses = $run->responses ?? [];

        return Inertia::render('fleet-assets/inspections/show', [
            'inspection' => [
                'id' => $run->id,
                'type' => $responses['inspection_type'] ?? $this->resolveInspectionType($run),
                'asset' => $run->asset ? [
                    'id' => $run->asset->id,
                    'name' => $run->asset->name,
                    'registration_number' => $run->asset->registration_number ?? null,
                ] : null,
                'user' => $run->user ? ['id' => $run->user->id, 'name' => $run->user->name] : null,
                'passed' => $run->passed,
                'notes' => $run->notes,
                'odometer' => $responses['odometer'] ?? null,
                'overall_condition' => $responses['overall_condition'] ?? null,
                'responses' => collect($responses)
                    ->except(['odometer', 'overall_condition', 'inspection_type'])
                    ->toArray(),
                'completed_at' => optional($run->completed_at)->toISOString(),
                'created_at' => optional($run->created_at)->toISOString(),
            ],
        ]);
    }

    /**
     * Resolve inspection type from the run's responses or template.
     */
    private function resolveInspectionType(FleetChecklistRun $run): string
    {
        return $run->responses['inspection_type']
            ?? ($run->template?->name ? (str_contains(strtolower($run->template->name), 'pre') ? 'pre-trip' : 'inspection') : 'inspection');
    }

    /**
     * Default inspection template items for auto-creation.
     */
    private function defaultInspectionItems(): array
    {
        return [
            ['label' => 'Tyres - Condition & Pressure', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Lights - Front', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Lights - Rear', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Body Damage', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Windscreen', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Mirrors', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Number Plates', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Seatbelts', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Horn', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Wipers', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Dashboard Warnings', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Cleanliness', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'First Aid Kit', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Oil Level', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Coolant Level', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Brake Fluid', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
            ['label' => 'Battery', 'type' => 'select', 'options' => ['pass', 'fail', 'na'], 'required' => true],
        ];
    }
}
