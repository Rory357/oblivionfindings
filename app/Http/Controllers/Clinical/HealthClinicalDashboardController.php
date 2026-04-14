<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Enums\AlertSeverity;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

class HealthClinicalDashboardController extends Controller
{
    public function __construct(
        private readonly ClinicalDashboardService $dashboardService,
    ) {}

    public function index(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.dashboard'), 403);

        return inertia('health-clinical/index', [
            'kpis' => $this->dashboardService->getKpis(),
            'overdue_items' => $this->dashboardService->getOverdueItems(),
            'recent_events' => $this->dashboardService->getRecentEvents(),
            'recent_observations' => $this->dashboardService->getRecentObservations(),
        ]);
    }

    /**
     * Cross-client observation register — paginated, filterable.
     */
    public function observations(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.observations.viewAny'), 403);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'observation_type' => ['nullable', 'string', 'in:' . implode(',', array_column(ObservationType::cases(), 'value'))],
            'recorded_by' => ['nullable', 'integer', 'exists:users,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $observations = $this->dashboardService->getObservationRegister($filters);
        $stats = $this->dashboardService->getObservationRegisterStats();

        return inertia('health-clinical/observations', [
            'observations' => $observations,
            'stats' => $stats,
            'filters' => $filters,
            'filter_options' => [
                'clients' => Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
                'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
                'staff' => User::query()->whereHas('roles')->orderBy('name')->get(['id', 'name']),
                'observation_types' => collect(ObservationType::cases())->map(fn ($t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Cross-client clinical event register — paginated, filterable.
     */
    public function events(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.events.viewAny'), 403);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'event_type' => ['nullable', 'string', 'in:' . implode(',', array_map(
                fn (ClinicalEventType $type) => $type->value,
                ClinicalEventType::cases()
            ))],
            'severity' => ['nullable', 'string', 'in:' . implode(',', AlertSeverity::ALL)],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'follow_up_status' => ['nullable', 'string', 'in:none,required,pending,completed'],
            'review_status' => ['nullable', 'string', 'in:reviewed,unreviewed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $eventTypes = collect(ClinicalEventType::cases())->map(fn (ClinicalEventType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ])->values();

        return inertia('health-clinical/Events', [
            'events' => $this->dashboardService->getEventRegister($filters),
            'stats' => $this->dashboardService->getEventRegisterStats(),
            'filters' => $filters,
            'filter_options' => [
                'clients' => Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
                'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
                'event_types' => $eventTypes,
                'severities' => collect(AlertSeverity::ALL)->map(fn (string $severity) => [
                    'value' => $severity,
                    'label' => ucfirst($severity),
                ])->values(),
                'follow_up_statuses' => collect([
                    ['value' => 'required', 'label' => 'Follow-up required'],
                    ['value' => 'pending', 'label' => 'Pending follow-up'],
                    ['value' => 'completed', 'label' => 'Follow-up complete'],
                    ['value' => 'none', 'label' => 'No follow-up'],
                ]),
                'review_statuses' => collect([
                    ['value' => 'unreviewed', 'label' => 'Unreviewed'],
                    ['value' => 'reviewed', 'label' => 'Reviewed'],
                ]),
            ],
            'event_types' => $eventTypes->pluck('label', 'value'),
        ]);
    }
}
