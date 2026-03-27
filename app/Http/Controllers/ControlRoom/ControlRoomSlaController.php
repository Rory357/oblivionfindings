<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ControlRoomSlaController extends Controller
{
    /**
     * List all SLA definitions with compliance statistics.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $slaDefinitions = SlaDefinition::withCount('alertSlas')
            ->get()
            ->map(function (SlaDefinition $sla) {
                $totalAlerts = $sla->alert_slas_count;

                $acknowledgeMetCount = 0;
                $responseMetCount = 0;
                $resolutionMetCount = 0;
                $acknowledgeApplicable = 0;
                $responseApplicable = 0;
                $resolutionApplicable = 0;

                if ($totalAlerts > 0) {
                    $acknowledgeApplicable = AlertSla::where('sla_definition_id', $sla->id)
                        ->whereNotNull('acknowledge_deadline')
                        ->count();

                    $responseApplicable = AlertSla::where('sla_definition_id', $sla->id)
                        ->whereNotNull('response_deadline')
                        ->count();

                    $resolutionApplicable = AlertSla::where('sla_definition_id', $sla->id)
                        ->whereNotNull('resolution_deadline')
                        ->count();

                    $acknowledgeMetCount = AlertSla::where('sla_definition_id', $sla->id)
                        ->whereNotNull('acknowledge_deadline')
                        ->where(function ($q) {
                            $q->where('acknowledge_breached', false)
                                ->orWhereNull('acknowledge_breached');
                        })
                        ->count();

                    $responseMetCount = AlertSla::where('sla_definition_id', $sla->id)
                        ->whereNotNull('response_deadline')
                        ->where(function ($q) {
                            $q->where('response_breached', false)
                                ->orWhereNull('response_breached');
                        })
                        ->count();

                    $resolutionMetCount = AlertSla::where('sla_definition_id', $sla->id)
                        ->whereNotNull('resolution_deadline')
                        ->where(function ($q) {
                            $q->where('resolution_breached', false)
                                ->orWhereNull('resolution_breached');
                        })
                        ->count();
                }

                return [
                    'id' => $sla->id,
                    'name' => $sla->name,
                    'code' => $sla->code,
                    'description' => $sla->description,
                    'alert_types' => $sla->alert_types ?? [],
                    'severities' => $sla->severities ?? [],
                    'sources' => $sla->sources ?? [],
                    'acknowledge_target_minutes' => $sla->acknowledge_target_minutes,
                    'response_target_minutes' => $sla->response_target_minutes,
                    'resolution_target_minutes' => $sla->resolution_target_minutes,
                    'business_hours_only' => $sla->business_hours_only,
                    'business_hours' => $sla->business_hours,
                    'escalate_on_acknowledge_breach' => $sla->escalate_on_acknowledge_breach,
                    'escalate_on_response_breach' => $sla->escalate_on_response_breach,
                    'escalate_on_resolution_breach' => $sla->escalate_on_resolution_breach,
                    'breach_notify_roles' => $sla->breach_notify_roles ?? [],
                    'is_active' => $sla->is_active,
                    'total_alerts' => $totalAlerts,
                    'compliance' => [
                        'acknowledge_pct' => $acknowledgeApplicable > 0
                            ? round(($acknowledgeMetCount / $acknowledgeApplicable) * 100, 1)
                            : null,
                        'response_pct' => $responseApplicable > 0
                            ? round(($responseMetCount / $responseApplicable) * 100, 1)
                            : null,
                        'resolution_pct' => $resolutionApplicable > 0
                            ? round(($resolutionMetCount / $resolutionApplicable) * 100, 1)
                            : null,
                    ],
                ];
            });

        return Inertia::render('control-room/sla/index', [
            'slaDefinitions' => $slaDefinitions,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    /**
     * Store a new SLA definition.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('control_room_sla_definitions', 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
            'alert_types' => ['nullable', 'array'],
            'alert_types.*' => ['string', 'max:100'],
            'severities' => ['nullable', 'array'],
            'severities.*' => ['string', 'in:low,medium,high,critical'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'max:100'],
            'acknowledge_target_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'response_target_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'resolution_target_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'business_hours_only' => ['boolean'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.start' => ['nullable', 'string', 'date_format:H:i'],
            'business_hours.end' => ['nullable', 'string', 'date_format:H:i'],
            'business_hours.days' => ['nullable', 'array'],
            'business_hours.days.*' => ['integer', 'min:1', 'max:7'],
            'escalate_on_acknowledge_breach' => ['boolean'],
            'escalate_on_response_breach' => ['boolean'],
            'escalate_on_resolution_breach' => ['boolean'],
            'breach_notify_roles' => ['nullable', 'array'],
            'breach_notify_roles.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $sla = SlaDefinition::create($data);

        AuditLogger::log('controlRoom.sla.create', $sla, [
            'sla_id' => $sla->id,
            'name' => $sla->name,
        ]);

        return back()->with('success', 'SLA definition created.');
    }

    /**
     * Update an existing SLA definition.
     */
    public function update(Request $request, SlaDefinition $sla)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('control_room_sla_definitions', 'code')->ignore($sla->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'alert_types' => ['nullable', 'array'],
            'alert_types.*' => ['string', 'max:100'],
            'severities' => ['nullable', 'array'],
            'severities.*' => ['string', 'in:low,medium,high,critical'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'max:100'],
            'acknowledge_target_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'response_target_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'resolution_target_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'business_hours_only' => ['boolean'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.start' => ['nullable', 'string', 'date_format:H:i'],
            'business_hours.end' => ['nullable', 'string', 'date_format:H:i'],
            'business_hours.days' => ['nullable', 'array'],
            'business_hours.days.*' => ['integer', 'min:1', 'max:7'],
            'escalate_on_acknowledge_breach' => ['boolean'],
            'escalate_on_response_breach' => ['boolean'],
            'escalate_on_resolution_breach' => ['boolean'],
            'breach_notify_roles' => ['nullable', 'array'],
            'breach_notify_roles.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $sla->update($data);

        AuditLogger::log('controlRoom.sla.update', $sla, [
            'sla_id' => $sla->id,
            'name' => $sla->name,
        ]);

        return back()->with('success', 'SLA definition updated.');
    }

    /**
     * Toggle active status of an SLA definition.
     */
    public function toggleActive(SlaDefinition $sla)
    {
        $user = request()->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $sla->update(['is_active' => !$sla->is_active]);

        AuditLogger::log('controlRoom.sla.toggleActive', $sla, [
            'sla_id' => $sla->id,
            'is_active' => $sla->is_active,
        ]);

        return back()->with('success', $sla->is_active ? 'SLA activated.' : 'SLA deactivated.');
    }

    /**
     * Breach report: show all breached AlertSla records with filters.
     */
    public function breachReport(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'breach_type' => ['nullable', 'string', 'in:acknowledge,response,resolution'],
        ]);

        $dateFrom = $filters['date_from'] ?? now()->subDays(30)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();

        $query = AlertSla::query()
            ->breached()
            ->whereDate('first_breach_at', '>=', $dateFrom)
            ->whereDate('first_breach_at', '<=', $dateTo)
            ->with([
                'alert:id,alert_type,severity,source,status,triggered_at',
                'slaDefinition:id,name,code',
            ]);

        if (!empty($filters['severity'])) {
            $query->whereHas('alert', function ($q) use ($filters) {
                $q->where('severity', $filters['severity']);
            });
        }

        if (!empty($filters['breach_type'])) {
            $breachType = $filters['breach_type'];
            $query->where("{$breachType}_breached", true);
        }

        $breaches = $query->orderByDesc('first_breach_at')
            ->paginate(25)
            ->through(function (AlertSla $alertSla) {
                $breachTypes = [];
                if ($alertSla->acknowledge_breached) {
                    $breachTypes[] = 'acknowledge';
                }
                if ($alertSla->response_breached) {
                    $breachTypes[] = 'response';
                }
                if ($alertSla->resolution_breached) {
                    $breachTypes[] = 'resolution';
                }

                return [
                    'id' => $alertSla->id,
                    'alert_id' => $alertSla->alert_id,
                    'alert_type' => $alertSla->alert?->alert_type,
                    'severity' => $alertSla->alert?->severity,
                    'source' => $alertSla->alert?->source,
                    'sla_name' => $alertSla->slaDefinition?->name,
                    'sla_code' => $alertSla->slaDefinition?->code,
                    'breach_types' => $breachTypes,
                    'acknowledge_deadline' => $alertSla->acknowledge_deadline?->toISOString(),
                    'acknowledged_at' => $alertSla->acknowledged_at?->toISOString(),
                    'acknowledge_variance_minutes' => $alertSla->acknowledge_breached ? $alertSla->acknowledge_variance_minutes : null,
                    'response_deadline' => $alertSla->response_deadline?->toISOString(),
                    'responded_at' => $alertSla->responded_at?->toISOString(),
                    'response_variance_minutes' => $alertSla->response_breached ? $alertSla->response_variance_minutes : null,
                    'resolution_deadline' => $alertSla->resolution_deadline?->toISOString(),
                    'resolved_at' => $alertSla->resolved_at?->toISOString(),
                    'resolution_variance_minutes' => $alertSla->resolution_breached ? $alertSla->resolution_variance_minutes : null,
                    'first_breach_at' => $alertSla->first_breach_at?->toISOString(),
                ];
            });

        // Summary stats
        $statsQuery = AlertSla::query()
            ->breached()
            ->whereDate('first_breach_at', '>=', $dateFrom)
            ->whereDate('first_breach_at', '<=', $dateTo);

        $totalBreaches = $statsQuery->count();

        $acknowledgeBreaches = (clone $statsQuery)->where('acknowledge_breached', true)->count();
        $responseBreaches = (clone $statsQuery)->where('response_breached', true)->count();
        $resolutionBreaches = (clone $statsQuery)->where('resolution_breached', true)->count();

        return Inertia::render('control-room/sla/breaches', [
            'breaches' => $breaches,
            'stats' => [
                'total' => $totalBreaches,
                'acknowledge' => $acknowledgeBreaches,
                'response' => $responseBreaches,
                'resolution' => $resolutionBreaches,
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'severity' => $filters['severity'] ?? null,
                'breach_type' => $filters['breach_type'] ?? null,
            ],
        ]);
    }
}
