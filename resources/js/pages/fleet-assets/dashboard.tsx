/* eslint-disable no-restricted-syntax -- Map markers, filter segments and dense list selectors use compact button layouts; shared Button remains the standard action control. */
import { FleetPageMenu } from '@/components/fleet-assets/fleet-page-menu';
import { FleetQueueActions } from '@/components/fleet-assets/fleet-queue-actions';
import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import PageShell from '@/components/page-shell';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, useRemember } from '@inertiajs/react';
import {
    ArrowRight,
    Bookmark,
    CalendarDays,
    Car,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    Clock3,
    Database,
    Info,
    Layers,
    LayoutDashboard,
    List,
    MapPin,
    Maximize2,
    Minimize2,
    Package,
    RefreshCw,
    RotateCcw,
    Search,
    ShieldAlert,
    Wrench,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import './dashboard.css';
import {
    bookingOverlapsOverviewDay,
    boundedOverviewPage,
    browserViewsToImport,
    isOverviewOverdue,
    normalizeOverviewFilters,
} from './overview-model';
import {
    AvailabilityDonut,
    BookingLoad,
    UpcomingAgenda,
    WorkRows,
} from './overview-panels';

type View = 'overview' | 'attention' | 'upcoming' | 'availability';
type Availability = 'Available now' | 'In use' | 'Restricted' | 'Unknown';
export type Resource = {
    id: number;
    kind: 'vehicle' | 'asset';
    name: string;
    ref: string;
    registration: string | null;
    site_id: number | null;
    site: string | null;
    availability: Availability | null;
    readiness_note: string | null;
    location: {
        lat: number;
        lng: number;
        observed_at: string | null;
        source: string;
        fresh: boolean;
    } | null;
    href: string;
    can_book: boolean;
    can_location: boolean;
};
export type WorkItem = {
    id: string;
    type: 'booking' | 'work' | 'due' | 'appointment';
    category?: 'returns' | 'restricted' | 'unassigned' | 'work' | 'evidence';
    title: string;
    owner?: string | null;
    detail?: string;
    ref: string;
    resource: string;
    resource_ref: string;
    site_id: number | null;
    site: string | null;
    due_at: string | null;
    starts_at: string | null;
    ends_at: string | null;
    href: string;
};
type Overview = {
    as_of: string;
    timezone: string;
    viewer_key: number;
    sites: { id: number; name: string }[];
    vehicles: Resource[];
    assets: Resource[];
    attention: WorkItem[];
    agenda: WorkItem[];
    receipts: {
        state: 'loaded' | 'unavailable' | 'no_access';
        count: number | null;
        page: number;
        pages: number;
        site: string;
        query: string;
        rows: {
            id: number;
            asset_id: number;
            asset: string;
            ref: string;
            site_id: number | null;
            site: string | null;
            assigned_at: string | null;
            href: string;
        }[];
    };
    bookings: {
        id: number;
        asset_id: number;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
    }[];
    booked_hours: {
        date: string;
        site_id: number | null;
        asset_id: number;
        hours: number;
    }[];
    sources: {
        name: string;
        state: 'loaded' | 'unavailable' | 'no_access';
        count: number | null;
        description: string;
    }[];
    can: {
        fleet: boolean;
        assets: boolean;
        booking: boolean;
        maintenance: boolean;
        settings: boolean;
    };
};
type Props = { overview: Overview; saved_views: SavedView[] };
type Filters = {
    view: View;
    site: string;
    period: 'week' | 'today';
    q: string;
    attention: string;
    due: string;
    sort: string;
    availability: string;
    mapType: string;
    mapFresh: string;
    agendaKind: string;
    agendaDay: string;
};
const DEFAULT: Filters = {
    view: 'overview',
    site: 'all',
    period: 'week',
    q: '',
    attention: 'all',
    due: 'all',
    sort: 'due',
    availability: 'all',
    mapType: 'all',
    mapFresh: 'all',
    agendaKind: 'all',
    agendaDay: 'all',
};
const SAVABLE: (keyof Filters)[] = [
    'view',
    'site',
    'period',
    'q',
    'attention',
    'due',
    'sort',
    'availability',
    'mapType',
    'mapFresh',
    'agendaKind',
    'agendaDay',
];
type SavedView = { name: string; filters: Filters };
const PAGE_SIZE = 5;

function usableSavedViews(
    value: unknown,
    sites: Overview['sites'],
): SavedView[] {
    if (!Array.isArray(value)) return [];
    const names = new Set<string>();
    return value
        .flatMap((item): SavedView[] => {
            if (!item || typeof item.name !== 'string' || !item.filters)
                return [];
            const name = item.name.trim().slice(0, 40);
            if (!name || names.has(name.toLocaleLowerCase())) return [];
            names.add(name.toLocaleLowerCase());
            const filters = normalizeOverviewFilters(item.filters, DEFAULT);
            if (
                filters.site !== 'all' &&
                !sites.some((site) => String(site.id) === filters.site)
            )
                filters.site = 'all';
            return [{ name, filters }];
        })
        .slice(0, 6);
}

function readFilters(): Filters {
    if (typeof window === 'undefined') return DEFAULT;
    const params = new URLSearchParams(window.location.search);
    return {
        view: ['overview', 'attention', 'upcoming', 'availability'].includes(
            params.get('view') || '',
        )
            ? (params.get('view') as View)
            : 'overview',
        site: params.get('site') || 'all',
        period: params.get('period') === 'today' ? 'today' : 'week',
        q: (params.get('q') || '').slice(0, 120),
        attention: [
            'all',
            'returns',
            'restricted',
            'unassigned',
            'work',
            'evidence',
        ].includes(params.get('attention') || '')
            ? params.get('attention')!
            : 'all',
        due: ['all', 'overdue', 'today', 'undated'].includes(
            params.get('due') || '',
        )
            ? params.get('due')!
            : 'all',
        sort: params.get('sort') === 'resource' ? 'resource' : 'due',
        availability: [
            'all',
            'Available now',
            'In use',
            'Restricted',
            'Unknown',
        ].includes(params.get('availability') || '')
            ? params.get('availability')!
            : 'all',
        mapType: ['all', 'vehicle', 'asset'].includes(
            params.get('mapType') || '',
        )
            ? params.get('mapType')!
            : 'all',
        mapFresh: ['all', 'stale'].includes(params.get('mapFresh') || '')
            ? params.get('mapFresh')!
            : 'all',
        agendaKind: ['all', 'booking', 'appointment', 'due', 'work'].includes(
            params.get('agendaKind') || '',
        )
            ? params.get('agendaKind')!
            : 'all',
        agendaDay: /^(all|today|tomorrow|rest|\d{4}-\d{2}-\d{2})$/.test(
            params.get('agendaDay') || '',
        )
            ? params.get('agendaDay')!
            : 'all',
    };
}
function writeFilters(value: Filters, resetReceiptPage = false) {
    const url = new URL(window.location.href);
    if (resetReceiptPage) url.searchParams.delete('receipt_page');
    for (const key of SAVABLE) {
        const part = value[key];
        if (part === DEFAULT[key]) url.searchParams.delete(key);
        else url.searchParams.set(key, part);
    }
    router.replace({
        url: url.pathname + url.search,
        preserveState: true,
        preserveScroll: true,
    });
    return url.pathname + url.search;
}
function nzDay(value: string, zone: string): string {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(value));
    const get = (type: string) =>
        parts.find((part) => part.type === type)?.value || '';
    return `${get('year')}-${get('month')}-${get('day')}`;
}
function dayLabel(value: string, zone: string) {
    if (!value) return 'No due date';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value))
        return new Intl.DateTimeFormat('en-NZ', {
            timeZone: 'UTC',
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        }).format(new Date(`${value}T12:00:00Z`));
    return new Intl.DateTimeFormat('en-NZ', {
        timeZone: zone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}
function matches(term: string, ...parts: (string | null | undefined)[]) {
    return parts
        .join(' ')
        .toLocaleLowerCase()
        .includes(term.trim().toLocaleLowerCase());
}
function pageRows<T>(rows: T[], page: number) {
    const current = boundedOverviewPage(page, rows.length);
    return rows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
}
function Pager({
    page,
    count,
    onPage,
}: {
    page: number;
    count: number;
    onPage: (page: number) => void;
}) {
    const pages = Math.max(1, Math.ceil(count / PAGE_SIZE));
    page = boundedOverviewPage(page, count);
    if (pages <= 1) return null;
    return (
        <nav aria-label="Results pages" className="fo-pager">
            <span>
                {Math.min((page - 1) * PAGE_SIZE + 1, count)}–
                {Math.min(page * PAGE_SIZE, count)} of {count}
            </span>
            <Button
                variant="outline"
                size="sm"
                disabled={page <= 1}
                onClick={() => onPage(page - 1)}
                aria-label="Previous page"
            >
                <ChevronLeft className="size-4" />
            </Button>
            <span>
                Page {page} of {pages}
            </span>
            <Button
                variant="outline"
                size="sm"
                disabled={page >= pages}
                onClick={() => onPage(page + 1)}
                aria-label="Next page"
            >
                <ChevronRight className="size-4" />
            </Button>
        </nav>
    );
}
function stateClass(state: string | null) {
    return state === 'Available now'
        ? 'fo-good'
        : state === 'Restricted'
          ? 'fo-danger'
          : state === 'In use'
            ? 'fo-info'
            : 'fo-unknown';
}
function Status({ state }: { state: string | null }) {
    return (
        <span className={`fo-status ${stateClass(state)}`}>
            {state || 'Site record'}
        </span>
    );
}

function MapPanel({
    resources,
    filter,
    onFilter,
    freshness,
    onFreshness,
    onOpen,
    focusId,
    onFocusHandled,
}: {
    resources: Resource[];
    filter: string;
    onFilter: (value: string) => void;
    freshness: string;
    onFreshness: (value: string) => void;
    onOpen: (href: string) => void;
    focusId: string | null;
    onFocusHandled: () => void;
}) {
    const [selected, setSelected] = useState<string | null>(null);
    const [expanded, setExpanded] = useState(false);
    const [list, setList] = useState<'all' | 'unknown' | null>(null);
    const [listQuery, setListQuery] = useState('');
    const [listPage, setListPage] = useState(1);
    const [context, setContext] = useState<{
        x: number;
        y: number;
        id: string;
    } | null>(null);
    const [tiles, setTiles] = useState<'loading' | 'loaded' | 'failed'>(
        'loading',
    );
    const [mapQuery, setMapQuery] = useState('');
    const [groupNearby, setGroupNearby] = useState(true);
    const [mapKey, setMapKey] = useState(0);
    const mapRef = useRef<HTMLDivElement>(null);
    const visible = useMemo(
        () =>
            resources.filter(
                (resource) =>
                    (filter === 'all' || resource.kind === filter) &&
                    matches(
                        mapQuery,
                        resource.name,
                        resource.ref,
                        resource.registration,
                        resource.site,
                    ),
            ),
        [resources, filter, mapQuery],
    );
    const known = useMemo(
        () => visible.filter((resource) => resource.location !== null),
        [visible],
    );
    const unknown = visible.filter((resource) => resource.location === null);
    const plotted = useMemo(
        () =>
            known.filter(
                (resource) =>
                    freshness !== 'stale' ||
                    (resource.kind === 'vehicle' && !resource.location?.fresh),
            ),
        [known, freshness],
    );
    // Identical Site coordinates represent distinct assets. Keep an explicit
    // chooser so every record is reachable even at Leaflet's maximum zoom.
    const markerGroups = useMemo(() => {
        const groups = new Map<string, Resource[]>();
        for (const resource of plotted) {
            const key = `${resource.location!.lat.toFixed(6)},${resource.location!.lng.toFixed(6)}`;
            groups.set(key, [...(groups.get(key) || []), resource]);
        }
        return Array.from(groups.values());
    }, [plotted]);
    const markers: MapMarker[] = useMemo(
        () =>
            markerGroups.map((group) => {
                const first = group[0];
                const isGroup = group.length > 1;
                const assetSiteGroup = group.every(
                    (item) => item.kind === 'asset',
                );
                const staleTrackerGroup = group.some(
                    (item) => item.kind === 'vehicle' && !item.location?.fresh,
                );
                return {
                    id: isGroup
                        ? `group-${first.location!.lat},${first.location!.lng}`
                        : `${first.kind}-${first.id}`,
                    lat: first.location!.lat,
                    lng: first.location!.lng,
                    type: isGroup ? 'default' : first.kind,
                    color: assetSiteGroup
                        ? 'var(--chart-2)'
                        : staleTrackerGroup
                          ? 'var(--status-warning)'
                          : 'var(--primary)',
                    title: isGroup
                        ? `${group.length} resources at this recorded point`
                        : `${first.registration || first.ref} · ${first.name}`,
                    stats: isGroup
                        ? [
                              [
                                  'Resources',
                                  group
                                      .map(
                                          (item) =>
                                              item.registration || item.ref,
                                      )
                                      .join(' · '),
                              ],
                              [
                                  'Sources',
                                  [
                                      ...new Set(
                                          group.map(
                                              (item) => item.location!.source,
                                          ),
                                      ),
                                  ].join(' · '),
                              ],
                          ]
                        : [
                              [
                                  'Availability',
                                  first.availability || 'Not a vehicle',
                              ],
                              ['Site', first.site || 'Unknown'],
                              ['Source', first.location!.source],
                              [
                                  'Observed',
                                  first.location!.observed_at
                                      ? dayLabel(
                                            first.location!.observed_at,
                                            'Pacific/Auckland',
                                        )
                                      : 'No live observation',
                              ],
                          ],
                    hint: 'Select for evidence and actions · right-click for options',
                };
            }),
        [markerGroups],
    );
    const markerItems = markers.map((marker, index) => ({
        marker,
        group: markerGroups[index],
    }));
    const current = markerItems.find(
        (item) => String(item.marker.id) === selected,
    );
    useEffect(() => {
        if (!focusId) return;
        const match = markerItems.find((entry) =>
            entry.group.some(
                (resource) => `${resource.kind}-${resource.id}` === focusId,
            ),
        );
        setSelected(
            match && match.group.length > 1 ? String(match.marker.id) : focusId,
        );
        mapRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        onFocusHandled();
    }, [focusId, markerItems, onFocusHandled]);
    const shownRows = (list === 'unknown' ? unknown : visible).filter((item) =>
        matches(listQuery, item.name, item.ref, item.registration, item.site),
    );
    const safePage = Math.min(
        listPage,
        Math.max(1, Math.ceil(shownRows.length / PAGE_SIZE)),
    );
    const openDetails = useCallback((id: string | number) => {
        setSelected(String(id));
        setList(null);
        setContext(null);
    }, []);
    useEffect(() => {
        setListPage(1);
    }, [list, listQuery]);
    useEffect(() => {
        if (!expanded) return;
        const close = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || context) return;
            if (list) {
                setList(null);
                return;
            }
            if (selected) {
                setSelected(null);
                return;
            }
            setExpanded(false);
        };
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, [expanded, context, list, selected]);
    useEffect(() => {
        if (!expanded) return;
        const previousOverflow = document.body.style.overflow;
        const previousFocus = document.activeElement as HTMLElement | null;
        document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = previousOverflow;
            previousFocus?.focus();
        };
    }, [expanded]);
    const actions = (resource: Resource): MenuItem[] => [
        {
            label:
                resource.kind === 'vehicle'
                    ? 'Open vehicle profile'
                    : 'Open asset profile',
            icon: ArrowRight,
            onClick: () => onOpen(resource.href),
        },
        ...(resource.can_location
            ? [
                  {
                      label: 'View location evidence',
                      icon: MapPin,
                      onClick: () =>
                          onOpen(`${resource.href}?tab=map&view=location`),
                  },
              ]
            : []),
        ...(resource.can_book
            ? [
                  {
                      label: 'Open bookings',
                      icon: CalendarDays,
                      onClick: () =>
                          onOpen(
                              `/fleet-assets/bookings?asset_id=${resource.id}`,
                          ),
                  },
              ]
            : []),
    ];
    const contextGroup = context
        ? markerItems.find((item) => String(item.marker.id) === context.id)
              ?.group
        : null;
    return (
        <section
            className={`fo-map-panel ${expanded ? 'fo-map-expanded' : ''}`}
            ref={mapRef}
            aria-label="Fleet and asset locations"
            role={expanded ? 'dialog' : 'region'}
            aria-modal={expanded || undefined}
            onKeyDown={(event) => {
                if (!expanded || context || event.key !== 'Tab') return;
                const controls = Array.from(
                    mapRef.current?.querySelectorAll<HTMLElement>(
                        'button:not(:disabled), a[href], input, select, [tabindex="0"]',
                    ) || [],
                ).filter((element) => element.getClientRects().length > 0);
                const first = controls[0],
                    last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }
            }}
        >
            <div className="fo-section-head">
                <div>
                    <h2>Vehicles &amp; assets on the map</h2>
                    <p>Last reported locations · approved Sites only</p>
                </div>
            </div>
            <div className="fo-map-toolbar">
                <label className="fo-map-search">
                    <Search size={16} />
                    <input
                        type="search"
                        aria-label="Search map resources"
                        placeholder="Find a vehicle or asset…"
                        value={mapQuery}
                        onChange={(event) => setMapQuery(event.target.value)}
                    />
                </label>
                <div className="fo-segmented" aria-label="Map resource type">
                    {[
                        ['all', 'All'],
                        ['vehicle', 'Vehicles'],
                        ['asset', 'Assets'],
                    ].map(([value, label]) => (
                        <button
                            key={value}
                            className={filter === value ? 'active' : ''}
                            aria-pressed={filter === value}
                            onClick={() => {
                                onFilter(value);
                                setSelected(null);
                            }}
                        >
                            {label}{' '}
                            <small>
                                {
                                    resources.filter(
                                        (item) =>
                                            value === 'all' ||
                                            item.kind === value,
                                    ).length
                                }
                            </small>
                        </button>
                    ))}
                </div>
                <button
                    className={`fo-stale-toggle fo-group-toggle ${groupNearby ? 'active' : ''}`}
                    aria-pressed={groupNearby}
                    onClick={() => setGroupNearby(!groupNearby)}
                >
                    <Layers size={14} /> Group nearby
                </button>
                <button
                    className={`fo-stale-toggle ${freshness === 'stale' ? 'active' : ''}`}
                    title="Show last-known tracker locations"
                    aria-pressed={freshness === 'stale'}
                    onClick={() =>
                        onFreshness(freshness === 'stale' ? 'all' : 'stale')
                    }
                >
                    <Clock3 size={14} /> Stale{' '}
                    {
                        known.filter(
                            (item) =>
                                item.kind === 'vehicle' &&
                                !item.location?.fresh,
                        ).length
                    }
                </button>
                <div className="fo-head-actions">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setList('all');
                            setSelected(null);
                        }}
                    >
                        <List className="size-4" /> List
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Reset map view"
                        onClick={() => setMapKey((key) => key + 1)}
                    >
                        <RotateCcw size={15} />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setExpanded(!expanded)}
                        aria-label={
                            expanded ? 'Exit full screen map' : 'Expand map'
                        }
                    >
                        {expanded ? (
                            <Minimize2 className="size-4" />
                        ) : (
                            <Maximize2 className="size-4" />
                        )}
                    </Button>
                </div>
            </div>
            <div className="fo-map-canvas">
                <LeafletMap
                    key={mapKey}
                    center={
                        markers.length
                            ? { lat: markers[0].lat, lng: markers[0].lng }
                            : { lat: -41.2865, lng: 174.7767 }
                    }
                    zoom={markers.length ? 12 : 5}
                    markers={markers}
                    clustering={groupNearby}
                    clusterThreshold={0}
                    showMarkerPopups={false}
                    autoFit
                    fitMarkers={false}
                    observeResize
                    height="100%"
                    onMarkerClick={openDetails}
                    onContext={(point) =>
                        point.markerId
                            ? setContext({
                                  x: point.x,
                                  y: point.y,
                                  id: String(point.markerId),
                              })
                            : setContext(null)
                    }
                    onTileStatus={setTiles}
                />
                {tiles === 'failed' && (
                    <div className="fo-tile-notice">
                        Street tiles unavailable. Recorded resources remain in
                        the list.
                    </div>
                )}
            </div>
            <div className="fo-map-summary">
                <span>
                    <MapPin size={14} /> <strong>{plotted.length}</strong>{' '}
                    mapped
                </span>
                <button onClick={() => setList('unknown')}>
                    <CircleHelp size={14} /> {unknown.length} without a location{' '}
                    <ArrowRight size={13} />
                </button>
            </div>
            <div className="fo-map-sources">
                <span>
                    <Car size={13} />
                    Vehicle report
                </span>
                <span>
                    <Package size={13} />
                    Recorded asset Site
                </span>
                <span>
                    <MapPin size={13} />
                    Last-known tracker
                </span>
                <span>
                    <Layers size={13} />
                    Number = nearby pins
                </span>
            </div>
            <p className="fo-note">
                Select a pin for details and actions · hover for a quick look ·
                right-click for shortcuts. Equipment pins show a recorded Site,
                not live GPS.
            </p>
            {current && (
                <div className="fo-map-detail">
                    <button
                        className="fo-close"
                        onClick={() => setSelected(null)}
                        aria-label="Close location details"
                    >
                        <X className="size-4" />
                    </button>
                    {current.group.length > 1 ? (
                        <>
                            <p className="fo-eyebrow">Shared recorded point</p>
                            <h3>{current.group.length} resources</h3>
                            <p>
                                Select a resource for its own evidence and
                                actions.
                            </p>
                            <div className="fo-resource-chooser">
                                {current.group.map((resource) => (
                                    <button
                                        key={`${resource.kind}-${resource.id}`}
                                        onClick={() =>
                                            setSelected(
                                                `${resource.kind}-${resource.id}`,
                                            )
                                        }
                                    >
                                        {resource.name}
                                        <span>{resource.ref}</span>
                                    </button>
                                ))}
                            </div>
                        </>
                    ) : (
                        <ResourceDetail
                            resource={current.group[0]}
                            actions={actions(current.group[0])}
                        />
                    )}
                </div>
            )}
            {selected &&
                !current &&
                (() => {
                    const resource = resources.find(
                        (item) => `${item.kind}-${item.id}` === selected,
                    );
                    return resource ? (
                        <div className="fo-map-detail">
                            <button
                                className="fo-close"
                                onClick={() => setSelected(null)}
                                aria-label="Close location details"
                            >
                                <X className="size-4" />
                            </button>
                            <ResourceDetail
                                resource={resource}
                                actions={actions(resource)}
                            />
                        </div>
                    ) : null;
                })()}
            {list && (
                <div className="fo-map-list">
                    <div className="fo-section-head">
                        <div>
                            <p className="fo-eyebrow">Resource list</p>
                            <h3>
                                {list === 'unknown'
                                    ? 'Without a recorded pin'
                                    : 'All resources in scope'}
                            </h3>
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => setList(null)}
                            aria-label="Close resource list"
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                    <input
                        type="search"
                        value={listQuery}
                        onChange={(event) => setListQuery(event.target.value)}
                        placeholder="Search name, registration or Site"
                        aria-label="Search resource list"
                        className="fo-input"
                    />
                    {pageRows(shownRows, safePage).map((item) => (
                        <button
                            key={`${item.kind}-${item.id}`}
                            className="fo-list-row"
                            onClick={() => {
                                setSelected(`${item.kind}-${item.id}`);
                                setList(null);
                            }}
                        >
                            <span>
                                <strong>{item.name}</strong>
                                <small>
                                    {item.ref} · {item.site || 'Site unknown'}
                                </small>
                            </span>
                            <span>
                                {item.location
                                    ? item.location.source
                                    : 'Location unknown'}
                            </span>
                        </button>
                    ))}
                    {shownRows.length === 0 && (
                        <p className="fo-empty">No matching resources.</p>
                    )}
                    <Pager
                        page={safePage}
                        count={shownRows.length}
                        onPage={setListPage}
                    />
                </div>
            )}
            {context && contextGroup && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    icon={contextGroup[0].kind === 'vehicle' ? Car : Package}
                    title={
                        contextGroup.length > 1
                            ? `${contextGroup.length} resources · choose one`
                            : contextGroup[0].name
                    }
                    items={
                        contextGroup.length > 1
                            ? contextGroup.map((item) => ({
                                  label: `${item.name} · ${item.ref}`,
                                  onClick: () =>
                                      setSelected(`${item.kind}-${item.id}`),
                              }))
                            : actions(contextGroup[0])
                    }
                    onClose={() => setContext(null)}
                />
            )}
        </section>
    );
}
function ResourceDetail({
    resource,
    actions,
}: {
    resource: Resource;
    actions: MenuItem[];
}) {
    return (
        <>
            <p className="fo-eyebrow">
                {resource.kind === 'vehicle'
                    ? 'Vehicle location'
                    : 'Asset Site record'}
            </p>
            <h3>{resource.name}</h3>
            <p>
                {resource.registration || resource.ref} ·{' '}
                {resource.site || 'Site unknown'}
            </p>
            <Status state={resource.availability} />
            <dl>
                <div>
                    <dt>Source</dt>
                    <dd>{resource.location?.source || 'Location unknown'}</dd>
                </div>
                <div>
                    <dt>Observed</dt>
                    <dd>
                        {resource.location?.observed_at
                            ? dayLabel(
                                  resource.location.observed_at,
                                  'Pacific/Auckland',
                              )
                            : 'No live observation'}
                    </dd>
                </div>
                <div>
                    <dt>Readiness</dt>
                    <dd>
                        {resource.readiness_note || 'Check the source record'}
                    </dd>
                </div>
            </dl>
            <div className="fo-detail-actions">
                {actions.map((action) => (
                    <Button
                        key={action.label}
                        variant="outline"
                        size="sm"
                        onClick={action.onClick}
                    >
                        {action.label}
                    </Button>
                ))}
            </div>
        </>
    );
}

export default function FleetAssetsDashboard({ overview, saved_views }: Props) {
    const [filters, setFilters] = useState<Filters>(readFilters);
    const [attentionPage, setAttentionPage] = useRemember(
        1,
        'FleetOverview.attentionPage',
    );
    const [agendaPage, setAgendaPage] = useRemember(
        1,
        'FleetOverview.agendaPage',
    );
    const [vehiclePage, setVehiclePage] = useRemember(
        1,
        'FleetOverview.vehiclePage',
    );
    const [focusResource, setFocusResource] = useState<string | null>(null);
    const [dataOpen, setDataOpen] = useState(false);
    const [chartOpen, setChartOpen] = useState(false);
    const [saveOpen, setSaveOpen] = useState(false);
    const [savedViewsOpen, setSavedViewsOpen] = useState(false);
    const [saveName, setSaveName] = useState('');
    const [renameFrom, setRenameFrom] = useState<string | null>(null);
    const [saveError, setSaveError] = useState('');
    const [saved, setSaved] = useState<SavedView[]>(() =>
        usableSavedViews(saved_views, overview.sites),
    );
    const [undo, setUndo] = useState<SavedView[] | null>(null);
    const [legacyViews, setLegacyViews] = useState<SavedView[]>([]);
    const [savingViews, setSavingViews] = useState(false);
    const savingViewsRef = useRef(false);
    const receiptReloadTimer = useRef<number | null>(null);
    const [viewMessage, setViewMessage] = useState('');
    const [refreshMessage, setRefreshMessage] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [receiptError, setReceiptError] = useState('');
    const storageKey = `fleet-overview-views-v1-${overview.viewer_key}`;
    const zone = overview.timezone || 'Pacific/Auckland';
    const today = nzDay(overview.as_of, zone);
    const resources = [...overview.vehicles, ...overview.assets];
    const update = (patch: Partial<Filters>) => {
        const next = normalizeOverviewFilters(
            { ...filters, ...patch },
            DEFAULT,
        );
        setFilters(next);
        const scopeChanged = next.site !== filters.site || next.q !== filters.q;
        writeFilters(next, scopeChanged);
        if (scopeChanged) {
            if (receiptReloadTimer.current !== null)
                window.clearTimeout(receiptReloadTimer.current);
            receiptReloadTimer.current = window.setTimeout(() => {
                loadReceiptScope(
                    window.location.pathname + window.location.search,
                );
                receiptReloadTimer.current = null;
            }, 250);
        }
        setAttentionPage(1);
        setAgendaPage(1);
        setVehiclePage(1);
    };
    useEffect(() => {
        setSaved(usableSavedViews(saved_views, overview.sites));
    }, [saved_views, overview.sites]);
    useEffect(() => {
        try {
            const value = JSON.parse(localStorage.getItem(storageKey) || '[]');
            setLegacyViews(usableSavedViews(value, overview.sites));
        } catch {
            setLegacyViews([]);
        }
    }, [storageKey, overview.sites]);
    useEffect(() => {
        const listener = () => setFilters(readFilters());
        window.addEventListener('popstate', listener);
        return () => {
            window.removeEventListener('popstate', listener);
            if (receiptReloadTimer.current !== null)
                window.clearTimeout(receiptReloadTimer.current);
        };
    }, []);
    const persist = (next: SavedView[], afterSave?: () => void) => {
        if (savingViewsRef.current) return;
        savingViewsRef.current = true;
        setSavingViews(true);
        setViewMessage('Saving views…');
        const previous = saved;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            savingViewsRef.current = false;
            setSavingViews(false);
            offInvalid();
            offException();
        };
        const fail = () => {
            setViewMessage('Could not save views. Try again.');
            finish();
        };
        const offInvalid = router.on('invalid', (event) => {
            event.preventDefault();
            fail();
        });
        const offException = router.on('exception', (event) => {
            event.preventDefault();
            fail();
        });
        try {
            router.put(
                '/settings/ui-preferences/fleet.overview.saved-views',
                {
                    value: next,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setSaved(next);
                        setUndo(previous);
                        setViewMessage('Saved to your account.');
                        afterSave?.();
                    },
                    onError: fail,
                    onCancel: () => {
                        setViewMessage(
                            'Save cancelled. Your previous views are intact.',
                        );
                        finish();
                    },
                    onFinish: finish,
                },
            );
        } catch {
            fail();
        }
    };
    const saveView = () => {
        const name = saveName.trim();
        if (!name || name.length > 40) {
            setSaveError('Enter a name of 1–40 characters.');
            return;
        }
        if (
            saved.some(
                (item) =>
                    item.name !== renameFrom &&
                    item.name.toLocaleLowerCase() === name.toLocaleLowerCase(),
            )
        ) {
            setSaveError('A view already has this name.');
            return;
        }
        const close = () => {
            setSaveOpen(false);
            setRenameFrom(null);
            setSaveName('');
            setSaveError('');
        };
        if (renameFrom)
            persist(
                saved.map((item) =>
                    item.name === renameFrom ? { ...item, name } : item,
                ),
                close,
            );
        else {
            if (saved.length >= 6) {
                setSaveError('You can save up to six views.');
                return;
            }
            persist([...saved, { name, filters }], close);
        }
    };
    const scoped = (resource: Resource) =>
        (filters.site === 'all' || String(resource.site_id) === filters.site) &&
        matches(
            filters.q,
            resource.name,
            resource.ref,
            resource.registration,
            resource.site,
        );
    const vehicles = overview.vehicles.filter(scoped);
    const assets = overview.assets.filter(scoped);
    const relatedRefs = new Set(
        [...vehicles, ...assets].map((item) => item.ref),
    );
    const workScoped = (item: WorkItem) =>
        (filters.site === 'all' || String(item.site_id) === filters.site) &&
        (matches(
            filters.q,
            item.title,
            item.ref,
            item.resource,
            item.resource_ref,
            item.site,
        ) ||
            (filters.q.trim() && relatedRefs.has(item.resource_ref)));
    const attention = overview.attention
        .filter(workScoped)
        .filter(
            (item) =>
                filters.attention === 'all' ||
                item.category === filters.attention,
        )
        .filter((item) => {
            if (filters.due === 'all') return true;
            if (filters.due === 'undated') return !item.due_at;
            if (!item.due_at) return false;
            const due = nzDay(item.due_at, zone);
            return filters.due === 'today'
                ? due === today
                : isOverviewOverdue(item.due_at, overview.as_of);
        })
        .sort((a, b) =>
            filters.sort === 'resource'
                ? a.resource.localeCompare(b.resource)
                : (a.due_at || '9999').localeCompare(b.due_at || '9999'),
        );
    const tomorrowDate = new Date(`${today}T12:00:00Z`);
    tomorrowDate.setUTCDate(tomorrowDate.getUTCDate() + 1);
    const tomorrow = tomorrowDate.toISOString().slice(0, 10);
    const agendaForPeriod = overview.agenda
        .filter(workScoped)
        .filter((item) => {
            const time = item.starts_at || item.due_at;
            if (!time) return false;
            const day = /^\d{4}-\d{2}-\d{2}$/.test(time)
                ? time
                : nzDay(time, zone);
            return filters.period !== 'today' || day === today;
        })
        .sort((a, b) =>
            (a.starts_at || a.due_at || '').localeCompare(
                b.starts_at || b.due_at || '',
            ),
        );
    const isBookedDay =
        filters.agendaKind === 'booking' &&
        /^\d{4}-\d{2}-\d{2}$/.test(filters.agendaDay);
    const agenda = (
        isBookedDay
            ? [
                  ...agendaForPeriod,
                  ...overview.attention.filter(
                      (item) => item.type === 'booking' && workScoped(item),
                  ),
              ]
            : agendaForPeriod
    )
        .filter(
            (item) =>
                filters.agendaKind === 'all' ||
                item.type === filters.agendaKind,
        )
        .filter((item) => {
            const time = item.starts_at || item.due_at || '';
            if (
                item.type === 'booking' &&
                /^\d{4}-\d{2}-\d{2}$/.test(filters.agendaDay)
            ) {
                const booking = overview.bookings.find(
                    (row) => item.id === `booking-${row.id}`,
                );
                return (
                    !!booking &&
                    bookingOverlapsOverviewDay(
                        booking.starts_at,
                        booking.ends_at,
                        filters.agendaDay,
                        zone,
                    )
                );
            }
            const day = /^\d{4}-\d{2}-\d{2}$/.test(time)
                ? time
                : nzDay(time, zone);
            if (filters.agendaDay === 'today') return day === today;
            if (filters.agendaDay === 'tomorrow') return day === tomorrow;
            if (filters.agendaDay === 'rest') return day > tomorrow;
            return filters.agendaDay === 'all' || day === filters.agendaDay;
        });
    const availableVehicles = vehicles.filter(
        (vehicle) =>
            filters.availability === 'all' ||
            vehicle.availability === filters.availability,
    );
    const counts = ['Available now', 'In use', 'Restricted', 'Unknown'].map(
        (state) =>
            vehicles.filter((vehicle) => vehicle.availability === state).length,
    );
    const scopedAttention = overview.attention.filter(workScoped);
    const needsAttention = scopedAttention.length;
    const bookedHours = overview.booked_hours.filter((row) =>
        vehicles.some((vehicle) => vehicle.id === row.asset_id),
    );
    const bookingsHealthy =
        overview.sources.find((source) => source.name === 'Bookings')?.state ===
        'loaded';
    const openBookedDay = (day: string) =>
        update({
            view: 'upcoming',
            period: 'week',
            agendaKind: 'booking',
            agendaDay: day,
        });
    const noKnownAvailability =
        overview.sources.find((source) => source.name === 'Bookings')?.state !==
            'loaded' ||
        overview.sources.find((source) => source.name === 'Vehicle readiness')
            ?.state !== 'loaded';
    const siteName =
        filters.site === 'all'
            ? `${overview.sites.length} approved ${overview.sites.length === 1 ? 'Site' : 'Sites'}`
            : overview.sites.find((site) => String(site.id) === filters.site)
                  ?.name || 'Approved Site';
    const summary = `${siteName} · ${vehicles.length} ${vehicles.length === 1 ? 'vehicle' : 'vehicles'} · ${assets.length} ${assets.length === 1 ? 'asset' : 'assets'} · observed ${dayLabel(overview.as_of, zone)}`;
    const inSite = (siteId: number | null) =>
        filters.site === 'all' || String(siteId) === filters.site;
    const sourceCount = (name: string, fallback: number | null) => {
        if (filters.site === 'all' || fallback === null) return fallback;
        if (name === 'Bookings')
            return overview.bookings.filter((row) =>
                inSite(
                    overview.vehicles.find(
                        (vehicle) => vehicle.id === row.asset_id,
                    )?.site_id ?? null,
                ),
            ).length;
        if (name === 'Vehicle readiness')
            return overview.vehicles.filter((vehicle) =>
                inSite(vehicle.site_id),
            ).length;
        if (name === 'Maintenance')
            return overview.attention.filter(
                (row) => row.type === 'work' && inSite(row.site_id),
            ).length;
        if (name === 'Locations')
            return [...overview.vehicles, ...overview.assets].filter(
                (resource) => inSite(resource.site_id) && resource.location,
            ).length;
        if (name === 'Planning')
            return overview.agenda.filter(
                (row) =>
                    ['appointment', 'due'].includes(row.type) &&
                    inSite(row.site_id),
            ).length;
        if (name === 'Assignments and receipt') return overview.receipts.count;
        return fallback;
    };
    const legacyNeedsImport = legacyViews.some(
        (local) =>
            !saved.some(
                (account) =>
                    account.name.toLocaleLowerCase() ===
                        local.name.toLocaleLowerCase() &&
                    JSON.stringify(account.filters) ===
                        JSON.stringify(local.filters),
            ),
    );
    const importableLegacy = browserViewsToImport(saved, legacyViews);
    const navigate = (href: string) => router.visit(href);
    const openView = (view: View) => update({ view });
    const receiptInScope =
        overview.receipts.site === filters.site &&
        overview.receipts.query === filters.q.trim().slice(0, 120);
    const receiptCount = receiptInScope ? overview.receipts.count : null;
    const loadReceiptScope = (url: string) => {
        setReceiptError('');
        let settled = false;
        const finish = () => {
            if (settled) return;
            settled = true;
            offInvalid();
            offException();
        };
        const fail = () => {
            setReceiptError('Could not load receipts. Try again.');
            finish();
        };
        const offInvalid = router.on('invalid', (event) => {
            event.preventDefault();
            fail();
        });
        const offException = router.on('exception', (event) => {
            event.preventDefault();
            fail();
        });
        try {
            router.get(
                url,
                {},
                {
                    only: ['overview'],
                    replace: true,
                    preserveState: true,
                    preserveScroll: true,
                    onError: fail,
                    onFinish: finish,
                },
            );
        } catch {
            fail();
        }
    };
    const goReceiptPage = (page: number) => {
        const url = new URL(window.location.href);
        if (page <= 1) url.searchParams.delete('receipt_page');
        else url.searchParams.set('receipt_page', String(page));
        loadReceiptScope(url.pathname + url.search);
    };
    const openReceipts = () => {
        if (overview.receipts.state !== 'loaded') {
            setDataOpen(true);
            return;
        }
        if (filters.view !== 'overview') update({ view: 'overview' });
        window.setTimeout(
            () =>
                document
                    .getElementById('fo-receipts')
                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
            100,
        );
    };
    const refresh = () => {
        setRefreshing(true);
        setRefreshMessage('');
        const failed = () =>
            setRefreshMessage(
                'Refresh failed. Existing observations remain visible. Try again.',
            );
        const offInvalid = router.on('invalid', (event) => {
            event.preventDefault();
            failed();
        });
        const offException = router.on('exception', (event) => {
            event.preventDefault();
            failed();
        });
        router.reload({
            only: ['overview'],
            onSuccess: (page) => {
                const latest = page.props.overview as Overview;
                const unavailable = latest.sources.filter(
                    (source) => source.state === 'unavailable',
                );
                setRefreshMessage(
                    unavailable.length
                        ? `Refreshed. Still unavailable: ${unavailable.map((source) => source.name).join(', ')}.`
                        : 'Latest source data loaded.',
                );
            },
            onError: failed,
            onFinish: () => {
                setRefreshing(false);
                offInvalid();
                offException();
            },
        });
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
            ]}
        >
            <Head title="Fleet & Assets Overview" />
            <PageShell>
                <div className="fleet-overview">
                    <PageHeader
                        icon={Car}
                        title="Fleet & Assets"
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant="neutral">
                                Overview
                            </PageHeaderStatusChip>
                        }
                        subline="Find vehicles, follow up work and plan ahead"
                        actions={
                            <>
                                <FleetPageMenu />
                                {
                                    <>
                                        <PageHeaderSearch
                                            value={filters.q}
                                            onChange={(q) => update({ q })}
                                            placeholder="Find records…"
                                        />
                                        <FleetQueueActions
                                            siteId={filters.site}
                                        />
                                        <PageHeaderGlassButton
                                            icon={RefreshCw}
                                            aria-label="Refresh overview"
                                            disabled={refreshing}
                                            onClick={refresh}
                                        />
                                        {overview.can.fleet && (
                                            <PageHeaderPrimaryButton
                                                onClick={() =>
                                                    router.visit(
                                                        '/fleet-assets/vehicles',
                                                    )
                                                }
                                            >
                                                View fleet{' '}
                                                <ArrowRight className="size-4" />
                                            </PageHeaderPrimaryButton>
                                        )}
                                    </>
                                }
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Needs attention"
                                    onClick={() => openView('attention')}
                                >
                                    <PageHeaderMeterBig>
                                        {needsAttention}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Work needing action
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Available now"
                                    value={
                                        noKnownAvailability
                                            ? '—'
                                            : `${counts[0]} / ${vehicles.length}`
                                    }
                                    onClick={() => openView('availability')}
                                >
                                    <PageHeaderMeterBar
                                        percent={
                                            vehicles.length
                                                ? (counts[0] /
                                                      vehicles.length) *
                                                  100
                                                : 0
                                        }
                                    />
                                    <PageHeaderMeterCaption>
                                        {counts[3]} unknown · check before use
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Coming up"
                                    onClick={() => openView('upcoming')}
                                >
                                    <PageHeaderMeterBig>
                                        {agendaForPeriod.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Bookings, appointments, due work
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Receipt to confirm"
                                    onClick={openReceipts}
                                >
                                    <PageHeaderMeterBig>
                                        {overview.receipts.state === 'loaded' &&
                                        receiptCount !== null
                                            ? receiptCount
                                            : '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {overview.receipts.state === 'loaded'
                                            ? receiptCount === null
                                                ? 'Updating selected scope…'
                                                : 'Equipment receipt unconfirmed'
                                            : overview.receipts.state ===
                                                'no_access'
                                              ? 'Assignment access required'
                                              : 'Receipt source unavailable'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                <span className="fo-approved-scope">
                                    Approved Sites only
                                </span>
                                <PageHeaderFilterSelect
                                    label="All approved Sites"
                                    value={filters.site}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All approved Sites',
                                        },
                                        ...overview.sites.map((site) => ({
                                            value: String(site.id),
                                            label: site.name,
                                        })),
                                    ]}
                                    onChange={(site) => update({ site })}
                                />
                                <PageHeaderFilterSelect
                                    label="Next 7 days"
                                    value={filters.period}
                                    allValue="week"
                                    options={[
                                        { value: 'week', label: 'Next 7 days' },
                                        { value: 'today', label: 'Today' },
                                    ]}
                                    onChange={(period) =>
                                        update({
                                            period: period as Filters['period'],
                                        })
                                    }
                                />
                                <PageHeaderGlassButton
                                    className="fo-saved-trigger"
                                    icon={Bookmark}
                                    onClick={() => setSavedViewsOpen(true)}
                                >
                                    Saved views {saved.length || ''}
                                </PageHeaderGlassButton>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={[
                                    {
                                        key: 'overview',
                                        label: 'Overview',
                                        icon: LayoutDashboard,
                                    },
                                    {
                                        key: 'attention',
                                        label: 'Needs attention',
                                        icon: ShieldAlert,
                                    },
                                    {
                                        key: 'upcoming',
                                        label: 'Coming up',
                                        icon: CalendarDays,
                                    },
                                    {
                                        key: 'availability',
                                        label: 'Availability',
                                        count: vehicles.length,
                                        icon: Car,
                                    },
                                ]}
                                value={filters.view}
                                onSelect={openView}
                            />
                        }
                    />
                    <div className="fo-footer fo-observation" title={summary}>
                        <span>
                            <Clock3 size={14} /> Observed{' '}
                            {formatDateTime(overview.as_of)} · {zone}
                        </span>
                        <button onClick={() => setDataOpen(true)}>
                            <Database className="size-3" /> Data status
                        </button>
                    </div>
                    {(filters.q || filters.site !== 'all') && (
                        <div className="fo-controls" aria-label="Active scope">
                            <span>
                                {filters.site !== 'all'
                                    ? siteName
                                    : 'All approved Sites'}
                                {filters.q ? ` · Search: ${filters.q}` : ''}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => update({ q: '', site: 'all' })}
                            >
                                Clear search and Site
                            </Button>
                        </div>
                    )}
                    <Dialog
                        open={savedViewsOpen}
                        onOpenChange={setSavedViewsOpen}
                    >
                        <DialogContent className="fleet-overview fo-dialog">
                            <DialogHeader>
                                <DialogTitle>Saved views</DialogTitle>
                                <DialogDescription>
                                    Save the current filters to your account, or
                                    open a saved view.
                                </DialogDescription>
                            </DialogHeader>
                            <Button
                                variant="outline"
                                disabled={savingViews}
                                onClick={() => {
                                    setRenameFrom(null);
                                    setSaveName('');
                                    setSaveOpen(true);
                                }}
                            >
                                <Bookmark className="size-4" /> Save current
                                view
                            </Button>
                            {saved.length === 0 && (
                                <p className="fo-note">
                                    No saved views yet. Save your current
                                    filters to find them here next time.
                                </p>
                            )}
                            <div className="fo-saved fo-saved-dialog">
                                <span>
                                    <Bookmark className="size-3.5" /> Saved
                                    views
                                </span>
                                {importableLegacy.length > 0 && (
                                    <button
                                        disabled={savingViews}
                                        onClick={() =>
                                            persist([
                                                ...saved,
                                                ...importableLegacy,
                                            ])
                                        }
                                    >
                                        Import {importableLegacy.length} view
                                        {importableLegacy.length === 1
                                            ? ''
                                            : 's'}{' '}
                                        from this browser
                                    </button>
                                )}
                                {legacyNeedsImport &&
                                    importableLegacy.length === 0 && (
                                        <span>
                                            Remove a saved view to make room for
                                            browser views.
                                        </span>
                                    )}
                                {saved.map((item) => (
                                    <div
                                        key={item.name}
                                        className="fo-saved-item"
                                    >
                                        <button
                                            disabled={savingViews}
                                            onClick={() => {
                                                const validated = {
                                                    ...DEFAULT,
                                                    ...normalizeOverviewFilters(
                                                        item.filters,
                                                        DEFAULT,
                                                    ),
                                                    site: overview.sites.some(
                                                        (site) =>
                                                            String(site.id) ===
                                                            item.filters.site,
                                                    )
                                                        ? item.filters.site
                                                        : 'all',
                                                };
                                                update(validated);
                                                setSavedViewsOpen(false);
                                            }}
                                        >
                                            {item.name}
                                        </button>
                                        <EntityKebab
                                            label={`Actions for ${item.name}`}
                                            actions={
                                                savingViews
                                                    ? []
                                                    : [
                                                          {
                                                              label: 'Update with current filters',
                                                              onClick: () =>
                                                                  persist(
                                                                      saved.map(
                                                                          (
                                                                              view,
                                                                          ) =>
                                                                              view.name ===
                                                                              item.name
                                                                                  ? {
                                                                                        ...view,
                                                                                        filters,
                                                                                    }
                                                                                  : view,
                                                                      ),
                                                                  ),
                                                          },
                                                          {
                                                              label: 'Rename',
                                                              onClick: () => {
                                                                  setRenameFrom(
                                                                      item.name,
                                                                  );
                                                                  setSaveName(
                                                                      item.name,
                                                                  );
                                                                  setSaveOpen(
                                                                      true,
                                                                  );
                                                              },
                                                          },
                                                          {
                                                              label: 'Remove',
                                                              onClick: () =>
                                                                  persist(
                                                                      saved.filter(
                                                                          (
                                                                              view,
                                                                          ) =>
                                                                              view !==
                                                                              item,
                                                                      ),
                                                                  ),
                                                          },
                                                      ]
                                            }
                                        />
                                    </div>
                                ))}
                                {undo && (
                                    <button
                                        disabled={savingViews}
                                        onClick={() => {
                                            persist(undo, () => setUndo(null));
                                        }}
                                    >
                                        Undo last change
                                    </button>
                                )}
                                {viewMessage && (
                                    <span role="status">{viewMessage}</span>
                                )}
                            </div>
                        </DialogContent>
                    </Dialog>
                    {filters.view === 'overview' && (
                        <>
                            <section
                                className="fo-urgent-strip"
                                aria-label="Priority follow-ups"
                            >
                                <strong>
                                    <ShieldAlert size={17} />
                                    Follow up
                                </strong>
                                {[
                                    {
                                        key: 'returns',
                                        label: 'Overdue returns',
                                        Icon: CalendarDays,
                                    },
                                    {
                                        key: 'restricted',
                                        label: 'Restricted vehicles',
                                        Icon: ShieldAlert,
                                    },
                                    {
                                        key: 'unassigned',
                                        label: 'Unassigned repairs',
                                        Icon: Wrench,
                                    },
                                ].map(({ key, label, Icon }) => (
                                    <button
                                        key={key}
                                        onClick={() =>
                                            update({
                                                view: 'attention',
                                                attention: key,
                                                due: 'all',
                                            })
                                        }
                                    >
                                        <Icon size={16} />
                                        <b>
                                            {key === 'returns' &&
                                            !bookingsHealthy
                                                ? '—'
                                                : scopedAttention.filter(
                                                      (item) =>
                                                          item.category === key,
                                                  ).length}
                                        </b>
                                        <span>{label}</span>
                                        <ArrowRight size={14} />
                                    </button>
                                ))}
                            </section>
                            <MapPanel
                                resources={[...vehicles, ...assets]}
                                filter={filters.mapType}
                                onFilter={(mapType) => update({ mapType })}
                                freshness={filters.mapFresh}
                                onFreshness={(mapFresh) => update({ mapFresh })}
                                onOpen={navigate}
                                focusId={focusResource}
                                onFocusHandled={() => setFocusResource(null)}
                            />
                            <div className="fo-operations-grid">
                                <div className="fo-operations-work">
                                    <section>
                                        <div className="fo-section-head">
                                            <div>
                                                <h2>Needs attention</h2>
                                                <p>
                                                    {Math.min(
                                                        scopedAttention.length,
                                                        4,
                                                    )}{' '}
                                                    of {scopedAttention.length}{' '}
                                                    shown · follow-ups that need
                                                    action
                                                </p>
                                            </div>
                                            <button
                                                className="fo-text-link"
                                                onClick={() =>
                                                    update({
                                                        view: 'attention',
                                                        attention: 'all',
                                                        due: 'all',
                                                    })
                                                }
                                            >
                                                View all{' '}
                                                <ArrowRight size={14} />
                                            </button>
                                        </div>
                                        <WorkRows
                                            items={scopedAttention.slice(0, 4)}
                                            zone={zone}
                                            navigate={navigate}
                                        />
                                    </section>
                                    <section>
                                        <div className="fo-section-head">
                                            <div>
                                                <h2>Coming up</h2>
                                                <p>
                                                    Next in your selected period
                                                </p>
                                            </div>
                                            <button
                                                className="fo-text-link"
                                                onClick={() =>
                                                    openView('upcoming')
                                                }
                                            >
                                                View all{' '}
                                                {agendaForPeriod.length}{' '}
                                                <ArrowRight size={14} />
                                            </button>
                                        </div>
                                        <UpcomingAgenda
                                            items={agendaForPeriod.slice(0, 2)}
                                            today={today}
                                            navigate={navigate}
                                        />
                                    </section>
                                </div>
                                <section className="fo-card fo-availability-panel">
                                    <div className="fo-section-head">
                                        <div>
                                            <h2>Vehicle availability</h2>
                                            <p>
                                                Recorded state ·{' '}
                                                {formatDateTime(overview.as_of)}
                                            </p>
                                        </div>
                                        <button
                                            aria-label="About vehicle availability"
                                            onClick={() => setDataOpen(true)}
                                        >
                                            <Info size={17} />
                                        </button>
                                    </div>
                                    <AvailabilityDonut
                                        vehicles={vehicles}
                                        onSelect={(availability) =>
                                            update({
                                                view: 'availability',
                                                availability,
                                            })
                                        }
                                    />
                                    <p className="fo-note">
                                        No recorded block is not a safety
                                        assurance. Driver, time and checkout
                                        checks still apply.
                                    </p>
                                    <button
                                        className="fo-text-link"
                                        onClick={() => openView('availability')}
                                    >
                                        Inspect vehicle evidence{' '}
                                        <ArrowRight size={14} />
                                    </button>
                                </section>
                            </div>
                            <BookingLoad
                                hours={bookedHours}
                                site={filters.site}
                                today={today}
                                healthy={bookingsHealthy}
                                onDay={openBookedDay}
                            />
                            <section
                                id="fo-receipts"
                                className="fo-card fo-receipt-panel"
                            >
                                {receiptError && (
                                    <div role="alert" className="fo-notice">
                                        {receiptError}{' '}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                loadReceiptScope(
                                                    window.location.pathname +
                                                        window.location.search,
                                                )
                                            }
                                        >
                                            Retry receipts
                                        </Button>
                                    </div>
                                )}
                                <div className="fo-section-head">
                                    <div>
                                        <h2>Asset receipt</h2>
                                        <p>
                                            Active assignments awaiting an
                                            explicit receipt verification in the
                                            selected Site and search.
                                        </p>
                                    </div>
                                    <span
                                        className={`fo-status ${overview.receipts.state === 'loaded' ? '' : 'fo-unknown'}`}
                                    >
                                        {overview.receipts.state === 'loaded' &&
                                        receiptCount !== null
                                            ? `${receiptCount} pending`
                                            : overview.receipts.state ===
                                                'loaded'
                                              ? 'Updating…'
                                              : overview.receipts.state ===
                                                  'no_access'
                                                ? 'No access'
                                                : 'Unavailable'}
                                    </span>
                                </div>
                                {receiptInScope &&
                                overview.receipts.state === 'loaded' &&
                                overview.receipts.rows.length > 0 ? (
                                    <div className="fo-receipt-rows">
                                        {overview.receipts.rows.map((row) => (
                                            <Link
                                                key={row.id}
                                                href={row.href}
                                                className="fo-receipt-row"
                                            >
                                                <span>
                                                    <strong>{row.asset}</strong>
                                                    <small>
                                                        {row.ref} ·{' '}
                                                        {row.site ||
                                                            'Site not shown'}
                                                    </small>
                                                </span>
                                                <span>
                                                    {row.assigned_at
                                                        ? formatDateTime(
                                                              row.assigned_at,
                                                          )
                                                        : 'Date unavailable'}{' '}
                                                    <ArrowRight size={14} />
                                                </span>
                                            </Link>
                                        ))}
                                        <Pager
                                            page={overview.receipts.page}
                                            count={overview.receipts.count ?? 0}
                                            onPage={goReceiptPage}
                                        />
                                    </div>
                                ) : (
                                    <div className="fo-receipt-empty">
                                        <Package size={22} />
                                        <div>
                                            <strong>
                                                {!receiptInScope
                                                    ? 'Updating selected scope'
                                                    : overview.receipts
                                                            .state === 'loaded'
                                                      ? 'No receipt verifications pending'
                                                      : overview.receipts
                                                              .state ===
                                                          'no_access'
                                                        ? 'Assignment access required'
                                                        : 'Receipt source unavailable'}
                                            </strong>
                                            <p>
                                                {!receiptInScope
                                                    ? 'Loading matching assignments…'
                                                    : overview.receipts
                                                            .state === 'loaded'
                                                      ? 'No active assignment in this Site and search is awaiting verification.'
                                                      : 'Open data status for source details.'}
                                            </p>
                                        </div>
                                        {overview.receipts.state !==
                                            'loaded' && (
                                            <button
                                                className="fo-text-link"
                                                onClick={() =>
                                                    setDataOpen(true)
                                                }
                                            >
                                                View data status{' '}
                                                <ArrowRight size={14} />
                                            </button>
                                        )}
                                    </div>
                                )}
                            </section>
                        </>
                    )}
                    {filters.view === 'attention' && (
                        <section className="fo-card fo-workspace">
                            <div className="fo-section-head">
                                <div>
                                    <p className="fo-eyebrow">
                                        Source-owned work
                                    </p>
                                    <h2>Needs attention</h2>
                                    <p>
                                        Open the canonical record for decisions
                                        and updates.
                                    </p>
                                </div>
                                <span className="fo-count">
                                    {attention.length}{' '}
                                    {attention.length === 1 ? 'item' : 'items'}
                                </span>
                            </div>
                            <div className="fo-controls">
                                <select
                                    aria-label="Attention category"
                                    value={filters.attention}
                                    onChange={(event) =>
                                        update({
                                            attention: event.target.value,
                                        })
                                    }
                                >
                                    <option value="all">All follow-up</option>
                                    <option value="returns">
                                        Overdue returns
                                    </option>
                                    <option value="restricted">
                                        Active holds
                                    </option>
                                    <option value="work">
                                        Maintenance work
                                    </option>
                                    <option value="evidence">
                                        Readiness evidence
                                    </option>
                                    <option value="unassigned">
                                        Owner needed
                                    </option>
                                </select>
                                <select
                                    aria-label="Due date"
                                    value={filters.due}
                                    onChange={(event) =>
                                        update({ due: event.target.value })
                                    }
                                >
                                    <option value="all">Any due date</option>
                                    <option value="overdue">Overdue</option>
                                    <option value="today">Due today</option>
                                    <option value="undated">No due date</option>
                                </select>
                                <select
                                    aria-label="Sort attention"
                                    value={filters.sort}
                                    onChange={(event) =>
                                        update({ sort: event.target.value })
                                    }
                                >
                                    <option value="due">Due soonest</option>
                                    <option value="resource">
                                        Resource name
                                    </option>
                                </select>
                                {(filters.attention !== 'all' ||
                                    filters.due !== 'all') && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            update({
                                                attention: 'all',
                                                due: 'all',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                )}
                            </div>
                            <WorkRows
                                items={pageRows(attention, attentionPage)}
                                zone={zone}
                                navigate={navigate}
                            />
                            <Pager
                                page={attentionPage}
                                count={attention.length}
                                onPage={setAttentionPage}
                            />
                        </section>
                    )}
                    {filters.view === 'upcoming' && (
                        <section className="fo-card fo-workspace">
                            <div className="fo-section-head">
                                <div>
                                    <p className="fo-eyebrow">Planning</p>
                                    <h2>Coming up</h2>
                                    <p>
                                        Confirmed bookings are reservations.
                                        Date-only work has no invented
                                        appointment time.
                                    </p>
                                </div>
                                <span className="fo-count">
                                    {agenda.length}{' '}
                                    {agenda.length === 1 ? 'item' : 'items'}
                                </span>
                            </div>
                            <div className="fo-controls">
                                <button
                                    className={
                                        filters.agendaDay === 'today' ||
                                        filters.period === 'today'
                                            ? 'active'
                                            : ''
                                    }
                                    onClick={() =>
                                        update({
                                            period: 'week',
                                            agendaDay: 'today',
                                        })
                                    }
                                >
                                    Today
                                </button>
                                <button
                                    className={
                                        filters.agendaDay === 'tomorrow'
                                            ? 'active'
                                            : ''
                                    }
                                    onClick={() =>
                                        update({
                                            period: 'week',
                                            agendaDay: 'tomorrow',
                                        })
                                    }
                                >
                                    Tomorrow
                                </button>
                                <button
                                    className={
                                        filters.agendaDay === 'rest'
                                            ? 'active'
                                            : ''
                                    }
                                    onClick={() =>
                                        update({
                                            period: 'week',
                                            agendaDay: 'rest',
                                        })
                                    }
                                >
                                    Rest of week
                                </button>
                                <button
                                    className={
                                        filters.agendaDay === 'all' &&
                                        filters.period === 'week'
                                            ? 'active'
                                            : ''
                                    }
                                    onClick={() =>
                                        update({
                                            period: 'week',
                                            agendaDay: 'all',
                                        })
                                    }
                                >
                                    All 7 days
                                </button>
                                <select
                                    aria-label="Coming up type"
                                    value={filters.agendaKind}
                                    onChange={(event) =>
                                        update({
                                            agendaKind: event.target.value,
                                        })
                                    }
                                >
                                    <option value="all">All types</option>
                                    <option value="booking">Bookings</option>
                                    <option value="appointment">
                                        Appointments
                                    </option>
                                    <option value="work">
                                        Maintenance work
                                    </option>
                                    <option value="due">Due dates</option>
                                </select>
                                {(filters.agendaDay !== 'all' ||
                                    filters.agendaKind !== 'all' ||
                                    filters.period !== 'week') && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            update({
                                                agendaDay: 'all',
                                                agendaKind: 'all',
                                                period: 'week',
                                            })
                                        }
                                    >
                                        Clear agenda filters
                                    </Button>
                                )}
                            </div>
                            {/\d{4}-\d{2}-\d{2}/.test(filters.agendaDay) && (
                                <p className="fo-note">
                                    Bookings overlapping{' '}
                                    {dayLabel(filters.agendaDay, zone)}
                                </p>
                            )}
                            <UpcomingAgenda
                                items={pageRows(agenda, agendaPage)}
                                today={today}
                                navigate={navigate}
                            />
                            <Pager
                                page={agendaPage}
                                count={agenda.length}
                                onPage={setAgendaPage}
                            />
                            <Button
                                variant="outline"
                                onClick={() => setChartOpen(true)}
                            >
                                <CalendarDays size={16} />
                                Booking load
                            </Button>
                        </section>
                    )}
                    {filters.view === 'availability' && (
                        <section className="fo-card fo-workspace">
                            <div className="fo-section-head">
                                <div>
                                    <p className="fo-eyebrow">
                                        Evidence, not clearance
                                    </p>
                                    <h2>Vehicle availability</h2>
                                    <p>
                                        Check the vehicle profile before making
                                        a use decision.
                                    </p>
                                </div>
                                <AvailabilityDonut
                                    vehicles={vehicles}
                                    onSelect={(availability) =>
                                        update({ availability })
                                    }
                                />
                            </div>
                            <div className="fo-controls">
                                {[
                                    'all',
                                    'Available now',
                                    'In use',
                                    'Restricted',
                                    'Unknown',
                                ].map((state) => (
                                    <button
                                        key={state}
                                        className={
                                            filters.availability === state
                                                ? 'active'
                                                : ''
                                        }
                                        onClick={() =>
                                            update({ availability: state })
                                        }
                                    >
                                        {state === 'all'
                                            ? 'All vehicles'
                                            : state}
                                    </button>
                                ))}
                            </div>
                            {pageRows(availableVehicles, vehiclePage).map(
                                (vehicle) => (
                                    <div
                                        key={vehicle.id}
                                        className="fo-work-row"
                                    >
                                        <span className="fo-row-icon">
                                            <Car className="size-4" />
                                        </span>
                                        <div className="fo-row-main">
                                            <strong>{vehicle.name}</strong>
                                            <small>
                                                {vehicle.registration ||
                                                    vehicle.ref}{' '}
                                                ·{' '}
                                                {vehicle.site || 'Site unknown'}{' '}
                                                · {vehicle.readiness_note}
                                            </small>
                                        </div>
                                        <Status state={vehicle.availability} />
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                update({
                                                    view: 'overview',
                                                    mapType: 'all',
                                                    mapFresh: 'all',
                                                });
                                                setFocusResource(
                                                    `vehicle-${vehicle.id}`,
                                                );
                                            }}
                                        >
                                            View on map
                                        </Button>
                                        <Link
                                            href={vehicle.href}
                                            className="fo-row-link"
                                        >
                                            Open{' '}
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </div>
                                ),
                            )}
                            {availableVehicles.length === 0 && (
                                <p className="fo-empty">
                                    No vehicles match this scope. Clear the
                                    state filter or change the Site.
                                </p>
                            )}
                            <Pager
                                page={vehiclePage}
                                count={availableVehicles.length}
                                onPage={setVehiclePage}
                            />
                        </section>
                    )}

                    <Dialog open={chartOpen} onOpenChange={setChartOpen}>
                        <DialogContent className="fleet-overview fo-dialog sm:max-w-3xl">
                            <DialogHeader>
                                <DialogTitle>Booking load</DialogTitle>
                                <DialogDescription>
                                    Confirmed reservation hours for your
                                    selected vehicles.
                                </DialogDescription>
                            </DialogHeader>
                            <BookingLoad
                                hours={bookedHours}
                                site={filters.site}
                                today={today}
                                healthy={bookingsHealthy}
                                onDay={(day) => {
                                    setChartOpen(false);
                                    openBookedDay(day);
                                }}
                            />
                        </DialogContent>
                    </Dialog>
                    <Dialog open={dataOpen} onOpenChange={setDataOpen}>
                        <DialogContent className="fleet-overview fo-dialog sm:max-w-xl">
                            <DialogHeader>
                                <DialogTitle>Data status</DialogTitle>
                                <DialogDescription>
                                    Source records within your approved Site
                                    scope. Loaded data does not confirm safety,
                                    availability or receipt.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="fo-source-list">
                                {overview.sources.map((source) => {
                                    const count =
                                        source.state === 'loaded'
                                            ? sourceCount(
                                                  source.name,
                                                  source.count,
                                              )
                                            : null;
                                    return (
                                        <div key={source.name}>
                                            <span>
                                                <strong>{source.name}</strong>
                                                <small>
                                                    {source.description}
                                                </small>
                                            </span>
                                            <span
                                                className={`fo-status ${source.state === 'loaded' ? 'fo-good' : 'fo-unknown'}`}
                                            >
                                                {source.state === 'no_access'
                                                    ? 'No access'
                                                    : source.state ===
                                                        'unavailable'
                                                      ? 'Unavailable'
                                                      : count === 0
                                                        ? 'No records'
                                                        : `${count} observed`}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                            <p className="fo-note">
                                Counts use{' '}
                                {filters.site === 'all'
                                    ? 'all approved Sites'
                                    : siteName}{' '}
                                before text search, except receipt counts which
                                follow the selected Site and search. Tracker
                                positions are subject to consent and
                                personal-trip privacy. Registered Site pins are
                                approximate.
                            </p>
                            {refreshMessage && (
                                <p role="status" className="fo-note">
                                    {refreshMessage}
                                </p>
                            )}
                            <Button
                                variant="outline"
                                disabled={refreshing}
                                onClick={refresh}
                            >
                                <RefreshCw
                                    className={`size-4 ${refreshing ? 'animate-spin' : ''}`}
                                />{' '}
                                Refresh sources
                            </Button>
                        </DialogContent>
                    </Dialog>
                    <Dialog
                        open={saveOpen}
                        onOpenChange={(open) => {
                            setSaveOpen(open);
                            if (!open) setRenameFrom(null);
                        }}
                    >
                        <DialogContent className="fleet-overview fo-dialog sm:max-w-sm">
                            <DialogHeader>
                                <DialogTitle>
                                    {renameFrom
                                        ? 'Rename saved view'
                                        : 'Save current view'}
                                </DialogTitle>
                                <DialogDescription>
                                    Keep these filters in your account across
                                    devices. Up to six views.
                                </DialogDescription>
                            </DialogHeader>
                            <label className="fo-save-label">
                                View name
                                <input
                                    className="fo-input"
                                    maxLength={40}
                                    value={saveName}
                                    onChange={(event) => {
                                        setSaveName(event.target.value);
                                        setSaveError('');
                                    }}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') saveView();
                                    }}
                                />
                            </label>
                            {(saveError ||
                                viewMessage.startsWith('Could not')) && (
                                <p className="fo-error" role="alert">
                                    {saveError || viewMessage}
                                </p>
                            )}
                            <Button onClick={saveView} disabled={savingViews}>
                                {renameFrom ? 'Rename view' : 'Save view'}
                            </Button>
                        </DialogContent>
                    </Dialog>
                </div>
            </PageShell>
        </AppLayout>
    );
}
