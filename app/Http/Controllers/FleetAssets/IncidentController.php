<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ClientIncident;
use App\Models\FleetIncident;
use App\Models\FleetIncidentAttachment;
use App\Models\FleetIncidentFollowup;
use App\Models\FleetResidentTransport;
use App\Models\SafeguardingAlert;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Fleet\FleetIncidentReportedNotification;
use App\Services\AuditLogger;
use App\Services\HealthSafety\NotifiableEventClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentController extends Controller
{
    use RespondsToInertiaOrJson;
    use ServesPrivateAttachments;

    /** Tab → model scope (worklist views). */
    private const TAB_SCOPES = [
        'open' => 'open',
        'under_investigation' => 'underInvestigation',
        'police_report_due' => 'policeReportDue',
        'injury_acc' => 'injuryAcc',
        'insurance_claims' => 'insuranceClaims',
        'off_road' => 'offRoad',
        'near_misses' => 'nearMisses',
        'closed' => 'closed',
    ];

    public function index(Request $request)
    {
        $filterKeys = ['vehicle_id', 'driver_id', 'site_id', 'severity', 'incident_type', 'status', 'search', 'date_from', 'date_to', 'tab'];

        if (! Schema::hasTable('fleet_incidents')) {
            return Inertia::render('fleet-assets/incidents/index', [
                'incidents' => ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]],
                'filters' => $request->only($filterKeys),
                'tab' => $request->input('tab', 'all'),
                'tabCounts' => [],
                'stats' => $this->emptyStats(),
                'formOptions' => $this->formOptions(),
                'can' => ['manage' => $this->userCanManage()],
                'detail' => null,
                'report' => $request->input('report'),
            ]);
        }

        // Base query with every filter EXCEPT the tab (so hero/tab counts reflect scope).
        $base = $this->applyFilters(FleetIncident::query(), $request);

        // CSV export honours the active filters.
        if ($request->input('export') === 'csv') {
            return $this->exportCsv($base);
        }

        $tab = $request->input('tab', 'all');
        $incidentParam = $request->filled('incident') ? (int) $request->input('incident') : null;

        // Expensive props are closures so a `detail`-only partial reload (opening the
        // modal over the list) doesn't recompute the list / stats / counts.
        return Inertia::render('fleet-assets/incidents/index', [
            'incidents' => fn () => $this->buildListPayload((clone $base), $tab),
            'tab' => $tab,
            'tabCounts' => fn () => $this->tabCounts((clone $base)),
            'stats' => fn () => $this->stats((clone $base)),
            'filters' => $request->only($filterKeys),
            'formOptions' => fn () => $this->formOptions(),
            'can' => ['manage' => $this->userCanManage()],
            'detail' => function () use ($incidentParam) {
                if (! $incidentParam) {
                    return null;
                }
                $found = FleetIncident::find($incidentParam);

                return $found ? $this->buildDetailPayload($found) : null;
            },
            'report' => $request->input('report'),
        ]);
    }

    /** @return array{data: mixed, links: array, meta: array} */
    private function buildListPayload($base, string $tab): array
    {
        $listQuery = $base
            ->with([
                'asset:id,name,registration_number,category',
                'reportedBy:id,name',
                'driver:id,name',
            ])
            ->withCount(['attachments', 'followups']);

        if (isset(self::TAB_SCOPES[$tab])) {
            $listQuery->{self::TAB_SCOPES[$tab]}();
        }

        $paginator = $listQuery->latest('occurred_at')->paginate(25)->withQueryString();

        return [
            'data' => $paginator->getCollection()->map(fn (FleetIncident $i) => $this->rowPayload($i))->values(),
            'links' => $paginator->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function create(Request $request)
    {
        // Legacy full-page create is retired in Step 5 (redirects to the list +
        // opens the wizard). Kept here so a direct hit still works meanwhile.
        return redirect()->route('fleet-assets.incidents.index', array_filter([
            'report' => 1,
            'asset_id' => $request->input('asset_id'),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->captureRules(forCreate: true));

        $asset = Asset::find($data['asset_id']);

        $attributes = $this->mapCaptureToColumns($data);
        $attributes['reported_by_user_id'] = $request->user()->id;
        $attributes['status'] = 'reported';

        // Snapshot register-sourced identity now (rego/category live on the asset);
        // WoF/odometer/licence stay PREP-LATER until the register/driver profile lands.
        $attributes['asset_category'] = $attributes['asset_category'] ?? $asset?->category;
        $attributes['vehicle_rego_snapshot'] = $attributes['vehicle_rego_snapshot'] ?? $asset?->registration_number;

        $attributes = $this->applyRegulatory($attributes);

        $incident = FleetIncident::create($attributes);

        AuditLogger::log('fleet.incident.create', $incident, [
            'asset_id' => $incident->asset_id,
            'incident_type' => $incident->incident_type,
            'severity' => $incident->severity,
            'is_notifiable' => $incident->is_notifiable,
        ]);

        $this->emitSignal($incident, 'incident.reported', $incident->occurred_at);

        $incident->load('asset:id,name');
        User::whereHas('roles', function ($q) {
            $q->whereHas('permissions', fn ($p) => $p->where('key', 'fleet.incidents.manage'));
        })->get()->each->notify(new FleetIncidentReportedNotification($incident));

        $this->processIncidentChain($incident, $request);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'Incident reported.',
                'incident' => $this->buildDetailPayload($incident->refresh()),
            ]);
        }

        return back()
            ->with('success', 'Incident '.$incident->reference().' reported.')
            ->with('created_fleet_incident_id', $incident->id)
            ->with('created_fleet_incident_reference', $incident->reference());
    }

    public function show(Request $request, FleetIncident $incident)
    {
        // Modal/axios callers want the JSON detail payload. A direct deep-link
        // opens the detail modal over the list (nothing navigates away — the old
        // full-page show.tsx is retired).
        if ($request->wantsJson()) {
            return response()->json(['incident' => $this->buildDetailPayload($incident)]);
        }

        return redirect()->route('fleet-assets.incidents.index', ['incident' => $incident->id]);
    }

    public function update(Request $request, FleetIncident $incident)
    {
        $data = $request->validate($this->captureRules(forCreate: false));

        $attributes = $this->mapCaptureToColumns($data);
        $attributes = $this->applyRegulatory($attributes, $incident);

        $incident->update($attributes);

        AuditLogger::log('fleet.incident.update', $incident, ['fields' => array_keys($attributes)]);

        return $this->inertiaOrJson($request, 'Incident updated.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    /** Lifecycle status move + closure gate. */
    public function updateStatus(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:reported,investigating,resolved,closed'],
            'resolution_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Closure gate: resolution notes are mandatory to close.
        if ($data['status'] === 'closed' && blank($data['resolution_notes'] ?? $incident->resolution_notes)) {
            return back()->withErrors(['resolution_notes' => 'Resolution notes are required before closing.']);
        }

        $updates = ['status' => $data['status']];
        if (array_key_exists('resolution_notes', $data) && filled($data['resolution_notes'])) {
            $updates['resolution_notes'] = $data['resolution_notes'];
        }
        if (in_array($data['status'], ['resolved', 'closed'], true) && ! $incident->resolved_at) {
            $updates['resolved_at'] = now();
        }

        $previousStatus = $incident->status;
        $incident->update($updates);

        AuditLogger::log('fleet.incident.status', $incident, [
            'status' => $data['status'],
            'previous_status' => $previousStatus,
        ]);

        if ($previousStatus !== $data['status']) {
            $this->emitSignal($incident, 'incident.'.$data['status'], now());
        }

        return $this->inertiaOrJson($request, 'Status updated to '.$data['status'].'.', [
            'warnings' => $this->closureWarnings($incident),
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function addFollowup(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $followup = $incident->followups()->create([
            'notes' => $data['notes'],
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::log('fleet.incident.followup.add', $followup, ['fleet_incident_id' => $incident->id]);

        return $this->inertiaOrJson($request, 'Follow-up added.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function completeFollowup(Request $request, FleetIncident $incident, FleetIncidentFollowup $followup)
    {
        abort_unless((int) $followup->fleet_incident_id === (int) $incident->id, 404);

        $followup->update(['completed_at' => now()]);

        AuditLogger::log('fleet.incident.followup.complete', $followup, ['fleet_incident_id' => $incident->id]);

        return $this->inertiaOrJson($request, 'Follow-up completed.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function uploadAttachment(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20 MB (dashcam clips)
            'kind' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('fleet_incident_attachments', $disk);

        $incident->attachments()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $data['kind'] ?? null,
            'notes' => $data['notes'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
        ]);

        AuditLogger::log('fleet.incident.attachment.add', $incident, ['original_name' => $file->getClientOriginalName()]);

        return $this->inertiaOrJson($request, 'Evidence uploaded.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function downloadAttachment(Request $request, FleetIncident $incident, FleetIncidentAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $attachment->fleet_incident_id === (int) $incident->id, 404);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    public function destroyAttachment(Request $request, FleetIncident $incident, FleetIncidentAttachment $attachment)
    {
        abort_unless((int) $attachment->fleet_incident_id === (int) $incident->id, 404);

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        AuditLogger::log('fleet.incident.attachment.remove', $incident, ['attachment_id' => $attachment->id]);

        return $this->inertiaOrJson($request, 'Evidence removed.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    /** Log the Land Transport Act s22 Traffic Crash Report (TCR). */
    public function logPoliceReport(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'traffic_crash_report_reference' => ['nullable', 'string', 'max:60'],
            'police_reference' => ['nullable', 'string', 'max:100'],
            'attending_officer' => ['nullable', 'string', 'max:120'],
            'reported_at' => ['nullable', 'date'],
        ]);

        $incident->update([
            'police_notified' => true,
            'police_report_logged_at' => $data['reported_at'] ?? now(),
            'traffic_crash_report_reference' => $data['traffic_crash_report_reference'] ?? $incident->traffic_crash_report_reference,
            'police_reference' => $data['police_reference'] ?? $incident->police_reference,
            'attending_officer' => $data['attending_officer'] ?? $incident->attending_officer,
        ]);

        AuditLogger::log('fleet.incident.police_report', $incident, [
            'tcr' => $incident->traffic_crash_report_reference,
        ]);

        return $this->inertiaOrJson($request, 'Police report (TCR) logged.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function logClaim(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'insurer_name' => ['nullable', 'string', 'max:120'],
            'insurance_reference' => ['nullable', 'string', 'max:100'],
            'insurance_excess' => ['nullable', 'numeric', 'min:0'],
            'insurance_amount_sought' => ['nullable', 'numeric', 'min:0'],
            'insurance_claim_status' => ['nullable', 'string', 'max:30'],
        ]);

        $incident->update(array_merge(
            ['insurance_claimed' => true],
            array_filter($data, fn ($v) => $v !== null),
        ));

        AuditLogger::log('fleet.incident.claim', $incident, ['ref' => $incident->insurance_reference]);

        return $this->inertiaOrJson($request, 'Insurance claim logged.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function markOffRoad(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'off_road_from' => ['nullable', 'date'],
            'off_road_to' => ['nullable', 'date'],
        ]);

        $incident->update([
            'vehicle_off_road' => true,
            'off_road_from' => $data['off_road_from'] ?? $incident->off_road_from ?? now()->toDateString(),
            'off_road_to' => $data['off_road_to'] ?? $incident->off_road_to,
            'service_resumed_at' => null,
        ]);

        AuditLogger::log('fleet.incident.off_road', $incident, []);

        return $this->inertiaOrJson($request, 'Vehicle marked off-road (VOR).', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    public function backInService(Request $request, FleetIncident $incident)
    {
        $data = $request->validate([
            'service_resumed_at' => ['nullable', 'date'],
        ]);

        $incident->update([
            'service_resumed_at' => $data['service_resumed_at'] ?? now()->toDateString(),
        ]);

        AuditLogger::log('fleet.incident.back_in_service', $incident, []);

        return $this->inertiaOrJson($request, 'Vehicle returned to service.', [
            'incident' => $this->buildDetailPayload($incident->refresh()),
        ]);
    }

    /* ================================================================== */
    /*  Filters / stats / payloads                                        */
    /* ================================================================== */

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', $request->input('vehicle_id'));
        }
        if ($request->filled('driver_id')) {
            $query->where('driver_user_id', $request->input('driver_id'));
        }
        if ($request->filled('site_id')) {
            $siteId = $request->input('site_id');
            $query->whereHas('asset', fn ($q) => $q->where('site_id', $siteId));
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
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('location', 'like', "%{$term}%")
                    ->orWhere('traffic_crash_report_reference', 'like', "%{$term}%")
                    ->orWhere('insurance_reference', 'like', "%{$term}%")
                    ->orWhereHas('asset', fn ($a) => $a->where('name', 'like', "%{$term}%")->orWhere('registration_number', 'like', "%{$term}%"));
            });
        }

        return $query;
    }

    private function rowPayload(FleetIncident $i): array
    {
        return [
            'id' => $i->id,
            'reference' => $i->reference(),
            'asset' => $i->asset ? [
                'id' => $i->asset->id,
                'name' => $i->asset->name,
                'registration_number' => $i->asset->registration_number,
                'category' => $i->asset->category,
            ] : null,
            'reported_by' => $i->reportedBy ? ['id' => $i->reportedBy->id, 'name' => $i->reportedBy->name] : null,
            'driver' => $i->driver ? ['id' => $i->driver->id, 'name' => $i->driver->name] : null,
            'incident_type' => $i->incident_type,
            'severity' => $i->severity,
            'occurred_at' => optional($i->occurred_at)->toISOString(),
            'location' => $i->location,
            'status' => $i->status,
            'flags' => [
                'police_report_due' => $i->isPoliceReportDue(),
                'police_report_due_at' => optional($i->policeReportDueAt())->toISOString(),
                'police_hours_remaining' => $i->policeReportHoursRemaining(),
                'injury' => (bool) ($i->injury_involved || $i->fatality_involved),
                'off_road' => $i->isOffRoad(),
                'claim_open' => (bool) $i->insurance_claimed && $i->status !== 'closed',
                'alert_linked' => $i->isHighSeverity(),
                'attachments' => (int) ($i->attachments_count ?? 0),
                'followups' => (int) ($i->followups_count ?? 0),
            ],
        ];
    }

    private function stats($base): array
    {
        return [
            'reported' => (clone $base)->where('status', 'reported')->count(),
            'investigating' => (clone $base)->where('status', 'investigating')->count(),
            'resolved' => (clone $base)->where('status', 'resolved')->count(),
            'closed' => (clone $base)->where('status', 'closed')->count(),
            'police_due' => (clone $base)->policeReportDue()->count(),
            'worksafe_notifiable' => (clone $base)
                ->where('is_notifiable', true)
                ->where('status', '!=', 'closed')
                ->where(fn ($q) => $q->whereNull('worksafe_notification_status')->orWhereNotIn('worksafe_notification_status', ['notified', 'acknowledged']))
                ->count(),
            'off_road' => (clone $base)->offRoad()->count(),
            'open_claims' => (clone $base)->insuranceClaims()->where('status', '!=', 'closed')->count(),
            'injury_acc' => (clone $base)->injuryAcc()->count(),
        ];
    }

    private function emptyStats(): array
    {
        return array_fill_keys(['reported', 'investigating', 'resolved', 'closed', 'police_due', 'worksafe_notifiable', 'off_road', 'open_claims', 'injury_acc'], 0);
    }

    private function tabCounts($base): array
    {
        $counts = ['all' => (clone $base)->count()];
        foreach (self::TAB_SCOPES as $tab => $scope) {
            $counts[$tab] = (clone $base)->{$scope}()->count();
        }

        return $counts;
    }

    /** @return array<int, string> advisory closure warnings (gate is resolution_notes). */
    private function closureWarnings(FleetIncident $incident): array
    {
        $warnings = [];

        if ($incident->requiresPoliceReport() && ! $incident->hasLoggedPoliceReport()) {
            $warnings[] = 'Injury/fatal crash without a logged Police report (TCR) — Land Transport Act s22.';
        }
        if ($incident->is_notifiable && ! in_array($incident->worksafe_notification_status, ['notified', 'acknowledged'], true)) {
            $warnings[] = 'WorkSafe-notifiable event not yet notified.';
        }
        if ($incident->followups()->whereNull('completed_at')->exists()) {
            $warnings[] = 'Open follow-ups remain on this incident.';
        }

        return $warnings;
    }

    private function buildDetailPayload(FleetIncident $incident): array
    {
        $incident->load([
            'asset:id,name,registration_number,category,site_id',
            'asset.site:id,name',
            'reportedBy:id,name',
            'driver:id,name',
            'supervisor:id,name',
            'assignedTo:id,name',
            'booking:id,purpose,starts_at,ends_at',
            'attachments.uploader:id,name',
            'followups.assignedTo:id,name',
            'followups.creator:id,name',
            'clientIncidents:id,fleet_incident_id,client_id,severity,status,type',
            'clientIncidents.client:id,first_name,last_name',
        ]);

        $hsEvent = $incident->linkedHsEvent();

        return [
            'id' => $incident->id,
            'reference' => $incident->reference(),
            'incident_type' => $incident->incident_type,
            'severity' => $incident->severity,
            'hs_severity' => $incident->hsSeverity(),
            'status' => $incident->status,
            'occurred_at' => optional($incident->occurred_at)->toISOString(),
            'created_at' => optional($incident->created_at)->toISOString(),
            'location' => $incident->location,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'description' => $incident->description,
            'resolution_notes' => $incident->resolution_notes,
            'resolved_at' => optional($incident->resolved_at)->toISOString(),

            'asset' => $incident->asset ? [
                'id' => $incident->asset->id,
                'name' => $incident->asset->name,
                'registration_number' => $incident->asset->registration_number,
                'category' => $incident->asset->category,
                'site' => $incident->asset->site ? ['id' => $incident->asset->site->id, 'name' => $incident->asset->site->name] : null,
            ] : null,
            'reported_by' => $this->userRef($incident->reportedBy),
            'driver' => $this->userRef($incident->driver),
            'supervisor' => $this->userRef($incident->supervisor),
            'assigned_to' => $this->userRef($incident->assignedTo),
            'booking' => $incident->booking ? [
                'id' => $incident->booking->id,
                'purpose' => $incident->booking->purpose,
                'starts_at' => optional($incident->booking->starts_at)->toISOString(),
                'ends_at' => optional($incident->booking->ends_at)->toISOString(),
            ] : null,

            // §3.1–3.2 snapshots (PREP-LATER where unpopulated)
            'asset_category' => $incident->asset_category,
            'vehicle_rego_snapshot' => $incident->vehicle_rego_snapshot,
            'wof_status_snapshot' => $incident->wof_status_snapshot,
            'wof_expiry_snapshot' => optional($incident->wof_expiry_snapshot)->toDateString(),
            'cof_status_snapshot' => $incident->cof_status_snapshot,
            'cof_expiry_snapshot' => optional($incident->cof_expiry_snapshot)->toDateString(),
            'odometer_at_incident' => $incident->odometer_at_incident,
            'fuel_type_snapshot' => $incident->fuel_type_snapshot,
            'driver_licence_number' => $incident->driver_licence_number,
            'driver_licence_class' => $incident->driver_licence_class,
            'driver_licence_expiry' => optional($incident->driver_licence_expiry)->toDateString(),
            'driver_years_held' => $incident->driver_years_held,
            'driver_on_duty' => $incident->driver_on_duty,

            // §3.3–3.5 people / third party / witnesses
            'people_aboard' => $incident->people_aboard,
            'people_aboard_count' => $incident->people_aboard_count,
            'whanau_informed' => $incident->whanau_informed,
            'third_party_involved' => $incident->third_party_involved,
            'third_parties' => $incident->third_parties,
            'witnesses' => $incident->witnesses,
            'attending_officer' => $incident->attending_officer,

            // §3.6 scene & conditions
            'road_type' => $incident->road_type,
            'weather' => $incident->weather,
            'lighting' => $incident->lighting,
            'traffic_conditions' => $incident->traffic_conditions,
            'speed_limit' => $incident->speed_limit,
            'estimated_speed' => $incident->estimated_speed,
            'manoeuvre' => $incident->manoeuvre,
            'road_hazard' => $incident->road_hazard,

            // §3.7 damage & recovery
            'damage_details' => $incident->damage_details,
            'damage_classification' => $incident->damage_classification,
            'is_drivable' => $incident->is_drivable,
            'tow_required' => $incident->tow_required,
            'tow_provider' => $incident->tow_provider,
            'cargo_equipment_damage' => $incident->cargo_equipment_damage,
            'vehicle_off_road' => $incident->vehicle_off_road,
            'off_road_from' => optional($incident->off_road_from)->toDateString(),
            'off_road_to' => optional($incident->off_road_to)->toDateString(),
            'service_resumed_at' => optional($incident->service_resumed_at)->toDateString(),
            'is_off_road' => $incident->isOffRoad(),

            // §3.8 Police & regulatory (NZ)
            'injury_involved' => $incident->injury_involved,
            'fatality_involved' => $incident->fatality_involved,
            'injury_severity' => $incident->injury_severity,
            'requires_police_report' => $incident->requiresPoliceReport(),
            'is_police_report_due' => $incident->isPoliceReportDue(),
            'police_report_due_at' => optional($incident->policeReportDueAt())->toISOString(),
            'police_report_hours_remaining' => $incident->policeReportHoursRemaining(),
            'police_report_logged_at' => optional($incident->police_report_logged_at)->toISOString(),
            'police_notified' => $incident->police_notified,
            'police_reference' => $incident->police_reference,
            'traffic_crash_report_reference' => $incident->traffic_crash_report_reference,
            'is_notifiable' => $incident->is_notifiable,
            'worksafe_notification_status' => $incident->worksafe_notification_status,
            'worksafe_notified_at' => optional($incident->worksafe_notified_at)->toISOString(),
            'worksafe_reference' => $incident->worksafe_reference,
            'acc_claim_lodged' => $incident->acc_claim_lodged,
            'acc_claim_reference' => $incident->acc_claim_reference,
            'breath_test_administered' => $incident->breath_test_administered,
            'breath_test_result' => $incident->breath_test_result,
            'drug_test_administered' => $incident->drug_test_administered,
            'drug_test_result' => $incident->drug_test_result,

            // §3.9 insurance & cost
            'insurance_claimed' => $incident->insurance_claimed,
            'insurance_reference' => $incident->insurance_reference,
            'insurer_name' => $incident->insurer_name,
            'insurance_excess' => $incident->insurance_excess,
            'insurance_amount_sought' => $incident->insurance_amount_sought,
            'insurance_amount_approved' => $incident->insurance_amount_approved,
            'insurance_claim_status' => $incident->insurance_claim_status,
            'repair_contractor' => $incident->repair_contractor,
            'actual_repair_cost' => $incident->actual_repair_cost,
            'total_incident_cost' => $incident->total_incident_cost,

            // §3.10 investigation
            'root_cause' => $incident->root_cause,
            'corrective_actions' => $incident->corrective_actions,
            'contributing_factors' => $incident->contributing_factors,
            'investigation_completed_at' => optional($incident->investigation_completed_at)->toISOString(),

            // §3.12 non-vehicle asset
            'asset_serial_snapshot' => $incident->asset_serial_snapshot,
            'asset_condition_before' => $incident->asset_condition_before,
            'asset_condition_after' => $incident->asset_condition_after,
            'warranty_status' => $incident->warranty_status,
            'replacement_cost' => $incident->replacement_cost,

            // near-miss
            'potential_severity' => $incident->potential_severity,

            // evidence + follow-ups
            'attachments' => $incident->attachments->map(fn (FleetIncidentAttachment $a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'url' => route('fleet-assets.incidents.attachments.download', [$incident->id, $a->id]),
                'mime' => $a->mime,
                'kind' => $a->kind,
                'notes' => $a->notes,
                'alt_text' => $a->alt_text,
                'size' => $a->size,
                'is_image' => $a->isImage(),
                'uploaded_by' => $this->userRef($a->uploader),
                'created_at' => optional($a->created_at)->toISOString(),
            ])->values(),
            'followups' => $incident->followups->map(fn (FleetIncidentFollowup $f) => [
                'id' => $f->id,
                'notes' => $f->notes,
                'assigned_to' => $this->userRef($f->assignedTo),
                'created_by' => $this->userRef($f->creator),
                'due_at' => optional($f->due_at)->toISOString(),
                'completed_at' => optional($f->completed_at)->toISOString(),
                'is_completed' => $f->isCompleted(),
            ])->values(),

            // linked records (cross-module)
            'client_incidents' => $incident->clientIncidents->map(fn (ClientIncident $ci) => [
                'id' => $ci->id,
                'client' => $ci->client ? ['id' => $ci->client->id, 'name' => trim(($ci->client->first_name ?? '').' '.($ci->client->last_name ?? ''))] : null,
                'severity' => $ci->severity,
                'status' => $ci->status,
                'type' => $ci->type,
            ])->values(),
            'hs_event' => $hsEvent ? [
                'id' => $hsEvent->id,
                'reference' => $hsEvent->reference_number ?? $hsEvent->reference ?? null,
                'status' => $hsEvent->status,
                'control_room_alert_id' => $hsEvent->control_room_alert_id ?? null,
            ] : null,

            'can' => ['manage' => $this->userCanManage()],
        ];
    }

    /* ================================================================== */
    /*  Validation + column mapping + regulatory                          */
    /* ================================================================== */

    /** @return array<string, mixed> */
    private function captureRules(bool $forCreate): array
    {
        $req = fn (array $rules) => $forCreate ? $rules : array_merge(['sometimes'], $rules);

        return [
            'asset_id' => $req(['required', 'integer', 'exists:assets,id']),
            'incident_type' => $req(['required', 'string', 'in:'.implode(',', FleetIncident::TYPES)]),
            'severity' => $req(['required', 'string', 'in:'.implode(',', FleetIncident::SEVERITIES)]),
            'occurred_at' => $req(['required', 'date']),
            'description' => $req(['required', 'string', 'max:10000']),

            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'supervisor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'booking_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // snapshots (mostly PREP-LATER; accepted when the wizard supplies them)
            'asset_category' => ['nullable', 'string', 'max:30'],
            'vehicle_rego_snapshot' => ['nullable', 'string', 'max:20'],
            'wof_status_snapshot' => ['nullable', 'string', 'max:30'],
            'wof_expiry_snapshot' => ['nullable', 'date'],
            'cof_status_snapshot' => ['nullable', 'string', 'max:30'],
            'cof_expiry_snapshot' => ['nullable', 'date'],
            'odometer_at_incident' => ['nullable', 'integer', 'min:0'],
            'fuel_type_snapshot' => ['nullable', 'string', 'max:30'],
            'driver_licence_number' => ['nullable', 'string', 'max:40'],
            'driver_licence_class' => ['nullable', 'string', 'max:20'],
            'driver_licence_expiry' => ['nullable', 'date'],
            'driver_years_held' => ['nullable', 'integer', 'min:0'],
            'driver_on_duty' => ['nullable', 'boolean'],

            // people / third party / witnesses (JSON groups — frontend owns shape)
            'people_aboard' => ['nullable', 'array'],
            'people_aboard_count' => ['nullable', 'integer', 'min:0'],
            'whanau_informed' => ['nullable', 'boolean'],
            'third_party_involved' => ['nullable', 'boolean'],
            'third_parties' => ['nullable', 'array'],
            'witnesses' => ['nullable', 'array'],
            'attending_officer' => ['nullable', 'string', 'max:120'],

            // scene & conditions
            'road_type' => ['nullable', 'string', 'max:40'],
            'weather' => ['nullable', 'string', 'max:40'],
            'lighting' => ['nullable', 'string', 'max:40'],
            'traffic_conditions' => ['nullable', 'string', 'max:40'],
            'speed_limit' => ['nullable', 'integer', 'min:0', 'max:200'],
            'estimated_speed' => ['nullable', 'integer', 'min:0', 'max:400'],
            'manoeuvre' => ['nullable', 'string', 'max:60'],
            'road_hazard' => ['nullable', 'string', 'max:120'],

            // damage & recovery
            'damage_details' => ['nullable', 'array'],
            'damage_details.areas' => ['nullable', 'array'],
            'damage_details.estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'damage_classification' => ['nullable', 'string', 'in:'.implode(',', FleetIncident::DAMAGE_CLASSIFICATIONS)],
            'is_drivable' => ['nullable', 'boolean'],
            'tow_required' => ['nullable', 'boolean'],
            'tow_provider' => ['nullable', 'string', 'max:120'],
            'cargo_equipment_damage' => ['nullable', 'string', 'max:2000'],
            'vehicle_off_road' => ['nullable', 'boolean'],
            'off_road_from' => ['nullable', 'date'],
            'off_road_to' => ['nullable', 'date'],
            'service_resumed_at' => ['nullable', 'date'],

            // Police & regulatory
            'injury_involved' => ['nullable', 'boolean'],
            'fatality_involved' => ['nullable', 'boolean'],
            'injury_severity' => ['nullable', 'string', 'in:'.implode(',', FleetIncident::INJURY_SEVERITIES)],
            'police_notified' => ['nullable', 'boolean'],
            'police_reference' => ['nullable', 'string', 'max:100'],
            'traffic_crash_report_reference' => ['nullable', 'string', 'max:60'],
            'acc_claim_lodged' => ['nullable', 'boolean'],
            'acc_claim_reference' => ['nullable', 'string', 'max:60'],
            'breath_test_administered' => ['nullable', 'boolean'],
            'breath_test_result' => ['nullable', 'string', 'max:40'],
            'drug_test_administered' => ['nullable', 'boolean'],
            'drug_test_result' => ['nullable', 'string', 'max:40'],

            // insurance & cost
            'insurance_claimed' => ['nullable', 'boolean'],
            'insurance_reference' => ['nullable', 'string', 'max:100'],
            'insurer_name' => ['nullable', 'string', 'max:120'],
            'insurance_excess' => ['nullable', 'numeric', 'min:0'],
            'insurance_amount_sought' => ['nullable', 'numeric', 'min:0'],
            'insurance_amount_approved' => ['nullable', 'numeric', 'min:0'],
            'insurance_claim_status' => ['nullable', 'string', 'max:30'],
            'repair_contractor' => ['nullable', 'string', 'max:120'],
            'actual_repair_cost' => ['nullable', 'numeric', 'min:0'],
            'total_incident_cost' => ['nullable', 'numeric', 'min:0'],

            // investigation
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'corrective_actions' => ['nullable', 'string', 'max:5000'],
            'contributing_factors' => ['nullable', 'array'],
            'investigation_completed_at' => ['nullable', 'date'],

            // non-vehicle asset
            'asset_serial_snapshot' => ['nullable', 'string', 'max:80'],
            'asset_condition_before' => ['nullable', 'string', 'max:40'],
            'asset_condition_after' => ['nullable', 'string', 'max:40'],
            'warranty_status' => ['nullable', 'string', 'max:40'],
            'replacement_cost' => ['nullable', 'numeric', 'min:0'],

            // near-miss
            'potential_severity' => ['nullable', 'string', 'in:'.implode(',', FleetIncident::SEVERITIES)],
        ];
    }

    /**
     * Take validated capture data and return a column => value array (only the
     * keys present), suitable for create()/update(). Field names already match
     * columns 1:1, so this is mostly a pass-through that drops unknown keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapCaptureToColumns(array $data): array
    {
        // Everything in captureRules() maps to a fillable column of the same name.
        unset($data['sometimes']);

        return $data;
    }

    /**
     * Derive the s22 Police-report window + WorkSafe-notifiable flag from injury
     * + severity. Runs on create and whenever those fields change on update.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyRegulatory(array $attributes, ?FleetIncident $existing = null): array
    {
        $injury = $attributes['injury_involved'] ?? $existing?->injury_involved ?? false;
        $fatal = $attributes['fatality_involved'] ?? $existing?->fatality_involved ?? false;
        $severity = $attributes['severity'] ?? $existing?->severity;
        $occurredAt = $attributes['occurred_at'] ?? $existing?->occurred_at;
        $injurySeverity = $attributes['injury_severity'] ?? $existing?->injury_severity;

        // s22: injury/fatal crash → a 24-hour Police-report window from occurred_at.
        if (($injury || $fatal) && $occurredAt) {
            if (! ($existing?->police_report_due_at) || isset($attributes['occurred_at'])) {
                $attributes['police_report_due_at'] = \Illuminate\Support\Carbon::parse($occurredAt)->addHours(FleetIncident::POLICE_REPORT_WINDOW_HOURS);
            }
        }

        // WorkSafe notifiable (HSWA) — reuse the client-incident classifier.
        $harm = $fatal ? 'death' : ($injurySeverity ?: ($injury ? 'medical' : 'none'));
        $notifiable = app(NotifiableEventClassifier::class)->isNotifiable($harm, $severity);
        $attributes['is_notifiable'] = $notifiable;
        if ($notifiable && blank($attributes['worksafe_notification_status'] ?? $existing?->worksafe_notification_status)) {
            $attributes['worksafe_notification_status'] = 'pending';
        }

        return $attributes;
    }

    /* ================================================================== */
    /*  Cross-module cascade (existing — now sets the direct FK)           */
    /* ================================================================== */

    private function processIncidentChain(FleetIncident $incident, Request $request): void
    {
        if (! $incident->booking_id || ! Schema::hasTable('fleet_resident_transports')) {
            return;
        }

        $transports = FleetResidentTransport::where('booking_id', $incident->booking_id)->get();
        if ($transports->isEmpty()) {
            return;
        }

        $clientSeverity = FleetIncident::mapSeverityToHs($incident->severity);

        if (Schema::hasTable('client_incidents')) {
            foreach ($transports as $transport) {
                $clientId = $transport->resident_id;
                if (! $clientId) {
                    continue;
                }

                try {
                    $clientIncident = ClientIncident::create([
                        'client_id' => $clientId,
                        'fleet_incident_id' => $incident->id, // Gap F1 — direct reverse link
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

        if ($incident->isHighSeverity() && Schema::hasTable('safeguarding_alerts')) {
            foreach ($transports as $transport) {
                $clientId = $transport->resident_id;
                if (! $clientId) {
                    continue;
                }

                try {
                    // alert_type + severity are enums (requires_monitoring + low/medium/high/
                    // critical) — pass valid values. (The previous raw 'transport_incident'
                    // + fleet-vocab 'major' silently failed the enum constraints.)
                    $alert = SafeguardingAlert::create([
                        'alertable_type' => 'App\\Models\\Client',
                        'alertable_id' => $clientId,
                        'alert_type' => 'requires_monitoring',
                        'alert_summary' => "Transport incident ({$incident->severity}): {$incident->incident_type}",
                        'alert_details' => $incident->description,
                        'severity' => $clientSeverity,
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

    /* ================================================================== */
    /*  Small helpers                                                      */
    /* ================================================================== */

    private function emitSignal(FleetIncident $incident, string $signalType, $occurredAt): void
    {
        if (! $incident->isHighSeverity()) {
            return;
        }

        app(\App\Services\Fleet\FleetSignalService::class)->emit([
            'asset_id' => $incident->asset_id,
            'signal_type' => $signalType,
            'severity_hint' => $incident->severity === 'critical' ? 'critical' : 'high',
            'occurred_at' => $occurredAt,
            'payload' => [
                'incident_id' => $incident->id,
                'incident_type' => $incident->incident_type,
                'description' => Str::limit((string) $incident->description, 200),
            ],
        ]);
    }

    private function userRef(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    private function userCanManage(): bool
    {
        $user = request()->user();

        return $user ? ((bool) $user->canDo('fleet.manage') || (bool) $user->canDo('fleet.incidents.manage')) : false;
    }

    private function formOptions(): array
    {
        $assets = Asset::query()
            ->orderBy('name')
            ->get(['id', 'name', 'registration_number', 'category'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'registration_number' => $a->registration_number,
                'category' => $a->category,
            ])->values();

        $users = User::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();

        $sites = Schema::hasTable('sites')
            ? Site::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()
            : collect();

        return [
            'assets' => $assets,
            'users' => $users,
            'sites' => $sites,
            'types' => FleetIncident::TYPES,
            'severities' => FleetIncident::SEVERITIES,
            'injury_severities' => FleetIncident::INJURY_SEVERITIES,
            'damage_classifications' => FleetIncident::DAMAGE_CLASSIFICATIONS,
        ];
    }

    private function exportCsv($query)
    {
        $all = (clone $query)->with(['asset:id,name', 'reportedBy:id,name', 'driver:id,name'])->latest('occurred_at')->limit(5000)->get();

        return response()->streamDownload(function () use ($all) {
            $handle = fopen('php://output', 'w');
            $this->putCsv($handle, ['Ref', 'Date', 'Vehicle/Asset', 'Type', 'Severity', 'Location', 'Reported By', 'Driver', 'Status', 'Injury', 'Off-road', 'Police due', 'TCR ref', 'Insurance ref', 'Description']);
            foreach ($all as $i) {
                $this->putCsv($handle, [
                    $i->reference(),
                    optional($i->occurred_at)->format('Y-m-d H:i') ?? '',
                    $i->asset?->name ?? '',
                    $i->incident_type,
                    $i->severity,
                    $i->location ?? '',
                    $i->reportedBy?->name ?? '',
                    $i->driver?->name ?? '',
                    $i->status,
                    ($i->injury_involved || $i->fatality_involved) ? 'Yes' : 'No',
                    $i->isOffRoad() ? 'Yes' : 'No',
                    $i->isPoliceReportDue() ? 'Yes' : 'No',
                    $i->traffic_crash_report_reference ?? '',
                    $i->insurance_reference ?? '',
                    $i->description,
                ]);
            }
            fclose($handle);
        }, 'fleet-incidents-'.now()->format('Y-m-d').'.csv');
    }
}
