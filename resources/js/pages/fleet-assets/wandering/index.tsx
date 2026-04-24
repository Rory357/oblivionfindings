import { FleetStatCard } from '@/components/fleet-stat-card';
import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { formatRelativeTime, severityColor, severityVariant, statusColor } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    Eye,
    MapPin,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useMemo } from 'react';

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

type Props = {
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
    can: {
        manage: boolean;
    };
};

function alertTypeLabel(type: string): string {
    switch (type) {
        case 'geofence_breach': return 'Left Zone';
        case 'wandering': return 'Wandering';
        case 'sos': return 'SOS';
        case 'low_battery': return 'Low Battery';
        case 'offline': return 'Offline';
        default: return type?.replace(/_/g, ' ') ?? 'Unknown';
    }
}

export default function WanderingAlertsIndex({ alerts, stats, filters, can }: Props) {
    const alertData = alerts?.data ?? [];
    const canManage = can.manage;

    // Real-time WebSocket listener for wandering alert broadcasts.
    // NOTE: Requires Laravel Echo + Reverb/Pusher to be installed and configured.
    // When Echo is not available, the page will rely on manual refresh.
    useEffect(() => {
        if (typeof window !== 'undefined' && (window as any).Echo) {
            const channel = (window as any).Echo.channel('fleet.wandering-alerts');
            channel.listen('.alert.triggered', (data: any) => {
                // Auto-refresh the page data when a new alert arrives
                router.reload({ only: ['alerts', 'stats'] });

                // Play audio notification for critical/high severity alerts
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
        router.get('/fleet-assets/wandering-alerts', {
            status: value === 'all' ? undefined : value,
        }, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Wandering Alerts', href: '#' },
            ]}
        >
            <Head title="Wandering Alerts" />
            <PageShell>
                <FleetHero
                    title="Wandering Alerts"
                    subtitle="Resident geofence breach and safety alerts"
                />

                {/* KPI Cards */}
                <div className="grid grid-cols-3 gap-4">
                    <FleetStatCard
                        label="Active Alerts"
                        value={stats?.active_alerts ?? 0}
                        icon={ShieldAlert}
                        color="red"
                    />
                    <FleetStatCard
                        label="Resolved Today"
                        value={stats?.resolved_today ?? 0}
                        icon={CheckCircle}
                        color="purple"
                    />
                    <FleetStatCard
                        label="Total This Week"
                        value={stats?.total_this_week ?? 0}
                        icon={Clock}
                        color="blue"
                    />
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
                                            <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                                No wandering alerts found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        alertData.map((alert) => (
                                            <TableRow key={alert.id}>
                                                <TableCell>
                                                    <div className="flex items-center gap-3">
                                                        <img
                                                            src={alert.client?.photo ?? '/images/avatar-placeholder.svg'}
                                                            alt={alert.client?.name ?? ''}
                                                            className="h-8 w-8 rounded-full object-cover border"
                                                        />
                                                        <div>
                                                            <p className="text-sm font-medium">{alert.client?.name ?? 'Unknown'}</p>
                                                            <p className="text-xs text-muted-foreground">{alert.client?.house ?? ''}</p>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={severityVariant(alert.severity)} className="text-xs">
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
                                                    <Badge className={`text-[10px] ${statusColor(alert.status)}`}>
                                                        {alert.status?.replace(/_/g, ' ')}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {canManage ? (
                                                        <div className="flex items-center justify-end gap-1">
                                                            {alert.status !== 'acknowledged' && alert.status !== 'resolved' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => handleAcknowledge(alert.id)}
                                                                >
                                                                    <Eye className="mr-1 h-3.5 w-3.5" />
                                                                    Ack
                                                                </Button>
                                                            )}
                                                            {alert.status !== 'resolved' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => handleResolve(alert.id)}
                                                                    className="text-status-success"
                                                                >
                                                                    <CheckCircle className="mr-1 h-3.5 w-3.5" />
                                                                    Resolve
                                                                </Button>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">View only</span>
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
                {alerts?.meta?.last_page > 1 && (
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
            </PageShell>
        </AppLayout>
    );
}
