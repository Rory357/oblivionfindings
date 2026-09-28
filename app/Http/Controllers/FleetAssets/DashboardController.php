<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Services\Fleet\FleetAlertScope;
use App\Services\Fleet\FleetOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Fleet & Assets Overview is a read-only lens over canonical sources. */
class DashboardController extends Controller
{
    public function __construct(private readonly FleetOverviewService $overview) {}

    public function __invoke(Request $request): Response
    {
        $site = $request->query('site');
        $receiptSiteId = is_string($site) && ctype_digit($site) && (int) $site > 0 ? (int) $site : null;
        $search = $request->query('q');
        $receiptSearch = is_string($search) ? mb_substr(trim($search), 0, 120) : '';

        return Inertia::render('fleet-assets/dashboard', [
            'overview' => [
                ...$this->overview->present($request->user(), $receiptSiteId, $receiptSearch, $request->integer('receipt_page', 1)),
                'fleet_alert_count' => $request->user()->canDo('assets.viewAny') || $request->user()->canDo('assets.alerts.view')
                    ? app(FleetAlertScope::class)->query($request->user(), ['site_id' => $receiptSiteId, 'entity' => $request->input('entity')])->actionable()->count()
                    : null,
            ],
            'saved_views' => $request->user()->uiPreferences()
                ->where('key', 'fleet.overview.saved-views')->first()?->value ?? [],
        ]);
    }
}
