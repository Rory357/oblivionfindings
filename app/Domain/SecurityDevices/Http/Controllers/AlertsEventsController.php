<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Support\WorkerClock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class AlertsEventsController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.events.view'), 403);
        $eventScope = fn () => $this->access->applyTemporalEventCustodyScope(
            DeviceEvent::query(),
            $user,
        );

        $forcedDeviceId = null;
        if ($request->filled('device_id')) {
            $forcedDeviceId = filter_var(
                $request->input('device_id'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            abort_if($forcedDeviceId === false, 404);
            abort_unless(
                $eventScope()->where('device_id', $forcedDeviceId)->exists(),
                404,
            );
        }

        // ── Stats ─────────────────────────────────────────────────

        $snapshotAt = now();
        $last24h = $snapshotAt->copy()->subHours(24);
        $currentDeviceScope = $this->access->visibleDevices($user)->select('devices.id');

        $stats = [
            'total24h' => $eventScope()->whereBetween('occurred_at', [$last24h, $snapshotAt])->count(),
            'critical24h' => $eventScope()->whereBetween('occurred_at', [$last24h, $snapshotAt])->bySeverity('critical')->count(),
            'warning24h' => $eventScope()->whereBetween('occurred_at', [$last24h, $snapshotAt])->bySeverity('warning')->count(),
            'unprocessed' => $eventScope()->unprocessed()->count(),
        ];

        // ── Event query ───────────────────────────────────────────

        $query = $eventScope()->with(['device:id,name,device_uid,domain,category']);
        if ($request->input('window') === '24h') {
            $query->whereBetween('occurred_at', [$last24h, $snapshotAt]);
        }

        // Severity filter.
        if ($request->filled('severity') && $request->input('severity') !== 'all') {
            $query->bySeverity($request->input('severity'));
        }

        // Event type filter.
        if ($request->filled('event_type') && $request->input('event_type') !== 'all') {
            $query->ofType($request->input('event_type'));
        }

        // Domain filter (via device relationship).
        if ($request->filled('domain') && $request->input('domain') !== 'all') {
            $query->whereHas('device', fn ($q) => $q->whereIn('devices.id', clone $currentDeviceScope)->byDomain($request->input('domain')));
        }

        // Specific device filter.
        if ($forcedDeviceId !== null) {
            $query->where('device_id', $forcedDeviceId);
        }

        // Source filter.
        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('source', $request->input('source'));
        }

        // Processed/unprocessed filter.
        if ($request->filled('processed') && $request->input('processed') !== 'all') {
            if ($request->input('processed') === 'yes') {
                $query->whereNotNull('processed_at');
            } else {
                $query->unprocessed();
            }
        }

        // Date range filter: `from` and `to` are New Zealand calendar days
        // (Y-m-d), matching the times shown on the page; their bounds are
        // converted to UTC for the stored occurred_at instants.
        $from = $this->calendarDay($request->input('from'));
        if ($from !== null) {
            $query->where('occurred_at', '>=', WorkerClock::dayStartUtc($from));
        }
        $to = $this->calendarDay($request->input('to'));
        if ($to !== null) {
            $query->where('occurred_at', '<=', WorkerClock::dayEndUtc($to));
        }

        // Search across event_type and source.
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search, $currentDeviceScope) {
                $q->where('event_type', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhereHas('device', fn ($dq) => $dq->whereIn('devices.id', clone $currentDeviceScope)->where(fn ($identity) => $identity->where('name', 'like', "%{$search}%")->orWhere('device_uid', 'like', "%{$search}%")));
            });
        }

        $query->latest('occurred_at');
        $events = $query->paginate(50)->withQueryString();
        $visibleCurrentIds = $this->access->visibleDevices($user)
            ->whereIn('devices.id', $events->getCollection()->pluck('device_id')->unique())
            ->pluck('devices.id')->map(fn ($id) => (int) $id)->all();
        $canViewDeviceProfiles = $user->canDo('securityDevices.devices.view');

        // ── Filter options ────────────────────────────────────────

        $eventTypes = $eventScope()
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        $sources = $eventScope()
            ->whereNotNull('source')
            ->select('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source');

        return Inertia::render('security-devices/alerts-events', [
            'pageMeta' => [
                'title' => 'Alerts & events',
                'description' => 'Read-only device event stream. For alert triage and escalation, use Control Room.',
                'href' => '/security-devices/alerts-events',
            ],
            'stats' => $stats,
            'snapshotAt' => $snapshotAt->toISOString(),
            'windowStart' => $last24h->toISOString(),
            'controlRoom' => [
                'href' => $user->canDo('controlRoom.viewAny') ? '/control-room'
                    : (($user->canDo('controlRoom.alerts.view') || $user->canDo('controlRoom.alerts.manage')) ? '/control-room/alerts' : null),
            ],
            'events' => [
                'data' => $events->getCollection()->map(function (DeviceEvent $e) use ($visibleCurrentIds, $canViewDeviceProfiles): array {
                    $currentVisible = in_array((int) $e->device_id, $visibleCurrentIds, true);
                    $canOpen = $currentVisible && $canViewDeviceProfiles;

                    return [
                        'id' => $e->id,
                        'device_id' => $e->device_id,
                        'device_name' => $currentVisible ? $e->device?->name : null,
                        'device_uid' => $currentVisible ? $e->device?->device_uid : null,
                        'device_domain' => $currentVisible ? $e->device?->domain : null,
                        'device_href' => $canOpen ? '/security-devices/devices/'.$e->device_id : null,
                        'device_access' => [
                            'state' => $canOpen ? 'available' : 'restricted',
                            'metadata_state' => $currentVisible ? 'available' : 'restricted',
                            'label' => $canOpen ? 'Open device profile' : ($currentVisible ? 'Device profile permission required' : 'Current device details restricted'),
                        ],
                        'event_type' => $e->event_type,
                        'severity' => $e->severity,
                        'source' => $e->source,
                        'occurred_at' => $e->occurred_at?->toISOString(),
                        'processed_at' => $e->processed_at?->toISOString(),
                    ];
                }),
                'links' => $events->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page' => $events->lastPage(),
                    'total' => $events->total(),
                ],
            ],
            'filters' => $request->only(['severity', 'event_type', 'domain', 'device_id', 'source', 'processed', 'from', 'to', 'search', 'window']),
            'filterOptions' => [
                'eventTypes' => $eventTypes,
                'sources' => $sources,
                'domains' => collect(DeviceDomain::cases())
                    ->map(fn ($d) => ['value' => $d->value, 'label' => $d->label()]),
            ],
        ]);
    }

    /** A real Y-m-d calendar day from the query string, or null. */
    private function calendarDay(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
