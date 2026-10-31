<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\SignalSource;
use App\Services\ControlRoom\ControlRoomDeviceVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitoring › Coverage — read only. Which houses are monitored, by which kind
 * of source, and whether each is still reporting; plus the houses with no
 * monitoring at all.
 *
 * Built from the Control Room signal projection under exactly the Site, source
 * and privacy boundary of the Signals list (ControlRoomDeviceController::index):
 * the same permission, the same projection scope and the same visible Sites.
 * Rows are grouped by Site and source kind (device type) so each row's link
 * opens exactly those signals on the Signals list.
 */
class ControlRoomCoverageController extends Controller
{
    /**
     * A source is quiet after this long without a signal. It is the Signals
     * list's own "active in the last 24 hours" boundary, so both views agree.
     */
    public const QUIET_AFTER_HOURS = 24;

    private const STATES = ['reporting', 'quiet', 'never', 'none'];

    /** Problems first, then the rest. */
    private const STATE_ORDER = ['quiet' => 0, 'never' => 1, 'none' => 2, 'reporting' => 3];

    public function __construct(
        private readonly ControlRoomDeviceVisibilityService $visibility,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $filters = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', Rule::in(array_keys(Device::types()))],
            'state' => ['nullable', 'string', Rule::in(self::STATES)],
        ]);
        $selectedSiteId = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        if ($selectedSiteId) {
            $this->visibility->assertCanFilterSite($user, $selectedSiteId);
        }
        $type = $filters['type'] ?? null;
        $state = $filters['state'] ?? null;
        $canViewCanonicalDevices = $this->visibility->canViewCanonicalDevices($user);
        $since = now()->subHours(self::QUIET_AFTER_HOURS);

        $query = Device::query();
        $this->visibility->applyScope(
            $query,
            $user,
            $selectedSiteId ? [$selectedSiteId] : null,
        );
        $devices = $query
            ->leftJoin('sites', 'control_room_devices.site_id', '=', 'sites.id')
            ->when($type !== null, fn ($q) => $q->where('control_room_devices.type', $type))
            ->get([
                'control_room_devices.id',
                'control_room_devices.site_id',
                'control_room_devices.type',
                'control_room_devices.signal_source_id',
                'control_room_devices.last_signal_at',
                'sites.name as site_name',
            ]);

        $sourceNames = SignalSource::query()
            ->whereKey($devices->pluck('signal_source_id')->filter()->unique()->values())
            ->pluck('name', 'id');
        $types = Device::types();

        $sourceRows = $devices
            ->groupBy(fn (Device $device): string => ($device->site_id ?? 0).'|'.$device->type)
            ->map(fn (Collection $group): array => $this->sourceRow($group, $since, $sourceNames, $types))
            ->values();

        $houses = $this->visibility->visibleSites($user)
            ->when($selectedSiteId !== null, fn ($q) => $q->whereKey($selectedSiteId))
            ->orderBy('name')
            ->get(['id', 'name']);
        $coveredSiteIds = $sourceRows->pluck('site_id')->filter()->unique();
        $uncoveredRows = $houses
            ->reject(fn ($site): bool => $coveredSiteIds->contains((int) $site->id))
            ->map(fn ($site): array => [
                'key' => "{$site->id}|none",
                'site_id' => (int) $site->id,
                'site_name' => $site->name,
                'type' => null,
                'type_label' => null,
                'sources' => [],
                'devices' => 0,
                'reporting' => 0,
                'quiet' => 0,
                'never' => 0,
                'state' => 'none',
                'last_signal_at' => null,
            ])
            ->values();

        $rows = $sourceRows->concat($uncoveredRows);
        $houseCount = $houses->pluck('id')->map(fn ($id): int => (int) $id)
            ->concat($coveredSiteIds)
            ->unique()
            ->count();

        $stats = [
            'houses' => $houseCount,
            'houses_covered' => $coveredSiteIds->count(),
            'sources' => $sourceRows->count(),
            'reporting' => $rows->where('state', 'reporting')->count(),
            'quiet' => $rows->where('state', 'quiet')->count(),
            'never' => $rows->where('state', 'never')->count(),
            'no_monitoring' => $uncoveredRows->count(),
        ];

        $shown = $rows
            ->when($state !== null, fn (Collection $all) => $all->where('state', $state))
            ->sort(fn (array $a, array $b): int => [
                self::STATE_ORDER[$a['state']],
                mb_strtolower($a['site_name'] ?? "\u{10FFFF}"),
                mb_strtolower($a['type_label'] ?? ''),
            ] <=> [
                self::STATE_ORDER[$b['state']],
                mb_strtolower($b['site_name'] ?? "\u{10FFFF}"),
                mb_strtolower($b['type_label'] ?? ''),
            ])
            ->values();

        $sites = $this->visibility->visibleSites($user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($site): array => ['id' => (int) $site->id, 'name' => $site->name])
            ->values();

        return Inertia::render('control-room/coverage', [
            'rows' => $shown,
            'stats' => $stats,
            'filters' => [
                'site_id' => $selectedSiteId ? (string) $selectedSiteId : '',
                'type' => $type ?? '',
                'state' => $state ?? '',
            ],
            'sites' => $sites,
            'device_types' => $types,
            'quiet_after_hours' => self::QUIET_AFTER_HOURS,
            'can' => [
                'view_canonical_devices' => $canViewCanonicalDevices,
            ],
            'canonicalIndexUrl' => $canViewCanonicalDevices ? '/security-devices/devices' : null,
        ]);
    }

    /**
     * One house × one kind of source. Quiet wins over never-reported (a source
     * that stopped is the stronger sign of a fault); reporting only when every
     * source of that kind reported inside the window.
     *
     * @param  Collection<int, Device>  $group
     * @param  Collection<int|string, string>  $sourceNames
     * @param  array<string, string>  $types
     */
    private function sourceRow(Collection $group, Carbon $since, Collection $sourceNames, array $types): array
    {
        /** @var Device $first */
        $first = $group->first();
        $reporting = $group->filter(fn (Device $d): bool => $d->last_signal_at !== null && $d->last_signal_at->gte($since))->count();
        $never = $group->filter(fn (Device $d): bool => $d->last_signal_at === null)->count();
        $quiet = $group->count() - $reporting - $never;
        $latest = $group->pluck('last_signal_at')->filter()->sortDesc()->first();

        return [
            'key' => ($first->site_id ?? 0).'|'.$first->type,
            'site_id' => $first->site_id ? (int) $first->site_id : null,
            'site_name' => $first->site_name,
            'type' => $first->type,
            'type_label' => $types[$first->type] ?? ucfirst(str_replace('_', ' ', (string) $first->type)),
            'sources' => $group->pluck('signal_source_id')
                ->filter()
                ->unique()
                ->map(fn ($id): ?string => $sourceNames->get($id))
                ->filter()
                ->sort()
                ->values()
                ->all(),
            'devices' => $group->count(),
            'reporting' => $reporting,
            'quiet' => $quiet,
            'never' => $never,
            'state' => $quiet > 0 ? 'quiet' : ($never > 0 ? 'never' : 'reporting'),
            'last_signal_at' => $latest?->toISOString(),
        ];
    }
}
