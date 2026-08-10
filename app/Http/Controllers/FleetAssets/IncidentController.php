<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ClientIncident;
use App\Models\FleetIncident;
use App\Models\FleetResidentTransport;
use App\Models\SafeguardingAlert;
use App\Models\User;
use App\Notifications\Fleet\FleetIncidentReportedNotification;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('fleet_incidents')) {
            $vehicles = Asset::query()
                ->where('category', 'vehicle')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
                ->values();

            return Inertia::render('fleet-assets/incidents/index', [
                'incidents' => collect(),
                'vehicles' => $vehicles,
                'filters' => $request->only(['vehicle_id', 'severity', 'incident_type', 'status', 'date_from', 'date_to']),
                'stats' => [
                    'total_mtd' => 0,
                    'open_investigations' => 0,
                    'unresolved' => 0,
                    'insurance_claims' => 0,
                ],
            ]);
        }

        $query = FleetIncident::query()
            ->with([
                'asset:id,name,registration_number',
                'reportedBy:id,name',
                'driver:id,name',
            ]);

        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', $request->input('vehicle_id'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('incident_type')) {
            $query->where('incident_type', $request->input('incident_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('occurred_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('occurred_at', '<=', $request->input('date_to'));
        }

        // CSV export
        if ($request->input('export') === 'csv') {
            $all = (clone $query)->latest('occurred_at')->limit(5000)->get();
            return response()->streamDownload(function () use ($all) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Date', 'Vehicle', 'Type', 'Severity', 'Location', 'Reported By', 'Driver', 'Status', 'Description', 'Police Ref', 'Insurance Ref']);
                foreach ($all as $i) {
                    fputcsv($handle, [
                        optional($i->occurred_at)->format('Y-m-d H:i') ?? '',
                        $i->asset?->name ?? '',
                        $i->incident_type,
                        $i->severity,
                        $i->location ?? '',
                        $i->reportedBy?->name ?? '',
                        $i->driver?->name ?? '',
                        $i->status,
                        $i->description,
                        $i->police_reference ?? '',
                        $i->insurance_reference ?? '',
                    ]);
                }
                fclose($handle);
            }, 'fleet-incidents-' . now()->format('Y-m-d') . '.csv');
        }

        $paginator = $query
            ->latest('occurred_at')
            ->paginate(25)
            ->withQueryString();

        $incidents = [
            'data' => $paginator->getCollection()->map(fn ($i) => [
                'id' => $i->id,
                'asset' => $i->asset ? [
                    'id' => $i->asset->id,
                    'name' => $i->asset->name,
                    'registration_number' => $i->asset->registration_number,
                ] : null,
                'reported_by' => $i->reportedBy ? ['id' => $i->reportedBy->id, 'name' => $i->reportedBy->name] : null,
                'driver' => $i->driver ? ['id' => $i->driver->id, 'name' => $i->driver->name] : null,
                'incident_type' => $i->incident_type,
                'severity' => $i->severity,
                'occurred_at' => optional($i->occurred_at)->toISOString(),
                'location' => $i->location,
                'status' => $i->status,
                'police_notified' => $i->police_notified,
                'insurance_claimed' => $i->insurance_claimed,
            ])->values(),
            'links' => $paginator->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];

        // Summary stats
        $mtdStart = now()->startOfMonth();
        $totalMtd = FleetIncident::where('occurred_at', '>=', $mtdStart)->count();
        $openInvestigations = FleetIncident::where('status', 'investigating')->count();
        $unresolved = FleetIncident::whereIn('status', ['reported', 'investigating'])->count();
        $insuranceClaims = FleetIncident::where('insurance_claimed', true)->whereIn('status', ['reported', 'investigating'])->count();

        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
            ->values();

        return Inertia::render('fleet-assets/incidents/index', [
            'incidents' => $incidents,
            'vehicles' => $vehicles,
            'filters' => $request->only(['vehicle_id', 'severity', 'incident_type', 'status', 'date_from', 'date_to']),
            'stats' => [
                'total_mtd' => $totalMtd,
                'open_investigations' => $openInvestigations,
                'unresolved' => $unresolved,
                'insurance_claims' => $insuranceClaims,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name', 'registration_number'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'registration_number' => $a->registration_number,
            ])
            ->values();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return Inertia::render('fleet-assets/incidents/create', [
            'vehicles' => $vehicles,
            'users' => $users,
            'preselected_asset_id' => $request->input('asset_id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'incident_type' => ['required', 'string', 'in:collision,damage,theft,vandalism,breakdown,near_miss,other'],
            'severity' => ['required', 'string', 'in:minor,moderate,major,critical'],
            'occurred_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:10000'],
            'damage_details' => ['nullable', 'array'],
            'damage_details.areas' => ['nullable', 'array'],
            'damage_details.estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'police_notified' => ['boolean'],
            'police_reference' => ['nullable', 'string', 'max:100'],
            'insurance_claimed' => ['boolean'],
            'insurance_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $incident = FleetIncident::create([
            'asset_id' => $data['asset_id'],
            'reported_by_user_id' => $request->user()->id,
            'driver_user_id' => $data['driver_user_id'] ?? null,
            'incident_type' => $data['incident_type'],
            'severity' => $data['severity'],
            'occurred_at' => $data['occurred_at'],
            'location' => $data['location'] ?? null,
            'description' => $data['description'],
            'damage_details' => $data['damage_details'] ?? null,
            'police_notified' => $data['police_notified'] ?? false,
            'police_reference' => $data['police_reference'] ?? null,
            'insurance_claimed' => $data['insurance_claimed'] ?? false,
            'insurance_reference' => $data['insurance_reference'] ?? null,
            'status' => 'reported',
        ]);

        AuditLogger::log('fleet.incident.create', $incident, [
            'asset_id' => $data['asset_id'],
            'incident_type' => $data['incident_type'],
            'severity' => $data['severity'],
        ]);

        // Emit fleet signal for critical/major incidents → feeds control room
        if (in_array($data['severity'], ['critical', 'major'])) {
            app(\App\Services\Fleet\FleetSignalService::class)->emit([
                'asset_id' => $data['asset_id'],
                'signal_type' => 'incident.reported',
                'severity_hint' => $data['severity'] === 'critical' ? 'critical' : 'high',
                'occurred_at' => $data['occurred_at'],
                'payload' => [
                    'incident_id' => $incident->id,
                    'incident_type' => $data['incident_type'],
                    'description' => \Illuminate\Support\Str::limit($data['description'], 200),
                ],
            ]);
        }

        // Notify fleet managers
        $incident->load('asset:id,name');
        User::whereHas('roles', function ($q) {
            $q->whereHas('permissions', fn ($p) => $p->where('key', 'fleet.incidents.manage'));
        })->get()->each->notify(new FleetIncidentReportedNotification($incident));

        // Incident chain: auto-create client incidents & safeguarding alerts
        $this->processIncidentChain($incident, $request);

        return redirect()
            ->route('fleet-assets.incidents.show', $incident)
            ->with('success', 'Incident reported successfully.');
    }

    public function show(FleetIncident $incident)
    {
        $incident->load([
            'asset:id,name,registration_number',
            'reportedBy:id,name',
            'driver:id,name',
            'booking:id,purpose,starts_at,ends_at',
        ]);

        return Inertia::render('fleet-assets/incidents/show', [
            'incident' => [
                'id' => $incident->id,
                'asset' => $incident->asset ? [
                    'id' => $incident->asset->id,
                    'name' => $incident->asset->name,
                    'registration_number' => $incident->asset->registration_number,
                ] : null,
                'reported_by' => $incident->reportedBy ? [
                    'id' => $incident->reportedBy->id,
                    'name' => $incident->reportedBy->name,
                ] : null,
                'driver' => $incident->driver ? [
                    'id' => $incident->driver->id,
                    'name' => $incident->driver->name,
                ] : null,
                'booking' => $incident->booking ? [
                    'id' => $incident->booking->id,
                    'purpose' => $incident->booking->purpose,
                ] : null,
                'incident_type' => $incident->incident_type,
                'severity' => $incident->severity,
                'occurred_at' => optional($incident->occurred_at)->toISOString(),
                'location' => $incident->location,
                'description' => $incident->description,
                'damage_details' => $incident->damage_details,
                'police_notified' => $incident->police_notified,
                'police_reference' => $incident->police_reference,
                'insurance_claimed' => $incident->insurance_claimed,
                'insurance_reference' => $incident->insurance_reference,
                'status' => $incident->status,
                'resolution_notes' => $incident->resolution_notes,
                'resolved_at' => optional($incident->resolved_at)->toISOString(),
                'created_at' => optional($incident->created_at)->toISOString(),
            ],
            'can' => [
                'manage' => $incident->exists && ($this->getUserCanManage($incident) ?? false),
            ],
        ]);
    }

    private function getUserCanManage(FleetIncident $incident): bool
    {
        $user = request()->user();

        if (!$user) {
            return false;
        }

        return (bool) $user->canDo('fleet.manage')
            || (bool) $user->canDo('fleet.incidents.manage');
    }

    public function update(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:reported,investigating,resolved,closed'],
            'resolution_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $updates = [
            'status' => $data['status'],
            'resolution_notes' => $data['resolution_notes'] ?? $incident->resolution_notes,
        ];

        if (in_array($data['status'], ['resolved', 'closed']) && !$incident->resolved_at) {
            $updates['resolved_at'] = now();
        }

        $previousStatus = $incident->status;
        $incident->update($updates);

        AuditLogger::log('fleet.incident.update', $incident, [
            'status' => $data['status'],
            'previous_status' => $previousStatus,
        ]);

        // Emit fleet signal for critical/major incidents on status change
        if (in_array($incident->severity, ['critical', 'major']) && $previousStatus !== $data['status']) {
            app(\App\Services\Fleet\FleetSignalService::class)->emit([
                'asset_id' => $incident->asset_id,
                'signal_type' => 'incident.' . $data['status'],
                'severity_hint' => $incident->severity === 'critical' ? 'critical' : 'high',
                'occurred_at' => now(),
                'payload' => [
                    'incident_id' => $incident->id,
                    'incident_type' => $incident->incident_type,
                    'previous_status' => $previousStatus,
                    'new_status' => $data['status'],
                ],
            ]);
        }

        return back()->with('success', 'Incident updated successfully.');
    }

    /**
     * Process incident chain logic:
     * - Auto-create ClientIncident for each resident on the transport
     * - Auto-create SafeguardingAlert if severity >= major
     */
    private function processIncidentChain(FleetIncident $incident, Request $request): void
    {
        // Only trigger chain if incident is linked to a booking
        if (!$incident->booking_id) {
            return;
        }

        // Check for resident transports linked to this booking
        if (!Schema::hasTable('fleet_resident_transports')) {
            return;
        }

        $transports = FleetResidentTransport::where('booking_id', $incident->booking_id)->get();

        if ($transports->isEmpty()) {
            return;
        }

        // Map severity from fleet incident to client incident
        $clientSeverity = match ($incident->severity) {
            'critical' => 'high',
            'major' => 'high',
            'moderate' => 'medium',
            default => 'low',
        };

        // Auto-create ClientIncident for each resident on that transport
        if (Schema::hasTable('client_incidents')) {
            foreach ($transports as $transport) {
                $clientId = $transport->resident_id;
                if (!$clientId) {
                    continue;
                }

                try {
                    $clientIncident = ClientIncident::create([
                        'client_id' => $clientId,
                        'reported_by' => $request->user()->id,
                        'type' => 'transport_incident',
                        'severity' => $clientSeverity,
                        'status' => 'submitted',
                        'occurred_at' => $incident->occurred_at,
                        'description' => $incident->description,
                        'location' => $incident->location,
                        'title' => "Transport Incident: {$incident->incident_type}",
                    ]);

                    AuditLogger::log('fleet.incident.chain.client_incident', $clientIncident, [
                        'fleet_incident_id' => $incident->id,
                        'client_id' => $clientId,
                        'auto_created' => true,
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        // Auto-create SafeguardingAlert if severity >= major
        if (in_array($incident->severity, ['major', 'critical']) && Schema::hasTable('safeguarding_alerts')) {
            // Create one alert per resident on the transport
            foreach ($transports as $transport) {
                $clientId = $transport->resident_id;
                if (!$clientId) {
                    continue;
                }

                try {
                    $alert = SafeguardingAlert::create([
                        'alertable_type' => 'App\\Models\\Client',
                        'alertable_id' => $clientId,
                        'alert_type' => 'transport_incident',
                        'alert_summary' => "Transport incident ({$incident->severity}): {$incident->incident_type}",
                        'alert_details' => $incident->description,
                        'severity' => $incident->severity,
                        'active' => true,
                        'created_by' => $request->user()->id,
                    ]);

                    AuditLogger::log('fleet.incident.chain.safeguarding_alert', $alert, [
                        'fleet_incident_id' => $incident->id,
                        'client_id' => $clientId,
                        'auto_created' => true,
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
