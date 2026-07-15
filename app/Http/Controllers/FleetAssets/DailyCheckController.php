<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DailyCheckController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');

        // Get vehicles, optionally filtered to user's site
        $query = Asset::vehicles();

        if ($hasFleetFields && $request->user()?->site_id) {
            $query->where(function ($q) use ($request) {
                $q->where('home_site_id', $request->user()->site_id)
                  ->orWhere('site_id', $request->user()->site_id);
            });
        }

        $vehicles = $query->orderBy('name')->get(['id', 'name', 'asset_tag', 'status']);

        // Get today's checks
        $today = now()->startOfDay();
        $todayChecks = FleetChecklistRun::query()
            ->where('completed_at', '>=', $today)
            ->whereHas('template', function ($q) {
                $q->where('type', 'daily_check');
            })
            ->get()
            ->keyBy('asset_id');

        $vehicleData = $vehicles->map(function ($v) use ($todayChecks) {
            $check = $todayChecks->get($v->id);
            return [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'status' => $v->status,
                'checked_today' => $check !== null,
                'check_result' => $check ? ($check->passed ? 'good' : 'issue') : null,
                'check_notes' => $check?->notes,
                'checked_at' => $check?->completed_at?->toISOString(),
                'checked_by' => $check?->user?->name ?? null,
            ];
        })->values();

        $checkedCount = $vehicleData->where('checked_today', true)->count();

        // Roadworthiness badges (org-wide) — same COUNT patterns as
        // VehicleController::index; the pre-drive check is exactly where an
        // expired WOF must be visible.
        $wofDue = Asset::query()->where(fn ($q) => $q->vehicles())->wofExpiring(30)->count();
        $wofExpired = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->whereNotNull('wof_expires_at')
            ->where('wof_expires_at', '<', now())
            ->count();
        $regoDue = Asset::query()->where(fn ($q) => $q->vehicles())->registrationExpiring(30)->count();
        $regoExpired = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->whereNotNull('registration_expires_at')
            ->where('registration_expires_at', '<', now())
            ->count();
        $cofDue = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->whereNotNull('cof_expires_at')
            ->where('cof_expires_at', '<=', now()->addDays(30))
            ->where('cof_expires_at', '>=', now())
            ->count();
        $cofExpired = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->whereNotNull('cof_expires_at')
            ->where('cof_expires_at', '<', now())
            ->count();
        $hasInsuranceExpiry = Schema::hasColumn('assets', 'insurance_expires_at');
        $insuranceExpiring = $hasInsuranceExpiry
            ? Asset::query()
                ->where(fn ($q) => $q->vehicles())
                ->whereNotNull('insurance_expires_at')
                ->where('insurance_expires_at', '<=', now()->addDays(30))
                ->where('insurance_expires_at', '>=', now())
                ->count()
            : null;
        $insuranceExpired = $hasInsuranceExpiry
            ? Asset::query()
                ->where(fn ($q) => $q->vehicles())
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
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'condition' => ['required', 'string', 'in:good,issue'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Find or create the daily_check template
        $template = FleetChecklistTemplate::firstOrCreate(
            ['type' => 'daily_check'],
            [
                'name' => 'Daily Vehicle Check',
                'type' => 'daily_check',
                'items' => [
                    ['label' => 'Visual Condition', 'type' => 'select', 'options' => ['good', 'issue']],
                    ['label' => 'Notes', 'type' => 'text'],
                ],
                'is_active' => true,
            ]
        );

        // Check if already checked today
        $today = now()->startOfDay();
        $existing = FleetChecklistRun::query()
            ->where('asset_id', $data['asset_id'])
            ->where('template_id', $template->id)
            ->where('completed_at', '>=', $today)
            ->first();

        if ($existing) {
            // Update existing check
            $existing->update([
                'responses' => [
                    'condition' => $data['condition'],
                ],
                'passed' => $data['condition'] === 'good',
                'notes' => $data['notes'] ?? null,
                'user_id' => $request->user()->id,
                'completed_at' => now(),
            ]);
        } else {
            FleetChecklistRun::create([
                'template_id' => $template->id,
                'asset_id' => $data['asset_id'],
                'user_id' => $request->user()->id,
                'responses' => [
                    'condition' => $data['condition'],
                ],
                'passed' => $data['condition'] === 'good',
                'notes' => $data['notes'] ?? null,
                'completed_at' => now(),
            ]);
        }

        return back()->with('success', 'Daily check recorded.');
    }
}
