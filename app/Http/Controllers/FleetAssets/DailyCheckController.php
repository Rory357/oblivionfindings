<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Services\Fleet\VehicleDailyCheckService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * The Daily checks page. Each check is recorded by VehicleDailyCheckService as
 * its own submitted record: checking again adds a record, and daily checks
 * never block bookings or a maintenance release.
 */
class DailyCheckController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SecurityDevicesAccessService $vehicleAccess,
        private readonly VehicleDailyCheckService $dailyChecks,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');

        // Only vehicles at the person's permitted sites, optionally narrowed to
        // their own site. The store refuses anything outside this scope.
        $permitted = $this->vehicleAccess->accessibleVehiclesForFleet($user);
        $query = clone $permitted;

        if ($hasFleetFields && $request->user()?->site_id) {
            $query->where(function ($q) use ($request) {
                $q->where('home_site_id', $request->user()->site_id)
                  ->orWhere('site_id', $request->user()->site_id);
            });
        }

        $vehicles = $query->orderBy('name')->get(['id', 'name', 'asset_tag', 'status']);

        // Today's checks on the Auckland calendar, newest first. Each check is
        // its own record, so the card shows the latest and counts the rest.
        $today = CarbonImmutable::now((string) config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay()->utc();
        $todayChecks = FleetChecklistRun::query()
            ->whereIn('asset_id', $vehicles->pluck('id'))
            ->where('completed_at', '>=', $today)
            ->whereHas('template', fn ($q) => $q->where('type', FleetChecklistTemplate::TYPE_DAILY_CHECK))
            ->with('user:id,name')
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->get()
            ->groupBy('asset_id');

        $vehicleData = $vehicles->map(function ($v) use ($todayChecks) {
            $checks = $todayChecks->get($v->id) ?? collect();
            $check = $checks->first();
            return [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'status' => $v->status,
                'checked_today' => $check !== null,
                'checks_today' => $checks->count(),
                'check_result' => $check ? ($check->passed ? 'good' : 'issue') : null,
                'check_notes' => $check ? $this->dailyChecks->notes($check) : null,
                'checked_at' => $check?->completed_at?->toISOString(),
                'checked_by' => $check?->user?->name,
            ];
        })->values();

        $checkedCount = $vehicleData->where('checked_today', true)->count();

        // Roadworthiness badges over the person's permitted vehicles — the same
        // scope and COUNT patterns as VehicleController::index; the pre-drive
        // check is exactly where an expired WOF must be visible.
        $wofDue = (clone $permitted)->wofExpiring(30)->count();
        $wofExpired = (clone $permitted)
            ->whereNotNull('wof_expires_at')
            ->where('wof_expires_at', '<', now())
            ->count();
        $regoDue = (clone $permitted)->registrationExpiring(30)->count();
        $regoExpired = (clone $permitted)
            ->whereNotNull('registration_expires_at')
            ->where('registration_expires_at', '<', now())
            ->count();
        $cofDue = (clone $permitted)
            ->whereNotNull('cof_expires_at')
            ->where('cof_expires_at', '<=', now()->addDays(30))
            ->where('cof_expires_at', '>=', now())
            ->count();
        $cofExpired = (clone $permitted)
            ->whereNotNull('cof_expires_at')
            ->where('cof_expires_at', '<', now())
            ->count();
        $hasInsuranceExpiry = Schema::hasColumn('assets', 'insurance_expires_at');
        $insuranceExpiring = $hasInsuranceExpiry
            ? (clone $permitted)
                ->whereNotNull('insurance_expires_at')
                ->where('insurance_expires_at', '<=', now()->addDays(30))
                ->where('insurance_expires_at', '>=', now())
                ->count()
            : null;
        $insuranceExpired = $hasInsuranceExpiry
            ? (clone $permitted)
                ->whereNotNull('insurance_expires_at')
                ->where('insurance_expires_at', '<', now())
                ->count()
            : null;
        $alertQuery = ControlRoomAlert::query()->actionable();
        $this->siteAccess->applyAlertScope($alertQuery, $user, ['fleet.manage']);
        $openAlerts = (clone $alertQuery)->count();
        $criticalAlerts = (clone $alertQuery)
            ->where('severity', 'critical')
            ->count();

        return Inertia::render('fleet-assets/daily-check', [
            'vehicles' => $vehicleData,
            'summary' => [
                'total' => $vehicleData->count(),
                'checked' => $checkedCount,
                'unchecked' => $vehicleData->count() - $checkedCount,
            ],
            'compliance' => [
                'wof_due' => $wofDue,
                'wof_expired' => $wofExpired,
                'rego_due' => $regoDue,
                'rego_expired' => $regoExpired,
                'cof_due' => $cofDue,
                'cof_expired' => $cofExpired,
                'insurance_expiring' => $insuranceExpiring,
                'insurance_expired' => $insuranceExpired,
                'open_alerts' => $openAlerts,
                'critical_alerts' => $criticalAlerts,
            ],
            'can' => [
                // Recorded checks are reviewed on the vehicle profile.
                'view_vehicles' => (bool) $user?->canDo('fleet.viewAny'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['asset_id' => ['required', 'integer']]);
        // A vehicle outside the person's fleet site scope (or missing) is not
        // found; the service resolves it again under its lock.
        $run = $this->dailyChecks->record(
            $request->user(),
            (int) $data['asset_id'],
            $request->only(['condition', 'notes']),
            (string) $request->input('request_key', ''),
        );

        return back()->with('success', $run->outcome === FleetChecklistRun::OUTCOME_ISSUE
            ? 'Daily check recorded with an issue. The vehicle can still be booked, so tell your coordinator or report it to Maintenance.'
            : 'Daily check recorded.');
    }
}
