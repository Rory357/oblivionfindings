import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Cctv,
    Cpu,
    Download,
    MonitorOff,
    Plus,
    Search,
    Shield,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    DeviceCard,
    type DeviceListItem,
    type FilterOption,
    FilterSelect,
    type Paginated,
    StatCard,
} from './shared';

type SavedView = {
    key: string;
    label: string;
    count: number;
};

type Props = {
    devices: Paginated<DeviceListItem>;
    stats: {
        total: number;
        active: number;
        offline: number;
        attention: number;
    };
    savedViews: SavedView[];
    filters: Record<string, string>;
    filterOptions: {
        domains: FilterOption[];
        statuses: FilterOption[];
        healthStatuses: FilterOption[];
        providers: string[];
    };
    can: {
        create: boolean;
        export: boolean;
        bulk_select: boolean;
    };
    exportHref: string;
    scopeLabel?: string | null;
};

export default function DevicesIndex({
    devices,
    stats,
    savedViews,
    filters,
    filterOptions,
    can,
    exportHref,
    scopeLabel,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

    const applyFilters = (newFilters: Record<string, string>) => {
        setSelectedIds(new Set());
        router.get(
            '/security-devices/devices',
            { ...filters, ...newFilters, page: '1' },
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        router.get('/security-devices/devices', {}, { preserveState: true });
        setSearch('');
        setSelectedIds(new Set());
    };

    const hasActiveFilters = Object.entries(filters).some(
        ([key, value]) => key !== 'view' && Boolean(value) && value !== 'all',
    );
    const currentView = filters.view || 'all';
    const pageIds = devices.data.map((device) => device.id);
    const allPageSelected =
        pageIds.length > 0 && pageIds.every((id) => selectedIds.has(id));
    const selectedExportHref = useMemo(
        () =>
            selectedIds.size > 0
                ? `${exportHref}?ids=${Array.from(selectedIds).join(',')}`
                : exportHref,
        [exportHref, selectedIds],
    );

    const toggleDevice = (deviceId: number, checked: boolean) => {
        setSelectedIds((current) => {
            const next = new Set(current);
            if (checked) next.add(deviceId);
            else next.delete(deviceId);
            return next;
        });
    };

    const togglePage = (checked: boolean) => {
        setSelectedIds((current) => {
            const next = new Set(current);
            for (const id of pageIds) {
                if (checked) next.add(id);
                else next.delete(id);
            }
            return next;
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
            ]}
        >
            <Head title="All devices - Security & Devices" />

            <PageShell>
                <PageHero
                    icon={Cctv}
                    title="All devices"
                    description={
                        scopeLabel
                            ? `Canonical inventory assigned to ${scopeLabel}, with ownership context, monitoring coverage, stable filters and governed export.`
                            : 'Canonical inventory with ownership context, monitoring coverage, stable filters and governed export.'
                    }
                    stats={[
                        { label: 'Total', value: stats.total },
                        { label: 'Active', value: stats.active },
                        { label: 'Offline', value: stats.offline },
                        { label: 'Attention', value: stats.attention },
                    ]}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <Button asChild variant="outline" size="sm">
                                    <a href={exportHref}>
                                        <Download
                                            className="mr-2 h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        {scopeLabel
                                            ? 'Export full authorised inventory'
                                            : 'Export inventory'}
                                    </a>
                                </Button>
                            ) : null}
                            {can.create ? (
                                <Button asChild size="sm">
                                    <Link href="/security-devices/devices/create">
                                        <Plus
                                            className="mr-2 h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Register device
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Total" value={stats.total} icon={Cpu} />
                    <StatCard
                        label="Active"
                        value={stats.active}
                        icon={Activity}
                    />
                    <StatCard
                        label="Offline"
                        value={stats.offline}
                        icon={MonitorOff}
                        variant={stats.offline > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Needs attention"
                        value={stats.attention}
                        icon={AlertTriangle}
                        variant={stats.attention > 0 ? 'warning' : 'default'}
                    />
                </div>

                <section aria-labelledby="device-saved-views-heading">
                    <h2
                        id="device-saved-views-heading"
                        className="mb-2 text-sm font-semibold"
                    >
                        Saved views
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        {savedViews.map((view) => (
                            <Button
                                key={view.key}
                                type="button"
                                variant={
                                    currentView === view.key
                                        ? 'default'
                                        : 'outline'
                                }
                                className="frontline-tap"
                                onClick={() => applyFilters({ view: view.key })}
                            >
                                {view.label}
                                <span className="ml-2 rounded-full border border-current/25 px-2 py-0.5 text-xs font-medium">
                                    {view.count}
                                </span>
                            </Button>
                        ))}
                    </div>
                </section>

                <div className="flex flex-col gap-3 rounded-2xl border bg-card p-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search
                            className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            placeholder="Search name, UID, serial, MAC, IMEI..."
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={(event) =>
                                event.key === 'Enter' &&
                                applyFilters({ search })
                            }
                            className="pl-9"
                        />
                    </div>

                    <FilterSelect
                        value={filters.domain}
                        onChange={(value) => applyFilters({ domain: value })}
                        placeholder="Domain"
                        options={filterOptions.domains}
                    />
                    <FilterSelect
                        value={filters.status}
                        onChange={(value) => applyFilters({ status: value })}
                        placeholder="Status"
                        options={filterOptions.statuses}
                    />
                    <FilterSelect
                        value={filters.health}
                        onChange={(value) => applyFilters({ health: value })}
                        placeholder="Health"
                        options={filterOptions.healthStatuses}
                    />
                    {filterOptions.providers.length > 0 ? (
                        <FilterSelect
                            value={filters.provider}
                            onChange={(value) =>
                                applyFilters({ provider: value })
                            }
                            placeholder="Provider"
                            options={filterOptions.providers.map(
                                (provider) => ({
                                    value: provider,
                                    label: provider,
                                }),
                            )}
                        />
                    ) : null}
                    <FilterSelect
                        value={filters.assigned}
                        onChange={(value) => applyFilters({ assigned: value })}
                        placeholder="Assignment"
                        options={[
                            { value: 'yes', label: 'Assigned' },
                            { value: 'no', label: 'Unassigned' },
                        ]}
                    />

                    {hasActiveFilters ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                        >
                            Clear filters
                        </Button>
                    ) : null}
                </div>

                {can.bulk_select && devices.data.length > 0 ? (
                    <div className="flex flex-col gap-3 rounded-2xl border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <label className="frontline-tap flex cursor-pointer items-center gap-3 rounded-xl px-2 text-sm font-medium">
                            <Checkbox
                                checked={allPageSelected}
                                onCheckedChange={(checked) =>
                                    togglePage(checked === true)
                                }
                            />
                            Select this page
                        </label>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm text-muted-foreground">
                                {selectedIds.size} selected
                            </span>
                            {selectedIds.size > 0 ? (
                                <Button asChild size="sm">
                                    <a href={selectedExportHref}>
                                        <Download
                                            className="mr-2 h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Export selected
                                    </a>
                                </Button>
                            ) : (
                                <Button size="sm" disabled>
                                    <Download
                                        className="mr-2 h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Export selected
                                </Button>
                            )}
                        </div>
                    </div>
                ) : null}

                {devices.data.length > 0 ? (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {devices.data.map((device) => (
                            <div key={device.id} className="space-y-2">
                                {can.bulk_select ? (
                                    <label className="frontline-tap flex cursor-pointer items-center gap-3 rounded-xl border bg-card px-3 text-sm">
                                        <Checkbox
                                            checked={selectedIds.has(device.id)}
                                            onCheckedChange={(checked) =>
                                                toggleDevice(
                                                    device.id,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Select {device.name}
                                    </label>
                                ) : null}
                                <DeviceCard device={device} />
                            </div>
                        ))}
                    </div>
                ) : hasActiveFilters || currentView !== 'all' ? (
                    <EmptySearch
                        onClear={clearFilters}
                        searchTerm={filters.search}
                    />
                ) : (
                    <EmptyList
                        icon={Shield}
                        itemName="device"
                        createHref={
                            can.create
                                ? '/security-devices/devices/create'
                                : undefined
                        }
                        createLabel={can.create ? 'Register device' : undefined}
                    />
                )}

                {(devices.meta.last_page ?? 1) > 1 ? (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {devices.links.map((link, index) => (
                            <Button
                                key={`${link.label}-${index}`}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                className="frontline-tap"
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
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
