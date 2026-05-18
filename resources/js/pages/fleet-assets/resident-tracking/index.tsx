import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FLEET_COLORS, HalfMoonGauge } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import type { MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import ResidentMap from '@/components/resident-tracking/resident-map';
import ResidentSidebar from '@/components/resident-tracking/resident-sidebar';
import type { Geofence, Resident } from '@/components/resident-tracking/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatRelativeTime, severityColor } from '@/lib/fleet-utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Battery,
    Bus,
    CheckCircle2,
    MapPin,
    Search,
    Shield,
    UserPlus,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

type Props = {
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
    can: {
        manage: boolean;
    };
};

function formatAlertType(alertType: string): string {
    return alertType.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function ResidentTrackingIndex({
    residents,
    stats,
    recent_alerts,
    active_outings,
    geofences,
    focus_client_id,
    can,
}: Props) {
    const [search, setSearch] = useState('');
    const [activeTab, setActiveTab] = useState<'all' | 'outside' | 'panic' | 'alerts'>('all');
    const [activeResidentId, setActiveResidentId] = useState<number | null>(
        focus_client_id ?? null,
    );
    const [lastUpdatedAt, setLastUpdatedAt] = useState<string>(new Date().toISOString());
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const sidebarRef = useRef<HTMLDivElement | null>(null);

    const safeResidents = useMemo(() => residents ?? [], [residents]);
    const safeStats = stats ?? ({} as Props['stats']);
    const safeAlerts = recent_alerts ?? [];
    const safeOutings = active_outings ?? [];
    const safeGeofences = useMemo(() => geofences ?? [], [geofences]);

    const heroStats = useMemo(
        () => [
            { label: 'Tracked', value: safeStats.tracked ?? 0 },
            { label: 'Online', value: safeStats.online ?? 0 },
            { label: 'Offline', value: safeStats.offline ?? 0 },
            { label: 'In Zone', value: safeStats.in_geofence ?? 0 },
            { label: 'Outside Zone', value: safeStats.outside_geofence ?? 0 },
            { label: 'Low Battery', value: safeStats.low_battery ?? 0 },
            { label: 'Panic', value: safeStats.panic_active ?? 0 },
        ],
        [safeStats],
    );

    // Auto-refresh
    useEffect(() => {
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
    }, []);

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
        if (activeTab === 'outside') {
            list = list.filter((r) => r.geofence_status === 'outside_zone');
        } else if (activeTab === 'panic') {
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
    }, [safeResidents, search, activeTab]);

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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Resident Tracking', href: '#' },
            ]}
        >
            <Head title="Resident Tracking" />
            <PageShell>
                <FleetHero
                    title="Resident Tracking"
                    subtitle="Safety command center — monitor tracked residents in real-time"
                    stats={heroStats}
                    actions={
                        can.manage ? (
                            <Button asChild>
                                <Link href="/fleet-assets/resident-tracking/assign">
                                    <UserPlus className="mr-2 h-4 w-4" />
                                    Assign Tracker
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

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
                                onClick={() => setActiveTab('panic')}
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
                                    onClick={() => setActiveTab('all')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'all'
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
                                    onClick={() => setActiveTab('outside')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'outside'
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
                                    onClick={() => setActiveTab('panic')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'panic'
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
                                    onClick={() => setActiveTab('alerts')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'alerts'
                                            ? 'bg-status-warning-bg text-status-warning'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    Alerts ({safeAlerts.length})
                                </Button>
                            </div>
                            {activeTab !== 'alerts' && (
                                <div className="relative mt-2">
                                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
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
                                {activeTab === 'alerts' ? (
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
                                <Button variant="outline" size="sm" className="w-full" asChild>
                                    <Link href="/fleet-assets/wandering-alerts">
                                        View All Alerts
                                    </Link>
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
            </PageShell>
        </AppLayout>
    );
}
