import LeafletMap, { MapGeofence } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router } from '@inertiajs/react';
import {
    Circle,
    Edit,
    Filter,
    Layers,
    MapPin,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import {
    GeofenceWizard,
    type GeofenceAssetOption,
    type GeofenceSiteOption,
} from './create';

type Geofence = {
    id: number;
    name: string;
    type: 'circle' | 'polygon';
    scope: 'vehicle' | 'resident';
    breach_type: string;
    is_active: boolean;
    shape: {
        type: 'circle' | 'polygon';
        center?: { lat: number; lng: number };
        radius_m?: number;
        coordinates?: { lat: number; lng: number }[];
    } | null;
    time_rules: Record<string, any> | null;
    alert_config: {
        on_enter?: boolean;
        on_exit?: boolean;
        on_speed?: boolean;
        severity?: string;
        notify_control_room?: boolean;
    } | null;
    asset_id: number | null;
    site_id: number | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
    site: { id: number; name: string } | null;
};

type Props = {
    hero?: {
        active: number;
        vehicles_covered: number;
        breaches_7d: number;
    };
    geofences: Geofence[];
    sites: GeofenceSiteOption[];
    assets: GeofenceAssetOption[];
    filters: {
        status: string;
        type: string;
        site_id: string;
    };
};

const GEOFENCE_COLORS = [
    '#ef4444',
    '#3b82f6',
    '#22c55e',
    '#f59e0b',
    '#8b5cf6',
    '#ec4899',
    '#14b8a6',
    '#f97316',
    '#6366f1',
    '#84cc16',
];

export default function GeofencesIndex({
    hero: rawHero,
    geofences,
    sites,
    assets,
    filters,
}: Props) {
    const hero = rawHero ?? { active: 0, vehicles_covered: 0, breaches_7d: 0 };
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [showFilters, setShowFilters] = useState(
        !!(filters?.status || filters?.type || filters?.site_id),
    );
    const searchParams = useMemo(
        () =>
            new URLSearchParams(
                typeof window === 'undefined' ? '' : window.location.search,
            ),
        [],
    );
    const editingId = Number(searchParams.get('edit')) || null;
    const editingGeofence =
        (geofences ?? []).find((geofence) => geofence.id === editingId) ?? null;
    const wizardOpen = searchParams.get('new') === '1' || !!editingGeofence;
    const closeWizard = () => {
        router.get('/fleet-assets/geofences', filters, {
            preserveScroll: true,
        });
    };

    // Assign colors to geofences
    const colorMap = useMemo(() => {
        const map = new Map<number, string>();
        (geofences ?? []).forEach((gf, i) => {
            map.set(
                gf.id,
                GEOFENCE_COLORS[i % GEOFENCE_COLORS.length] ?? '#ef4444',
            );
        });
        return map;
    }, [geofences]);

    // Filter geofences locally by search query
    const filteredGeofences = useMemo(() => {
        if (!searchQuery.trim()) return geofences ?? [];
        const q = searchQuery.toLowerCase();
        return (geofences ?? []).filter(
            (gf) =>
                gf.name.toLowerCase().includes(q) ||
                gf.site?.name?.toLowerCase().includes(q) ||
                gf.asset?.name?.toLowerCase().includes(q),
        );
    }, [geofences, searchQuery]);

    const mapGeofences = useMemo<MapGeofence[]>(() => {
        return filteredGeofences.map((gf) => ({
            id: gf.id,
            name: gf.name,
            type: gf.type,
            center: gf.shape?.center,
            radius_m: gf.shape?.radius_m,
            coordinates: gf.shape?.coordinates,
            color:
                selectedId === gf.id
                    ? '#fbbf24'
                    : gf.is_active
                      ? (colorMap.get(gf.id) ?? '#ef4444')
                      : '#9ca3af',
        }));
    }, [filteredGeofences, selectedId, colorMap]);

    const mapCenter = useMemo(() => {
        if (selectedId) {
            const gf = (geofences ?? []).find((g) => g.id === selectedId);
            if (gf?.shape?.center)
                return { lat: gf.shape.center.lat, lng: gf.shape.center.lng };
            if (gf?.shape?.coordinates?.length) {
                const coords = gf.shape.coordinates;
                const avgLat =
                    coords.reduce((s, c) => s + c.lat, 0) / coords.length;
                const avgLng =
                    coords.reduce((s, c) => s + c.lng, 0) / coords.length;
                return { lat: avgLat, lng: avgLng };
            }
        }
        const first = (geofences ?? []).find((g) => g.shape?.center?.lat);
        if (first?.shape?.center)
            return { lat: first.shape.center.lat, lng: first.shape.center.lng };
        return { lat: -36.8485, lng: 174.7633 };
    }, [geofences, selectedId]);

    const mapZoom = useMemo(() => {
        if (selectedId) return 14;
        return 11;
    }, [selectedId]);

    const handleFilterChange = useCallback(
        (key: string, value: string) => {
            router.get(
                '/fleet-assets/geofences',
                {
                    ...filters,
                    [key]: value || undefined,
                },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const handleToggle = useCallback((gf: Geofence) => {
        router.post(
            `/fleet-assets/geofences/${gf.id}/toggle`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    }, []);

    const handleDelete = useCallback((gf: Geofence) => {
        router.delete(`/fleet-assets/geofences/${gf.id}`, {
            preserveScroll: true,
        });
    }, []);

    const formatRadius = (meters: number) => {
        if (meters >= 1000) return `${(meters / 1000).toFixed(1)}km`;
        return `${Math.round(meters)}m`;
    };

    const activeCount = (geofences ?? []).filter((g) => g.is_active).length;
    const inactiveCount = (geofences ?? []).length - activeCount;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Geofences', href: '/fleet-assets/geofences' },
            ]}
        >
            <Head title="Geofences" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={MapPin} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Geofence register · live evaluation
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Geofences
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                {`${(geofences ?? []).length} geofence${(geofences ?? []).length !== 1 ? 's' : ''} configured. ${activeCount} active, ${inactiveCount} inactive.`}
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-3 gap-2 lg:ml-auto lg:max-w-xl">
                            <HeroClusterTile
                                href="/fleet-assets/geofences?status=active"
                                label="Active geofences"
                                value={fmt(hero.active)}
                                caption="being evaluated"
                                tone={hero.active > 0 ? 'success' : 'neutral'}
                            />
                            <HeroClusterTile
                                label="Vehicles covered"
                                value={fmt(hero.vehicles_covered)}
                                caption="inside an active zone rule"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/alerts"
                                label="Breaches 7d"
                                value={fmt(hero.breaches_7d)}
                                caption="exit signals this week"
                                tone={
                                    hero.breaches_7d > 0 ? 'warning' : 'success'
                                }
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction
                            href="/fleet-assets/geofences?new=1"
                            icon={Plus}
                            emphasis
                        >
                            Create geofence
                        </FleetHeroAction>
                        {/* eslint-disable-next-line no-restricted-syntax -- onDark filters toggle in the hero action row */}
                        <button
                            type="button"
                            onClick={() => setShowFilters(!showFilters)}
                            aria-pressed={showFilters}
                            className="inline-flex h-[34px] items-center gap-2 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                        >
                            <Filter className="h-[15px] w-[15px]" />
                            Filters
                        </button>
                    </div>
                </HeroShell>

                {/* Filters */}
                {showFilters && (
                    <Card className="mb-4">
                        <CardContent className="py-3">
                            <div className="grid gap-3 sm:grid-cols-4">
                                <div>
                                    <div className="relative">
                                        <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                        <Input
                                            placeholder="Search geofences..."
                                            value={searchQuery}
                                            onChange={(e) =>
                                                setSearchQuery(e.target.value)
                                            }
                                            className="pl-8"
                                        />
                                    </div>
                                </div>
                                <Select
                                    value={filters?.status ?? ''}
                                    onValueChange={(v) =>
                                        handleFilterChange(
                                            'status',
                                            v === 'all' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Statuses
                                        </SelectItem>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="inactive">
                                            Inactive
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={filters?.type ?? ''}
                                    onValueChange={(v) =>
                                        handleFilterChange(
                                            'type',
                                            v === 'all' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Types
                                        </SelectItem>
                                        <SelectItem value="circle">
                                            Circle
                                        </SelectItem>
                                        <SelectItem value="polygon">
                                            Polygon
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={filters?.site_id ?? ''}
                                    onValueChange={(v) =>
                                        handleFilterChange(
                                            'site_id',
                                            v === 'all' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Sites
                                        </SelectItem>
                                        {(sites ?? []).map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Split Layout: Map + List */}
                <div className="grid gap-4 lg:grid-cols-[3fr,2fr]">
                    {/* Map */}
                    <div>
                        <LeafletMap
                            center={mapCenter}
                            zoom={mapZoom}
                            geofences={mapGeofences}
                            height={550}
                        />
                    </div>

                    {/* List */}
                    <div
                        className="space-y-2"
                        style={{ maxHeight: '550px', overflowY: 'auto' }}
                    >
                        {filteredGeofences.length > 0 ? (
                            filteredGeofences.map((gf) => (
                                <div
                                    key={gf.id}
                                    className={`cursor-pointer rounded-lg border p-3 text-sm transition-colors ${
                                        selectedId === gf.id
                                            ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                            : 'hover:bg-muted/50'
                                    }`}
                                    onClick={() =>
                                        setSelectedId(
                                            selectedId === gf.id ? null : gf.id,
                                        )
                                    }
                                >
                                    {/* Header row */}
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex min-w-0 items-center gap-2">
                                            <div
                                                className="h-3 w-3 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        colorMap.get(gf.id) ??
                                                        '#ef4444',
                                                }}
                                            />
                                            <span className="truncate font-semibold">
                                                {gf.name}
                                            </span>
                                        </div>
                                        <Badge
                                            variant={
                                                gf.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                            className="shrink-0"
                                        >
                                            {gf.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </div>

                                    {/* Type + Shape info */}
                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                        <Badge
                                            variant="outline"
                                            className="text-xs"
                                        >
                                            {gf.type === 'circle' ? (
                                                <>
                                                    <Circle className="mr-1 h-3 w-3" />
                                                    Circle
                                                </>
                                            ) : (
                                                <>
                                                    <Pencil className="mr-1 h-3 w-3" />
                                                    Polygon
                                                </>
                                            )}
                                        </Badge>
                                        {gf.type === 'circle' &&
                                            gf.shape?.radius_m && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-xs"
                                                >
                                                    {formatRadius(
                                                        gf.shape.radius_m,
                                                    )}
                                                </Badge>
                                            )}
                                        {gf.type === 'polygon' &&
                                            gf.shape?.coordinates && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-xs"
                                                >
                                                    {
                                                        gf.shape.coordinates
                                                            .length
                                                    }{' '}
                                                    points
                                                </Badge>
                                            )}
                                        <Badge
                                            variant="outline"
                                            className="text-xs"
                                        >
                                            {gf.breach_type}
                                        </Badge>
                                    </div>

                                    {/* Links */}
                                    <div className="mt-1.5 space-y-0.5 text-xs text-muted-foreground">
                                        {gf.site && (
                                            <div>
                                                Site:{' '}
                                                <span className="text-foreground">
                                                    {gf.site.name}
                                                </span>
                                            </div>
                                        )}
                                        {gf.asset && (
                                            <div>
                                                Asset:{' '}
                                                <Link
                                                    href={`/fleet-assets/assets/${gf.asset.id}`}
                                                    className="text-primary hover:underline"
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    {gf.asset.name}
                                                </Link>
                                            </div>
                                        )}
                                    </div>

                                    {/* Alert config summary */}
                                    {gf.alert_config && (
                                        <div className="mt-1.5 flex flex-wrap gap-1">
                                            {gf.alert_config.severity && (
                                                <Badge
                                                    variant="outline"
                                                    className={`text-xs ${
                                                        gf.alert_config
                                                            .severity ===
                                                        'critical'
                                                            ? 'border-status-critical/30 text-status-critical'
                                                            : gf.alert_config
                                                                    .severity ===
                                                                'high'
                                                              ? 'border-status-warning/30 text-status-warning'
                                                              : gf.alert_config
                                                                      .severity ===
                                                                  'medium'
                                                                ? 'border-status-warning/30 text-status-warning'
                                                                : 'border-status-success/30 text-status-success'
                                                    }`}
                                                >
                                                    {gf.alert_config.severity}
                                                </Badge>
                                            )}
                                            {gf.alert_config
                                                .notify_control_room && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-info/30 text-xs text-status-info"
                                                >
                                                    Control Room
                                                </Badge>
                                            )}
                                        </div>
                                    )}

                                    {/* Actions */}
                                    <div
                                        className="mt-2 flex items-center gap-1"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 text-xs"
                                            asChild
                                        >
                                            <Link
                                                href={`/fleet-assets/geofences?edit=${gf.id}`}
                                            >
                                                <Edit className="mr-1 h-3 w-3" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => handleToggle(gf)}
                                        >
                                            {gf.is_active ? (
                                                <>
                                                    <PowerOff className="mr-1 h-3 w-3" />
                                                    Disable
                                                </>
                                            ) : (
                                                <>
                                                    <Power className="mr-1 h-3 w-3" />
                                                    Enable
                                                </>
                                            )}
                                        </Button>
                                        <AlertDialog>
                                            <AlertDialogTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 text-xs text-destructive hover:text-destructive"
                                                >
                                                    <Trash2 className="mr-1 h-3 w-3" />
                                                    Delete
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>
                                                        Delete Geofence
                                                    </AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        Are you sure you want to
                                                        delete "{gf.name}"? This
                                                        action cannot be undone.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>
                                                        Cancel
                                                    </AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={() =>
                                                            handleDelete(gf)
                                                        }
                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                    >
                                                        Delete
                                                    </AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="rounded-lg border p-8 text-center">
                                <Layers className="mx-auto h-12 w-12 text-muted-foreground/50" />
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {searchQuery
                                        ? 'No geofences match your search.'
                                        : 'No geofences configured.'}
                                </p>
                                <Button asChild className="mt-4" size="sm">
                                    <Link href="/fleet-assets/geofences?new=1">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Create Geofence
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </PageShell>
            <GeofenceWizard
                open={wizardOpen}
                assets={assets ?? []}
                sites={sites ?? []}
                prefillSiteId={searchParams.get('site_id')}
                geofence={editingGeofence}
                onClose={closeWizard}
            />
        </AppLayout>
    );
}
