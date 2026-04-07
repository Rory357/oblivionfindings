import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
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
import {
    TabsRoot as Tabs,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Download,
    MapPin,
    Package,
    Plus,
    Search,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useState } from 'react';

type Asset = {
    id: number;
    name: string;
    asset_tag: string;
    category: string;
    status: string;
    category_ref: { id: number; name: string; slug: string } | null;
    site: { id: number; name: string } | null;
    home_site: { id: number; name: string } | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    tracker_count: number;
};

type Props = {
    assets: {
        data: Asset[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
        };
    };
    filters: {
        category: string;
        status: string;
        site_id: string;
        search: string;
    };
    sites: Array<{ id: number; name: string }>;
    categories: string[];
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'out_of_service':
            return 'destructive';
        case 'retired':
            return 'secondary';
        default:
            return 'outline';
    }
}

function categoryColor(category: string): string {
    switch (category) {
        case 'vehicle':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
        case 'equipment':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
        case 'property':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
    }
}

export default function AssetsIndex({ assets, filters, sites, categories }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const allAssets = assets?.data ?? [];
    const totalAssets = assets?.meta?.total ?? allAssets.length;
    const activeCount = allAssets.filter((a) => a.status === 'active').length;
    const maintenanceCount = allAssets.filter((a) => a.status === 'out_of_service').length;
    const offlineCount = allAssets.filter((a) => a.status === 'retired').length;

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get('/fleet-assets/assets', {
            ...filters,
            ...newFilters,
            page: 1,
        }, { preserveState: true });
    };

    const handleSearch = () => {
        applyFilters({ search });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Assets', href: '/fleet-assets/assets' },
            ]}
        >
            <Head title="Assets" />
            <PageShell>
                <FleetHero
                    title="Assets"
                    description="Manage all organisational assets including vehicles, equipment, and property."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href="/fleet-assets/assets?export=csv">
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </a>
                            </Button>
                            <Button asChild>
                                <Link href="/fleet-assets/assets/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create Asset
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Dark KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="TOTAL ASSETS" value={totalAssets} icon={Package} subtitle="All registered assets" />
                    <FleetStatCard label="ACTIVE" value={activeCount} icon={Activity} subtitle="Currently in use" />
                    <FleetStatCard label="IN MAINTENANCE" value={maintenanceCount} icon={AlertTriangle} subtitle="Out of service" />
                    <FleetStatCard label="OFFLINE" value={offlineCount} icon={WifiOff} subtitle="Retired assets" />
                </div>

                {/* Category Tabs */}
                <Tabs
                    value={filters.category || 'all'}
                    onValueChange={(value) => applyFilters({ category: value === 'all' ? '' : value })}
                >
                    <TabsList className="h-8">
                        <TabsTrigger value="all" className="text-xs px-3 py-1">All</TabsTrigger>
                        <TabsTrigger value="vehicle" className="text-xs px-3 py-1">Vehicles</TabsTrigger>
                        <TabsTrigger value="equipment" className="text-xs px-3 py-1">Equipment</TabsTrigger>
                        <TabsTrigger value="property" className="text-xs px-3 py-1">Property</TabsTrigger>
                        <TabsTrigger value="other" className="text-xs px-3 py-1">Other</TabsTrigger>
                    </TabsList>
                </Tabs>

                {/* Filters Row */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search assets..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="pl-9"
                        />
                    </div>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="out_of_service">Out of Service</SelectItem>
                            <SelectItem value="retired">Retired</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.site_id || 'all'}
                        onValueChange={(value) => applyFilters({ site_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Site" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All sites</SelectItem>
                            {(sites ?? []).map((site) => (
                                <SelectItem key={site.id} value={String(site.id)}>
                                    {site.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Asset Tile Grid */}
                {(allAssets).length ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {allAssets.map((asset) => (
                            <Link
                                key={asset.id}
                                href={`/fleet-assets/assets/${asset.id}`}
                                className="group flex flex-col rounded-lg border p-4 transition-all hover:bg-muted/50 hover:shadow-md"
                            >
                                <div className="flex items-start justify-between">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">{asset.name}</span>
                                            {asset.asset_tag && (
                                                <Badge variant="outline" className="font-mono text-xs">
                                                    {asset.asset_tag}
                                                </Badge>
                                            )}
                                        </div>
                                        {asset.manufacturer && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {asset.manufacturer}{asset.model ? ` ${asset.model}` : ''}
                                            </p>
                                        )}
                                    </div>
                                    <Badge variant={statusVariant(asset.status)} className="shrink-0">
                                        {asset.status.replace(/_/g, ' ')}
                                    </Badge>
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${categoryColor(asset.category)}`}>
                                        {asset.category}
                                    </span>
                                    {asset.site && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <MapPin className="h-3 w-3" />
                                            {asset.site.name}
                                        </span>
                                    )}
                                    {asset.tracker_count != null && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Wifi className={`h-3 w-3 ${asset.tracker_count > 0 ? 'text-green-500' : 'text-gray-400'}`} />
                                            {asset.tracker_count > 0 ? `${asset.tracker_count} tracker(s)` : 'No trackers'}
                                        </span>
                                    )}
                                </div>
                                {asset.serial_number && (
                                    <p className="mt-2 text-[10px] text-muted-foreground">S/N: {asset.serial_number}</p>
                                )}
                            </Link>
                        ))}
                    </div>
                ) : (
                    <FleetEmptyState icon={Package} title="No assets found" description="Add assets to start tracking." actionLabel="Create Asset" actionHref="/fleet-assets/assets/create" />
                )}

                {/* Pagination */}
                {(assets?.meta?.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {(assets?.links ?? []).map((link, i) => (
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
            </PageShell>
        </AppLayout>
    );
}
