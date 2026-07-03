import { FleetEmptyState } from '@/components/fleet-empty-state';
import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import {
    FleetComplianceBadges,
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
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
    Bookmark,
    Car,
    ClipboardCheck,
    Download,
    Gauge,
    RefreshCw,
    Search,
    WifiOff,
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
    hero: {
        total: number;
        available: number;
        in_use: number;
        maintenance: number;
    };
    compliance: {
        wof_due: number;
        wof_expired: number;
        rego_due: number;
        cof_due: number;
        insurance_expiring: number | null;
        open_alerts: number;
        critical_alerts: number;
    };
    can: {
        manage: boolean;
    };
};

export default function VehiclesIndex({ vehicles: rawVehicles, sites, hero, compliance, can }: Props) {
    const vehicles = toArray(rawVehicles);
    const paginationLinks = !Array.isArray(rawVehicles) ? rawVehicles?.links ?? [] : [];
    const paginationMeta = !Array.isArray(rawVehicles) ? rawVehicles?.meta ?? {} : {};
    const canManage = can.manage;
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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Vehicles', href: '/fleet-assets/vehicles' },
            ]}
        >
            <Head title="Vehicles" />
            <PageShell>
                <HeroShell
                    footer={
                        <FleetComplianceBadges
                            wofDue={compliance.wof_due}
                            wofExpired={compliance.wof_expired}
                            regoDue={compliance.rego_due}
                            cofDue={compliance.cof_due}
                            insuranceExpiring={compliance.insurance_expiring}
                            openAlerts={compliance.open_alerts}
                            criticalAlerts={compliance.critical_alerts}
                            hrefs={{
                                wof: '/fleet-assets/compliance',
                                rego: '/fleet-assets/compliance',
                                cof: '/fleet-assets/compliance',
                                insurance: '/fleet-assets/compliance',
                                alerts: '/fleet-assets/alerts',
                            }}
                        />
                    }
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Car} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Vehicle fleet · live sync
                                {isRefreshing && <RefreshCw className="h-3 w-3 animate-spin" />}
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">Vehicles</h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Live vehicle tracking and management.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:max-w-2xl lg:ml-auto">
                            <HeroClusterTile label="Total" value={fmt(hero.total)} caption="in the fleet" tone="neutral" />
                            <HeroClusterTile
                                label="Available"
                                value={fmt(hero.available)}
                                caption="ready to book"
                                tone={hero.available > 0 ? 'success' : 'warning'}
                            />
                            <HeroClusterTile
                                href="/fleet-assets/bookings"
                                label="In use"
                                value={fmt(hero.in_use)}
                                caption="checked out"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/maintenance/work-orders"
                                label="Maintenance"
                                value={fmt(hero.maintenance)}
                                caption="in the workshop"
                                tone={hero.maintenance > 0 ? 'warning' : 'success'}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction href="/fleet-assets/bookings?new=1" icon={Bookmark} emphasis>
                            Book vehicle
                        </FleetHeroAction>
                        <FleetHeroAction href="/fleet-assets/daily-check" icon={ClipboardCheck}>
                            Daily check
                        </FleetHeroAction>
                        <FleetHeroAction href="/fleet-assets/vehicles?export=csv" icon={Download} external>
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {vehicles.length === 0 && !searchTerm && statusFilter === 'all' ? (
                    <FleetEmptyState icon={Car} title="No vehicles tracked yet" description="Add vehicles to start fleet tracking. Vehicles with GPS trackers will appear on the map in real time." actionLabel="Add Vehicle" actionHref="/fleet-assets/assets?new=1&category=vehicle" />
                ) : (
                    <>
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
                                {canManage && (
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            checked={filteredVehicles.length > 0 && selectedIds.length === filteredVehicles.length}
                                            onChange={toggleSelectAll}
                                            className="h-3.5 w-3.5 rounded border-border"
                                        />
                                        <span>Select all</span>
                                    </div>
                                )}

                                <div className="space-y-2" style={{ maxHeight: '420px', overflowY: 'auto' }}>
                                    {filteredVehicles.length > 0 ? (
                                        filteredVehicles.map((vehicle) => (
                                            <div key={vehicle.id} className={`flex items-start ${canManage ? 'gap-2' : ''}`}>
                                                {canManage && (
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.includes(vehicle.id)}
                                                        onChange={() => toggleSelect(vehicle.id)}
                                                        className="mt-3.5 h-3.5 w-3.5 rounded border-border"
                                                    />
                                                )}
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
                        {canManage && selectedIds.length > 0 && (
                            <article className="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg">
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
                            </article>
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
