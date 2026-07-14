<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\ControlRoomAlertController as CanonicalAlertController;
use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class AlertController extends Controller
{
    /**
     * Acknowledge a control room alert from the fleet module.
     */
    public function acknowledge(
        Request $request,
        ControlRoomAlert $alert,
        CanonicalAlertController $canonical,
        ControlRoomAlertLifecycleService $lifecycle,
    )
    {
        $this->assertFleetAlert($alert);
        $this->siteAccess()->assertCanAccessAlert(
            $request->user(),
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to acknowledge this fleet alert.',
        );

        return $canonical->acknowledge($request, $alert, $lifecycle);
    }

    /**
     * Start the canonical Control Room triage step from the fleet module.
     */
    public function triage(
        Request $request,
        ControlRoomAlert $alert,
        CanonicalAlertController $canonical,
        ControlRoomAlertLifecycleService $lifecycle,
    )
    {
        $this->assertFleetAlert($alert);
        $this->siteAccess()->assertCanAccessAlert(
            $request->user(),
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to triage this fleet alert.',
        );

        return $canonical->triage($request, $alert, $lifecycle);
    }

    /**
     * Resolve a control room alert from the fleet module.
     */
    public function resolve(
        Request $request,
        ControlRoomAlert $alert,
        CanonicalAlertController $canonical,
        ControlRoomAlertLifecycleService $lifecycle,
    )
    {
        $this->assertFleetAlert($alert);
        $this->siteAccess()->assertCanAccessAlert(
            $request->user(),
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to resolve this fleet alert.',
        );

        return $canonical->resolve($request, $alert, $lifecycle);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();
        $provenance = $this->alertProvenance();

        if ($request->filled('asset_id')) {
            $this->assertCanAccessAssetId($user, (int) $request->input('asset_id'));
        }

        // Canonical operational alerts from fleet/asset sources.
        $crQuery = ControlRoomAlert::query()
            ->with([
                'asset:id,name,asset_tag,site_id,home_site_id,client_id',
                'asset.client:id,site_id,organization_id',
                'fleetSignal:id,asset_id',
                'fleetSignal.asset:id,site_id,home_site_id,client_id',
                'fleetSignal.asset.client:id,site_id,organization_id',
                'assignedTo:id,name',
            ])
            ->whereIn('source', ['fleet', 'asset', 'tracker', 'geofence']);
        $siteAccess->applyAlertScope($crQuery, $user, $bypassPermissions);

        if ($request->filled('status')) {
            $crQuery->where('status', $request->input('status'));
        } else {
            // Default to unresolved
            $crQuery->actionable();
        }

        if ($request->filled('severity')) {
            $crQuery->where('severity', $request->input('severity'));
        }

        if ($request->filled('asset_id')) {
            $crQuery->where('asset_id', (int) $request->input('asset_id'));
        }

        // Sorting
        $allowedSorts = ['triggered_at', 'severity', 'status'];
        $sort = $request->input('sort', 'triggered_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'triggered_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';

        $controlRoomAlerts = $crQuery->orderBy($sort, $direction)
            ->paginate(25, ['*'], 'cr_page')
            ->withQueryString();

        // Archived legacy asset_alerts history.
        $archivedAssetAlertQuery = AssetAlert::query()
            ->with(['asset:id,name,asset_tag', 'tracker:id,vendor,device_uid']);
        $this->applyArchivedAssetAlertScope($archivedAssetAlertQuery, $user);

        if ($request->filled('status')) {
            $archivedAssetAlertQuery->where('status', $request->input('status'));
        }

        if ($request->filled('severity')) {
            $archivedAssetAlertQuery->where('severity', $request->input('severity'));
        }

        if ($request->filled('asset_id')) {
            $archivedAssetAlertQuery->where('asset_id', (int) $request->input('asset_id'));
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
        $heroBase = ControlRoomAlert::query()->whereIn('source', $this->fleetAlertSources());
        $siteAccess->applyAlertScope($heroBase, $user, $bypassPermissions);
        $hero = [
            'unresolved' => (clone $heroBase)->actionable()->count(),
            'critical' => (clone $heroBase)->actionable()->where('severity', 'critical')->count(),
            'acknowledged_today' => (clone $heroBase)->where('acknowledged_at', '>=', now()->startOfDay())->count(),
            'resolved_7d' => (clone $heroBase)->where('resolved_at', '>=', now()->subDays(7))->count(),
        ];

        return Inertia::render('fleet-assets/alerts/index', [
            'hero' => $hero,
            'control_room_alerts' => [
                'data' => $controlRoomAlerts->getCollection()
                    ->map(fn (ControlRoomAlert $alert) => $this->mapControlRoomAlert($alert, $provenance))
                    ->values(),
                'links' => $controlRoomAlerts->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $controlRoomAlerts->currentPage(),
                    'last_page' => $controlRoomAlerts->lastPage(),
                    'total' => $controlRoomAlerts->total(),
                ],
            ],
            'archived_asset_alerts' => $archivedAssetAlerts,
            'filters' => $request->only(['status', 'severity', 'asset_id']),
            'can' => [
                'manage' => (bool) $request->user()?->canDo('controlRoom.alerts.manage'),
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
            'resolution_notes' => ['required_if:action,resolve', 'nullable', 'string', 'max:2000'],
        ]);

        $ids = collect($data['ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $alerts = ControlRoomAlert::query()
            ->with('sla')
            ->whereIn('source', $this->fleetAlertSources())
            ->whereIn('id', $ids)
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
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
            'source' => 'control_room',
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
        if ($this->hasInstallationWideAssetAccess($user)) {
            return;
        }

        $siteIds = $this->siteAccess()->accessibleSiteIds($user, $this->alertBypassPermissions());
        $query = Asset::query()->whereKey($assetId);
        $this->applyAssetSiteScope(
            $query,
            $siteIds,
            $user?->organization_id === null ? null : (int) $user->organization_id,
        );

        abort_unless($query->exists(), 403, 'You are not authorized to access fleet alerts for that asset.');
    }

    protected function applyArchivedAssetAlertScope($query, $user): void
    {
        if ($this->hasInstallationWideAssetAccess($user)) {
            return;
        }

        $siteIds = $this->siteAccess()->accessibleSiteIds($user, $this->alertBypassPermissions());
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $organizationId = $user?->organization_id;
        $query->whereHas('asset', fn ($assetQuery) => $this->applyAssetSiteScope(
            $assetQuery,
            $siteIds,
            $organizationId === null ? null : (int) $organizationId,
        ));
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyAssetSiteScope(
        $query,
        array $siteIds,
        ?int $organizationId,
    ): void
    {
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $assetSiteColumn = $query->qualifyColumn('site_id');
        $assetHomeSiteColumn = $query->qualifyColumn('home_site_id');
        $assetClientColumn = $query->qualifyColumn('client_id');

        $query->where(function ($provenance) use (
            $siteIds,
            $organizationId,
            $assetSiteColumn,
            $assetHomeSiteColumn,
            $assetClientColumn,
        ) {
            $provenance->where(function ($directSite) use (
                $siteIds,
                $organizationId,
                $assetSiteColumn,
                $assetClientColumn,
            ) {
                $directSite
                    ->whereIn($assetSiteColumn, $siteIds)
                    ->where(function ($clientAgreement) use (
                        $organizationId,
                        $assetSiteColumn,
                        $assetClientColumn,
                    ) {
                        $clientAgreement
                            ->whereNull($assetClientColumn)
                            ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                                ->where('organization_id', $organizationId)
                                ->whereColumn(
                                    $clientQuery->qualifyColumn('site_id'),
                                    $assetSiteColumn,
                                ));
                    });
            })->orWhere(function ($homeSite) use (
                $siteIds,
                $organizationId,
                $assetSiteColumn,
                $assetHomeSiteColumn,
                $assetClientColumn,
            ) {
                $homeSite
                    ->whereNull($assetSiteColumn)
                    ->whereIn($assetHomeSiteColumn, $siteIds)
                    ->where(function ($clientAgreement) use (
                        $organizationId,
                        $assetHomeSiteColumn,
                        $assetClientColumn,
                    ) {
                        $clientAgreement
                            ->whereNull($assetClientColumn)
                            ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                                ->where('organization_id', $organizationId)
                                ->whereColumn(
                                    $clientQuery->qualifyColumn('site_id'),
                                    $assetHomeSiteColumn,
                                ));
                    });
            })->orWhere(function ($clientFallback) use (
                $siteIds,
                $organizationId,
                $assetSiteColumn,
                $assetHomeSiteColumn,
                $assetClientColumn,
            ) {
                $clientFallback
                    ->whereNull($assetSiteColumn)
                    ->whereNull($assetHomeSiteColumn)
                    ->whereNotNull($assetClientColumn)
                    ->whereHas('client', fn ($clientQuery) => $clientQuery
                        ->where('organization_id', $organizationId)
                        ->whereIn('site_id', $siteIds));
            });
        });
    }

    protected function hasInstallationWideAssetAccess($user): bool
    {
        return $this->siteAccess()->canBypass($user, $this->alertBypassPermissions())
            && $this->siteAccess()->isUnrestrictedPlatformUser($user);
    }

    /**
     * @return array<int, string>
     */
    protected function fleetAlertSources(): array
    {
        return ['fleet', 'asset', 'tracker', 'geofence'];
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
        return ['reports.viewAny', 'fleet.manage'];
    }
}
