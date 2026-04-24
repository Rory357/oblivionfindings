import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    Cctv,
    HeartPulse,
    Key,
    type LucideIcon,
    MonitorOff,
    Plus,
    Search,
    Server,
    Siren,
    Smartphone,
} from 'lucide-react';
import { useState } from 'react';

import {
    type DeviceListItem,
    type FilterOption,
    type Paginated,
    DeviceCard,
    FilterSelect,
    StatCard,
} from './devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type CategoryStats = {
    total: number;
    active: number;
    offline: number;
    attention: number;
    bySubcategory: Record<string, number>;
};

type PageConfig = {
    slug: string;
    title: string;
    description: string;
    emptyTitle: string;
    emptyDescription: string;
    icon: string;
    domain: string;
    categories: string[] | null;
};

type Props = {
    devices: Paginated<DeviceListItem>;
    stats: CategoryStats;
    filters: Record<string, string>;
    filterOptions: {
        subcategories: FilterOption[];
        categories: FilterOption[] | null;
        statuses: FilterOption[];
        healthStatuses: FilterOption[];
        providers: string[];
    };
    pageConfig: PageConfig;
};

// ── Icon map ──────────────────────────────────────────────────────

const iconMap: Record<string, LucideIcon> = {
    'alarms': Siren,
    'cctv': Cctv,
    'access-control': Key,
    'tracking-devices': Smartphone,
    'smart-iot-healthcare': HeartPulse,
    'it-infrastructure': Server,
    'facilities': Building2,
};

function singularizeTitle(title: string): string {
    if (title.endsWith('ies')) {
        return `${title.slice(0, -3)}y`;
    }

    if (title.endsWith('s')) {
        return title.slice(0, -1);
    }

    return title;
}

// ── Component ─────────────────────────────────────────────────────

export default function CategoryPage({ devices, stats, filters, filterOptions, pageConfig }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const PageIcon = iconMap[pageConfig.icon] ?? Server;
    const pageUrl = `/security-devices/${pageConfig.slug}`;
    const registerLabel = `Register ${singularizeTitle(pageConfig.title)}`;

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get(
            pageUrl,
            { ...filters, ...newFilters, page: '1' },
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        router.get(pageUrl, {}, { preserveState: true });
        setSearch('');
    };

    const hasActiveFilters = Object.values(filters).some((v) => v && v !== 'all');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: pageConfig.title, href: pageUrl },
            ]}
        >
            <Head title={`${pageConfig.title} - Security & Devices`} />

            <PageShell>
                <PageHeader
                    title={
                        <span className="flex items-center gap-3">
                            <PageIcon className="h-6 w-6 text-primary" />
                            {pageConfig.title}
                        </span>
                    }
                    description={pageConfig.description}
                    actions={
                        <Button asChild size="sm">
                            <Link href={`/security-devices/devices/create?domain=${pageConfig.domain}`}>
                                <Plus className="mr-2 h-4 w-4" />
                                {registerLabel}
                            </Link>
                        </Button>
                    }
                />

                {/* Stats cards */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label={`Total ${pageConfig.title}`} value={stats.total} icon={PageIcon} />
                    <StatCard label="Active" value={stats.active} icon={Activity} />
                    <StatCard label="Offline" value={stats.offline} icon={MonitorOff} />
                    <StatCard label="Needing Attention" value={stats.attention} icon={AlertTriangle} variant={stats.attention > 0 ? 'warning' : 'default'} />
                </div>

                {/* Subcategory chips */}
                {filterOptions.subcategories.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={() => applyFilters({ subcategory: 'all' })}
                            className={`h-auto rounded-full px-3 py-1 ${
                                !filters.subcategory || filters.subcategory === 'all'
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                            }`}
                        >
                            All
                            <span className="ml-1 text-muted-foreground">({stats.total})</span>
                        </Button>
                        {filterOptions.subcategories.map((sub) => {
                            const count = stats.bySubcategory[sub.value] ?? 0;
                            const isActive = filters.subcategory === sub.value;
                            return (
                                <Button
                                    key={sub.value}
                                    type="button"
                                    variant="outline"
                                    size="xs"
                                    onClick={() => applyFilters({ subcategory: sub.value })}
                                    className={`h-auto rounded-full px-3 py-1 ${
                                        isActive
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                    }`}
                                >
                                    {sub.label}
                                    {count > 0 && <span className="ml-1 text-muted-foreground/70">({count})</span>}
                                </Button>
                            );
                        })}
                    </div>
                )}

                {/* Category filter (for multi-category or whole-domain pages) */}
                {filterOptions.categories && filterOptions.categories.length > 1 && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={() => applyFilters({ category: 'all' })}
                            className={`h-auto rounded-full px-3 py-1 ${
                                !filters.category || filters.category === 'all'
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                            }`}
                        >
                            All categories
                        </Button>
                        {filterOptions.categories.map((cat) => (
                            <Button
                                key={cat.value}
                                type="button"
                                variant="outline"
                                size="xs"
                                onClick={() => applyFilters({ category: cat.value })}
                                className={`h-auto rounded-full px-3 py-1 ${
                                    filters.category === cat.value
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                }`}
                            >
                                {cat.label}
                            </Button>
                        ))}
                    </div>
                )}

                {/* Filter bar */}
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder={`Search ${pageConfig.title.toLowerCase()}...`}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                            className="pl-9"
                        />
                    </div>

                    <FilterSelect
                        value={filters.status}
                        onChange={(v) => applyFilters({ status: v })}
                        placeholder="Status"
                        options={filterOptions.statuses}
                    />
                    <FilterSelect
                        value={filters.health}
                        onChange={(v) => applyFilters({ health: v })}
                        placeholder="Health"
                        options={filterOptions.healthStatuses}
                    />
                    {filterOptions.providers.length > 0 && (
                        <FilterSelect
                            value={filters.provider}
                            onChange={(v) => applyFilters({ provider: v })}
                            placeholder="Provider"
                            options={filterOptions.providers.map((p: string) => ({ value: p, label: p }))}
                        />
                    )}
                    <FilterSelect
                        value={filters.assigned}
                        onChange={(v) => applyFilters({ assigned: v })}
                        placeholder="Assignment"
                        options={[
                            { value: 'yes', label: 'Assigned' },
                            { value: 'no', label: 'Unassigned' },
                        ]}
                    />

                    {hasActiveFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </div>

                {/* Results */}
                {devices.data.length > 0 ? (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {devices.data.map((device) => (
                            <DeviceCard key={device.id} device={device} />
                        ))}
                    </div>
                ) : hasActiveFilters ? (
                    <EmptySearch
                        onClear={clearFilters}
                        searchTerm={filters.search}
                        title={`No matching ${pageConfig.title.toLowerCase()} found`}
                    />
                ) : (
                    <EmptyState
                        icon={PageIcon}
                        title={pageConfig.emptyTitle}
                        description={pageConfig.emptyDescription}
                        action={
                            <Button asChild size="sm">
                                <Link href={`/security-devices/devices/create?domain=${pageConfig.domain}`}>
                                    {registerLabel}
                                </Link>
                            </Button>
                        }
                    />
                )}

                {/* Pagination */}
                {(devices.meta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {devices.links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
