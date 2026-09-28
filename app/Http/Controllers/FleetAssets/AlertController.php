<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\ControlRoomAlertController as CanonicalAlertController;
use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\Fleet\FleetAlertScope;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use InvalidArgumentException;

class AlertController extends Controller
{
    public function snapshot(Request $request, int $alert)
    {
        $row = app(FleetAlertScope::class)->query($request->user())->whereKey($alert)
            ->with(['asset.client', 'fleetSignal.asset.client', 'assignedTo:id,name', 'site:id,name'])->firstOrFail();
        $responseScope = ControlRoomAlert::query()->whereKey($alert);
        app(ControlRoomAlertAccessService::class)->applyReadableScope($responseScope, $request->user());
        $readable = $responseScope->exists();

        return response()->json([
            ...$this->mapControlRoomAlert($row, $this->alertProvenance()),
            'can_open_control_room' => $readable,
            'can_respond' => $request->user()->canDo('controlRoom.alerts.manage') && $readable,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Acknowledge a control room alert from the fleet module.
     */
    public function acknowledge(
        Request $request,
        ControlRoomAlert $alert,
        CanonicalAlertController $canonical,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $this->assertFleetAlert($alert);
        $this->siteAccess()->assertCanAccessAlert(
            $request->user(),
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to acknowledge this fleet alert.',
        );

        return $request->wantsJson() ? $this->respond($request, $alert, $lifecycle, 'acknowledge') : $canonical->acknowledge($request, $alert, $lifecycle);
    }

    /**
     * Start the canonical Control Room triage step from the fleet module.
     */
    public function triage(
        Request $request,
        ControlRoomAlert $alert,
        CanonicalAlertController $canonical,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $this->assertFleetAlert($alert);
        $this->siteAccess()->assertCanAccessAlert(
            $request->user(),
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to triage this fleet alert.',
        );

        return $request->wantsJson() ? $this->respond($request, $alert, $lifecycle, 'triage') : $canonical->triage($request, $alert, $lifecycle);
    }

    /**
     * Resolve a control room alert from the fleet module.
     */
    public function resolve(
        Request $request,
        ControlRoomAlert $alert,
        CanonicalAlertController $canonical,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $this->assertFleetAlert($alert);
        $this->siteAccess()->assertCanAccessAlert(
            $request->user(),
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to resolve this fleet alert.',
        );

        return $request->wantsJson() ? $this->respond($request, $alert, $lifecycle, 'resolve') : $canonical->resolve($request, $alert, $lifecycle);
    }

    /** JSON adapter for the Fleet wizard; the canonical lifecycle owns every write. */
    private function respond(Request $request, ControlRoomAlert $alert, ControlRoomAlertLifecycleService $lifecycle, string $action)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('controlRoom.alerts.manage'), 403);
        $data = $request->validate([
            'expected_status' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'resolution_notes' => [$action === 'resolve' ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($actor, $alert, $data, $action, $lifecycle) {
            $locked = ControlRoomAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            $this->assertFleetAlert($locked);
            // Match the canonical controller's access rule as well as the Fleet projection.
            app(ControlRoomAlertAccessService::class)->assertCanView($locked, $actor);
            abort_unless($locked->status === $data['expected_status'], 409, 'This alert changed. Review its latest state before responding.');
            $updated = $this->applyResponse($locked, $actor, $data, $action, $lifecycle);

            return response()->json(['id' => $updated->id, 'status' => $updated->status]);
        }, 3);
    }

    private function applyResponse(ControlRoomAlert $alert, User $actor, array $data, string $action, ControlRoomAlertLifecycleService $lifecycle): ControlRoomAlert
    {
        try {
            return match ($action) {
                'acknowledge' => $lifecycle->acknowledge($alert, $actor, $data['notes'] ?? null),
                'triage' => $lifecycle->startTriage($alert, $actor, $data['notes'] ?? null),
                'resolve' => $lifecycle->resolve($alert, $actor, trim($data['resolution_notes']), $alert->resolution_code ?? 'resolved'),
            };
        } catch (InvalidArgumentException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();
        $provenance = $this->alertProvenance();
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'], 'asset_id' => ['nullable', 'integer', 'min:1'],
            'entity' => ['nullable', 'in:all,vehicle,asset'], 'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:unresolved,all,open,ack,triaging,confirmed,resolved,closed,dismissed'],
            'severity' => ['nullable', 'in:all,critical,high,medium,low'], 'layout' => ['nullable', 'in:table,cards'],
            'sort' => ['nullable', 'in:triggered_at,severity,status'], 'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $scope = app(FleetAlertScope::class)->query($user, $filters);

        if ($request->filled('asset_id')) {
            $this->assertCanAccessAssetId($user, (int) $request->input('asset_id'));
        }

        // Canonical operational alerts from fleet/asset sources.
        $crQuery = (clone $scope)
            ->with([
                'asset:id,name,asset_tag,category,site_id,home_site_id,client_id',
                'asset.client:id,site_id',
                'fleetSignal:id,asset_id',
                'fleetSignal.asset:id,site_id,home_site_id,client_id',
                'fleetSignal.asset.client:id,site_id',
                'assignedTo:id,name',
                'site:id,name',
            ]);

        if ($request->filled('status') && ! in_array($request->input('status'), ['all', 'unresolved'], true)) {
            $crQuery->where('status', $request->input('status'));
        } elseif ($request->input('status') !== 'all') {
            // Default to unresolved
            $crQuery->actionable();
        }

        if ($request->filled('search')) {
            $search = '%'.trim($request->string('search')).'%';
            $crQuery->where(function ($query) use ($search) {
                $query->where('alert_type', 'like', $search)->orWhere('source', 'like', $search)
                    ->orWhere('reference_number', 'like', $search)
                    ->orWhereHas('asset', fn ($asset) => $asset->where('name', 'like', $search)->orWhere('asset_tag', 'like', $search));
            });
        }
        // The four instruments describe this status/search before severity, across every page.
        $severityCounts = (clone $crQuery)->select('severity', DB::raw('count(*) as aggregate'))->groupBy('severity')->pluck('aggregate', 'severity');
        $severity = collect(['critical', 'high', 'medium', 'low'])->mapWithKeys(fn ($key) => [$key => (int) ($severityCounts[$key] ?? 0)])->all();
        if ($request->filled('severity') && $request->input('severity') !== 'all') {
            $crQuery->where('severity', $request->input('severity'));
        }

        if ($request->filled('asset_id')) {
            $crQuery->where('asset_id', (int) $request->input('asset_id'));
        }

        // Sorting
        $allowedSorts = ['triggered_at', 'severity', 'status'];
        $sort = $request->input('sort', 'triggered_at');
        $direction = $request->input('direction', 'desc');
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'triggered_at';
        }
        if (! in_array($direction, ['asc', 'desc'])) {
            $direction = 'desc';
        }

        $total = (clone $crQuery)->count();
        $requestedPage = filter_var($request->input('cr_page', 1), FILTER_VALIDATE_INT);
        $page = $requestedPage && $requestedPage > 0 && $requestedPage <= max(1, (int) ceil($total / 25)) ? $requestedPage : 1;
        $controlRoomAlerts = $crQuery->orderBy($sort, $direction)->orderBy('id')
            ->paginate(25, ['*'], 'cr_page', $page)
            ->withQueryString();
        $responseScope = ControlRoomAlert::query()->whereKey($controlRoomAlerts->getCollection()->modelKeys());
        app(ControlRoomAlertAccessService::class)->applyReadableScope($responseScope, $user);
        $readableIds = $responseScope->pluck('id')->all();
        $respondIds = $user->canDo('controlRoom.alerts.manage') ? $readableIds : [];

        // Archived legacy asset_alerts history.
        $archivedAssetAlertQuery = AssetAlert::query()
            ->with(['asset:id,name,asset_tag', 'tracker:id,vendor,device_uid']);
        $this->applyArchivedAssetAlertScope($archivedAssetAlertQuery, $user);

        if ($request->filled('status') && ! in_array($request->input('status'), ['all', 'unresolved'], true)) {
            $archivedAssetAlertQuery->where('status', $request->input('status'));
        }

        if ($request->filled('severity') && $request->input('severity') !== 'all') {
            $archivedAssetAlertQuery->where('severity', $request->input('severity'));
        }

        if ($request->filled('asset_id')) {
            $archivedAssetAlertQuery->where('asset_id', (int) $request->input('asset_id'));
        }
        if ($request->filled('site_id')) {
            $archivedAssetAlertQuery->whereHas('asset', fn ($asset) => $this->applyAssetSiteScope($asset, [(int) $request->input('site_id')]));
        }

        $archivedAssetAlerts = $archivedAssetAlertQuery->latest('triggered_at')
            ->limit(25)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => optional($a->triggered_at)->toISOString(),
                'acknowledged_at' => optional($a->acknowledged_at)->toISOString(),
                'resolved_at' => optional($a->resolved_at)->toISOString(),
                'context' => $a->context,
                'asset' => $a->asset ? ['id' => $a->asset->id, 'name' => $a->asset->name, 'asset_tag' => $a->asset->asset_tag] : null,
                'tracker' => $a->tracker ? ['id' => $a->tracker->id, 'vendor' => $a->tracker->vendor, 'device_uid' => $a->tracker->device_uid] : null,
            ])->values();

        // Hero — whole fleet-alert universe (independent of filters/pagination).
        $heroBase = clone $scope;
        $hero = [
            'total' => (clone $heroBase)->count(),
            'unresolved' => (clone $heroBase)->actionable()->count(),
            'critical' => (clone $heroBase)->actionable()->where('severity', 'critical')->count(),
            'acknowledged_today' => (clone $heroBase)->where('acknowledged_at', '>=', now()->startOfDay())->count(),
            'resolved_7d' => (clone $heroBase)->where('resolved_at', '>=', now()->subDays(7))->count(),
        ];

        return Inertia::render('fleet-assets/alerts/index', [
            'hero' => $hero,
            'severity_counts' => $severity,
            'severity_total' => array_sum($severity),
            'sites' => Site::query()->whereIn('id', $siteAccess->accessibleSiteIds($user, $bypassPermissions))->orderBy('name')->get(['id', 'name']),
            'snapshot_at' => now()->toIso8601String(),
            'control_room_alerts' => [
                'data' => $controlRoomAlerts->getCollection()
                    ->map(fn (ControlRoomAlert $alert) => [...$this->mapControlRoomAlert($alert, $provenance), 'can_respond' => in_array($alert->id, $respondIds, true), 'can_open_control_room' => in_array($alert->id, $readableIds, true)])
                    ->values(),
                'links' => $controlRoomAlerts->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $controlRoomAlerts->currentPage(),
                    'last_page' => $controlRoomAlerts->lastPage(),
                    'total' => $controlRoomAlerts->total(),
                    'from' => $controlRoomAlerts->firstItem(),
                    'to' => $controlRoomAlerts->lastItem(),
                ],
            ],
            'archived_asset_alerts' => $archivedAssetAlerts,
            'filters' => [...$filters, 'status' => $filters['status'] ?? 'unresolved'],
            'can' => [
                'manage' => (bool) $request->user()?->canDo('controlRoom.alerts.manage'),
                'control_room' => app(ControlRoomAlertAccessService::class)->canRead($user),
            ],
        ]);
    }

    public function bulkAction(Request $request, ControlRoomAlertLifecycleService $lifecycle)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:acknowledge,triage,resolve'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_statuses' => [$request->wantsJson() ? 'required' : 'nullable', 'array'],
            'expected_statuses.*' => ['required', 'string'],
            'resolution_notes' => ['required_if:action,resolve', 'nullable', 'string', 'max:2000'],
        ]);

        $ids = collect($data['ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($request->wantsJson()) {
            return DB::transaction(function () use ($user, $ids, $data, $lifecycle) {
                $query = app(FleetAlertScope::class)->query($user)->whereKey($ids)->orderBy('id');
                app(ControlRoomAlertAccessService::class)->applyReadableScope($query, $user);
                $alerts = $query->lockForUpdate()->get();
                abort_unless($alerts->count() === $ids->count(), 403, 'One or more selected alerts are no longer available.');
                foreach ($alerts as $alert) {
                    abort_unless($alert->status === ($data['expected_statuses'][$alert->id] ?? null), 409, 'A selected alert changed. Review the latest state before responding.');
                    $this->applyResponse($alert, $user, $data, $data['action'], $lifecycle);
                }

                return response()->json(['updated' => $alerts->count()]);
            }, 3);
        }

        $alerts = ControlRoomAlert::query()
            ->with('sla')
            ->whereIn('source', $this->fleetAlertSources())
            ->whereIn('id', $ids)
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
            ->tap(fn ($query) => app(ControlRoomAlertAccessService::class)->applyReadableScope($query, $user))
            ->get();

        abort_if(
            $alerts->count() !== $ids->count(),
            403,
            'You are not authorized to update one or more selected alerts.',
        );

        $count = 0;
        $skipped = 0;

        foreach ($alerts as $alert) {
            try {
                if ($data['action'] === 'acknowledge') {
                    $lifecycle->acknowledge($alert, $user);
                } elseif ($data['action'] === 'triage') {
                    $lifecycle->startTriage($alert, $user);
                } else {
                    $lifecycle->resolve(
                        $alert,
                        $user,
                        $data['resolution_notes'],
                        'fleet_bulk_resolution',
                    );
                }
            } catch (InvalidArgumentException) {
                $skipped++;

                continue;
            }

            $count++;
        }

        $message = "{$count} alert(s) {$data['action']}d.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped because their current status cannot transition.";
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapControlRoomAlert(
        ControlRoomAlert $alert,
        ControlRoomAlertProvenanceService $provenance,
    ): array {
        $safeAsset = $alert->asset && $provenance->assetMatchesAlert($alert, $alert->asset)
            ? $alert->asset
            : null;
        $unsafeFleetReference = ($alert->asset_id !== null && $safeAsset === null)
            || ($alert->fleet_signal_id !== null
                && ! $provenance->fleetSignalMatchesAlert($alert, $alert->fleetSignal));
        $context = is_array($alert->context) ? $alert->context : [];

        if ($unsafeFleetReference) {
            $context = $this->sanitiseUnsafeFleetContext($context);
        }

        return [
            'id' => $alert->id,
            'source' => $alert->source,
            'reference' => $alert->reference_number ?: 'CR-'.$alert->id,
            'updated_at' => optional($alert->updated_at)->toISOString(),
            'site' => $alert->site ? ['id' => $alert->site->id, 'name' => $alert->site->name] : null,
            'alert_type' => $alert->alert_type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'triggered_at' => optional($alert->triggered_at)->toISOString(),
            'acknowledged_at' => optional($alert->acknowledged_at)->toISOString(),
            'resolved_at' => optional($alert->resolved_at)->toISOString(),
            'context' => $context,
            'notes' => $alert->notes,
            'asset' => $safeAsset ? [
                'id' => $safeAsset->id,
                'name' => $safeAsset->name,
                'asset_tag' => $safeAsset->asset_tag,
                'category' => strtolower($safeAsset->category ?? $safeAsset->categoryRef?->slug ?? 'asset'),
                'href' => $this->resourceHref($safeAsset),
            ] : null,
            'assigned_to' => $alert->assignedTo ? [
                'id' => $alert->assignedTo->id,
                'name' => $alert->assignedTo->name,
            ] : null,
        ];
    }

    /**
     * Preserve lifecycle notes while removing nested fleet identifiers and
     * location data whose linked asset or signal failed provenance validation.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function sanitiseUnsafeFleetContext(array $context): array
    {
        unset(
            $context['fleet_context'],
            $context['asset_id'],
            $context['fleet_signal_id'],
            $context['latitude'],
            $context['longitude'],
            $context['coordinates'],
        );

        if (is_array($context['normalized_data'] ?? null)) {
            unset(
                $context['normalized_data']['fleet_context'],
                $context['normalized_data']['asset_id'],
                $context['normalized_data']['fleet_signal_id'],
                $context['normalized_data']['latitude'],
                $context['normalized_data']['longitude'],
                $context['normalized_data']['coordinates'],
            );
        }

        return $context;
    }

    protected function assertFleetAlert(ControlRoomAlert $alert): void
    {
        abort_unless(in_array($alert->source, $this->fleetAlertSources(), true), 404);
    }

    protected function assertCanAccessAssetId($user, int $assetId): void
    {
        if ($this->hasApplicationWideAssetAccess($user)) {
            return;
        }

        $siteIds = $this->siteAccess()->accessibleSiteIds($user, $this->alertBypassPermissions());
        $query = Asset::query()->whereKey($assetId);
        $this->applyAssetSiteScope($query, $siteIds);

        abort_unless($query->exists(), 403, 'You are not authorized to access fleet alerts for that asset.');
    }

    protected function applyArchivedAssetAlertScope($query, $user): void
    {
        if ($this->hasApplicationWideAssetAccess($user)) {
            return;
        }

        $siteIds = $this->siteAccess()->accessibleSiteIds($user, $this->alertBypassPermissions());
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('asset', fn ($assetQuery) => $this->applyAssetSiteScope(
            $assetQuery,
            $siteIds,
        ));
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyAssetSiteScope(
        $query,
        array $siteIds,
    ): void {
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $assetSiteColumn = $query->qualifyColumn('site_id');
        $assetHomeSiteColumn = $query->qualifyColumn('home_site_id');
        $assetClientColumn = $query->qualifyColumn('client_id');

        $query->where(function ($provenance) use (
            $siteIds,
            $assetSiteColumn,
            $assetHomeSiteColumn,
            $assetClientColumn,
        ) {
            $provenance->where(function ($directSite) use (
                $siteIds,
                $assetSiteColumn,
                $assetClientColumn,
            ) {
                $directSite
                    ->whereIn($assetSiteColumn, $siteIds)
                    ->where(function ($clientAgreement) use (
                        $assetSiteColumn,
                        $assetClientColumn,
                    ) {
                        $clientAgreement
                            ->whereNull($assetClientColumn)
                            ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                                ->whereColumn(
                                    $clientQuery->qualifyColumn('site_id'),
                                    $assetSiteColumn,
                                ));
                    });
            })->orWhere(function ($homeSite) use (
                $siteIds,
                $assetSiteColumn,
                $assetHomeSiteColumn,
                $assetClientColumn,
            ) {
                $homeSite
                    ->whereNull($assetSiteColumn)
                    ->whereIn($assetHomeSiteColumn, $siteIds)
                    ->where(function ($clientAgreement) use (
                        $assetHomeSiteColumn,
                        $assetClientColumn,
                    ) {
                        $clientAgreement
                            ->whereNull($assetClientColumn)
                            ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                                ->whereColumn(
                                    $clientQuery->qualifyColumn('site_id'),
                                    $assetHomeSiteColumn,
                                ));
                    });
            })->orWhere(function ($clientFallback) use (
                $siteIds,
                $assetSiteColumn,
                $assetHomeSiteColumn,
                $assetClientColumn,
            ) {
                $clientFallback
                    ->whereNull($assetSiteColumn)
                    ->whereNull($assetHomeSiteColumn)
                    ->whereNotNull($assetClientColumn)
                    ->whereHas('client', fn ($clientQuery) => $clientQuery
                        ->whereIn('site_id', $siteIds));
            });
        });
    }

    protected function hasApplicationWideAssetAccess($user): bool
    {
        return $this->siteAccess()->canBypass($user, $this->alertBypassPermissions());
    }

    /**
     * @return array<int, string>
     */
    protected function fleetAlertSources(): array
    {
        return FleetAlertScope::SOURCES;
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    protected function alertProvenance(): ControlRoomAlertProvenanceService
    {
        return app(ControlRoomAlertProvenanceService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return FleetAlertScope::BYPASS;
    }

    private function resourceHref(Asset $asset): ?string
    {
        $access = app(SecurityDevicesAccessService::class);
        $actor = request()->user();
        if (strtolower($asset->category ?? '') === 'vehicle' || $asset->categoryRef?->slug === 'vehicle') {
            return $access->canReadFleetVehicles($actor) && $access->fleetVehicle($actor, (int) $asset->id)
                ? '/fleet-assets/vehicles/'.$asset->id.'?tab=map&view=alerts' : null;
        }

        return $access->accessibleAssets($actor)->whereKey($asset->id)->exists()
            ? '/fleet-assets/assets/'.$asset->id : null;
    }
}
