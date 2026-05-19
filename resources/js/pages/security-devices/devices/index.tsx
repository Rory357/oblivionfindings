import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Cctv,
    Cpu,
    MonitorOff,
    Plus,
    Search,
    Shield,
} from 'lucide-react';
import { useState } from 'react';

import {
    type DeviceListItem,
    type FilterOption,
    type Paginated,
    DeviceCard,
    FilterSelect,
    StatCard,
} from './shared';

// ── Types ─────────────────────────────────────────────────────────

type Props = {
    devices: Paginated<DeviceListItem>;
    stats: { total: number; active: number; offline: number; attention: number };
    filters: Record<string, string>;
    filterOptions: {
        domains: FilterOption[];
        statuses: FilterOption[];
        healthStatuses: FilterOption[];
        providers: string[];
    };
};

// ── Component ─────────────────────────────────────────────────────

export default function DevicesIndex({ devices, stats, filters, filterOptions }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get(
            '/security-devices/devices',
            { ...filters, ...newFilters, page: '1' },
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        router.get('/security-devices/devices', {}, { preserveState: true });
        setSearch('');
    };

    const hasActiveFilters = Object.values(filters).some((v) => v && v !== 'all');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Devices', href: '/security-devices/devices' },
            ]}
        >
            <Head title="Devices - Security & Devices" />

            <PageShell>
                <PageHero
                    icon={Cctv}
                    title="Devices"
                    description="Canonical device registry across all hardware domains."
                    stats={[
                        { label: 'Total', value: stats.total },
                        { label: 'Active', value: stats.active },
                        { label: 'Offline', value: stats.offline },
                        { label: 'Attention', value: stats.attention },
                    ]}
                    actions={
                        <Button asChild size="sm">
                            <Link href="/security-devices/devices/create">
                                <Plus className="mr-2 h-4 w-4" />
                                Register Device
                            </Link>
                        </Button>
                    }
                />

                {/* Stats cards */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Total" value={stats.total} icon={Cpu} />
                    <StatCard label="Active" value={stats.active} icon={Activity} />
                    <StatCard label="Offline" value={stats.offline} icon={MonitorOff} />
                    <StatCard label="Needing Attention" value={stats.attention} icon={AlertTriangle} variant={stats.attention > 0 ? 'warning' : 'default'} />
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search name, UID, serial, MAC, IMEI..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                            className="pl-9"
                        />
                    </div>

                    <FilterSelect
                        value={filters.domain}
                        onChange={(v) => applyFilters({ domain: v })}
                        placeholder="Domain"
                        options={filterOptions.domains}
                    />
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
                    <EmptySearch onClear={clearFilters} searchTerm={filters.search} />
                ) : (
                    <EmptyList
                        icon={Shield}
                        itemName="device"
                        createHref="/security-devices/devices/create"
                        createLabel="Register Device"
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
