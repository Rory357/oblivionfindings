<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\SecurityDevices\Config\CategoryPageConfig;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Config\WorkspaceConfig;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Presenters\SecurityWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\WorkspacePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class CategoryPageController extends Controller
{
    use MapsDevicesForList;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly WorkspacePresenter $workspacePresenter,
        private readonly SecurityWorkspacePresenter $securityWorkspacePresenter,
    ) {}

    public function networkIt(Request $request)
    {
        return $this->buildCategoryPage($request, 'network-it');
    }

    public function security(Request $request)
    {
        return $this->buildCategoryPage($request, 'security');
    }

    public function healthcare(Request $request)
    {
        return $this->buildCategoryPage($request, 'healthcare');
    }

    public function tracking(Request $request)
    {
        return $this->buildCategoryPage($request, 'tracking');
    }

    public function facilitiesIot(Request $request)
    {
        return $this->buildCategoryPage($request, 'facilities-iot');
    }

    public function alarms(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/security', 'alarms');
    }

    public function cctv(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/security', 'cctv');
    }

    public function accessControl(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/security', 'access-control');
    }

    public function trackingDevices(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/tracking', 'overview');
    }

    public function smartIotHealthcare(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/healthcare', 'overview');
    }

    public function itInfrastructure(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/network-it', 'devices');
    }

    public function facilities(Request $request): RedirectResponse
    {
        return $this->redirectLegacyWorkspace($request, '/security-devices/facilities-iot', 'overview');
    }

    private function buildCategoryPage(Request $request, string $slug): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $workspaceConfig = WorkspaceConfig::get($slug);
        $config = CategoryPageConfig::get($slug);
        abort_unless($workspaceConfig !== null && $config !== null, 404);
        $activeTab = WorkspaceConfig::activeTab($workspaceConfig, $request->string('tab')->toString());

        // ── Base scope ────────────────────────────────────────────

        $baseScope = $this->access->visibleDevices($user)
            ->byDomain($config['domain']);

        $inventoryConfig = $config;
        $inventoryScope = clone $baseScope;

        if (isset($activeTab['categories'])) {
            $inventoryConfig['categories'] = $activeTab['categories'];
            $inventoryScope->whereIn('category', $activeTab['categories']);
        }

        $query = (clone $inventoryScope)
            ->with(['assignments' => fn ($q) => $q->active()])
            ->withCount([
                'monitors as enabled_monitors_count' => fn ($monitor) => $monitor->where('is_enabled', true),
                'monitors as failing_monitors_count' => fn ($monitor) => $monitor
                    ->where('is_enabled', true)
                    ->whereIn('current_state', [MonitorState::Failed->value, MonitorState::Degraded->value]),
                'monitors as uncertain_monitors_count' => fn ($monitor) => $monitor
                    ->where('is_enabled', true)
                    ->whereIn('current_state', [MonitorState::Unknown->value, MonitorState::Stale->value, MonitorState::Pending->value]),
            ]);

        // ── Subcategory filter (category-page-specific) ───────────

        if ($request->filled('subcategory') && $request->input('subcategory') !== 'all') {
            $query->where('subcategory', $request->input('subcategory'));
        }

        // For multi-category pages (e.g. Alarms = alarm + perimeter), allow
        // filtering to a single category within the page scope.
        if ($request->filled('category') && $request->input('category') !== 'all') {
            $query->where('category', $request->input('category'));
        }

        // ── Common filters, search, sort ──────────────────────────

        $this->applyCommonFilters($request, $query);
        $this->applyCommonSort($request, $query);

        $devices = $query->paginate(30)->withQueryString();

        // ── Scoped stats ──────────────────────────────────────────

        $statsBase = fn (): Builder => clone $inventoryScope;

        $stats = [
            'total' => $statsBase()->count(),
            'active' => $statsBase()->where('status', DeviceStatus::Active->value)->count(),
            'offline' => $statsBase()->where('status', DeviceStatus::Offline->value)->count(),
            'attention' => $statsBase()->needingAttention()->count(),
            'bySubcategory' => $statsBase()
                ->whereNotNull('subcategory')
                ->selectRaw('subcategory, count(*) as count')
                ->groupBy('subcategory')
                ->pluck('count', 'subcategory')
                ->toArray(),
        ];

        // ── Build subcategory options from taxonomy ───────────────

        $subcategories = $this->buildSubcategoryOptions($inventoryConfig);

        // ── Scoped provider list ──────────────────────────────────

        $providers = $statsBase()
            ->whereNotNull('provider')
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider');

        // ── Category options (for multi-category pages) ───────────

        $categoryOptions = null;
        if ($inventoryConfig['categories'] !== null && count($inventoryConfig['categories']) > 1) {
            $categoryOptions = collect($inventoryConfig['categories'])->map(fn ($cat) => [
                'value' => $cat,
                'label' => str_replace('_', ' ', ucfirst($cat)),
            ]);
        } elseif ($inventoryConfig['categories'] === null) {
            // Whole domain — show categories as a filter.
            $categoryOptions = collect(DeviceTaxonomy::categoriesFor($config['domain']))->map(fn ($label, $slug) => [
                'value' => $slug,
                'label' => $label,
            ])->values();
        }

        return Inertia::render('security-devices/category', [
            'devices' => [
                'data' => $devices->getCollection()->map(fn (Device $d) => $this->mapDeviceForList($d)),
                'links' => $devices->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $devices->currentPage(),
                    'last_page' => $devices->lastPage(),
                    'total' => $devices->total(),
                ],
            ],
            'stats' => $stats,
            'filters' => $request->only(['tab', 'device_id', 'subcategory', 'category', 'status', 'health', 'provider', 'assigned', 'search', 'sort', 'direction']),
            'filterOptions' => [
                'subcategories' => $subcategories,
                'categories' => $categoryOptions,
                'statuses' => collect(DeviceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
                'healthStatuses' => collect(HealthStatus::cases())->map(fn ($h) => ['value' => $h->value, 'label' => $h->label()]),
                'providers' => $providers,
            ],
            'pageConfig' => [
                'slug' => $workspaceConfig['slug'],
                'title' => $workspaceConfig['title'],
                'description' => $workspaceConfig['description'],
                'emptyTitle' => $config['emptyTitle'],
                'emptyDescription' => $config['emptyDescription'],
                'icon' => $config['icon'],
                'domain' => $config['domain'],
                'categories' => $inventoryConfig['categories'],
            ],
            'workspace' => $this->workspacePresenter->present(
                clone $baseScope,
                $workspaceConfig,
                $activeTab,
                $user,
            ),
            'securityWorkspace' => $slug === 'security'
                ? $this->securityWorkspacePresenter->present($user, clone $baseScope, $activeTab)
                : null,
        ]);
    }

    /**
     * Build subcategory filter options from the taxonomy for this page's scope.
     */
    private function buildSubcategoryOptions(array $config): array
    {
        $options = [];

        if ($config['categories'] !== null) {
            foreach ($config['categories'] as $cat) {
                foreach (DeviceTaxonomy::subcategoriesFor($config['domain'], $cat) as $slug => $label) {
                    $options[] = ['value' => $slug, 'label' => $label];
                }
            }
        } else {
            // Whole domain — merge all subcategories across all categories.
            $domainTree = DeviceTaxonomy::all()[$config['domain']] ?? [];
            foreach ($domainTree as $cat => $subs) {
                foreach ($subs as $slug => $label) {
                    $options[] = ['value' => $slug, 'label' => $label];
                }
            }
        }

        return $options;
    }

    private function redirectLegacyWorkspace(
        Request $request,
        string $canonicalPath,
        string $tab,
    ): RedirectResponse {
        abort_unless($request->user()->canDo('securityDevices.devices.view'), 403);

        $query = $request->query();
        $query['tab'] = $tab;

        return redirect()->to($canonicalPath.'?'.Arr::query($query));
    }
}
