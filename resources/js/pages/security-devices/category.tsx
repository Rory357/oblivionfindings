import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    BulkManagementWorkspace,
    type BulkManagementWorkspaceData,
} from '@/components/security-devices/bulk-management-workspace';
import {
    type FacilitiesWorkspaceData,
    FacilitiesWorkspacePanels,
} from '@/components/security-devices/facilities-workspace';
import {
    type HealthcareWorkspaceData,
    HealthcareWorkspacePanels,
} from '@/components/security-devices/healthcare-workspace';
import {
    type NetworkItWorkspaceData,
    NetworkItWorkspacePanels,
} from '@/components/security-devices/network-it-workspace';
import {
    type SecurityDevicesWorkspace,
    SecurityDevicesWorkspaceShell,
    WorkspaceDeviceList,
    WorkspaceFilterBar,
} from '@/components/security-devices/security-devices-workspace-shell';
import {
    type SecurityWorkspaceData,
    SecurityWorkspacePanels,
} from '@/components/security-devices/security-workspace';
import {
    type TrackingWorkspaceData,
    TrackingWorkspacePanels,
} from '@/components/security-devices/tracking-workspace';
import { Button } from '@/components/ui/button';
import { EmptySearch, EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Building2,
    Cctv,
    HeartPulse,
    Key,
    type LucideIcon,
    Plus,
    Search,
    Server,
    Siren,
    Smartphone,
} from 'lucide-react';
import { useState } from 'react';

import {
    DeviceCard,
    type DeviceListItem,
    type FilterOption,
    FilterSelect,
    type Paginated,
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
    workspace: SecurityDevicesWorkspace;
    facilitiesWorkspace?: FacilitiesWorkspaceData | null;
    networkItWorkspace?: NetworkItWorkspaceData | null;
    securityWorkspace?: SecurityWorkspaceData | null;
    healthcareWorkspace?: HealthcareWorkspaceData | null;
    trackingWorkspace?: TrackingWorkspaceData | null;
    bulkManagement?: BulkManagementWorkspaceData | null;
};

// ── Icon map ──────────────────────────────────────────────────────

const iconMap: Record<string, LucideIcon> = {
    'network-it': Server,
    security: Cctv,
    healthcare: HeartPulse,
    tracking: Smartphone,
    'facilities-iot': Building2,
    alarms: Siren,
    cctv: Cctv,
    'access-control': Key,
    'tracking-devices': Smartphone,
    'smart-iot-healthcare': HeartPulse,
    'it-infrastructure': Server,
    facilities: Building2,
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

type CategorySearchInputProps = {
    title: string;
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
};

export function CategorySearchInput({
    title,
    value,
    onChange,
    onSubmit,
}: CategorySearchInputProps) {
    const accessibleName = `Search ${title.toLowerCase()}`;

    return (
        <Input
            aria-label={accessibleName}
            placeholder={`${accessibleName}...`}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && onSubmit()}
            className="pl-9"
        />
    );
}

// ── Component ─────────────────────────────────────────────────────

export default function CategoryPage({
    devices,
    stats,
    filters,
    filterOptions,
    pageConfig,
    workspace,
    facilitiesWorkspace,
    networkItWorkspace,
    securityWorkspace,
    healthcareWorkspace,
    trackingWorkspace,
    bulkManagement,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const PageIcon = iconMap[pageConfig.icon] ?? Server;
    const pageUrl = workspace.canonicalHref;
    const registerLabel =
        pageConfig.slug.includes('-') ||
        ['security', 'healthcare', 'tracking'].includes(pageConfig.slug)
            ? 'Register device'
            : `Register ${singularizeTitle(pageConfig.title)}`;

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get(
            pageUrl,
            { ...filters, ...newFilters, page: '1' },
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        router.get(
            pageUrl,
            {
                tab: workspace.activeTab,
                ...(filters.device_id ? { device_id: filters.device_id } : {}),
            },
            { preserveState: true },
        );
        setSearch('');
    };

    const hasActiveFilters = Object.entries(filters).some(
        ([key, value]) =>
            !['tab', 'device_id'].includes(key) && value && value !== 'all',
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: pageConfig.title, href: pageUrl },
            ]}
        >
            <Head title={`${pageConfig.title} - Security & Devices`} />

            <PageShell>
                <PageHero
                    variant="compact"
                    title={
                        <span className="flex items-center gap-3">
                            <PageIcon className="h-6 w-6 text-primary" />
                            {pageConfig.title}
                        </span>
                    }
                    description={pageConfig.description}
                    actions={
                        <Button asChild size="sm">
                            <Link
                                href={`/security-devices/devices/create?domain=${pageConfig.domain}`}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                {registerLabel}
                            </Link>
                        </Button>
                    }
                />

                <SecurityDevicesWorkspaceShell
                    workspace={workspace}
                    filters={filters}
                >
                    {bulkManagement ? (
                        <BulkManagementWorkspace data={bulkManagement} />
                    ) : (
                        <>
                            {facilitiesWorkspace ? (
                                <FacilitiesWorkspacePanels
                                    data={facilitiesWorkspace}
                                />
                            ) : null}
                            {networkItWorkspace ? (
                                <NetworkItWorkspacePanels
                                    data={networkItWorkspace}
                                />
                            ) : null}
                            {securityWorkspace ? (
                                <SecurityWorkspacePanels
                                    data={securityWorkspace}
                                />
                            ) : null}
                            {healthcareWorkspace ? (
                                <HealthcareWorkspacePanels
                                    data={healthcareWorkspace}
                                />
                            ) : null}
                            {trackingWorkspace ? (
                                <TrackingWorkspacePanels
                                    data={trackingWorkspace}
                                />
                            ) : null}

                            {/* Subcategory chips */}
                            {filterOptions.subcategories.length > 0 && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="xs"
                                        onClick={() =>
                                            applyFilters({ subcategory: 'all' })
                                        }
                                        className={`h-auto rounded-full px-3 py-1 ${
                                            !filters.subcategory ||
                                            filters.subcategory === 'all'
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                        }`}
                                    >
                                        All
                                        <span className="ml-1 text-muted-foreground">
                                            ({stats.total})
                                        </span>
                                    </Button>
                                    {filterOptions.subcategories.map((sub) => {
                                        const count =
                                            stats.bySubcategory[sub.value] ?? 0;
                                        const isActive =
                                            filters.subcategory === sub.value;
                                        return (
                                            <Button
                                                key={sub.value}
                                                type="button"
                                                variant="outline"
                                                size="xs"
                                                onClick={() =>
                                                    applyFilters({
                                                        subcategory: sub.value,
                                                    })
                                                }
                                                className={`h-auto rounded-full px-3 py-1 ${
                                                    isActive
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                                }`}
                                            >
                                                {sub.label}
                                                {count > 0 && (
                                                    <span className="ml-1 text-muted-foreground/70">
                                                        ({count})
                                                    </span>
                                                )}
                                            </Button>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Category filter (for multi-category or whole-domain pages) */}
                            {filterOptions.categories &&
                                filterOptions.categories.length > 1 && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="xs"
                                            onClick={() =>
                                                applyFilters({
                                                    category: 'all',
                                                })
                                            }
                                            className={`h-auto rounded-full px-3 py-1 ${
                                                !filters.category ||
                                                filters.category === 'all'
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
                                                onClick={() =>
                                                    applyFilters({
                                                        category: cat.value,
                                                    })
                                                }
                                                className={`h-auto rounded-full px-3 py-1 ${
                                                    filters.category ===
                                                    cat.value
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
                            <WorkspaceFilterBar>
                                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                                    <div className="relative flex-1 sm:max-w-xs">
                                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        <CategorySearchInput
                                            title={pageConfig.title}
                                            value={search}
                                            onChange={setSearch}
                                            onSubmit={() =>
                                                applyFilters({ search })
                                            }
                                        />
                                    </div>

                                    <FilterSelect
                                        value={filters.status}
                                        onChange={(v) =>
                                            applyFilters({ status: v })
                                        }
                                        placeholder="Status"
                                        options={filterOptions.statuses}
                                    />
                                    <FilterSelect
                                        value={filters.health}
                                        onChange={(v) =>
                                            applyFilters({ health: v })
                                        }
                                        placeholder="Health"
                                        options={filterOptions.healthStatuses}
                                    />
                                    {filterOptions.providers.length > 0 && (
                                        <FilterSelect
                                            value={filters.provider}
                                            onChange={(v) =>
                                                applyFilters({ provider: v })
                                            }
                                            placeholder="Provider"
                                            options={filterOptions.providers.map(
                                                (p: string) => ({
                                                    value: p,
                                                    label: p,
                                                }),
                                            )}
                                        />
                                    )}
                                    <FilterSelect
                                        value={filters.assigned}
                                        onChange={(v) =>
                                            applyFilters({ assigned: v })
                                        }
                                        placeholder="Assignment"
                                        options={[
                                            { value: 'yes', label: 'Assigned' },
                                            {
                                                value: 'no',
                                                label: 'Unassigned',
                                            },
                                        ]}
                                    />

                                    {hasActiveFilters && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={clearFilters}
                                        >
                                            Clear filters
                                        </Button>
                                    )}
                                </div>
                            </WorkspaceFilterBar>

                            {/* Results */}
                            <WorkspaceDeviceList>
                                {devices.data.length > 0 ? (
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        {devices.data.map((device) => (
                                            <DeviceCard
                                                key={device.id}
                                                device={device}
                                            />
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
                                        description={
                                            pageConfig.emptyDescription
                                        }
                                        action={
                                            <Button asChild size="sm">
                                                <Link
                                                    href={`/security-devices/devices/create?domain=${pageConfig.domain}`}
                                                >
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
                                                variant={
                                                    link.active
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                size="sm"
                                                disabled={!link.url}
                                                onClick={() =>
                                                    link.url &&
                                                    router.get(
                                                        link.url,
                                                        {},
                                                        { preserveState: true },
                                                    )
                                                }
                                                dangerouslySetInnerHTML={{
                                                    __html: link.label,
                                                }}
                                            />
                                        ))}
                                    </div>
                                )}
                            </WorkspaceDeviceList>
                        </>
                    )}
                </SecurityDevicesWorkspaceShell>
            </PageShell>
        </AppLayout>
    );
}
