<?php

namespace App\Http\Controllers\Compliance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\Compliance\ComplianceMetricsService;
use Illuminate\Http\Request;

class ComplianceDashboardController extends Controller
{
    public function __construct(private readonly ComplianceMetricsService $metrics) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('compliance.view'), 403);

        $orgId = $user->organization_id ?? null;
        $period = $this->metrics->normalisePeriod($request->query('period'));

        $canManage = $user->canDo('governance.compliance.manage');
        $canTriage = $user->canDo('controlRoom.alerts.manage');

        return inertia('compliance/index', [
            'period' => $period,
            'kpis' => $this->metrics->exceptionKpis($orgId, $period),
            'whatsDue' => $this->metrics->whatsDue($orgId),
            'controlRoom' => $this->metrics->controlRoomSummary(),
            'charts' => $this->metrics->trends($orgId, $period),
            'can' => [
                'manage' => $canManage,
                'triage' => $canTriage,
            ],
            // Reference data for the create/record/respond wizards — only sent to users
            // who can actually manage obligations (the wizards are manage-gated).
            'frameworks' => collect(ComplianceObligation::frameworkOptions())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'owners' => $canManage
                ? User::query()->orderBy('name')->limit(200)->get(['id', 'name'])
                : [],
            'obligations' => $canManage
                ? ComplianceObligation::query()
                    ->where('status', '!=', 'complete')
                    ->orderBy('due_date')
                    ->limit(100)
                    ->get()
                    ->map(fn (ComplianceObligation $o) => [
                        'id' => $o->id,
                        'title' => $o->obligation_title,
                        'framework' => $o->getFrameworkLabel(),
                        'due_date' => $o->due_date?->toDateString(),
                    ])
                    ->values()
                : [],
            'relatedIncidents' => $canManage
                ? ClientIncident::query()
                    ->latest()
                    ->limit(50)
                    ->get()
                    ->map(fn (ClientIncident $i) => [
                        'id' => $i->id,
                        'label' => $i->title ?? "Incident #{$i->id}",
                    ])
                    ->values()
                : [],
        ]);
    }
}
