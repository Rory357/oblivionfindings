<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DailyCheckController extends Controller
{
    public function index(Request $request)
    {
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

        return Inertia::render('fleet-assets/daily-check', [
            'vehicles' => $vehicleData,
            'summary' => [
                'total' => $vehicleData->count(),
                'checked' => $checkedCount,
                'unchecked' => $vehicleData->count() - $checkedCount,
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
