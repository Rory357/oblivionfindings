import type { VehicleCalendarSummary } from '@/components/fleet-assets/vehicle-workspace/calendar-types';
import LeafletMap, {
    type MapContextPoint,
    type MapMarker,
} from '@/components/leaflet-map';
import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { CalendarContextMenu } from '@/pages/sites/calendar/_parts';
import {
    ArrowUpRight,
    CalendarDays,
    Car,
    Eye,
    EyeOff,
    Layers,
    LocateFixed,
    Lock,
    MapPin,
    MoreHorizontal,
    Plus,
    RefreshCw,
    Search,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FleetHeaderSlot } from './fleet-header';
import './fleet-map.css';

export type FleetMapVehicle = {
    id: number;
    name: string;
    asset_tag: string | null;
    registration_number: string | null;
    status: string;
    home_site: {
        id: number;
        name: string;
        lat: number | null;
        lng: number | null;
    } | null;
    tracker_linked: boolean;
    position: {
        lat: number | null;
        lng: number | null;
        observed_at: string | null;
        received_at: string | null;
        fresh: boolean;
        withheld: string | null;
        speed_kph: number | null;
        battery_pct: number | null;
        status: string;
    } | null;
};
type Feed = {
    as_of: string;
    timezone: string;
    fresh_minutes: number;
    vehicles: FleetMapVehicle[];
};
const EMPTY_VEHICLES: FleetMapVehicle[] = [];
type PositionState =
    | 'reported'
    | 'stale'
    | 'withheld'
    | 'no-tracker'
    | 'no-fix';
export function positionState(vehicle: FleetMapVehicle): PositionState {
    if (vehicle.position?.withheld) return 'withheld';
    if (!vehicle.tracker_linked) return 'no-tracker';
    if (
        vehicle.position?.lat === null ||
        vehicle.position?.lng === null ||
        !vehicle.position
    )
        return 'no-fix';
    return vehicle.position.fresh ? 'reported' : 'stale';
}
export const hasPosition = (vehicle: FleetMapVehicle) =>
    vehicle.position?.lat != null &&
    vehicle.position?.lng != null &&
    !vehicle.position.withheld;
const stateLabel: Record<PositionState, string> = {
    reported: 'Reported position',
    stale: 'Last known · stale',
    withheld: 'Location restricted',
    'no-tracker': 'No tracker',
    'no-fix': 'No position received',
};

export function FleetMap({
    scopeIds,
    requestableIds,
    onProfile,
    onCalendar,
    onRequest,
    headerTarget,
    permittedCount,
}: {
    scopeIds?: number[];
    requestableIds?: number[];
    onProfile: (vehicle: FleetMapVehicle, tab?: string) => void;
    onCalendar: (vehicle: FleetMapVehicle) => void;
    onRequest: (vehicle: FleetMapVehicle) => void;
    headerTarget?: HTMLElement | null;
    permittedCount?: number;
}) {
    const [feed, setFeed] = useState<Feed | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const request = useRef<AbortController | null>(null);
    const saved = useMemo(
        () => new URLSearchParams(window.location.search),
        [],
    );
    const [query, setQuery] = useState(saved.get('mapQuery') ?? '');
    const [positionFilter, setPositionFilter] = useState(
        saved.get('mapPosition') ?? 'all',
    );
    const [sort, setSort] = useState<'name' | 'recent'>(
        saved.get('mapSort') === 'recent' ? 'recent' : 'name',
    );
    const [onlyChecked, setOnlyChecked] = useState(
        saved.get('mapOnly') === 'on',
    );
    const [checked, setChecked] = useState<Set<number> | null>(() =>
        saved.has('mapIds')
            ? new Set(
                  (saved.get('mapIds') ?? '')
                      .split(',')
                      .filter(Boolean)
                      .map(Number),
              )
            : null,
    );
    const [focusId, setFocusId] = useState<number | null>(
        Number(saved.get('mapFocus')) || null,
    );
    const [homes, setHomes] = useState(saved.get('mapSites') === 'on');
    const [camera, setCamera] = useState<{
        lat: number;
        lng: number;
        nonce: number;
    }>();
    const [fit, setFit] = useState(true);
    const [fitNonce, setFitNonce] = useState(0);
    const [menu, setMenu] = useState<{
        point: MapContextPoint;
        opener: HTMLElement | null;
    } | null>(null);
    const [tileError, setTileError] = useState(false);
    const listRef = useRef<HTMLDivElement>(null);
    const reload = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setError('');
        try {
            const response = await fetch(
                '/fleet-assets/vehicles/fleet-map/data',
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            );
            if (!response.ok)
                throw new Error(
                    response.status === 403
                        ? 'You no longer have access to this fleet map.'
                        : 'Vehicle locations could not be loaded.',
                );
            const result = (await response.json()) as Feed;
            if (controller.signal.aborted) return;
            setFeed(result);
            setChecked((previous) =>
                previous === null
                    ? null
                    : new Set(
                          [...previous].filter((id) =>
                              result.vehicles.some(
                                  (vehicle) => vehicle.id === id,
                              ),
                          ),
                      ),
            );
        } catch (reason) {
            if (controller.signal.aborted) return;
            setFeed(null);
            setMenu(null);
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Vehicle locations could not be loaded.',
            );
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, []);
    useEffect(() => {
        void reload();
        const timer = window.setInterval(() => void reload(), 60000);
        return () => {
            window.clearInterval(timer);
            request.current?.abort();
        };
    }, [reload]);
    useEffect(() => {
        const url = new URL(window.location.href);
        const values: Record<string, string | null> = {
            mapQuery: query || null,
            mapPosition: positionFilter === 'all' ? null : positionFilter,
            mapSort: sort === 'name' ? null : sort,
            mapOnly: onlyChecked ? 'on' : null,
            mapIds:
                checked === null
                    ? null
                    : [...checked].sort((a, b) => a - b).join(','),
            mapFocus: focusId ? String(focusId) : null,
            mapSites: homes ? 'on' : null,
        };
        Object.entries(values).forEach(([key, value]) =>
            value === null
                ? url.searchParams.delete(key)
                : url.searchParams.set(key, value),
        );
        window.history.replaceState(window.history.state, '', url);
    }, [query, positionFilter, sort, onlyChecked, checked, focusId, homes]);
    const vehicles = useMemo(
        () =>
            (feed?.vehicles ?? EMPTY_VEHICLES).filter(
                (vehicle) => !scopeIds || scopeIds.includes(vehicle.id),
            ),
        [feed, scopeIds],
    );
    const requestAllowed = (vehicle: FleetMapVehicle) =>
        !loading &&
        !error &&
        (!requestableIds || requestableIds.includes(vehicle.id));
    const selected = useMemo(
        () => checked ?? new Set(vehicles.map((vehicle) => vehicle.id)),
        [checked, vehicles],
    );
    const matching = useMemo(
        () =>
            vehicles
                .filter((vehicle) => {
                    const search =
                        `${vehicle.name} ${vehicle.registration_number ?? ''} ${vehicle.asset_tag ?? ''} ${vehicle.home_site?.name ?? ''}`.toLowerCase();
                    const state = positionState(vehicle);
                    return (
                        search.includes(query.trim().toLowerCase()) &&
                        (positionFilter === 'all' ||
                            positionFilter === state ||
                            (positionFilter === 'located' &&
                                hasPosition(vehicle)) ||
                            (positionFilter === 'unavailable' &&
                                !hasPosition(vehicle))) &&
                        (!onlyChecked || selected.has(vehicle.id))
                    );
                })
                .sort((a, b) =>
                    sort === 'recent'
                        ? (b.position?.observed_at ?? '').localeCompare(
                              a.position?.observed_at ?? '',
                          ) || a.name.localeCompare(b.name)
                        : a.name.localeCompare(b.name),
                ),
        [vehicles, query, positionFilter, onlyChecked, selected, sort],
    );
    const shown = matching.filter((vehicle) => selected.has(vehicle.id));
    const located = shown.filter(hasPosition);
    const active = vehicles.find((vehicle) => vehicle.id === focusId) ?? null;
    const menuVehicle =
        vehicles.find(
            (vehicle) => vehicle.id === Number(menu?.point.markerId),
        ) ?? null;
    const sourceId = menuVehicle?.id ?? active?.id;
    const [source, setSource] = useState<{
        id: number;
        summary: VehicleCalendarSummary;
    } | null>(null);
    useEffect(() => {
        if (!sourceId) return;
        const controller = new AbortController();
        void fetch(`/fleet-assets/vehicles/${sourceId}/calendar/summary`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Source unavailable');
                return (await response.json()) as VehicleCalendarSummary;
            })
            .then((summary) => {
                if (!controller.signal.aborted)
                    setSource({ id: sourceId, summary });
            })
            .catch(() => {
                if (!controller.signal.aborted) setSource(null);
            });
        return () => controller.abort();
    }, [sourceId, feed]);
    const summary = source && source.id === sourceId ? source.summary : null;
    const hasCurrentBooking = summary?.bookings.some(
        (row) => row.kind === 'booking' && row.status === 'checked_out',
    );
    const sourceLabel = summary?.restriction
        ? 'View active restriction'
        : hasCurrentBooking
          ? 'View current booking'
          : summary?.next_due
            ? 'View due work'
            : 'Review vehicle evidence';
    const sourceTab = summary?.restriction
        ? 'maintenance'
        : summary?.next_due || hasCurrentBooking
          ? 'calendar'
          : 'overview';
    const homeMarkers = homes
        ? [
              ...new Map(
                  vehicles
                      .filter(
                          (vehicle) =>
                              vehicle.home_site?.lat != null &&
                              vehicle.home_site?.lng != null,
                      )
                      .map((vehicle) => [
                          vehicle.home_site!.id,
                          vehicle.home_site!,
                      ]),
              ).values(),
          ]
        : [];
    const markers: MapMarker[] = [
        ...located.map((vehicle) => ({
            id: vehicle.id,
            lat: Number(vehicle.position!.lat),
            lng: Number(vehicle.position!.lng),
            title: vehicle.name,
            type: 'vehicle' as const,
            color:
                positionState(vehicle) === 'stale'
                    ? 'var(--status-warning)'
                    : 'var(--primary)',
            status:
                positionState(vehicle) === 'stale' ? 'historical' : 'recorded',
            stats: [
                ['Location', stateLabel[positionState(vehicle)]],
                [
                    'Observed',
                    vehicle.position?.observed_at
                        ? formatDateTime(vehicle.position.observed_at)
                        : 'Unknown',
                ],
                [
                    'Speed',
                    vehicle.position?.speed_kph == null
                        ? 'Not reported'
                        : `${vehicle.position.speed_kph} km/h`,
                ],
                [
                    'Tracker battery',
                    vehicle.position?.battery_pct == null
                        ? 'Not reported'
                        : `${vehicle.position.battery_pct}%`,
                ],
            ] as [string, string][],
            hint: 'Right-click or use More actions',
        })),
        ...homeMarkers.map((site) => ({
            id: `site-${site.id}`,
            lat: Number(site.lat),
            lng: Number(site.lng),
            title: `${site.name} · home site`,
            type: 'house' as const,
            color: 'var(--category-sites)',
            popup: 'Assigned home site, not a vehicle position',
        })),
    ];
    const choose = (vehicle: FleetMapVehicle) => {
        setFocusId(vehicle.id);
        const url = new URL(window.location.href);
        url.searchParams.set('mapFocus', String(vehicle.id));
        window.history.replaceState(window.history.state, '', url);
        listRef.current
            ?.querySelector(`[data-vehicle-id="${vehicle.id}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    };
    const centre = (vehicle: FleetMapVehicle) => {
        if (!hasPosition(vehicle)) return;
        setFocusId(vehicle.id);
        setFit(false);
        setCamera({
            lat: Number(vehicle.position!.lat),
            lng: Number(vehicle.position!.lng),
            nonce: Date.now(),
        });
    };
    const toggle = (id: number) =>
        setChecked((previous) => {
            const next = new Set(
                previous ?? vehicles.map((vehicle) => vehicle.id),
            );
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    const chooseShown = (value: boolean) =>
        setChecked((previous) => {
            const next = new Set(
                previous ?? vehicles.map((vehicle) => vehicle.id),
            );
            matching.forEach((vehicle) =>
                value ? next.add(vehicle.id) : next.delete(vehicle.id),
            );
            return next;
        });
    const showMenu = (
        vehicle: FleetMapVehicle,
        element: HTMLElement,
        x?: number,
        y?: number,
    ) => {
        const rect = element.getBoundingClientRect();
        setMenu({
            point: {
                markerId: vehicle.id,
                lat: vehicle.position?.lat ?? 0,
                lng: vehicle.position?.lng ?? 0,
                x: x ?? rect.right,
                y: y ?? rect.bottom,
            },
            opener: element,
        });
    };
    const action = (fn: () => void) => () => {
        setMenu(null);
        fn();
    };
    const fitShown = () => {
        setCamera(undefined);
        setFit(true);
        setFitNonce((value) => value + 1);
    };
    const allShownChecked =
        matching.length > 0 && shown.length === matching.length;
    const staleCount = located.filter(
        (vehicle) => positionState(vehicle) === 'stale',
    ).length;
    const mapCount = (count: number) =>
        !feed ? (error ? 'Unavailable' : 'Checking…') : count;

    return (
        <section aria-label="Fleet map" className="fleet-map space-y-3 pt-4">
            <FleetHeaderSlot target={headerTarget}>
                <PageHeaderMeterBlock
                    label="Vehicles in view"
                    onClick={() => {
                        setPositionFilter('all');
                        setOnlyChecked(false);
                        setQuery('');
                    }}
                >
                    <PageHeaderMeterBig>
                        {mapCount(matching.length)}
                    </PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {shown.length} checked ·{' '}
                        {permittedCount ?? vehicles.length} permitted fleet
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
                <PageHeaderMeterBlock
                    label="Positions shown"
                    onClick={() => setPositionFilter('located')}
                >
                    <PageHeaderMeterBig>
                        {mapCount(located.length)}
                    </PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {located.length - staleCount} reported · {staleCount}{' '}
                        last known
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
                <PageHeaderMeterBlock
                    label="Stale positions"
                    tone={staleCount ? 'warning' : 'brand'}
                    onClick={() => setPositionFilter('stale')}
                >
                    <PageHeaderMeterBig>
                        {mapCount(staleCount)}
                    </PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        Older reports · may have moved
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
                <PageHeaderMeterBlock
                    label="Without a position"
                    tone={shown.length > located.length ? 'warning' : 'brand'}
                    onClick={() => setPositionFilter('unavailable')}
                >
                    <PageHeaderMeterBig>
                        {mapCount(shown.length - located.length)}
                    </PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        No tracker, no fix or restricted
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            </FleetHeaderSlot>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-section-title">Fleet locations</h2>
                    <p className="text-caption">
                        {feed
                            ? `Source checked ${formatDateTime(feed.as_of)} · ${feed.timezone} · observations older than ${feed.fresh_minutes} minutes are last known`
                            : 'Loading permitted vehicles and recorded positions'}
                    </p>
                </div>
                <Button
                    variant="outline"
                    onClick={() => window.location.assign('/fleet-assets/map')}
                >
                    <Layers className="size-4" />
                    Maps &amp; boundaries
                </Button>
            </div>
            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-status-critical bg-status-critical-bg p-3 text-status-critical-foreground"
                >
                    {error}{' '}
                    <Button variant="outline" onClick={() => void reload()}>
                        Retry
                    </Button>
                </div>
            )}
            <div className="fleet-map-grid grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div className="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-[14px] border bg-card shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b p-3 text-sm">
                        <MapPin className="size-4" />
                        <strong>
                            {located.length} recorded{' '}
                            {located.length === 1 ? 'position' : 'positions'}
                        </strong>
                        <span className="text-muted-foreground">
                            · {shown.length} checked vehicles ·{' '}
                            {shown.length - located.length} without a shareable
                            position
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            onClick={fitShown}
                            disabled={!located.length}
                        >
                            Fit shown
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label="Recheck vehicle locations"
                            onClick={() => void reload()}
                            disabled={loading}
                        >
                            <RefreshCw className="size-4" />
                        </Button>
                    </div>
                    <div
                        className="fleet-map-canvas relative"
                        aria-label="Recorded vehicle positions"
                    >
                        <LeafletMap
                            key={fitNonce}
                            center={
                                markers.length
                                    ? {
                                          lat: markers[0].lat,
                                          lng: markers[0].lng,
                                      }
                                    : { lat: -36.8485, lng: 174.7633 }
                            }
                            zoom={12}
                            markers={markers}
                            height="100%"
                            className="grayscale-map h-full"
                            autoFit
                            fitMarkers={fit}
                            observeResize
                            clustering
                            focus={camera}
                            onMarkerClick={(id) => {
                                const vehicle = vehicles.find(
                                    (row) => row.id === Number(id),
                                );
                                if (vehicle) choose(vehicle);
                            }}
                            onContext={(point) =>
                                setMenu({
                                    point,
                                    opener:
                                        document.activeElement instanceof
                                        HTMLElement
                                            ? document.activeElement
                                            : null,
                                })
                            }
                            onTileStatus={(status) =>
                                setTileError(status === 'failed')
                            }
                        />
                        {tileError && (
                            <Card className="absolute top-3 left-3 z-[500] rounded-lg p-2 text-xs">
                                Map tiles unavailable. Vehicle list and actions
                                remain available.
                            </Card>
                        )}
                        {located.length === 0 && (
                            <Card className="pointer-events-none absolute top-1/3 left-1/2 z-[500] max-w-xs -translate-x-1/2 p-4 text-center shadow">
                                <strong>
                                    {shown.length
                                        ? 'No shareable positions'
                                        : 'No vehicles selected'}
                                </strong>
                                <p className="text-caption">
                                    Home sites never substitute for vehicle
                                    positions.
                                </p>
                            </Card>
                        )}
                        <div
                            className="absolute bottom-3 left-3 z-[500] flex flex-col gap-3"
                            style={{ width: 'min(440px, calc(100% - 24px))' }}
                        >
                            {active && (
                                <Card
                                    className="gap-0 rounded-[14px] border bg-card p-3 shadow-lg"
                                    aria-label="Selected vehicle details"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <strong className="text-sm">
                                                {active.name}
                                            </strong>
                                            <p className="text-caption">
                                                {active.registration_number ||
                                                    active.asset_tag ||
                                                    'No registration'}
                                                {active.asset_tag &&
                                                active.registration_number
                                                    ? ` · ${active.asset_tag}`
                                                    : ''}
                                            </p>
                                            <p className="text-caption">
                                                Home site:{' '}
                                                {active.home_site?.name ??
                                                    'Not recorded'}
                                            </p>
                                        </div>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Close vehicle details"
                                            onClick={() => setFocusId(null)}
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                    <div className="mt-3 grid grid-cols-2 gap-3">
                                        <div>
                                            <StatusBadge
                                                variant={
                                                    positionState(active) ===
                                                    'reported'
                                                        ? 'info'
                                                        : positionState(
                                                                active,
                                                            ) === 'stale'
                                                          ? 'warning'
                                                          : 'neutral'
                                                }
                                            >
                                                {
                                                    stateLabel[
                                                        positionState(active)
                                                    ]
                                                }
                                            </StatusBadge>
                                            <p className="mt-1 text-xs">
                                                {active.position?.observed_at
                                                    ? `Observed ${formatDateTime(active.position.observed_at)}`
                                                    : 'No shareable observation'}
                                            </p>
                                            <p className="text-caption">
                                                {positionState(active) ===
                                                'stale'
                                                    ? 'Last known; this vehicle may have moved.'
                                                    : positionState(active) ===
                                                        'withheld'
                                                      ? 'Position is restricted by source privacy.'
                                                      : 'Position does not establish readiness or custody.'}
                                            </p>
                                        </div>
                                        <div>
                                            <StatusBadge
                                                variant={
                                                    summary?.restriction
                                                        ? 'critical'
                                                        : summary?.use_problem ||
                                                            summary?.next_due
                                                          ? 'warning'
                                                          : 'neutral'
                                                }
                                            >
                                                {summary?.readiness_label ??
                                                    'Evidence to assess'}
                                            </StatusBadge>
                                            <p className="mt-1 text-xs">
                                                {summary?.restriction
                                                    ?.work_title ??
                                                    summary?.use_problem ??
                                                    (summary?.next_due
                                                        ? `${summary.next_due.title} · ${formatDateTime(summary.next_due.start)}`
                                                        : 'Review current vehicle evidence')}
                                            </p>
                                            <p className="text-caption mt-1">
                                                Readiness comes from the vehicle
                                                source.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() => onProfile(active)}
                                        >
                                            Open vehicle{' '}
                                            <ArrowUpRight className="size-3" />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => onCalendar(active)}
                                        >
                                            <CalendarDays className="size-3" />{' '}
                                            Vehicle calendar
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                onProfile(active, sourceTab)
                                            }
                                        >
                                            {summary?.restriction
                                                ? 'View restriction'
                                                : summary?.next_due
                                                  ? 'View service due'
                                                  : 'Review evidence'}{' '}
                                            <ArrowUpRight className="size-3" />
                                        </Button>
                                    </div>
                                </Card>
                            )}
                            <Card className="flex flex-row flex-wrap items-center gap-3 rounded-lg bg-card/95 p-2 text-[10px] shadow">
                                <span className="inline-flex items-center gap-1">
                                    <span
                                        className="size-2 rounded-full"
                                        style={{
                                            background: 'var(--primary)',
                                        }}
                                    />
                                    Reported position
                                </span>
                                <span className="inline-flex items-center gap-1">
                                    <span
                                        className="size-2 rounded-full"
                                        style={{
                                            background: 'var(--status-warning)',
                                        }}
                                    />
                                    Last known · stale
                                </span>
                                <label className="ml-auto flex items-center gap-2">
                                    <Checkbox
                                        checked={homes}
                                        onCheckedChange={(value) =>
                                            setHomes(value === true)
                                        }
                                    />
                                    Home sites
                                </label>
                            </Card>
                        </div>
                    </div>
                </div>
                <aside
                    className="fleet-map-list flex min-h-0 min-w-0 flex-col overflow-hidden rounded-[14px] border bg-card shadow-sm"
                    aria-label="Choose fleet vehicles"
                >
                    <div className="space-y-2 border-b p-3">
                        <div className="flex justify-between">
                            <strong>Fleet vehicles</strong>
                            <span className="text-caption">
                                {matching.length} shown · {permittedCount ?? vehicles.length}{' '}
                                permitted
                            </span>
                        </div>
                        <div className="relative">
                            <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                            <Input
                                aria-label="Search fleet map"
                                className="pl-8"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Name, registration, tag or site"
                            />
                        </div>
                        <div className="grid grid-cols-[38px_minmax(0,1fr)] items-center gap-x-2 gap-y-3">
                            <label
                                htmlFor="fleet-map-position"
                                className="text-caption"
                            >
                                Position
                            </label>
                            <select
                                id="fleet-map-position"
                                aria-label="Position state"
                                className="min-w-0 rounded-md border bg-muted p-2 text-xs"
                                value={positionFilter}
                                onChange={(event) =>
                                    setPositionFilter(event.target.value)
                                }
                            >
                                {[
                                    ['all', 'All position states'],
                                    ['located', 'Located'],
                                    ['unavailable', 'Without position'],
                                    ...Object.entries(stateLabel),
                                ].map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <label
                                htmlFor="fleet-map-sort"
                                className="text-caption"
                            >
                                Sort
                            </label>
                            <select
                                id="fleet-map-sort"
                                aria-label="Sort vehicles"
                                className="min-w-0 rounded-md border bg-muted p-2 text-xs"
                                value={sort}
                                onChange={(event) =>
                                    setSort(
                                        event.target.value as 'name' | 'recent',
                                    )
                                }
                            >
                                <option value="name">Vehicle name</option>
                                <option value="recent">
                                    Latest observation
                                </option>
                            </select>
                        </div>
                    </div>
                    <div className="flex items-center justify-between gap-2 border-b px-3 py-2 text-xs">
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={
                                    allShownChecked
                                        ? true
                                        : shown.length
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={(value) =>
                                    chooseShown(value === true)
                                }
                            />
                            Select all shown
                        </label>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setChecked(new Set())}
                        >
                            Clear selection
                        </Button>
                    </div>
                    <label className="flex items-center gap-2 border-b px-3 py-2 text-xs">
                        <Checkbox
                            checked={onlyChecked}
                            onCheckedChange={(value) =>
                                setOnlyChecked(value === true)
                            }
                        />
                        Show checked only
                    </label>
                    <div
                        ref={listRef}
                        className="scrollbar-pretty min-h-0 flex-1 overflow-y-auto"
                    >
                        {matching.map((vehicle) => (
                            <div
                                data-vehicle-id={vehicle.id}
                                key={vehicle.id}
                                onContextMenu={(event) => {
                                    event.preventDefault();
                                    showMenu(
                                        vehicle,
                                        event.currentTarget,
                                        event.clientX,
                                        event.clientY,
                                    );
                                }}
                                className={`flex items-center gap-2 border-b p-2 ${focusId === vehicle.id ? 'border-l-[3px] border-l-primary bg-accent' : ''}`}
                            >
                                <Checkbox
                                    checked={selected.has(vehicle.id)}
                                    onCheckedChange={() => toggle(vehicle.id)}
                                    aria-label={`Show ${vehicle.name} on map`}
                                />
                                <Button
                                    variant="ghost"
                                    className="h-auto min-w-0 flex-1 flex-col items-start justify-start text-left"
                                    onClick={() => choose(vehicle)}
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'ContextMenu' ||
                                            (event.shiftKey &&
                                                event.key === 'F10')
                                        ) {
                                            event.preventDefault();
                                            showMenu(
                                                vehicle,
                                                event.currentTarget,
                                            );
                                        }
                                    }}
                                >
                                    <strong className="block truncate text-sm">
                                        {vehicle.name}
                                    </strong>
                                    <span className="text-caption block truncate">
                                        {vehicle.registration_number ||
                                            vehicle.asset_tag ||
                                            'No registration'}{' '}
                                        ·{' '}
                                        {vehicle.home_site?.name ??
                                            'No home site'}
                                    </span>
                                    <span className="text-caption block">
                                        {stateLabel[positionState(vehicle)]}
                                        {vehicle.position?.observed_at
                                            ? ` · ${formatDateTime(vehicle.position.observed_at)}`
                                            : ''}
                                    </span>
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`More actions for ${vehicle.name}`}
                                    onClick={(event) =>
                                        showMenu(vehicle, event.currentTarget)
                                    }
                                >
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </div>
                        ))}
                        {!matching.length && (
                            <p className="p-4 text-sm text-muted-foreground">
                                No vehicles match this search or filter.
                            </p>
                        )}
                    </div>
                    <div className="text-caption border-t p-3">
                        <strong>
                            {shown.length} checked of {matching.length} shown
                        </strong>
                        <p className="mt-1">
                            Search and filters apply to the list and map.
                        </p>
                    </div>
                </aside>
            </div>
            <p className="text-caption flex items-center gap-2">
                <Lock className="size-3" />
                Locations follow role and site permissions. A reported position
                does not prove availability, return or readiness.
            </p>
            {menu && (
                <CalendarContextMenu
                    x={menu.point.x}
                    y={menu.point.y}
                    heading={menuVehicle?.name ?? 'Map actions'}
                    subheading={
                        menuVehicle
                            ? stateLabel[positionState(menuVehicle)]
                            : 'Recorded positions'
                    }
                    chip={menuVehicle ? 'Vehicle' : 'Map'}
                    chipIcon={menuVehicle ? Car : MapPin}
                    ariaLabel="Fleet map actions"
                    returnFocus={menu.opener}
                    onClose={() => setMenu(null)}
                    sections={
                        menuVehicle
                            ? [
                                  {
                                      key: 'vehicle',
                                      items: [
                                          {
                                              key: 'inspect',
                                              label: 'Inspect location details',
                                              icon: MapPin,
                                              onSelect: action(() =>
                                                  choose(menuVehicle),
                                              ),
                                          },
                                          {
                                              key: 'centre',
                                              label: 'Centre recorded position',
                                              icon: LocateFixed,
                                              disabled:
                                                  !hasPosition(menuVehicle),
                                              detail: hasPosition(menuVehicle)
                                                  ? positionState(
                                                        menuVehicle,
                                                    ) === 'stale'
                                                      ? 'Last known position; vehicle may have moved.'
                                                      : undefined
                                                  : stateLabel[
                                                        positionState(
                                                            menuVehicle,
                                                        )
                                                    ],
                                              onSelect: action(() =>
                                                  centre(menuVehicle),
                                              ),
                                          },
                                          {
                                              key: 'profile',
                                              label: 'Open vehicle profile',
                                              icon: Car,
                                              onSelect: action(() => {
                                                  choose(menuVehicle);
                                                  onProfile(menuVehicle);
                                              }),
                                          },
                                          {
                                              key: 'calendar',
                                              label: 'View vehicle calendar',
                                              icon: CalendarDays,
                                              onSelect: action(() => {
                                                  choose(menuVehicle);
                                                  onCalendar(menuVehicle);
                                              }),
                                          },
                                          {
                                              key: 'request',
                                              label: 'Request this vehicle',
                                              icon: Plus,
                                              disabled:
                                                  !requestAllowed(menuVehicle),
                                              detail: requestAllowed(
                                                  menuVehicle,
                                              )
                                                  ? 'Readiness and conflicts are checked by the booking source.'
                                                  : 'Request access requires current data and the vehicle’s approved site.',
                                              onSelect: action(() => {
                                                  choose(menuVehicle);
                                                  onRequest(menuVehicle);
                                              }),
                                          },
                                          {
                                              key: 'source',
                                              label: sourceLabel,
                                              icon: Lock,
                                              onSelect: action(() => {
                                                  choose(menuVehicle);
                                                  onProfile(
                                                      menuVehicle,
                                                      sourceTab,
                                                  );
                                              }),
                                          },
                                      ],
                                  },
                                  {
                                      key: 'visibility',
                                      items: [
                                          {
                                              key: 'toggle',
                                              label: selected.has(
                                                  menuVehicle.id,
                                              )
                                                  ? 'Hide from map'
                                                  : 'Show on map',
                                              icon: selected.has(menuVehicle.id)
                                                  ? EyeOff
                                                  : Eye,
                                              onSelect: action(() =>
                                                  toggle(menuVehicle.id),
                                              ),
                                          },
                                          {
                                              key: 'isolate',
                                              label: 'Show only this vehicle',
                                              icon: Car,
                                              onSelect: action(() => {
                                                  setChecked(
                                                      new Set([menuVehicle.id]),
                                                  );
                                                  choose(menuVehicle);
                                              }),
                                          },
                                      ],
                                  },
                                  {
                                      key: 'map',
                                      items: [
                                          {
                                              key: 'fit',
                                              label: 'Fit shown positions',
                                              icon: LocateFixed,
                                              disabled: !located.length,
                                              onSelect: action(fitShown),
                                          },
                                          {
                                              key: 'homes',
                                              label: homes
                                                  ? 'Hide home sites'
                                                  : 'Show home sites',
                                              icon: MapPin,
                                              onSelect: action(() =>
                                                  setHomes(!homes),
                                              ),
                                          },
                                      ],
                                  },
                              ]
                            : [
                                  {
                                      key: 'map',
                                      items: [
                                          {
                                              key: 'centre-here',
                                              label: 'Centre map here',
                                              icon: LocateFixed,
                                              onSelect: action(() => {
                                                  setFit(false);
                                                  setCamera({
                                                      lat: menu.point.lat,
                                                      lng: menu.point.lng,
                                                      nonce: Date.now(),
                                                  });
                                              }),
                                          },
                                          {
                                              key: 'fit',
                                              label: 'Fit shown positions',
                                              icon: LocateFixed,
                                              disabled: !located.length,
                                              onSelect: action(fitShown),
                                          },
                                          {
                                              key: 'homes',
                                              label: homes
                                                  ? 'Hide home sites'
                                                  : 'Show home sites',
                                              icon: MapPin,
                                              onSelect: action(() =>
                                                  setHomes(!homes),
                                              ),
                                          },
                                          {
                                              key: 'refresh',
                                              label: 'Recheck source',
                                              icon: RefreshCw,
                                              onSelect: action(() => {
                                                  void reload();
                                              }),
                                          },
                                          {
                                              key: 'boundaries',
                                              label: 'Maps & boundaries',
                                              icon: Layers,
                                              onSelect: action(() =>
                                                  window.location.assign(
                                                      '/fleet-assets/map',
                                                  ),
                                              ),
                                          },
                                      ],
                                  },
                              ]
                    }
                />
            )}
        </section>
    );
}
