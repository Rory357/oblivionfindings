import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import { SparklineChart, FLEET_COLORS } from '@/components/fleet-charts';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Battery,
    Car,
    Download,
    Gauge,
    Loader2,
    Plus,
    RefreshCw,
    Search,
    WifiOff,
    Wrench,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type Vehicle = {
    id: number;
    name: string;
    asset_tag: string;
    status: string;
    state: {
        status: string;
        lat: number;
        lng: number;
        speed_kph: number;
        battery_pct: number;
        last_seen_at: string;
    } | null;
    home_site: { id: number; name: string } | null;
};

type PaginatedOrArray<T> = T[] | { data: T[]; links?: Array<{ url: string | null; label: string; active: boolean }>; meta?: { current_page?: number; last_page?: number; total?: number } };

function toArray<T>(input: PaginatedOrArray<T> | null | undefined): T[] {
    if (!input) return [];
    if (Array.isArray(input)) return input;
    return input.data ?? [];
}

type Props = {
    vehicles: PaginatedOrArray<Vehicle>;
    sites?: Array<{ id: number; name: string }>;
};

export default function VehiclesIndex({ vehicles: rawVehicles, sites }: Props) {
    const vehicles = toArray(rawVehicles);
    const paginationLinks = !Array.isArray(rawVehicles) ? rawVehicles?.links ?? [] : [];
    const paginationMeta = !Array.isArray(rawVehicles) ? rawVehicles?.meta ?? {} : {};
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [isRefreshing, setIsRefreshing] = useState(false);

    // Bulk selection
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkSiteId, setBulkSiteId] = useState('');

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            setIsRefreshing(true);
            router.reload({
                only: ['vehicles'],
                onFinish: () => setIsRefreshing(false),
            });
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    const filteredVehicles = useMemo(() => {
        const term = searchTerm.trim().toLowerCase();
        return (vehicles ?? []).filter((v) => {
            const name = (v.name ?? v.asset_tag ?? '').toLowerCase();
            const matchesTerm = !term || name.includes(term);
            const status = v.state?.status ?? 'offline';
            if (statusFilter === 'online' && status !== 'online') return false;
            if (statusFilter === 'offline' && status === 'online') return false;
            return matchesTerm;
        });
    }, [vehicles, searchTerm, statusFilter]);

    const markers = useMemo<MapMarker[]>(() => {
        return filteredVehicles
            .filter((v) => v.state?.lat && v.state?.lng)
            .map((v) => ({
                id: v.id,
                lat: Number(v.state!.lat),
                lng: Number(v.state!.lng),
                title: v.name ?? v.asset_tag ?? `Vehicle ${v.id}`,
                type: 'vehicle' as const,
                status: v.state!.status,
                popup: `Speed: ${v.state!.speed_kph ?? 0} kph | Battery: ${v.state!.battery_pct ?? 0}%`,
            }));
    }, [filteredVehicles]);

    const center = useMemo(() => {
        if (markers.length > 0) return { lat: markers[0].lat, lng: markers[0].lng };
        return { lat: -36.8485, lng: 174.7633 };
    }, [markers]);

    const toggleSelect = useCallback((id: number) => {
        setSelectedIds((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
    }, []);

    const toggleSelectAll = useCallback(() => {
        if (selectedIds.length === filteredVehicles.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(filteredVehicles.map((v) => v.id));
        }
    }, [filteredVehicles, selectedIds.length]);

    const handleBulkAction = useCallback((action: string) => {
        if (selectedIds.length === 0) return;
        const payload: Record<string, unknown> = { action, ids: selectedIds };
        if (action === 'assign_site' && bulkSiteId) {
            payload.site_id = Number(bulkSiteId);
        }
        router.post('/fleet-assets/vehicles/bulk-action', payload as any, {
            preserveState: true,
            onSuccess: () => setSelectedIds([]),
        });
    }, [selectedIds, bulkSiteId]);

    // KPI stats
    const totalCount = vehicles.length;
    const onlineCount = vehicles.filter((v) => v.state?.status === 'online').length;
    const offlineCount = vehicles.filter((v) => !v.state || v.state.status !== 'online').length;
    const inMaintenanceCount = vehicles.filter((v) => v.status === 'maintenance').length;

    // Fake sparkline data for KPI cards (derived from vehicle count for demo)
    const trendData = [3, 5, 4, 7, 6, 8, totalCount > 0 ? totalCount : 5];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Vehicles', href: '/fleet-assets/vehicles' },
            ]}
        >
            <Head title="Vehicles" />
            <PageShell>
                <PageHeader
                    title={
                        <div className="flex items-center gap-2">
                            <span>Vehicles</span>
                            {isRefreshing && <RefreshCw className="h-4 w-4 animate-spin text-muted-foreground" />}
                        </div>
                    }
                    description="Live vehicle tracking and management."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <a href="/fleet-assets/vehicles?export=csv">
                                <Download className="mr-2 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                    }
                />

                {vehicles.length === 0 && !searchTerm && statusFilter === 'all' ? (
                    <FleetEmptyState icon={Car} title="No vehicles tracked yet" description="Add vehicles to start fleet tracking. Vehicles with GPS trackers will appear on the map in real time." actionLabel="Add Vehicle" actionHref="/fleet-assets/assets/create" />
                ) : (
                    <>
                        {/* KPI Cards Row */}
                        <div className="grid gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            <FleetStatCard label="Total" value={totalCount} icon={Car} trend={trendData} />
                            <FleetStatCard label="Online" value={onlineCount} icon={Gauge} color="blue" />
                            <FleetStatCard label="Offline" value={offlineCount} icon={WifiOff} color="amber" trend={[2, 3, 1, 4, 2, 3, offlineCount > 0 ? offlineCount : 2]} />
                            <FleetStatCard label="Maintenance" value={inMaintenanceCount} icon={Wrench} color="cyan" subtitle="vehicles in service" />
                        </div>

                        {/* Map + Vehicle List side by side */}
                        <div className="grid gap-4 lg:grid-cols-[3fr,2fr]">
                            {/* Map */}
                            <div>
                                <LeafletMap
                                    center={center}
                                    zoom={12}
                                    markers={markers}
                                    height={500}
                                />
                            </div>

                            {/* Vehicle List */}
                            <div className="space-y-3">
                                <div className="flex flex-col gap-2">
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            placeholder="Search vehicles..."
                                            value={searchTerm}
                                            onChange={(e) => setSearchTerm(e.target.value)}
                                            className="pl-9"
                                        />
                                    </div>
                                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All statuses</SelectItem>
                                            <SelectItem value="online">Online</SelectItem>
                                            <SelectItem value="offline">Offline</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Select All */}
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <input
                                        type="checkbox"
                                        checked={filteredVehicles.length > 0 && selectedIds.length === filteredVehicles.length}
                                        onChange={toggleSelectAll}
                                        className="h-3.5 w-3.5 rounded border-gray-300"
                                    />
                                    <span>Select all</span>
                                </div>

                                <div className="space-y-2" style={{ maxHeight: '420px', overflowY: 'auto' }}>
                                    {filteredVehicles.length > 0 ? (
                                        filteredVehicles.map((vehicle) => (
                                            <div key={vehicle.id} className="flex items-start gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.includes(vehicle.id)}
                                                    onChange={() => toggleSelect(vehicle.id)}
                                                    className="mt-3.5 h-3.5 w-3.5 rounded border-gray-300"
                                                />
                                                <Link
                                                    href={`/fleet-assets/vehicles/${vehicle.id}`}
                                                    className="flex flex-1 flex-col gap-2 rounded-lg border p-3 transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <div className="flex items-center gap-2">
                                                            <Car className="h-4 w-4 text-muted-foreground" />
                                                            <span className="text-sm font-semibold">
                                                                {vehicle.name ?? vehicle.asset_tag ?? `Vehicle ${vehicle.id}`}
                                                            </span>
                                                        </div>
                                                        <Badge variant={vehicle.state?.status === 'online' ? 'default' : 'secondary'}>
                                                            {vehicle.state?.status ?? 'offline'}
                                                        </Badge>
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                        {vehicle.state?.speed_kph != null && (
                                                            <span className="inline-flex items-center gap-1">
                                                                <Gauge className="h-3 w-3" />
                                                                {vehicle.state.speed_kph} kph
                                                            </span>
                                                        )}
                                                        {vehicle.state?.battery_pct != null && (
                                                            <span className="inline-flex items-center gap-1">
                                                                <Battery className="h-3 w-3" />
                                                                {vehicle.state.battery_pct}%
                                                            </span>
                                                        )}
                                                        {vehicle.state?.last_seen_at && (
                                                            <span>Last seen: {vehicle.state.last_seen_at}</span>
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {vehicle.home_site ? (
                                                            <Badge variant="outline" className="text-[10px] font-normal">
                                                                Assigned to {vehicle.home_site.name}
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="secondary" className="text-[10px] font-normal">
                                                                Pool Vehicle
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </Link>
                                            </div>
                                        ))
                                    ) : (
                                        <FleetEmptyState icon={WifiOff} title="No vehicles match your filters" compact />
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Bulk Action Bar */}
                        {selectedIds.length > 0 && (
                            <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg">
                                <span className="text-sm font-medium">{selectedIds.length} vehicle{selectedIds.length !== 1 ? 's' : ''} selected</span>
                                <div className="flex items-center gap-2">
                                    <Select value={bulkSiteId} onValueChange={setBulkSiteId}>
                                        <SelectTrigger className="w-40 h-8 text-xs">
                                            <SelectValue placeholder="Assign to site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(sites ?? []).map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button size="sm" variant="outline" onClick={() => handleBulkAction('assign_site')} disabled={!bulkSiteId}>
                                        Assign
                                    </Button>
                                    <Button size="sm" variant="outline" onClick={() => handleBulkAction('mark_offline')}>
                                        Mark Offline
                                    </Button>
                                </div>
                                <Button size="sm" variant="ghost" onClick={() => setSelectedIds([])}>
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                        )}

                        {/* Pagination */}
                        {(paginationMeta.last_page ?? 1) > 1 && paginationLinks.length > 0 && (
                            <div className="flex items-center justify-center gap-1 pt-4">
                                {paginationLinks.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </PageShell>
        </AppLayout>
    );
}
