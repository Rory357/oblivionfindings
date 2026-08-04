<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Compatibility surface for the retired Operations geofence register.
 *
 * Canonical geofences live in asset_geofences and are managed from Site Profile
 * or Fleet & Assets. This controller intentionally never reads from or writes to
 * geofence_zones; legacy mutation routes only guide users to the canonical owner.
 */
class GeofenceController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $this->authorizeView($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        if (! empty($filters['site_id'])) {
            $this->siteAccess->assertCanAccessSiteId(
                $auth,
                (int) $filters['site_id'],
                ['reports.viewAny'],
            );
        }

        $search = trim((string) ($filters['q'] ?? ''));
        $geofences = $this->accessibleSiteGeofences($auth)
            ->with('site:id,name')
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when(! empty($filters['site_id']), fn (Builder $query) => $query->where('site_id', $filters['site_id']))
            ->orderBy('name')
            ->paginate(20)
            ->through(fn (AssetGeofence $geofence) => $this->presentGeofence($geofence))
            ->withQueryString();

        return inertia('operations/geofences/Index', [
            'geofences' => $geofences,
            'sites' => $this->accessibleSites($auth),
            'filters' => [
                'q' => $filters['q'] ?? null,
                'site_id' => $filters['site_id'] ?? null,
            ],
            'canManage' => $this->canManageGeofences($auth),
            'migrationNotice' => 'Older Operations zones are retired. Existing legacy rows require an explicit Site mapping before they can be migrated; all new geofences are managed in the canonical Site Profile workflow.',
        ]);
    }

    public function create(Request $request)
    {
        $auth = $this->authorizeManage($request);
        $selectedSiteId = $request->integer('site_id') ?: null;

        if ($selectedSiteId) {
            $this->siteAccess->assertCanAccessSiteId(
                $auth,
                $selectedSiteId,
                ['reports.viewAny'],
            );
        }

        return inertia('operations/geofences/Create', [
            'sites' => $this->accessibleSites($auth),
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $auth = $this->authorizeManage($request);
        $siteId = $request->integer('site_id') ?: null;

        if ($siteId) {
            $this->siteAccess->assertCanAccessSiteId(
                $auth,
                $siteId,
                ['reports.viewAny'],
            );

            return redirect()->route('sites.show', $siteId)
                ->with('warning', 'No duplicate zone was created. Add or edit the Site geofence from Map & Site Geofence on this Site Profile.');
        }

        return redirect()->route('operations.geofences.create')
            ->with('warning', 'Choose an accessible Site, then manage its canonical geofence from Site Profile.');
    }

    public function update(Request $request, $zone): RedirectResponse
    {
        return $this->redirectLegacyMutation($request, (int) $zone);
    }

    public function destroy(Request $request, $zone): RedirectResponse
    {
        return $this->redirectLegacyMutation($request, (int) $zone);
    }

    private function redirectLegacyMutation(Request $request, int $zoneId): RedirectResponse
    {
        $auth = $this->authorizeManage($request);
        $canonical = AssetGeofence::query()->whereKey($zoneId)->first();

        if ($canonical) {
            abort_unless(
                $canonical->asset_id === null
                && $canonical->site_id
                && $this->accessibleSiteGeofences($auth)->whereKey($canonical->id)->exists(),
                404,
            );

            return redirect()->route('sites.show', $canonical->site_id)
                ->with('warning', 'This compatibility route is read-only. Update or remove the geofence from Map & Site Geofence on the Site Profile.');
        }

        return redirect()->route('operations.geofences.index')
            ->with('warning', 'That legacy zone is retired and was not changed. Re-create it under an accessible Site Profile after confirming its Site mapping.');
    }

    private function authorizeView(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $this->canAccessGeofences($user), 403);

        return $user;
    }

    private function authorizeManage(Request $request): User
    {
        $user = $this->authorizeView($request);
        abort_unless($this->canManageGeofences($user), 403);

        return $user;
    }

    private function canAccessGeofences(User $user): bool
    {
        return $user->canDo('geofences.viewAny')
            || $user->canDo('evv.viewAny')
            || $user->canDo('fleet.viewAny')
            || $user->canDo('assets.viewAny')
            || $this->canManageGeofences($user);
    }

    private function canManageGeofences(User $user): bool
    {
        return $user->canDo('assets.geofences.manage')
            && $user->canDo('sites.viewAny');
    }

    private function accessibleSiteGeofences(User $user): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($user, ['reports.viewAny']);
        if ($siteIds === []) {
            return AssetGeofence::query()->whereRaw('1 = 0');
        }

        return AssetGeofence::query()
            ->whereNull('asset_id')
            ->whereIn('site_id', $siteIds);
    }

    private function accessibleSites(User $user)
    {
        return $this->siteAccess->applySiteScope(
            Site::query()->active()->notArchived()->orderBy('name'),
            $user,
            ['reports.viewAny'],
        )->get(['id', 'name']);
    }

    /** @return array<string, mixed> */
    private function presentGeofence(AssetGeofence $geofence): array
    {
        $shape = $geofence->shape ?? [];
        $center = is_array($shape['center'] ?? null) ? $shape['center'] : [];

        return [
            'id' => $geofence->id,
            'name' => $geofence->name,
            'type' => $geofence->type,
            'scope' => $geofence->scope,
            'radius_meters' => (float) ($shape['radius_m'] ?? 0),
            'latitude' => (float) ($shape['lat'] ?? $center['lat'] ?? 0),
            'longitude' => (float) ($shape['lon'] ?? $shape['lng'] ?? $center['lon'] ?? $center['lng'] ?? 0),
            'is_active' => (bool) $geofence->is_active,
            'site' => $geofence->site ? [
                'id' => $geofence->site->id,
                'name' => $geofence->site->name,
            ] : null,
            'canonical_href' => $geofence->site_id ? route('sites.show', $geofence->site_id) : null,
        ];
    }
}
