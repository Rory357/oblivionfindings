import {
    plainLabel,
    severityBadge,
    statusBadge,
} from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { applyLiveResponseFilters } from '@/components/control-room/live-response/live-response-filters';
import {
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { PopupCallout } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import 'leaflet/dist/leaflet.css';
import {
    Activity,
    Bell,
    Building2,
    ExternalLink,
    MapPin,
    Radio,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

// ── Types ────────────────────────────────────────────────────────────────────

interface MapDevice {
    id: number;
    device_uid: string | null;
    name: string;
    type: string;
    type_label: string;
    status: string;
    health_status: string | null;
    latitude: number;
    longitude: number;
    battery_level: number | null;
    last_seen_at: string | null;
    position_source: string;
    context_label: string | null;
    manufacturer: string | null;
    model: string | null;
    site_id: number | null;
    identity_source: 'canonical';
    privacy_state: string | null;
    privacy_basis: string | null;
    detail_url: string;
}

interface MapSite {
    id: number;
    name: string;
    address: string;
    latitude: number;
    longitude: number;
}

interface GeofenceShape {
    lat?: number;
    lon?: number;
    radius_m?: number;
    center?: { lat?: number; lng?: number };
    points?: { lat: number; lng: number }[];
}

interface MapGeofence {
    id: number;
    name: string;
    type: string;
    shape: GeofenceShape;
    breach_type: string;
    site_id: number | null;
}

interface MapAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    device_id: number | null;
    site_id: number | null;
    latitude: number | null;
    longitude: number | null;
    detail_url: string;
}

interface SiteOption {
    id: number;
    name: string;
}

interface Stats {
    total_devices: number;
    online: number;
    offline: number;
    active_alerts: number;
    location_restricted: number;
}

interface LocationBoundary {
    position_access: boolean;
    title: string;
    description: string;
    canonical_url: string | null;
}

interface Props {
    devices: MapDevice[];
    sites: MapSite[];
    geofences: MapGeofence[];
    alerts: MapAlert[];
    all_sites: SiteOption[];
    stats: Stats;
    location_boundary: LocationBoundary;
    filters: Record<string, string | null>;
}

const BASE_PATH = '/control-room/map';

// ── Helpers ──────────────────────────────────────────────────────────────────

function isRecentlySeen(lastSeen: string | null, minutes: number = 5): boolean {
    if (!lastSeen) return false;
    const diff = Date.now() - new Date(lastSeen).getTime();
    return diff < minutes * 60 * 1000;
}

export function escapeMapHtml(value: unknown): string {
    return String(value ?? '').replace(/[&<>"']/g, (character) => {
        const entities: Record<string, string> = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        };

        return entities[character];
    });
}

export function mapDeviceActivityLabel(device: {
    status: string;
    last_seen_at: string | null;
}): string {
    if (device.status === 'offline') return 'Offline';
    if (device.status === 'unknown') return 'Status unknown';

    return isRecentlySeen(device.last_seen_at, 5)
        ? 'Recently updated'
        : 'Reporting';
}

const ACTIVITY_TONE: Record<string, StatusVariant> = {
    Offline: 'critical',
    'Status unknown': 'neutral',
    'Recently updated': 'info',
    Reporting: 'success',
};

/**
 * One colour per marker meaning, all from tokens so Settings › Branding and
 * dark mode follow (MAP_GEOFENCING_STYLE_GUIDE.md: greyscale tiles, coloured
 * overlays). Offline wins over the tracker type, as the legend says.
 */
function deviceTone(device: MapDevice): { css: string; dot: string } {
    if (device.status === 'offline')
        return { css: 'var(--status-critical)', dot: 'bg-status-critical' };
    if (device.type === 'personal_tracker')
        return { css: 'var(--category-hr)', dot: 'bg-category-hr' };
    if (device.type === 'asset_tracker')
        return { css: 'var(--category-fleet)', dot: 'bg-category-fleet' };
    if (device.status === 'unknown')
        return { css: 'var(--muted-foreground)', dot: 'bg-muted-foreground' };
    if (isRecentlySeen(device.last_seen_at, 5))
        return { css: 'var(--status-info)', dot: 'bg-status-info' };
    return { css: 'var(--status-success)', dot: 'bg-status-success' };
}

const LEGEND: ReadonlyArray<{ label: string; swatch: string }> = [
    { label: 'Fleet tracker — reporting', swatch: 'rounded-full bg-status-success' },
    { label: 'Fleet tracker — recently updated', swatch: 'rounded-full bg-status-info' },
    { label: 'Fleet tracker — status unknown', swatch: 'rounded-full bg-muted-foreground' },
    { label: 'Tracker — offline', swatch: 'rounded-full bg-status-critical' },
    { label: 'Personal safety tracker', swatch: 'rounded-full bg-category-hr' },
    { label: 'Asset tracker', swatch: 'rounded-full bg-category-fleet' },
    { label: 'Site', swatch: 'rounded-full bg-category-sites' },
    {
        label: 'Active alert',
        swatch: 'rounded-full bg-status-critical ring-2 ring-status-critical ring-offset-1 ring-offset-card',
    },
    { label: 'Geofence', swatch: 'w-5 rounded-sm border-2 border-dashed border-primary bg-primary/10' },
];

/** Leaflet's popup frame follows the theme (it is white by default). */
const POPUP_CLASS =
    '[&_.leaflet-popup-content-wrapper]:bg-popover [&_.leaflet-popup-content-wrapper]:text-popover-foreground [&_.leaflet-popup-tip]:bg-popover';

const MARKER_SHADOW =
    'box-shadow: 0 1px 4px color-mix(in oklab, var(--foreground) 35%, transparent)';

function popupLink(href: string, label: string) {
    return `<a href="${escapeMapHtml(href)}" data-map-visit class="mt-1.5 inline-block font-semibold text-primary underline-offset-2 hover:underline">${escapeMapHtml(label)}</a>`;
}

function popupLine(text: string) {
    return `<span class="block text-muted-foreground">${escapeMapHtml(text)}</span>`;
}

function queryString(params: Record<string, string | null | undefined>) {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value) search.set(key, value);
    }
    const value = search.toString();
    return value ? `?${value}` : '';
}

// ── Component ────────────────────────────────────────────────────────────────

/**
 * Monitoring › Map — the latest governed tracker positions, sites and
 * geofences on one map. Positions come from Security & Devices Tracking under
 * the viewer's Site, source, purpose and consent rules; a last report is not
 * proof of where someone is now.
 */
export default function ControlRoomMap({
    devices,
    sites,
    geofences,
    alerts,
    all_sites,
    stats,
    location_boundary,
    filters,
}: Props) {
    const mapRef = useRef<HTMLDivElement>(null);
    const mapInstanceRef = useRef<L.Map | null>(null);
    const layerGroupRef = useRef<L.LayerGroup | null>(null);
    const markersRef = useRef(new Map<number, L.Marker>());
    const fittedRef = useRef<string | null>(null);
    const [isMapReady, setIsMapReady] = useState(false);
    const [lastRefreshed, setLastRefreshed] = useState<Date>(() => new Date());
    const [refreshing, setRefreshing] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const siteNames = useMemo(
        () => new Map(all_sites.map((site) => [site.id, site.name])),
        [all_sites],
    );
    const siteName = useCallback(
        (id: number | null) => (id ? (siteNames.get(id) ?? null) : null),
        [siteNames],
    );

    // ── Filters ──────────────────────────────────────────────────────────────

    const apply = useCallback(
        (next: Record<string, string | undefined>) =>
            applyLiveResponseFilters(BASE_PATH, { ...filters, ...next }),
        [filters],
    );
    const fromAll = (value: string) => (value === 'all' ? undefined : value);

    const refresh = useCallback(() => {
        setRefreshing(true);
        router.reload({
            only: ['devices', 'alerts', 'stats'],
            onSuccess: () => setLastRefreshed(new Date()),
            onFinish: () => setRefreshing(false),
        });
    }, []);

    // ── Map initialisation ───────────────────────────────────────────────────

    useEffect(() => {
        let mounted = true;
        let resize: ResizeObserver | null = null;

        async function initMap() {
            const leaflet = await import('leaflet');
            const L = (leaflet as unknown as { default?: typeof leaflet })
                .default ?? leaflet;

            if (!mounted || !mapRef.current || mapInstanceRef.current) return;

            const map = L.map(mapRef.current, {
                center: [-41.2865, 174.7762],
                zoom: 6,
                zoomControl: true,
                attributionControl: true,
            });

            const dark = document.documentElement.classList.contains('dark');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19,
                className: dark ? 'leaflet-tiles-dark' : undefined,
            }).addTo(map);

            const layerGroup = L.layerGroup().addTo(map);

            mapInstanceRef.current = map;
            layerGroupRef.current = layerGroup;
            if (typeof ResizeObserver !== 'undefined') {
                resize = new ResizeObserver(() => map.invalidateSize());
                resize.observe(mapRef.current);
            }

            if (mounted) setIsMapReady(true);
        }

        initMap();

        return () => {
            mounted = false;
            resize?.disconnect();
            if (mapInstanceRef.current) {
                mapInstanceRef.current.remove();
                mapInstanceRef.current = null;
                layerGroupRef.current = null;
                setIsMapReady(false);
            }
        };
    }, []);

    // Links inside Leaflet popups are plain HTML; open them as page visits.
    useEffect(() => {
        const element = mapRef.current;
        if (!element) return;
        const onClick = (event: MouseEvent) => {
            const link = (event.target as HTMLElement | null)?.closest?.(
                'a[data-map-visit]',
            );
            if (!(link instanceof HTMLAnchorElement)) return;
            if (event.metaKey || event.ctrlKey || event.shiftKey) return;
            event.preventDefault();
            router.visit(link.getAttribute('href') ?? '');
        };
        element.addEventListener('click', onClick);
        return () => element.removeEventListener('click', onClick);
    }, []);

    // ── Render overlays ──────────────────────────────────────────────────────

    useEffect(() => {
        if (!isMapReady || !mapInstanceRef.current || !layerGroupRef.current)
            return;

        async function renderMarkers() {
            const leaflet = await import('leaflet');
            const L = (leaflet as unknown as { default?: typeof leaflet })
                .default ?? leaflet;
            const map = mapInstanceRef.current;
            const layers = layerGroupRef.current;
            if (!map || !layers) return;

            layers.clearLayers();
            markersRef.current.clear();

            const bounds: L.LatLngExpression[] = [];
            const fenceStyle = {
                color: 'var(--primary)',
                fillColor: 'var(--primary)',
                fillOpacity: 0.08,
                weight: 1.5,
                dashArray: '5, 5',
            };

            // ── Geofence overlays ────────────────────────────────────────────

            geofences.forEach((gf) => {
                if (!gf.shape) return;

                const circleLatitude = gf.shape.lat ?? gf.shape.center?.lat;
                const circleLongitude = gf.shape.lon ?? gf.shape.center?.lng;
                const popup =
                    `<div class="text-sm"><strong class="font-semibold">${escapeMapHtml(gf.name)}</strong>` +
                    popupLine(`Alerts when: ${plainLabel(gf.breach_type)}`);

                if (
                    gf.type === 'circle' &&
                    circleLatitude !== undefined &&
                    circleLongitude !== undefined &&
                    gf.shape.radius_m
                ) {
                    L.circle([circleLatitude, circleLongitude], {
                        ...fenceStyle,
                        radius: gf.shape.radius_m,
                    })
                        .bindPopup(
                            popup + popupLine(`Radius ${gf.shape.radius_m} m`) + '</div>',
                            { className: POPUP_CLASS },
                        )
                        .addTo(layers);
                } else if (
                    gf.type === 'polygon' &&
                    gf.shape.points &&
                    gf.shape.points.length > 2
                ) {
                    const points: L.LatLngExpression[] = gf.shape.points.map(
                        (p) => [p.lat, p.lng],
                    );
                    L.polygon(points, fenceStyle)
                        .bindPopup(popup + '</div>', { className: POPUP_CLASS })
                        .addTo(layers);
                }
            });

            // ── Site markers ─────────────────────────────────────────────────

            sites.forEach((site) => {
                const siteIcon = L.divIcon({
                    className: '',
                    html: `<div style="width:28px;height:28px;border-radius:50%;background:var(--category-sites);border:3px solid var(--card);${MARKER_SHADOW};display:flex;align-items:center;justify-content:center;color:var(--card)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>`,
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                    popupAnchor: [0, -16],
                });

                L.marker([site.latitude, site.longitude], {
                    icon: siteIcon,
                    title: site.name,
                })
                    .bindPopup(
                        `<div class="text-sm"><strong class="font-semibold">${escapeMapHtml(site.name)}</strong>${site.address ? popupLine(site.address) : ''}</div>`,
                        { className: POPUP_CLASS },
                    )
                    .addTo(layers);

                bounds.push([site.latitude, site.longitude]);
            });

            // ── Tracker markers ──────────────────────────────────────────────

            devices.forEach((device) => {
                const tone = deviceTone(device);
                const label = mapDeviceActivityLabel(device);

                const deviceIcon = L.divIcon({
                    className: '',
                    html: `<div style="width:22px;height:22px;border-radius:50%;background:${tone.css};border:2.5px solid var(--card);${MARKER_SHADOW}"></div>`,
                    iconSize: [22, 22],
                    iconAnchor: [11, 11],
                    popupAnchor: [0, -14],
                });

                const deviceName = device.name || device.device_uid || 'Tracker';
                const site = siteName(device.site_id);
                const popupContent =
                    `<div class="min-w-[190px] text-sm">` +
                    `<strong class="font-semibold">${escapeMapHtml(device.context_label || deviceName)}</strong>` +
                    (site ? popupLine(site) : '') +
                    popupLine(`${label} · ${device.type_label}`) +
                    (device.last_seen_at
                        ? popupLine(`Last reported ${formatRelative(device.last_seen_at)}`)
                        : '') +
                    (device.battery_level !== null
                        ? popupLine(`Battery ${device.battery_level}%`)
                        : '') +
                    (device.context_label ? popupLine(deviceName) : '') +
                    (device.manufacturer
                        ? popupLine(
                              `${device.manufacturer}${device.model ? ` ${device.model}` : ''}`,
                          )
                        : '') +
                    popupLink(device.detail_url, 'Open device record') +
                    `</div>`;

                const marker = L.marker([device.latitude, device.longitude], {
                    icon: deviceIcon,
                    title: device.name || device.device_uid || 'Tracker',
                })
                    .bindPopup(popupContent, { className: POPUP_CLASS })
                    .on('click', () => setSelectedId(device.id))
                    .addTo(layers);
                markersRef.current.set(device.id, marker);

                bounds.push([device.latitude, device.longitude]);
            });

            // ── Alert markers (pulsing ring) ─────────────────────────────────

            alerts
                .filter((a) => a.latitude !== null && a.longitude !== null)
                .forEach((alert) => {
                    const alertIcon = L.divIcon({
                        className: '',
                        html:
                            `<div style="position:relative;width:22px;height:22px">` +
                            `<span class="absolute inset-0 rounded-full bg-status-critical opacity-50 animate-ping motion-reduce:animate-none"></span>` +
                            `<span style="position:absolute;inset:4px;border-radius:50%;background:var(--status-critical);box-shadow:0 0 0 2px var(--card),0 0 0 4px var(--status-critical)"></span>` +
                            `</div>`,
                        iconSize: [22, 22],
                        iconAnchor: [11, 11],
                        popupAnchor: [0, -12],
                    });

                    const popupContent =
                        `<div class="min-w-[190px] text-sm">` +
                        `<strong class="font-semibold text-status-critical">${escapeMapHtml(plainLabel(alert.alert_type))}</strong>` +
                        popupLine(
                            `${severityBadge(alert.severity).label} · ${statusBadge(alert.status).label}`,
                        ) +
                        popupLine(`Raised ${formatRelative(alert.triggered_at)}`) +
                        popupLink(alert.detail_url, 'Open alert') +
                        `</div>`;

                    L.marker([alert.latitude!, alert.longitude!], {
                        icon: alertIcon,
                        title: plainLabel(alert.alert_type),
                    })
                        .bindPopup(popupContent, { className: POPUP_CLASS })
                        .addTo(layers);

                    bounds.push([alert.latitude!, alert.longitude!]);
                });

            // ── Fit the view once per filter set; a refresh never moves it ──

            const fitKey = JSON.stringify(filters);
            if (fittedRef.current === fitKey || bounds.length === 0) return;
            fittedRef.current = fitKey;
            if (bounds.length > 1) {
                map.fitBounds(L.latLngBounds(bounds), {
                    padding: [40, 40],
                    maxZoom: 14,
                });
            } else if (bounds.length === 1) {
                map.setView(bounds[0] as L.LatLngExpression, 13);
            }
        }

        renderMarkers();
    }, [isMapReady, devices, sites, geofences, alerts, filters, siteName]);

    // ── Auto-refresh every 30 seconds ────────────────────────────────────────

    useEffect(() => {
        const interval = setInterval(() => {
            if (document.hidden) return;
            router.reload({
                only: ['devices', 'alerts', 'stats'],
                onSuccess: () => setLastRefreshed(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, []);

    const focusDevice = (device: MapDevice) => {
        setSelectedId(device.id);
        mapInstanceRef.current?.setView([device.latitude, device.longitude], 16);
        markersRef.current.get(device.id)?.openPopup();
    };

    const alertFor = (device: MapDevice) =>
        alerts.find((alert) => alert.device_id === device.id) ?? null;

    const siteQuery = { site_id: filters.site_id };
    const updated = `Updated ${formatTime(lastRefreshed)}`;
    const restricted = stats.location_restricted;

    return (
        <ControlRoomPage
            header={{
                subline: (
                    <>
                        Reported tracker positions, sites and geofences ·{' '}
                        <span aria-live="polite">
                            {refreshing ? 'Refreshing…' : updated}
                        </span>
                    </>
                ),
                actions: (
                    <>
                        {location_boundary.canonical_url ? (
                            <PageHeaderGlassButton icon={ShieldCheck} asChild>
                                <Link href={location_boundary.canonical_url}>
                                    Open Tracking
                                </Link>
                            </PageHeaderGlassButton>
                        ) : null}
                        <PageHeaderGlassButton
                            icon={RefreshCw}
                            onClick={refresh}
                            disabled={refreshing}
                        >
                            Refresh
                        </PageHeaderGlassButton>
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Positions available"
                            href={`${BASE_PATH}${queryString(siteQuery)}`}
                            preserveState
                            preserveScroll
                            ariaLabel="Show every position you can see"
                        >
                            <PageHeaderMeterBig>{stats.total_devices}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                governed tracker positions
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Reporting"
                            tone={stats.online > 0 ? 'success' : 'brand'}
                            href={`${BASE_PATH}${queryString({ ...siteQuery, status: 'online' })}`}
                            preserveState
                            preserveScroll
                            ariaLabel="Show trackers that are reporting"
                        >
                            <PageHeaderMeterBig>{stats.online}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>trackers online</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Offline"
                            tone={stats.offline > 0 ? 'warning' : 'brand'}
                            href={`${BASE_PATH}${queryString({ ...siteQuery, status: 'offline' })}`}
                            preserveState
                            preserveScroll
                            ariaLabel="Show trackers that are offline"
                        >
                            <PageHeaderMeterBig>{stats.offline}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>last position only</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Active alerts"
                            tone={stats.active_alerts > 0 ? 'critical' : 'brand'}
                            href={`/control-room${queryString(siteQuery)}`}
                            ariaLabel="Open the active alerts in Live response"
                        >
                            <PageHeaderMeterBig>{stats.active_alerts}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>open in Live response</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <>
                        {all_sites.length > 1 || filters.site_id ? (
                            <PageHeaderFilterSelect
                                icon={Building2}
                                label="All sites"
                                value={filters.site_id ?? 'all'}
                                onChange={(value) => apply({ site_id: fromAll(value) })}
                                options={[
                                    { value: 'all', label: 'All sites' },
                                    ...all_sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    })),
                                ]}
                            />
                        ) : null}
                        <PageHeaderFilterSelect
                            icon={Radio}
                            label="All trackers"
                            value={filters.type ?? 'all'}
                            onChange={(value) => apply({ type: fromAll(value) })}
                            options={[
                                { value: 'all', label: 'All trackers' },
                                { value: 'vehicle_tracker', label: 'Fleet tracker' },
                                { value: 'personal_tracker', label: 'Personal safety tracker' },
                                { value: 'asset_tracker', label: 'Asset tracker' },
                            ]}
                        />
                        <PageHeaderFilterSelect
                            icon={Activity}
                            label="All statuses"
                            value={filters.status ?? 'all'}
                            onChange={(value) => apply({ status: fromAll(value) })}
                            options={[
                                { value: 'all', label: 'All statuses' },
                                { value: 'online', label: 'Reporting' },
                                { value: 'offline', label: 'Offline' },
                                { value: 'unknown', label: 'Status unknown' },
                            ]}
                        />
                        <PageHeaderFilterCheck
                            label="With an active alert"
                            checked={filters.alert_only === '1'}
                            onChange={(on) => apply({ alert_only: on ? '1' : undefined })}
                        />
                    </>
                ),
            }}
        >
            <PopupCallout tone={location_boundary.position_access ? 'info' : 'warning'}>
                <p className="font-semibold">{location_boundary.title}</p>
                <p className="mt-0.5">{location_boundary.description}</p>
                <p className="mt-1">
                    A last reported position is not a confirmed current location.
                    {restricted > 0
                        ? ` ${restricted} ${restricted === 1 ? 'tracker has' : 'trackers have'} no position shown here because their location rules don't allow it.`
                        : ''}
                </p>
            </PopupCallout>

            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(320px,0.4fr)_minmax(0,1fr)]">
                <Card className="min-w-0 gap-0 overflow-hidden py-0">
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div className="min-w-0">
                            <h2 className="text-section-title">Reported positions</h2>
                            <p className="text-subtle mt-0.5">
                                Choose a tracker to find it on the map.
                            </p>
                        </div>
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {devices.length} {devices.length === 1 ? 'position' : 'positions'}
                        </StatusBadge>
                    </div>
                    {devices.length === 0 ? (
                        <div className="px-5 py-10">
                            <EmptyState
                                icon={MapPin}
                                title="No positions to show"
                                description="No tracker positions are available under the current Site, source-permission and privacy rules."
                            />
                        </div>
                    ) : (
                        <ol className="max-h-[680px] divide-y divide-border overflow-y-auto scrollbar-pretty">
                            {devices.map((device) => (
                                <PositionRow
                                    key={device.id}
                                    device={device}
                                    site={siteName(device.site_id)}
                                    alert={alertFor(device)}
                                    selected={device.id === selectedId}
                                    onFocus={() => focusDevice(device)}
                                />
                            ))}
                        </ol>
                    )}
                </Card>

                <Card className="min-w-0 gap-0 overflow-hidden py-0">
                    <div
                        ref={mapRef}
                        role="region"
                        aria-label="Live map of tracker positions, sites and geofences"
                        className="grayscale-map h-[560px] w-full xl:h-[680px]"
                        style={{ zIndex: 0 }}
                    />
                    <div className="grid gap-3 border-t border-border px-5 py-4">
                        <ul
                            aria-label="Map legend"
                            className="flex flex-wrap gap-x-4 gap-y-2 text-caption"
                        >
                            {LEGEND.map((item) => (
                                <li key={item.label} className="flex items-center gap-1.5">
                                    <span
                                        aria-hidden="true"
                                        className={cn('inline-block h-3 w-3 shrink-0', item.swatch)}
                                    />
                                    {item.label}
                                </li>
                            ))}
                        </ul>
                        <p className="text-caption">
                            Refreshes every 30 seconds · Last {formatTime(lastRefreshed)} ·{' '}
                            {devices.length} {devices.length === 1 ? 'position' : 'positions'},{' '}
                            {sites.length} {sites.length === 1 ? 'site' : 'sites'},{' '}
                            {alerts.length} {alerts.length === 1 ? 'alert' : 'alerts'}
                        </p>
                    </div>
                </Card>
            </div>
        </ControlRoomPage>
    );
}

function PositionRow({
    device,
    site,
    alert,
    selected,
    onFocus,
}: {
    device: MapDevice;
    site: string | null;
    alert: MapAlert | null;
    selected: boolean;
    onFocus: () => void;
}) {
    const label = mapDeviceActivityLabel(device);
    const deviceName = device.name || device.device_uid || 'Tracker';
    // Who or what it is about first; the tracker itself goes small.
    const name = device.context_label || deviceName;
    const items: ControlRoomRowAction[] = [
        { key: 'focus', label: 'Show on map', icon: MapPin, onSelect: onFocus },
        {
            key: 'device',
            label: 'Open device record',
            icon: ExternalLink,
            onSelect: () => router.visit(device.detail_url),
        },
    ];
    if (alert)
        items.push({
            key: 'alert',
            label: 'Open alert',
            icon: Bell,
            onSelect: () => router.visit(alert.detail_url),
        });

    return (
        <ControlRoomRowActions label={`Actions for ${name}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <li
                    onContextMenu={rowProps.onContextMenu}
                    className={cn(
                        'flex items-start gap-3 px-5 py-3 transition-colors',
                        selected
                            ? 'bg-primary-fill/10 shadow-[inset_3px_0_0_var(--primary)]'
                            : 'hover:bg-muted/40',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', deviceTone(device).dot)}
                    />
                    <Button
                        unstyled
                        type="button"
                        onClick={onFocus}
                        aria-current={selected ? 'true' : undefined}
                        className="min-w-0 flex-1 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="flex items-start justify-between gap-3">
                            <span className="min-w-0 font-semibold text-foreground">{name}</span>
                            {device.last_seen_at ? (
                                <span
                                    className="text-caption shrink-0 tabular-nums"
                                    title={formatDateTime(device.last_seen_at)}
                                >
                                    {formatRelative(device.last_seen_at)}
                                </span>
                            ) : null}
                        </span>
                        {site ? <span className="text-subtle block">{site}</span> : null}
                        <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <StatusBadge variant={ACTIVITY_TONE[label] ?? 'neutral'} size="sm">
                                {label}
                            </StatusBadge>
                            {alert ? (
                                <StatusBadge variant="critical" size="sm">
                                    Active alert
                                </StatusBadge>
                            ) : null}
                            {device.battery_level !== null && device.battery_level <= 20 ? (
                                <StatusBadge variant="warning" size="sm">
                                    Battery {device.battery_level}%
                                </StatusBadge>
                            ) : null}
                        </span>
                        <span className="text-caption mt-1 block">
                            {[device.context_label ? deviceName : null, device.type_label]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </Button>
                    <div className="shrink-0">{overflowButton}</div>
                </li>
            )}
        </ControlRoomRowActions>
    );
}
