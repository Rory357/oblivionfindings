import { FleetEmptyState } from '@/components/fleet-empty-state';
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
import { TabsRoot as Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import {
    AssetWizardDialog,
    type AssetWizardPrefill,
} from '@/pages/fleet-assets/assets/components/asset-wizard-dialog';
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
    Download,
    LockKeyhole,
    MapPin,
    Package,
    Plus,
    Search,
    Wifi,
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
    tracker_count: number | null;
};

type Props = {
    hero: {
        total: number;
        active: number;
        maintenance: number;
        inspections_due: number;
    };
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
    clients?: Array<{
        id: number;
        first_name: string;
        last_name: string;
        site_id?: number | null;
    }>;
    prefill?: AssetWizardPrefill | null;
    created_asset_id?: number | null;
};

export function AssetTechnologySummary({ count }: { count: number | null }) {
    if (count === null) {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <LockKeyhole className="h-3 w-3" />
                Technology restricted
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <Wifi
                className={`h-3 w-3 ${count > 0 ? 'text-status-success' : 'text-muted-foreground'}`}
            />
            {count > 0
                ? `${count} linked ${count === 1 ? 'device' : 'devices'}`
                : 'No linked devices'}
        </span>
    );
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
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
            return 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info';
        case 'equipment':
            return 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning';
        case 'property':
            return 'bg-primary/10 text-primary dark:bg-primary dark:text-primary/70';
        default:
            return 'bg-muted text-foreground dark:bg-muted dark:text-muted-foreground';
    }
}

export default function AssetsIndex({
    hero,
    assets,
    filters,
    sites,
    categories,
    clients,
    prefill,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    // /fleet-assets/assets/create now redirects here with ?new=1 — open the
    // create wizard on mount (the hero action links to the same shim).
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );

    const closeWizard = () => {
        setWizardOpen(false);
        // Strip the shim params so a refresh doesn't reopen the wizard.
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.delete('new');
            url.searchParams.delete('created');
            window.history.replaceState({}, '', url);
        }
    };

    const allAssets = assets?.data ?? [];

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get(
            '/fleet-assets/assets',
            {
                ...filters,
                ...newFilters,
                page: 1,
            },
            { preserveState: true },
        );
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
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Package} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Asset register · live
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Assets
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Manage all organisational assets including
                                vehicles, equipment, and property.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href="/fleet-assets/assets"
                                label="Total assets"
                                value={fmt(hero.total)}
                                caption="all registered"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/assets?status=active"
                                label="Active"
                                value={fmt(hero.active)}
                                caption="currently in use"
                                tone={hero.active > 0 ? 'success' : 'neutral'}
                            />
                            <HeroClusterTile
                                href="/fleet-assets/assets?status=out_of_service"
                                label="In maintenance"
                                value={fmt(hero.maintenance)}
                                caption="out of service"
                                tone={
                                    hero.maintenance > 0 ? 'warning' : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/inspections"
                                label="Inspections due"
                                value={fmt(hero.inspections_due)}
                                caption="within 30 days"
                                tone={
                                    hero.inspections_due > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction
                            href="/fleet-assets/assets?new=1"
                            icon={Plus}
                            emphasis
                        >
                            New asset
                        </FleetHeroAction>
                        <FleetHeroAction
                            href="/fleet-assets/assets?export=csv"
                            icon={Download}
                            external
                        >
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* Category Tabs */}
                <Tabs
                    value={filters.category || 'all'}
                    onValueChange={(value) =>
                        applyFilters({ category: value === 'all' ? '' : value })
                    }
                >
                    <TabsList className="h-8">
                        <TabsTrigger value="all" className="px-3 py-1 text-xs">
                            All
                        </TabsTrigger>
                        <TabsTrigger
                            value="vehicle"
                            className="px-3 py-1 text-xs"
                        >
                            Vehicles
                        </TabsTrigger>
                        <TabsTrigger
                            value="equipment"
                            className="px-3 py-1 text-xs"
                        >
                            Equipment
                        </TabsTrigger>
                        <TabsTrigger
                            value="property"
                            className="px-3 py-1 text-xs"
                        >
                            Property
                        </TabsTrigger>
                        <TabsTrigger
                            value="other"
                            className="px-3 py-1 text-xs"
                        >
                            Other
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                {/* Filters Row */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search assets..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && handleSearch()
                            }
                            className="pl-9"
                        />
                    </div>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(value) =>
                            applyFilters({
                                status: value === 'all' ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="out_of_service">
                                Out of Service
                            </SelectItem>
                            <SelectItem value="retired">Retired</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.site_id || 'all'}
                        onValueChange={(value) =>
                            applyFilters({
                                site_id: value === 'all' ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Site" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All sites</SelectItem>
                            {(sites ?? []).map((site) => (
                                <SelectItem
                                    key={site.id}
                                    value={String(site.id)}
                                >
                                    {site.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Asset Tile Grid */}
                {allAssets.length ? (
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
                                            <span className="text-sm font-semibold">
                                                {asset.name}
                                            </span>
                                            {asset.asset_tag && (
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono text-xs"
                                                >
                                                    {asset.asset_tag}
                                                </Badge>
                                            )}
                                        </div>
                                        {asset.manufacturer && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {asset.manufacturer}
                                                {asset.model
                                                    ? ` ${asset.model}`
                                                    : ''}
                                            </p>
                                        )}
                                    </div>
                                    <Badge
                                        variant={statusVariant(asset.status)}
                                        className="shrink-0"
                                    >
                                        {asset.status.replace(/_/g, ' ')}
                                    </Badge>
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <span
                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${categoryColor(asset.category)}`}
                                    >
                                        {asset.category}
                                    </span>
                                    {asset.site && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <MapPin className="h-3 w-3" />
                                            {asset.site.name}
                                        </span>
                                    )}
                                    <AssetTechnologySummary
                                        count={asset.tracker_count}
                                    />
                                </div>
                                {asset.serial_number && (
                                    <p className="mt-2 text-[10px] text-muted-foreground">
                                        S/N: {asset.serial_number}
                                    </p>
                                )}
                            </Link>
                        ))}
                    </div>
                ) : (
                    <FleetEmptyState
                        icon={Package}
                        title="No assets found"
                        description="Add assets to start tracking."
                        actionLabel="Create Asset"
                        actionHref="/fleet-assets/assets?new=1"
                    />
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

                <AssetWizardDialog
                    open={wizardOpen}
                    onClose={closeWizard}
                    sites={sites ?? []}
                    clients={clients}
                    prefill={prefill}
                />
            </PageShell>
        </AppLayout>
    );
}
