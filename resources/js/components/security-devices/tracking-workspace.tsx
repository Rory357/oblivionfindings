import LeafletMap, {
    type MapGeofence,
    type MapMarker,
} from '@/components/leaflet-map';
import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BatteryLow,
    CarFront,
    ChevronRight,
    CircleHelp,
    Clock3,
    ExternalLink,
    History,
    MapPinned,
    PackageSearch,
    RadioTower,
    ShieldCheck,
    UserRound,
} from 'lucide-react';

type TrackingGroupKey = 'personal-safety' | 'fleet' | 'assets';

type TrackingAction = {
    key: string;
    label: string;
    count: number;
    description: string;
    href: string;
};

type TrackingGroup = {
    key: TrackingGroupKey;
    label: string;
    count: number;
    description: string;
    href: string;
};

type TrackingDevice = {
    id: number;
    name: string;
    category: string;
    subcategory: string | null;
    status: string | null;
    health: string | null;
    battery: number | null;
    lastSeenAt: string | null;
    deviceHref: string;
    group: TrackingGroupKey;
    person: {
        id: number;
        displayName: string;
        href: string | null;
    } | null;
    asset: {
        id: number;
        name: string;
        category: string | null;
        reference: string | null;
        href: string;
    } | null;
    personalSafety: {
        personType: 'client' | 'staff' | 'unassigned';
        purposeLabel: string | null;
        sessionStatus: string | null;
    } | null;
    privacy: {
        state: string;
        basis: string;
        locationAllowed: boolean;
        reason: string;
        expiresAt: string | null;
    };
    location: {
        latitude: number;
        longitude: number;
        observedAt: string | null;
        source: string;
    } | null;
    canonicalHref: string | null;
    historyHref: string | null;
};

type TrackingGeofence = {
    id: number;
    name: string;
    type: 'circle' | 'polygon';
    scope: string;
    active: boolean;
    shape: {
        center?: { lat: number; lng: number };
        radius_m?: number;
        coordinates?: { lat: number; lng: number }[];
    };
    subjectLabel: string;
    canonicalHref: string;
    privacy: {
        state: string;
        basis: string;
    };
};

type TrackingHistory = {
    id: number;
    eventType: string;
    occurredAt: string | null;
    deviceName: string;
    subjectLabel: string;
    group: TrackingGroupKey;
    latitude: number;
    longitude: number;
    battery: number | null;
    speed: number | null;
    canonicalHref: string | null;
};

export type TrackingWorkspaceData = {
    permissions: {
        personalSafety: boolean;
        fleet: boolean;
        assets: boolean;
        telemetry: boolean;
        geofences: boolean;
    };
    boundary: {
        title: string;
        description: string;
        retentionDays: number;
    };
    overview: {
        inventory: {
            total: number;
            personal_safety: number;
            fleet: number;
            assets: number;
        };
        attention: {
            offline: number;
            low_battery: number;
            consent_blocked: number;
            unassigned: number;
            stale: number;
        };
        groups: TrackingGroup[];
        requiredActions: TrackingAction[];
    };
    activeTab: {
        key: string;
        label: string;
        description: string;
        restricted: boolean;
        inventoryTotal: number;
        inventoryShown: number;
        inventoryTruncated: boolean;
        devices: TrackingDevice[];
        markers: MapMarker[];
        geofences: TrackingGeofence[];
        history: TrackingHistory[];
        retentionDays: number;
    };
};

function headline(value: string | null | undefined): string {
    if (!value) return 'Unknown';

    return value
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function plural(count: number, singular: string, pluralLabel?: string): string {
    return `${count} ${count === 1 ? singular : (pluralLabel ?? `${singular}s`)}`;
}

function PrivacyBadge({ privacy }: { privacy: TrackingDevice['privacy'] }) {
    const label =
        privacy.basis === 'active_client_tracking_consent'
            ? 'Consent active'
            : privacy.basis === 'active_lone_worker_session'
              ? 'Safety session active'
              : ['fleet_operations', 'asset_operations'].includes(privacy.basis)
                ? 'Operational access'
                : privacy.state === 'withdrawn'
                  ? 'Consent withdrawn'
                  : privacy.state === 'expired'
                    ? 'Consent expired'
                    : privacy.state === 'missing'
                      ? 'Consent missing'
                      : headline(privacy.state);

    return (
        <Badge
            variant={privacy.locationAllowed ? 'outline' : 'secondary'}
            className={
                privacy.locationAllowed
                    ? 'border-status-success/30 bg-status-success-bg text-status-success'
                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning'
            }
        >
            {label}
        </Badge>
    );
}

function Boundary({ data }: { data: TrackingWorkspaceData['boundary'] }) {
    return (
        <Card className="border-primary/20 bg-primary/5">
            <CardContent className="flex items-start gap-3 py-4">
                <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                <div>
                    <h2 className="font-semibold">{data.title}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {data.description}
                    </p>
                    <p className="mt-2 text-xs font-medium text-primary">
                        Location history is limited to the configured{' '}
                        {data.retentionDays}-day window.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

const groupIcon = {
    'personal-safety': UserRound,
    fleet: CarFront,
    assets: PackageSearch,
};

function Overview({ data }: { data: TrackingWorkspaceData }) {
    const { inventory } = data.overview;

    return (
        <div className="space-y-5">
            <Card>
                <CardContent className="space-y-4 py-5">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Tracking at a glance
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            One technical register, separated by the purpose and
                            module that owns each tracked subject.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Summary
                            label="tracking devices"
                            value={inventory.total}
                        />
                        <Summary
                            label="personal safety"
                            value={inventory.personal_safety}
                        />
                        <Summary label="Fleet" value={inventory.fleet} />
                        <Summary
                            label="asset tracking"
                            value={inventory.assets}
                        />
                    </div>
                </CardContent>
            </Card>

            <section
                aria-labelledby="tracking-purpose-groups"
                className="space-y-3"
            >
                <div>
                    <h2
                        id="tracking-purpose-groups"
                        className="text-lg font-semibold"
                    >
                        Open by purpose
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Each view keeps its canonical Client, H&S, Fleet, or
                        Asset workflow one click away.
                    </p>
                </div>
                <div className="grid gap-3 lg:grid-cols-3">
                    {data.overview.groups.map((group) => {
                        const Icon = groupIcon[group.key];

                        return (
                            <Link
                                key={group.key}
                                href={group.href}
                                className="group rounded-xl border border-border bg-card p-4 shadow-sm transition hover:border-primary/40 hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-start gap-3">
                                        <span className="rounded-lg bg-primary/10 p-2 text-primary">
                                            <Icon className="h-5 w-5" />
                                        </span>
                                        <div>
                                            <h3 className="font-semibold">
                                                {group.label} ({group.count})
                                            </h3>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {group.description}
                                            </p>
                                        </div>
                                    </div>
                                    <ChevronRight className="h-5 w-5 shrink-0 text-muted-foreground group-hover:text-primary" />
                                </div>
                            </Link>
                        );
                    })}
                </div>
            </section>

            {data.overview.requiredActions.length > 0 ? (
                <section
                    aria-labelledby="tracking-actions"
                    className="space-y-3"
                >
                    <div>
                        <h2
                            id="tracking-actions"
                            className="text-lg font-semibold"
                        >
                            What needs action
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Counts never treat missing purpose or consent as
                            permission to show location.
                        </p>
                    </div>
                    <div className="grid gap-3 md:grid-cols-2">
                        {data.overview.requiredActions.map((action) => (
                            <Link
                                key={action.key}
                                href={action.href}
                                className="rounded-xl border border-border bg-card p-4 shadow-sm transition hover:border-primary/40 hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <p className="font-semibold">
                                    {action.label}{' '}
                                    <span className="text-primary">
                                        ({action.count})
                                    </span>
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {action.description}
                                </p>
                            </Link>
                        ))}
                    </div>
                </section>
            ) : null}
        </div>
    );
}

function Summary({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-border/70 bg-muted/20 p-3">
            <p className="text-lg font-bold">
                {value} {label}
            </p>
        </div>
    );
}

function canonicalActionLabel(device: TrackingDevice): string {
    if (device.group === 'fleet') return 'Open vehicle in Fleet';
    if (device.group === 'assets') return 'Open asset record';
    if (device.personalSafety?.personType === 'staff') {
        return 'Open lone-worker safety';
    }

    return 'Open client location';
}

function DeviceCard({ device }: { device: TrackingDevice }) {
    return (
        <article
            aria-label={device.name}
            className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={device.deviceHref}
                        className="font-semibold hover:text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {device.name}
                    </Link>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {headline(device.subcategory ?? device.category)}
                    </p>
                </div>
                <OperationalStateBadge
                    state={device.health ?? device.status ?? 'unknown'}
                />
            </div>

            <div className="space-y-2 text-sm">
                {device.person ? (
                    <div className="flex items-center gap-2">
                        <UserRound className="h-4 w-4 text-muted-foreground" />
                        {device.person.href ? (
                            <Link
                                href={device.person.href}
                                className="font-medium text-primary hover:underline"
                            >
                                {device.person.displayName}
                            </Link>
                        ) : (
                            <span className="font-medium">
                                {device.person.displayName}
                            </span>
                        )}
                        <span className="text-muted-foreground">
                            • {headline(device.personalSafety?.personType)}
                        </span>
                    </div>
                ) : null}
                {device.asset ? (
                    <div className="flex items-center gap-2">
                        {device.group === 'fleet' ? (
                            <CarFront className="h-4 w-4 text-muted-foreground" />
                        ) : (
                            <PackageSearch className="h-4 w-4 text-muted-foreground" />
                        )}
                        <Link
                            href={device.asset.href}
                            className="font-medium text-primary hover:underline"
                        >
                            {device.asset.name}
                        </Link>
                        {device.asset.reference ? (
                            <span className="text-muted-foreground">
                                • {device.asset.reference}
                            </span>
                        ) : null}
                    </div>
                ) : null}
                {!device.person && !device.asset ? (
                    <p className="text-status-warning">
                        Not assigned to a canonical subject
                    </p>
                ) : null}
            </div>

            <div className="rounded-lg border border-border bg-muted/20 p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <PrivacyBadge privacy={device.privacy} />
                    {device.personalSafety?.purposeLabel ? (
                        <span className="text-sm font-medium">
                            {device.personalSafety.purposeLabel}
                        </span>
                    ) : null}
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                    {device.privacy.reason}
                </p>
                {device.privacy.expiresAt ? (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Purpose ends or consent expires{' '}
                        {formatDateTime(device.privacy.expiresAt)}
                    </p>
                ) : null}
            </div>

            <div className="grid gap-2 sm:grid-cols-3">
                <Fact
                    icon={RadioTower}
                    label="Last check-in"
                    value={
                        device.lastSeenAt
                            ? formatRelative(device.lastSeenAt)
                            : 'Not observed'
                    }
                />
                <Fact
                    icon={BatteryLow}
                    label="Battery"
                    value={
                        device.battery === null
                            ? 'Not reported'
                            : `${device.battery}%`
                    }
                />
                {device.privacy.locationAllowed ? (
                    <Fact
                        icon={MapPinned}
                        label="Last location"
                        value={
                            device.location
                                ? device.location.observedAt
                                    ? formatRelative(device.location.observedAt)
                                    : 'Available'
                                : 'Not reported'
                        }
                        muted={!device.location}
                    />
                ) : null}
            </div>

            {device.canonicalHref ? (
                <Link
                    href={device.canonicalHref}
                    className="inline-flex min-h-10 items-center gap-2 rounded-md px-2 text-sm font-medium text-primary hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <ExternalLink className="h-4 w-4" />
                    {canonicalActionLabel(device)}
                </Link>
            ) : null}
        </article>
    );
}

function Fact({
    icon: Icon,
    label,
    value,
    muted = false,
}: {
    icon: typeof RadioTower;
    label: string;
    value: string;
    muted?: boolean;
}) {
    return (
        <div className="rounded-lg border border-border/70 bg-muted/20 p-2.5">
            <div className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                <Icon className="h-3.5 w-3.5" /> {label}
            </div>
            <p
                className={`mt-1 text-sm font-semibold ${muted ? 'text-muted-foreground' : ''}`}
            >
                {value}
            </p>
        </div>
    );
}

function DeviceGrid({ devices }: { devices: TrackingDevice[] }) {
    if (devices.length === 0) {
        return (
            <Card>
                <CardContent className="flex items-start gap-3 py-6">
                    <CircleHelp className="h-5 w-5 text-muted-foreground" />
                    <div>
                        <p className="font-medium">
                            No authorised trackers in this view
                        </p>
                        <p className="text-sm text-muted-foreground">
                            A tracker appears only when its canonical
                            destination record and purpose boundary are visible
                            to you.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            {devices.map((device) => (
                <DeviceCard key={device.id} device={device} />
            ))}
        </div>
    );
}

function mapCenter(
    markers: MapMarker[],
    geofences: MapGeofence[],
): { lat: number; lng: number } {
    if (markers[0]) return { lat: markers[0].lat, lng: markers[0].lng };
    if (geofences[0]?.center) return geofences[0].center;
    if (geofences[0]?.coordinates?.[0]) return geofences[0].coordinates[0];

    return { lat: -41.2866, lng: 174.7756 };
}

function MapPanel({
    markers,
    geofences = [],
    title = 'Authorised last-known locations',
}: {
    markers: MapMarker[];
    geofences?: MapGeofence[];
    title?: string;
}) {
    if (markers.length === 0 && geofences.length === 0) return null;

    return (
        <section aria-labelledby="tracking-map-heading" className="space-y-3">
            <div>
                <h2 id="tracking-map-heading" className="text-lg font-semibold">
                    {title}
                </h2>
                <p className="text-sm text-muted-foreground">
                    The shared map receives only rows that passed the current
                    source permission, purpose, consent, and retention checks.
                </p>
            </div>
            <Card className="gap-0 overflow-hidden py-0">
                <CardContent className="p-2">
                    <LeafletMap
                        center={mapCenter(markers, geofences)}
                        markers={markers}
                        geofences={geofences}
                        height={360}
                        clustering
                    />
                </CardContent>
            </Card>
        </section>
    );
}

function Geofences({ rows }: { rows: TrackingGeofence[] }) {
    if (rows.length === 0) {
        return (
            <Card>
                <CardContent className="flex items-start gap-3 py-6">
                    <MapPinned className="h-5 w-5 text-muted-foreground" />
                    <div>
                        <p className="font-medium">No authorised geofences</p>
                        <p className="text-sm text-muted-foreground">
                            Resident zones disappear when tracking consent is
                            inactive; Fleet and Asset zones follow their source
                            permissions.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    const mapRows: MapGeofence[] = rows.map((row) => ({
        id: row.id,
        name: row.name,
        type: row.type,
        center: row.shape.center,
        radius_m: row.shape.radius_m,
        coordinates: row.shape.coordinates,
    }));

    return (
        <div className="space-y-4">
            <MapPanel
                markers={[]}
                geofences={mapRows}
                title="Authorised geofence coverage"
            />
            <div className="grid gap-3 lg:grid-cols-2">
                {rows.map((row) => (
                    <article
                        key={row.id}
                        className="rounded-xl border border-border bg-card p-4"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="font-semibold">{row.name}</h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {row.subjectLabel}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {headline(row.scope)} scope
                                </p>
                            </div>
                            <Badge
                                variant={row.active ? 'outline' : 'secondary'}
                            >
                                {row.active ? 'Active' : 'Inactive'}
                            </Badge>
                        </div>
                        <div className="mt-3 flex items-center justify-between gap-3 text-xs text-muted-foreground">
                            <span>{headline(row.privacy.basis)}</span>
                            <Link
                                href={row.canonicalHref}
                                className="font-medium text-primary hover:underline"
                            >
                                Open Fleet geofences
                            </Link>
                        </div>
                    </article>
                ))}
            </div>
        </div>
    );
}

function HistoryPanel({
    rows,
    retentionDays,
    markers,
}: {
    rows: TrackingHistory[];
    retentionDays: number;
    markers: MapMarker[];
}) {
    return (
        <section
            aria-labelledby="tracking-history-heading"
            className="space-y-4"
        >
            <div>
                <h2
                    id="tracking-history-heading"
                    className="text-lg font-semibold"
                >
                    Retained location history
                </h2>
                <p className="text-sm text-muted-foreground">
                    <span>{retentionDays}-day retention window</span> •
                    privacy-blocked and raw provider envelopes are excluded.
                </p>
            </div>
            <MapPanel markers={markers} title="Retained authorised locations" />
            {rows.length > 0 ? (
                <div className="space-y-2">
                    {rows.map((row) => (
                        <article
                            key={row.id}
                            className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div className="flex items-start gap-3">
                                <span className="rounded-lg bg-muted p-2 text-muted-foreground">
                                    <History className="h-4 w-4" />
                                </span>
                                <div>
                                    <h3 className="font-semibold">
                                        {headline(row.eventType)}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {row.subjectLabel} • {row.deviceName}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {row.occurredAt
                                            ? formatDateTime(row.occurredAt)
                                            : 'Time not reported'}
                                        {row.battery !== null
                                            ? ` • ${row.battery}% battery`
                                            : ''}
                                        {row.speed !== null
                                            ? ` • ${row.speed} km/h`
                                            : ''}
                                    </p>
                                </div>
                            </div>
                            {row.canonicalHref ? (
                                <Link
                                    href={row.canonicalHref}
                                    className="inline-flex min-h-10 items-center gap-2 rounded-md px-2 text-sm font-medium text-primary hover:bg-primary/5"
                                >
                                    Open source record
                                    <ChevronRight className="h-4 w-4" />
                                </Link>
                            ) : null}
                        </article>
                    ))}
                </div>
            ) : (
                <Card>
                    <CardContent className="flex items-start gap-3 py-6">
                        <Clock3 className="h-5 w-5 text-muted-foreground" />
                        <div>
                            <p className="font-medium">
                                No retained authorised history
                            </p>
                            <p className="text-sm text-muted-foreground">
                                No current row passed source permission,
                                purpose, consent, and retention checks.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}
        </section>
    );
}

function Restricted({ tab }: { tab: string }) {
    const message =
        tab === 'fleet'
            ? 'Fleet permission is required before vehicle tracker context can be shown.'
            : tab === 'assets'
              ? 'Asset permission is required before asset tracker context can be shown.'
              : tab === 'history'
                ? 'Asset telemetry and source-module permission are required before retained location history can be shown.'
                : tab === 'geofences'
                  ? 'Fleet or full Asset permission is required before geofence definitions can be shown.'
                  : 'Client, lone-worker, Fleet, or Asset permission is required before personal-safety tracker context can be shown.';

    return (
        <Card>
            <CardContent className="flex items-start gap-3 py-6">
                <AlertTriangle className="h-5 w-5 text-status-warning" />
                <div>
                    <h2 className="font-semibold">Restricted workspace</h2>
                    <p className="text-sm text-muted-foreground">{message}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function TrackingWorkspacePanels({
    data,
}: {
    data: TrackingWorkspaceData;
}) {
    const tab = data.activeTab.key;
    const deviceTab = [
        'overview',
        'personal-safety',
        'fleet',
        'assets',
    ].includes(tab);

    return (
        <div className="space-y-5">
            <Boundary data={data.boundary} />

            {data.activeTab.restricted ? (
                <Restricted tab={tab} />
            ) : (
                <>
                    {tab === 'overview' ? <Overview data={data} /> : null}
                    {deviceTab ? (
                        <>
                            {tab !== 'overview' ? (
                                <MapPanel markers={data.activeTab.markers} />
                            ) : null}
                            <section
                                aria-labelledby="tracking-devices-heading"
                                className="space-y-3"
                            >
                                <div>
                                    <h2
                                        id="tracking-devices-heading"
                                        className="text-lg font-semibold"
                                    >
                                        {tab === 'overview'
                                            ? 'Device readiness'
                                            : data.activeTab.label}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {data.activeTab.inventoryShown} of{' '}
                                        {data.activeTab.inventoryTotal}{' '}
                                        authorised trackers shown.
                                    </p>
                                </div>
                                <DeviceGrid devices={data.activeTab.devices} />
                            </section>
                        </>
                    ) : null}
                    {tab === 'geofences' ? (
                        <Geofences rows={data.activeTab.geofences} />
                    ) : null}
                    {tab === 'history' ? (
                        <HistoryPanel
                            rows={data.activeTab.history}
                            retentionDays={data.activeTab.retentionDays}
                            markers={data.activeTab.markers}
                        />
                    ) : null}
                </>
            )}
        </div>
    );
}
