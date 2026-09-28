<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fleet\ComplianceQueueProjection;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\VehicleWorkspacePresenter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function __construct(private readonly SecurityDevicesAccessService $access, private readonly ComplianceQueueProjection $projection) {}

    public function context(Request $request, int $asset, VehicleWorkspacePresenter $presenter)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $this->access->canReadFleetVehicles($actor), 403);
        $vehicle = $this->access->fleetVehicle($actor, $asset) ?? abort(404);
        $workspace = $presenter->present($actor, $vehicle, false);

        return response()->json(collect($workspace)->only(['vehicle', 'compliance', 'can', 'catalogues']))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'],
            'view' => ['nullable', 'in:attention,all,current,not_applicable'],
            'state' => ['nullable', 'in:all,not_recorded,needs_assessment,not_applicable,current,recorded,due_soon,expired,failed,restricted,failed_restricted'],
            'kind' => ['nullable', 'in:all,wof,registration,cof,ruc,insurance,restriction'],
            'layout' => ['nullable', 'in:table,cards'],
        ]);
        $query = $this->access->canReadFleetVehicles($actor)
            ? $this->access->accessibleVehiclesForFleet($actor)
            : $this->access->accessibleAssets($actor, true);
        $vehicles = $query->with(['homeSite:id,name', 'site:id,name', 'fleetResponsible:id,name'])->orderBy('name')->get();
        $sites = $vehicles->map(fn ($vehicle) => $vehicle->homeSite ?? $vehicle->site)->filter()->unique('id')->map->only(['id', 'name'])->values();
        if (! empty($filters['site_id'])) {
            $vehicles = $vehicles->filter(fn ($vehicle) => (int) ($vehicle->home_site_id ?? $vehicle->site_id) === (int) $filters['site_id'])->values();
        }
        $maintenance = app(MaintenanceAccessService::class);
        $planningSites = $maintenance->canManage($actor) ? $maintenance->approvedSiteIds($actor) : [];
        $vehicleSites = $vehicles->pluck('site_id', 'id');
        $rows = $this->projection->rows($vehicles)->map(fn ($row) => [
            ...$row,
            'can_plan' => in_array((int) $vehicleSites[$row['vehicle']['id']], $planningSites, true),
        ]);
        $attention = fn ($row) => ! in_array($row['state'], ['current', 'not_applicable', 'recorded'], true);
        $unique = fn ($items) => $items->pluck('vehicle.id')->unique()->count();
        $summary = [
            'vehicles' => $vehicles->count(), 'attention' => $unique($rows->filter($attention)),
            'not_recorded' => $unique($rows->where('state', 'not_recorded')),
            'due_soon' => $unique($rows->where('state', 'due_soon')),
            'failed_restricted' => $unique($rows->whereIn('state', ['failed', 'restricted'])),
        ];
        $view = $filters['view'] ?? 'attention';
        $filtered = $rows->filter(function ($row) use ($filters, $view, $attention) {
            if ($view === 'attention' && ! $attention($row)) {
                return false;
            }
            if ($view === 'current' && $row['state'] !== 'current') {
                return false;
            }
            if ($view === 'not_applicable' && $row['state'] !== 'not_applicable') {
                return false;
            }
            $state = $filters['state'] ?? 'all';
            if ($state === 'failed_restricted' && ! in_array($row['state'], ['failed', 'restricted'], true)) {
                return false;
            }
            if (! in_array($state, ['all', 'failed_restricted'], true) && $row['state'] !== $state) {
                return false;
            }
            if (($filters['kind'] ?? 'all') !== 'all' && $row['kind'] !== $filters['kind']) {
                return false;
            }
            $text = implode(' ', [$row['vehicle']['name'], $row['vehicle']['asset_tag'], $row['vehicle']['registration_number'], $row['label'], $row['reference']]);

            return empty($filters['search']) || mb_stripos($text, trim($filters['search'])) !== false;
        })->values();
        $requestedPage = filter_var($request->input('page', 1), FILTER_VALIDATE_INT);
        $page = $requestedPage && $requestedPage > 0 && $requestedPage <= max(1, (int) ceil($filtered->count() / 25)) ? $requestedPage : 1;
        $queue = new LengthAwarePaginator($filtered->forPage($page, 25)->values(), $filtered->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('fleet-assets/compliance/index', [
            'queue' => $queue, 'summary' => $summary, 'sites' => $sites, 'filters' => [...$filters, 'view' => $view],
            'can' => ['manage' => $actor->canDo('fleet.manage')],
        ]);
    }
}
