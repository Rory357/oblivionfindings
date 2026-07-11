import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FLEET_COLORS, HalfMoonGauge } from '@/components/fleet-charts';
import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import ResidentMap from '@/components/resident-tracking/resident-map';
import ResidentSidebar from '@/components/resident-tracking/resident-sidebar';
import type { Geofence, Resident } from '@/components/resident-tracking/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    formatRelativeTime,
    severityColor,
    severityVariant,
    statusColor,
} from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Battery,
    Bus,
    CheckCircle,
    CheckCircle2,
    Eye,
    Link2,
    Link2Off,
    Loader2,
    MapPin,
    Radio,
    Search,
    Shield,
    UserPlus,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

type WanderingAlert = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    notes: string | null;
    context: Record<string, any> | null;
    client: {
        id: number;
        name: string;
        photo: string | null;
        house: string;
    } | null;
    last_lat: number | null;
    last_lng: number | null;
    geofence_name: string | null;
};

type WanderingPayload = {
    alerts: {
        data: WanderingAlert[];
        links: any[];
        meta: { current_page: number; last_page: number; total: number };
    };
    stats: {
        active_alerts: number;
        resolved_today: number;
        total_this_week: number;
    };
    filters: {
        status?: string;
    };
};

type ClientOption = {
    id: number;
    name: string;
    house: string;
    is_tracked: boolean;
};

type TrackerOption = {
    id: number;
    device_uid?: string | null;
    name: string;
    serial: string | null;
    mac: string | null;
    provider: string | null;
    status: string;
    battery?: number | null;
};

type AssignedTracker = {
    id: number;
    name: string;
    serial: string | null;
    provider: string | null;
    status: string;
    client_id: number;
    client_name: string;
    client_house: string;
    battery: number | null;
};

type AssignPayload = {
    clients: ClientOption[];
    available_trackers: TrackerOption[];
    assigned_trackers: AssignedTracker[];
};

type Props = {
    tab?: 'tracking' | 'wandering';
    residents: Resident[];
    stats: {
        tracked: number;
        online: number;
        offline: number;
        untracked: number;
        online_percent: number;
        in_geofence: number;
        outside_geofence: number;
        low_battery: number;
        safety_score: number;
        avg_battery: number;
        panic_active?: number;
        active_alerts?: number;
        wandering_7d?: number;
        panic_7d?: number;
    };
    recent_alerts: Array<{
        id: number;
        title: string;
        severity: string;
        status: string;
        created_at: string;
        resident_name?: string;
        client_id?: number;
    }>;
    active_outings: Array<{
        id: number;
        title: string;
        destination: string;
        resident_count: number;
        departed_at: string | null;
        vehicle_name: string | null;
    }>;
    geofences: Geofence[];
    focus_client_id?: number | null;
    wandering?: WanderingPayload | null;
    assign?: AssignPayload | null;
    can: {
        manage: boolean;
        manage_alerts?: boolean;
    };
};

function formatAlertType(alertType: string): string {
    return alertType.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function alertTypeLabel(type: string): string {
    switch (type) {
        case 'geofence_breach':
            return 'Left Zone';
        case 'wandering':
            return 'Wandering';
        case 'sos':
            return 'SOS';
        case 'low_battery':
            return 'Low Battery';
        case 'offline':
            return 'Offline';
        default:
            return type?.replace(/_/g, ' ') ?? 'Unknown';
    }
}

/* ------------------------------------------------------------------ */
/*  Wandering alerts tab (retired /fleet-assets/wandering-alerts page) */
/* ------------------------------------------------------------------ */

function WanderingAlertsTab({
    payload,
    canManage,
}: {
    payload: WanderingPayload;
    canManage: boolean;
}) {
    const alerts = payload.alerts;
    const stats = payload.stats;
    const filters = payload.filters;
    const alertData = useMemo(() => alerts?.data ?? [], [alerts?.data]);

    // Real-time WebSocket listener for wandering alert broadcasts.
    // NOTE: Requires Laravel Echo + Reverb/Pusher to be installed and configured.
    // When Echo is not available, the tab relies on manual refresh.
    useEffect(() => {
        if (typeof window !== 'undefined' && (window as any).Echo) {
            const channel = (window as any).Echo.channel('fleet.wandering-alerts');
            channel.listen('.alert.triggered', (data: any) => {
                router.reload({ only: ['wandering', 'stats'] });
                if (data.severity === 'critical' || data.severity === 'high') {
                    try {
                        new Audio('/sounds/alert.mp3').play();
                    } catch {
                        // Audio playback may be blocked by browser autoplay policies
                    }
                }
            });
            return () => {
                channel.stopListening('.alert.triggered');
            };
        }
    }, []);

    // Map markers for alerted residents with location
    const mapMarkers: MapMarker[] = useMemo(() => {
        return alertData
            .filter((a) => a.last_lat != null && a.last_lng != null)
            .map((a) => ({
                id: a.id,
                lat: a.last_lat!,
                lng: a.last_lng!,
                title: a.client?.name ?? 'Unknown',
                type: 'default' as const,
                status: a.status === 'resolved' ? 'online' : 'offline',
                popup: `${a.client?.name ?? 'Unknown'}<br/>${alertTypeLabel(a.alert_type)}<br/>${formatRelativeTime(a.triggered_at)}`,
            }));
    }, [alertData]);

    const mapCenter = useMemo(() => {
        if (mapMarkers.length > 0) {
            const avgLat = mapMarkers.reduce((s, m) => s + m.lat, 0) / mapMarkers.length;
            const avgLng = mapMarkers.reduce((s, m) => s + m.lng, 0) / mapMarkers.length;
            return { lat: avgLat, lng: avgLng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [mapMarkers]);

    const handleAcknowledge = (alertId: number) => {
        router.post(`/fleet-assets/alerts/${alertId}/acknowledge`, {}, { preserveScroll: true });
    };

    const handleResolve = (alertId: number) => {
        router.post(`/fleet-assets/alerts/${alertId}/resolve`, {}, { preserveScroll: true });
    };

    const handleStatusFilter = (value: string) => {
        router.get(
            '/fleet-assets/resident-tracking',
            {
                tab: 'wandering',
                status: value === 'all' ? undefined : value,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            {/* Summary chips */}
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                    <AlertTriangle className="h-3.5 w-3.5 text-status-critical" />
                    {stats?.active_alerts ?? 0} active
                </span>
                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                    <CheckCircle className="h-3.5 w-3.5 text-status-success" />
                    {stats?.resolved_today ?? 0} resolved today
                </span>
                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                    <Shield className="h-3.5 w-3.5 text-status-info" />
                    {stats?.total_this_week ?? 0} this week
                </span>
            </div>

            {/* Map */}
            {mapMarkers.length > 0 && (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin className="h-4 w-4" />
                            Last Known Locations
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <LeafletMap
                            center={mapCenter}
                            zoom={12}
                            markers={mapMarkers}
                            height={300}
                        />
                    </CardContent>
                </Card>
            )}

            {/* Filter + Table */}
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex items-center justify-between">
                        <CardTitle className="text-base">Alerts</CardTitle>
                        <Select
                            value={filters?.status ?? 'all'}
                            onValueChange={handleStatusFilter}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="Filter status..." />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Active</SelectItem>
                                <SelectItem value="new">New</SelectItem>
                                <SelectItem value="acknowledged">Acknowledged</SelectItem>
                                <SelectItem value="resolved">Resolved</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Resident</TableHead>
                                    <TableHead>Alert Type</TableHead>
                                    <TableHead>Geofence</TableHead>
                                    <TableHead>Time</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {alertData.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No wandering alerts found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    alertData.map((alert) => (
                                        <TableRow key={alert.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <img
                                                        src={
                                                            alert.client?.photo ??
                                                            '/images/avatar-placeholder.svg'
                                                        }
                                                        alt={alert.client?.name ?? ''}
                                                        className="h-8 w-8 rounded-full border object-cover"
                                                    />
                                                    <div>
                                                        <p className="text-sm font-medium">
                                                            {alert.client?.name ?? 'Unknown'}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {alert.client?.house ?? ''}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={severityVariant(alert.severity)}
                                                    className="text-xs"
                                                >
                                                    {alertTypeLabel(alert.alert_type)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {alert.geofence_name ?? '---'}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {formatRelativeTime(alert.triggered_at)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={`text-[10px] ${statusColor(alert.status)}`}
                                                >
                                                    {alert.status?.replace(/_/g, ' ')}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {canManage ? (
                                                    <div className="flex items-center justify-end gap-1">
                                                        {alert.status !== 'acknowledged' &&
                                                            alert.status !== 'resolved' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handleAcknowledge(alert.id)
                                                                    }
                                                                >
                                                                    <Eye className="mr-1 h-3.5 w-3.5" />
                                                                    Ack
                                                                </Button>
                                                            )}
                                                        {alert.status !== 'resolved' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    handleResolve(alert.id)
                                                                }
                                                                className="text-status-success"
                                                            >
                                                                <CheckCircle className="mr-1 h-3.5 w-3.5" />
                                                                Resolve
                                                            </Button>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        View only
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            {/* Pagination */}
            {(alerts?.meta?.last_page ?? 1) > 1 && (
                <div className="flex items-center justify-center gap-2 text-sm">
                    {alerts.links.map((link: any, i: number) => (
                        <Button
                            key={i}
                            variant={link.active ? 'default' : 'outline'}
                            size="sm"
                            disabled={!link.url}
                            onClick={() => link.url && router.visit(link.url)}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            )}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Assign-tracker modal (retired /resident-tracking/assign page)      */
/* ------------------------------------------------------------------ */

function AssignTrackerDialog({
    payload,
    open,
    onClose,
    canManage,
}: {
    payload: AssignPayload;
    open: boolean;
    onClose: () => void;
    canManage: boolean;
}) {
    const form = useForm({
        client_id: '',
        tracker_id: '',
    });

    const unassignableClients = (payload.clients ?? []).filter((c) => !c.is_tracked);
    const availableTrackers = payload.available_trackers ?? [];
    const assignedTrackers = payload.assigned_trackers ?? [];
    const selectedTracker = availableTrackers.find(
        (t) => String(t.id) === form.data.tracker_id,
    );

    const handleAssign = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/resident-tracking/assign', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const handleUnassign = (trackerId: number) => {
        router.post(
            `/fleet-assets/resident-tracking/${trackerId}/unassign`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Assign Tracker Device</DialogTitle>
                    <DialogDescription>
                        Link a personal tracker to a resident. Assignments require an
                        active Fleet Tracking consent for the resident.
                    </DialogDescription>
                </DialogHeader>

                {!canManage ? (
                    <p className="text-sm text-muted-foreground">
                        Assigning or unassigning trackers requires fleet manager access.
                    </p>
                ) : (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {/* Assign form */}
                        <form onSubmit={handleAssign} className="space-y-4">
                            <p className="flex items-center gap-2 text-sm font-semibold">
                                <UserPlus className="h-4 w-4" />
                                Assign New Tracker
                            </p>
                            <div className="space-y-2">
                                <Label>Resident</Label>
                                <Select
                                    value={form.data.client_id}
                                    onValueChange={(v) => form.setData('client_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a resident..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {unassignableClients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.name} ({c.house})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.client_id && (
                                    <p className="text-xs text-destructive">
                                        {form.errors.client_id}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label>Tracker Device</Label>
                                <Select
                                    value={form.data.tracker_id}
                                    onValueChange={(v) => form.setData('tracker_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an available tracker..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableTrackers.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.name ?? t.device_uid}{' '}
                                                {t.serial ? `(${t.serial})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.tracker_id && (
                                    <p className="text-xs text-destructive">
                                        {form.errors.tracker_id}
                                    </p>
                                )}
                            </div>

                            {/* Selected device details */}
                            {selectedTracker && (
                                <div className="space-y-2 rounded-lg border bg-muted/30 p-4">
                                    <p className="text-sm font-medium">Device Details</p>
                                    <dl className="grid grid-cols-2 gap-2 text-sm">
                                        <div>
                                            <dt className="text-xs text-muted-foreground">Name</dt>
                                            <dd className="font-medium">
                                                {selectedTracker.name ??
                                                    selectedTracker.device_uid}
                                            </dd>
                                        </div>
                                        {selectedTracker.serial && (
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Serial / IMEI
                                                </dt>
                                                <dd className="font-medium">
                                                    {selectedTracker.serial}
                                                </dd>
                                            </div>
                                        )}
                                        {selectedTracker.provider && (
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Vendor
                                                </dt>
                                                <dd className="font-medium capitalize">
                                                    {selectedTracker.provider}
                                                </dd>
                                            </div>
                                        )}
                                        <div>
                                            <dt className="text-xs text-muted-foreground">Status</dt>
                                            <dd>
                                                <Badge
                                                    variant="secondary"
                                                    className="text-xs capitalize"
                                                >
                                                    {selectedTracker.status}
                                                </Badge>
                                            </dd>
                                        </div>
                                        {selectedTracker.battery != null && (
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Battery
                                                </dt>
                                                <dd className="font-medium">
                                                    {selectedTracker.battery}%
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            )}

                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    !form.data.client_id ||
                                    !form.data.tracker_id
                                }
                                className="w-full"
                            >
                                {form.processing ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <Link2 className="mr-2 h-4 w-4" />
                                )}
                                Assign Tracker
                            </Button>
                        </form>

                        {/* Currently assigned */}
                        <div className="flex flex-col">
                            <p className="mb-2 flex items-center justify-between text-sm font-semibold">
                                <span className="flex items-center gap-2">
                                    <Radio className="h-4 w-4" />
                                    Currently Assigned
                                </span>
                                <Badge variant="secondary">{assignedTrackers.length}</Badge>
                            </p>
                            <div
                                className="divide-y overflow-y-auto rounded-lg border"
                                style={{ maxHeight: '360px' }}
                            >
                                {assignedTrackers.length === 0 ? (
                                    <div className="p-6 text-center text-sm text-muted-foreground">
                                        No trackers currently assigned.
                                    </div>
                                ) : (
                                    assignedTrackers.map((tracker) => (
                                        <div
                                            key={tracker.id}
                                            className="flex items-center gap-3 px-4 py-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium">
                                                    {tracker.client_name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {tracker.client_house} &middot;{' '}
                                                    {tracker.name}
                                                    {tracker.serial
                                                        ? ` (${tracker.serial})`
                                                        : ''}
                                                </p>
                                                <div className="mt-1 flex items-center gap-2">
                                                    <Badge
                                                        variant="secondary"
                                                        className={`text-[10px] ${
                                                            tracker.status === 'online' ||
                                                            tracker.status === 'active'
                                                                ? 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success'
                                                                : ''
                                                        }`}
                                                    >
                                                        {tracker.status}
                                                    </Badge>
                                                    {tracker.battery != null && (
                                                        <span className="text-[10px] text-muted-foreground">
                                                            {tracker.battery}% battery
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleUnassign(tracker.id)}
                                                className="text-destructive hover:bg-destructive/10"
                                            >
                                                <Link2Off className="mr-1 h-3.5 w-3.5" />
                                                Unassign
                                            </Button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ResidentTrackingIndex({
    tab = 'tracking',
    residents,
    stats,
    recent_alerts,
    active_outings,
    geofences,
    focus_client_id,
    wandering,
    assign,
    can,
}: Props) {
    const [search, setSearch] = useState('');
    const [activeSideTab, setActiveSideTab] = useState<'all' | 'outside' | 'panic' | 'alerts'>('all');
    const [activeResidentId, setActiveResidentId] = useState<number | null>(
        focus_client_id ?? null,
    );
    const [lastUpdatedAt, setLastUpdatedAt] = useState<string>(new Date().toISOString());
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const sidebarRef = useRef<HTMLDivElement | null>(null);

    const activeTab = tab === 'wandering' ? 'wandering' : 'tracking';

    const safeResidents = useMemo(() => residents ?? [], [residents]);
    const safeStats = stats ?? ({} as Props['stats']);
    const safeAlerts = useMemo(() => recent_alerts ?? [], [recent_alerts]);
    const safeOutings = active_outings ?? [];
    const safeGeofences = useMemo(() => geofences ?? [], [geofences]);

    // Auto-refresh (tracking tab only — the wandering tab refreshes via Echo)
    useEffect(() => {
        if (activeTab !== 'tracking') return;
        refreshTimerRef.current = setInterval(() => {
            router.reload({
                only: ['residents', 'stats', 'recent_alerts', 'active_outings', 'geofences'],
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setLastUpdatedAt(new Date().toISOString()),
            });
        }, 30000);
        return () => {
            if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
        };
    }, [activeTab]);

    // Focus on mount when ?focus= present
    useEffect(() => {
        if (!focus_client_id) return;
        const el = sidebarRef.current?.querySelector(
            `[data-resident-id="${focus_client_id}"]`,
        );
        if (el && el instanceof HTMLElement) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setActiveResidentId(focus_client_id);
        }
    }, [focus_client_id]);

    const outsideCount = useMemo(
        () => safeResidents.filter((r) => r.geofence_status === 'outside_zone').length,
        [safeResidents],
    );
    const panicCount = useMemo(
        () => safeResidents.filter((r) => r.panic_active).length,
        [safeResidents],
    );

    const filteredResidents = useMemo(() => {
        let list = safeResidents;
        if (activeSideTab === 'outside') {
            list = list.filter((r) => r.geofence_status === 'outside_zone');
        } else if (activeSideTab === 'panic') {
            list = list.filter((r) => r.panic_active);
        }
        if (search.trim()) {
            const q = search.toLowerCase();
            list = list.filter(
                (r) =>
                    r.name?.toLowerCase().includes(q) ||
                    r.preferred_name?.toLowerCase().includes(q) ||
                    r.house?.toLowerCase().includes(q),
            );
        }
        return list;
    }, [safeResidents, search, activeSideTab]);

    const mapMarkers: MapMarker[] = useMemo(() => {
        return safeResidents
            .filter((r) => r.lat != null && r.lng != null)
            .map((r) => ({
                id: r.client_id,
                lat: r.lat!,
                lng: r.lng!,
                title: r.preferred_name ?? r.name,
                type: 'default' as const,
                status: r.panic_active
                    ? 'offline'
                    : r.on_outing
                      ? 'moving'
                      : r.geofence_status === 'outside_zone'
                        ? 'offline'
                        : r.status === 'online'
                          ? 'online'
                          : 'idle',
                popup: `<strong>${r.name}</strong><br/>${r.house}<br/>${r.display_location ?? ''}<br/>Last seen: ${formatRelativeTime(r.last_seen_at)}`,
            }));
    }, [safeResidents]);

    const mapCenter = useMemo(() => {
        if (mapMarkers.length > 0) {
            const avgLat = mapMarkers.reduce((s, m) => s + m.lat, 0) / mapMarkers.length;
            const avgLng = mapMarkers.reduce((s, m) => s + m.lng, 0) / mapMarkers.length;
            return { lat: avgLat, lng: avgLng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [mapMarkers]);

    const handleMarkerClick = useCallback((id: string | number) => {
        const clientId = typeof id === 'number' ? id : parseInt(String(id), 10);
        setActiveResidentId(clientId);
        const el = sidebarRef.current?.querySelector(`[data-resident-id="${clientId}"]`);
        if (el && el instanceof HTMLElement) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, []);

    const handleLocateNow = useCallback((resident: Resident) => {
        if (!resident.locate_now_url) return;
        router.post(resident.locate_now_url, {}, { preserveScroll: true });
    }, []);

    const handleAcknowledgePanic = useCallback((resident: Resident) => {
        if (!resident.acknowledge_panic_url) return;
        router.post(resident.acknowledge_panic_url, {}, { preserveScroll: true });
    }, []);

    const handleOpenProfile = useCallback((resident: Resident) => {
        router.visit(resident.profile_url ?? `/operations/clients/${resident.client_id}?tab=location&from=fleet`);
    }, []);

    const safetyScoreColor = useMemo(() => {
        const score = safeStats.safety_score ?? 0;
        if (score >= 80) return FLEET_COLORS.primary;
        if (score >= 50) return FLEET_COLORS.warning;
        return FLEET_COLORS.danger;
    }, [safeStats.safety_score]);

    const panicAlerts = useMemo(() => {
        return safeAlerts.filter((a) => {
            const t = (a.title || '').toLowerCase();
            return t.includes('sos') || t.includes('panic') || t.includes('man_down');
        });
    }, [safeAlerts]);

    /* ── Tab switching (server supplies the wandering payload per tab) ── */
    const switchTab = (next: 'tracking' | 'wandering') => {
        if (next === activeTab) return;
        router.get(
            '/fleet-assets/resident-tracking',
            next === 'wandering' ? { tab: 'wandering' } : {},
            { preserveState: true, preserveScroll: true },
        );
    };

    /* ── Assign modal (?new=1 shim) ── */
    const [assignOpen, setAssignOpen] = useState(!!assign);
    useEffect(() => setAssignOpen(!!assign), [assign]);
    const closeAssign = () => {
        setAssignOpen(false);
        const params = new URLSearchParams(window.location.search);
        params.delete('new');
        const qs = params.toString();
        router.get(
            `${window.location.pathname}${qs ? `?${qs}` : ''}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const activeAlertCount = safeStats.active_alerts ?? 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Resident Tracking', href: '#' },
            ]}
        >
            <Head title="Resident Tracking" />
            <PageShell>
                {/* ── Hero ── */}
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Shield} />
                        <div className="min-w-0">
                            <HeroStatusPill>Safety command centre · live</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Resident Tracking
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Monitor tracked residents, wandering alerts and panic events in real time.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                label="Residents tracked"
                                value={fmt(safeStats.tracked ?? 0)}
                                caption={`${fmt(safeStats.online ?? 0)} online`}
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Active alerts"
                                value={fmt(activeAlertCount)}
                                caption="open right now"
                                tone={activeAlertCount > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                label="Wandering 7d"
                                value={fmt(safeStats.wandering_7d ?? 0)}
                                caption="zone breaches"
                                tone={(safeStats.wandering_7d ?? 0) > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Panic 7d"
                                value={fmt(safeStats.panic_7d ?? 0)}
                                caption="SOS events"
                                tone={(safeStats.panic_7d ?? 0) > 0 ? 'critical' : 'success'}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {can.manage && (
                            <FleetHeroAction
                                href="/fleet-assets/resident-tracking?new=1"
                                icon={UserPlus}
                                emphasis
                            >
                                Assign tracker
                            </FleetHeroAction>
                        )}
                        <FleetHeroAction href="/fleet-assets/devices" icon={Radio}>
                            Tracking devices
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* ── Tab strip ── */}
                <div className="inline-flex w-fit items-center gap-1 rounded-lg border bg-muted/40 p-1">
                    {(
                        [
                            { key: 'tracking', label: 'Tracking' },
                            {
                                key: 'wandering',
                                label: `Wandering alerts${activeAlertCount > 0 ? ` (${fmt(activeAlertCount)})` : ''}`,
                            },
                        ] as const
                    ).map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => switchTab(t.key)}
                            className={cn(
                                'rounded-md px-3.5 py-1.5 text-[13px] font-semibold transition-colors',
                                activeTab === t.key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {activeTab === 'wandering' && wandering ? (
                    <WanderingAlertsTab
                        payload={wandering}
                        canManage={can.manage_alerts ?? can.manage}
                    />
                ) : (
                    <>
                        {/* Active panic banner */}
                        {panicCount > 0 && (
                            <Card className="border-status-critical/30 bg-status-critical-bg">
                                <CardContent className="flex items-center justify-between gap-3 p-3">
                                    <div className="flex items-center gap-2 text-status-critical">
                                        <AlertTriangle className="h-4 w-4" />
                                        <span className="text-sm font-medium">
                                            {panicCount} active panic{panicCount > 1 ? 's' : ''} —
                                            immediate attention needed
                                        </span>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setActiveSideTab('panic')}
                                        className="border-status-critical/30 text-status-critical"
                                    >
                                        View
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {/* Map + sidebar grid */}
                        <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                            <Card className="overflow-hidden">
                                <CardContent className="p-0">
                                    <ResidentMap
                                        center={mapCenter}
                                        zoom={mapMarkers.length > 0 ? 12 : 6}
                                        markers={mapMarkers}
                                        geofences={safeGeofences}
                                        height={520}
                                        clustering
                                        onMarkerClick={handleMarkerClick}
                                        updatedAt={lastUpdatedAt}
                                    />
                                </CardContent>
                            </Card>

                            <Card className="flex flex-col">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">Residents</CardTitle>
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setActiveSideTab('all')}
                                            className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                                activeSideTab === 'all'
                                                    ? 'bg-primary/10 text-primary'
                                                    : 'text-muted-foreground hover:bg-muted'
                                            }`}
                                        >
                                            All ({safeResidents.length})
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setActiveSideTab('outside')}
                                            className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                                activeSideTab === 'outside'
                                                    ? 'bg-status-critical-bg text-status-critical'
                                                    : 'text-muted-foreground hover:bg-muted'
                                            }`}
                                        >
                                            Outside ({outsideCount})
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setActiveSideTab('panic')}
                                            className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                                activeSideTab === 'panic'
                                                    ? 'bg-status-critical-bg text-status-critical'
                                                    : 'text-muted-foreground hover:bg-muted'
                                            }`}
                                        >
                                            Panic ({panicCount})
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setActiveSideTab('alerts')}
                                            className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                                activeSideTab === 'alerts'
                                                    ? 'bg-status-warning-bg text-status-warning'
                                                    : 'text-muted-foreground hover:bg-muted'
                                            }`}
                                        >
                                            Alerts ({safeAlerts.length})
                                        </Button>
                                    </div>
                                    {activeSideTab !== 'alerts' && (
                                        <div className="relative mt-2">
                                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                placeholder="Search by name or house..."
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                                className="pl-9"
                                            />
                                        </div>
                                    )}
                                </CardHeader>
                                <CardContent
                                    className="flex-1 overflow-y-auto p-0"
                                    style={{ maxHeight: '460px' }}
                                >
                                    <div ref={sidebarRef}>
                                        {activeSideTab === 'alerts' ? (
                                            safeAlerts.length === 0 ? (
                                                <div className="flex flex-col items-center gap-2 py-10 text-center">
                                                    <CheckCircle2 className="h-8 w-8 text-primary" />
                                                    <p className="text-sm font-medium">
                                                        All residents safe
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        No recent alerts
                                                    </p>
                                                </div>
                                            ) : (
                                                <div className="divide-y">
                                                    {safeAlerts.map((alert) => (
                                                        <div
                                                            key={alert.id}
                                                            className="flex items-center gap-3 px-4 py-3 hover:bg-muted/40"
                                                        >
                                                            <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex items-center gap-2">
                                                                    <span className="truncate text-sm font-medium">
                                                                        {alert.resident_name ??
                                                                            formatAlertType(alert.title)}
                                                                    </span>
                                                                    <Badge
                                                                        className={`text-[10px] text-white ${severityColor(alert.severity)}`}
                                                                    >
                                                                        {alert.severity}
                                                                    </Badge>
                                                                </div>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {formatAlertType(alert.title)} ·{' '}
                                                                    {formatRelativeTime(alert.created_at)}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            )
                                        ) : filteredResidents.length === 0 ? (
                                            <div className="p-6 text-center text-sm text-muted-foreground">
                                                No residents found.
                                            </div>
                                        ) : (
                                            filteredResidents.map((resident) => (
                                                <ResidentSidebar
                                                    key={resident.id}
                                                    resident={resident}
                                                    variant="fleet-row"
                                                    canManage={can.manage}
                                                    onLocateNow={() => handleLocateNow(resident)}
                                                    onAcknowledgePanic={() =>
                                                        handleAcknowledgePanic(resident)
                                                    }
                                                    onOpenProfile={() => handleOpenProfile(resident)}
                                                    isActive={activeResidentId === resident.client_id}
                                                />
                                            ))
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Bottom row */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            {/* Recent Alerts */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <AlertTriangle className="h-4 w-4 text-status-warning" />
                                        Recent Alerts
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {panicAlerts.length > 0 && (
                                        <div className="mb-3 space-y-2">
                                            {panicAlerts.map((alert) => (
                                                <div
                                                    key={`panic-${alert.id}`}
                                                    className="rounded-md border border-status-critical/30 bg-status-critical-bg p-2"
                                                >
                                                    <div className="flex items-center gap-2 text-status-critical">
                                                        <AlertTriangle className="h-3.5 w-3.5" />
                                                        <span className="truncate text-xs font-semibold">
                                                            {alert.resident_name ??
                                                                formatAlertType(alert.title)}
                                                        </span>
                                                    </div>
                                                    <p className="mt-0.5 text-[11px] text-status-critical/80">
                                                        {formatRelativeTime(alert.created_at)}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    {safeAlerts.length === 0 ? (
                                        <div className="flex flex-col items-center gap-2 py-6 text-center">
                                            <CheckCircle2 className="h-8 w-8 text-primary" />
                                            <p className="text-sm font-medium">All residents safe</p>
                                            <p className="text-xs text-muted-foreground">
                                                No recent alerts to display
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {safeAlerts.slice(0, 5).map((alert) => (
                                                <div key={alert.id} className="flex items-start gap-2">
                                                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-warning" />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="truncate text-sm">
                                                                {alert.resident_name ??
                                                                    formatAlertType(alert.title)}
                                                            </span>
                                                            <Badge
                                                                className={`text-[10px] text-white ${severityColor(alert.severity)}`}
                                                            >
                                                                {alert.severity}
                                                            </Badge>
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">
                                                            {formatRelativeTime(alert.created_at)}
                                                        </p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <div className="mt-4 border-t pt-3">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                            onClick={() => switchTab('wandering')}
                                        >
                                            View All Alerts
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Active Outings */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Bus className="h-4 w-4 text-status-info" />
                                        Active Outings
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {safeOutings.length === 0 ? (
                                        <FleetEmptyState
                                            icon={Bus}
                                            title="No active outings"
                                            description="No residents are currently on outings"
                                            compact
                                        />
                                    ) : (
                                        <div className="space-y-3">
                                            {safeOutings.map((outing) => (
                                                <div key={outing.id} className="flex items-start gap-2">
                                                    <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-info" />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-sm font-medium">
                                                            {outing.destination}
                                                        </p>
                                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                            <span>
                                                                {outing.resident_count} resident
                                                                {outing.resident_count !== 1 ? 's' : ''}
                                                            </span>
                                                            {outing.departed_at && (
                                                                <>
                                                                    <span>·</span>
                                                                    <span>
                                                                        Departed{' '}
                                                                        {formatRelativeTime(outing.departed_at)}
                                                                    </span>
                                                                </>
                                                            )}
                                                        </div>
                                                        {outing.vehicle_name && (
                                                            <p className="text-xs text-muted-foreground">
                                                                {outing.vehicle_name}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <div className="mt-4 border-t pt-3">
                                        <Button variant="outline" size="sm" className="w-full" asChild>
                                            <Link href="/fleet-assets/outings">View All Outings</Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Safety Analytics */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Shield className="h-4 w-4 text-primary" />
                                        Safety Analytics
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex flex-col items-center">
                                        <HalfMoonGauge
                                            value={safeStats.safety_score ?? 0}
                                            label="Safety Score"
                                            sublabel={`${safeStats.in_geofence ?? 0} of ${safeStats.tracked ?? 0} residents within their zone`}
                                            size={160}
                                            color={safetyScoreColor}
                                        />
                                        <div className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-muted/30 px-4 py-2">
                                            <Battery className="h-4 w-4 text-muted-foreground" />
                                            <span className="text-sm text-muted-foreground">
                                                Avg Battery:
                                            </span>
                                            <span className="text-sm font-semibold tabular-nums">
                                                {safeStats.avg_battery ?? 0}%
                                            </span>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </>
                )}

                {/* ── Assign tracker modal (retired /resident-tracking/assign) ── */}
                {assign && (
                    <AssignTrackerDialog
                        payload={assign}
                        open={assignOpen}
                        onClose={closeAssign}
                        canManage={can.manage}
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
