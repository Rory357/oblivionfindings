<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\ResolvesDeviceTenant;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class AlertsEventsController extends Controller
{
    use ResolvesDeviceTenant;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.events.view'), 403);
        $tenantId = $this->resolveDeviceTenantId($user);
        $visibleDeviceIds = $this->access->visibleDevices($user)->select('devices.id');
        $eventScope = fn () => DeviceEvent::query()
            ->forTenant($tenantId)
            ->whereIn('device_id', clone $visibleDeviceIds);

        // ── Stats ─────────────────────────────────────────────────

        $last24h = now()->subHours(24);

        $stats = [
            'total24h' => $eventScope()->since($last24h)->count(),
            'critical24h' => $eventScope()->since($last24h)->bySeverity('critical')->count(),
            'warning24h' => $eventScope()->since($last24h)->bySeverity('warning')->count(),
            'unprocessed' => $eventScope()->unprocessed()->count(),
        ];

        // ── Event query ───────────────────────────────────────────

        $query = $eventScope()->with(['device:id,name,device_uid,domain,category']);

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
            $query->whereHas('device', fn ($q) => $q->byDomain($request->input('domain')));
        }

        // Specific device filter.
        if ($request->filled('device_id')) {
            $query->where('device_id', (int) $request->input('device_id'));
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

        // Date range filter.
        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to').' 23:59:59');
        }

        // Search across event_type and source.
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('event_type', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhereHas('device', fn ($dq) => $dq->where('name', 'like', "%{$search}%")->orWhere('device_uid', 'like', "%{$search}%"));
            });
        }

        $query->latest('occurred_at');
        $events = $query->paginate(50)->withQueryString();

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
            'pageMeta' => $request->routeIs('security-devices.monitoring')
                ? [
                    'title' => 'Monitoring',
                    'description' => 'Active device events and collection signals. Control Room remains the place for operational triage and escalation.',
                    'href' => '/security-devices/monitoring',
                ]
                : [
                    'title' => 'Alerts & Events',
                    'description' => 'Read-only device event stream. For alert triage and escalation, use Control Room.',
                    'href' => '/security-devices/alerts-events',
                ],
            'stats' => $stats,
            'events' => [
                'data' => $events->getCollection()->map(fn (DeviceEvent $e) => [
                    'id' => $e->id,
                    'device_id' => $e->device_id,
                    'device_name' => $e->device?->name,
                    'device_uid' => $e->device?->device_uid,
                    'device_domain' => $e->device?->domain,
                    'event_type' => $e->event_type,
                    'severity' => $e->severity,
                    'source' => $e->source,
                    'occurred_at' => $e->occurred_at?->toISOString(),
                    'processed_at' => $e->processed_at?->toISOString(),
                ]),
                'links' => $events->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page' => $events->lastPage(),
                    'total' => $events->total(),
                ],
            ],
            'filters' => $request->only(['severity', 'event_type', 'domain', 'device_id', 'source', 'processed', 'from', 'to', 'search']),
            'filterOptions' => [
                'eventTypes' => $eventTypes,
                'sources' => $sources,
                'domains' => collect(DeviceDomain::cases())
                    ->map(fn ($d) => ['value' => $d->value, 'label' => $d->label()]),
            ],
        ]);
    }
}
