<?php

namespace App\Http\Controllers\Compliance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\Compliance\ComplianceMetricsService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;

class ComplianceDashboardController extends Controller
{
    private const INCIDENT_SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites', 'reports.viewAny'];

    private const STAFF_SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly ComplianceMetricsService $metrics,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('compliance.view'), 403);

        $period = $this->metrics->normalisePeriod($request->query('period'));

        $canManage = $user->canDo('governance.compliance.manage');
        $canViewControlRoom = $user->canDo('controlRoom.viewAny');
        $canTriage = $canViewControlRoom && $user->canDo('controlRoom.alerts.manage');

        $owners = User::query()->orderBy('name')->limit(200);
        $this->siteAccess->applyStaffScope($owners, $user, self::STAFF_SITE_BYPASS_PERMISSIONS);

        $relatedIncidents = ClientIncident::query()->latest()->limit(50);
        $this->siteAccess->applyClientIncidentScope(
            $relatedIncidents,
            $user,
            self::INCIDENT_SITE_BYPASS_PERMISSIONS,
        );

        return inertia('compliance/index', [
            'period' => $period,
            'kpis' => $this->metrics->exceptionKpis($user, $period),
            'whatsDue' => $this->metrics->whatsDue($user),
            'controlRoom' => $this->metrics->controlRoomSummary($user),
            'charts' => $this->metrics->trends($user, $period),
            'can' => [
                'manage' => $canManage,
                'triage' => $canTriage,
                'viewControlRoom' => $canViewControlRoom,
                'viewAudit' => $user->canDo('audit.viewAny'),
                'viewReports' => $user->canDo('reports.viewAny'),
            ],
            // Reference data for the create/record/respond wizards — only sent to users
            // who can actually manage obligations (the wizards are manage-gated).
            'frameworks' => collect(ComplianceObligation::frameworkOptions())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'owners' => $canManage
                ? $owners->get(['id', 'name'])
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
                ? $relatedIncidents
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
