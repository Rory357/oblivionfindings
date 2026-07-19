<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Config\CategoryPageConfig;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\ResolvesDeviceTenant;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CategoryPageController extends Controller
{
    use MapsDevicesForList;
    use ResolvesDeviceTenant;

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

    public function alarms(Request $request)
    {
        return $this->buildCategoryPage($request, 'alarms');
    }

    public function cctv(Request $request)
    {
        return $this->buildCategoryPage($request, 'cctv');
    }

    public function accessControl(Request $request)
    {
        return $this->buildCategoryPage($request, 'access-control');
    }

    public function trackingDevices(Request $request)
    {
        return $this->buildCategoryPage($request, 'tracking-devices');
    }

    public function smartIotHealthcare(Request $request)
    {
        return $this->buildCategoryPage($request, 'smart-iot-healthcare');
    }

    public function itInfrastructure(Request $request)
    {
        return $this->buildCategoryPage($request, 'it-infrastructure');
    }

    public function facilities(Request $request)
    {
        return $this->buildCategoryPage($request, 'facilities');
    }

    private function buildCategoryPage(Request $request, string $slug): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $config = CategoryPageConfig::get($slug);
        abort_unless($config !== null, 404);
        $tenantId = $this->resolveDeviceTenantId($user);

        // ── Base scope ────────────────────────────────────────────

        $query = Device::query()
            ->forTenant($tenantId)
            ->with(['assignments' => fn ($q) => $q->active()])
            ->byDomain($config['domain']);

        if ($config['categories'] !== null) {
            $query->whereIn('category', $config['categories']);
        }

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

        $statsBase = fn (): Builder => $this->scopedBaseQuery($config, $tenantId);

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

        $subcategories = $this->buildSubcategoryOptions($config);

        // ── Scoped provider list ──────────────────────────────────

        $providers = $statsBase()
            ->whereNotNull('provider')
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider');

        // ── Category options (for multi-category pages) ───────────

        $categoryOptions = null;
        if ($config['categories'] !== null && count($config['categories']) > 1) {
            $categoryOptions = collect($config['categories'])->map(fn ($cat) => [
                'value' => $cat,
                'label' => str_replace('_', ' ', ucfirst($cat)),
            ]);
        } elseif ($config['categories'] === null) {
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
            'filters' => $request->only(['subcategory', 'category', 'status', 'health', 'provider', 'assigned', 'search', 'sort', 'direction']),
            'filterOptions' => [
                'subcategories' => $subcategories,
                'categories' => $categoryOptions,
                'statuses' => collect(DeviceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
                'healthStatuses' => collect(HealthStatus::cases())->map(fn ($h) => ['value' => $h->value, 'label' => $h->label()]),
                'providers' => $providers,
            ],
            'pageConfig' => [
                'slug' => $config['slug'],
                'title' => $config['title'],
                'description' => $config['description'],
                'emptyTitle' => $config['emptyTitle'],
                'emptyDescription' => $config['emptyDescription'],
                'icon' => $config['icon'],
                'domain' => $config['domain'],
                'categories' => $config['categories'],
            ],
        ]);
    }

    /**
     * Build an unfiltered base query scoped to the page's domain + categories.
     * Used for stats so they always reflect the full scope, regardless of user filters.
     */
    private function scopedBaseQuery(array $config, int $tenantId): Builder
    {
        $query = Device::query()
            ->forTenant($tenantId)
            ->byDomain($config['domain']);

        if ($config['categories'] !== null) {
            $query->whereIn('category', $config['categories']);
        }

        return $query;
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
}
