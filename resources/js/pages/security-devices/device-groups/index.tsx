import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Head, Link, router } from '@inertiajs/react';
import { GitBranch, Plus, Search } from 'lucide-react';
import { useState } from 'react';

import { type FilterOption, type Paginated, FilterSelect } from '../devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type GroupItem = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    devices_count: number;
    created_at: string | null;
};

type Props = {
    groups: Paginated<GroupItem>;
    filters: Record<string, string>;
};

function typeLabel(type: string): string {
    return { location: 'Location', functional: 'Functional', vendor: 'Vendor', maintenance: 'Maintenance', custom: 'Custom' }[type] ?? type;
}

function typeVariant(type: string): 'default' | 'secondary' | 'outline' {
    switch (type) {
        case 'location': return 'default';
        case 'functional': return 'default';
        case 'vendor': return 'secondary';
        case 'maintenance': return 'outline';
        default: return 'outline';
    }
}

// ── Component ─────────────────────────────────────────────────────

export default function DeviceGroupsIndex({ groups, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const pageUrl = '/security-devices/device-groups';

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get(pageUrl, { ...filters, ...newFilters, page: '1' }, { preserveState: true });
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
                { title: 'Device Groups', href: pageUrl },
            ]}
        >
            <Head title="Device Groups - Security & Devices" />

            <PageShell>
                <PageHeader
                    title={<span className="flex items-center gap-3"><GitBranch className="h-6 w-6 text-primary" /> Device Groups</span>}
                    description="Organise devices into logical groups for management, reporting, and operational visibility."
                    actions={
                        <Button asChild size="sm">
                            <Link href="/security-devices/device-groups/create">
                                <Plus className="mr-2 h-4 w-4" /> Create Group
                            </Link>
                        </Button>
                    }
                />

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search groups..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                            className="pl-9"
                        />
                    </div>
                    <FilterSelect
                        value={filters.type}
                        onChange={(v) => applyFilters({ type: v })}
                        placeholder="Type"
                        options={[
                            { value: 'location', label: 'Location' },
                            { value: 'functional', label: 'Functional' },
                            { value: 'vendor', label: 'Vendor' },
                            { value: 'maintenance', label: 'Maintenance' },
                            { value: 'custom', label: 'Custom' },
                        ]}
                    />
                    {hasActiveFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters}>Clear</Button>
                    )}
                </div>

                {/* Group list */}
                {groups.data.length > 0 ? (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {groups.data.map((group) => (
                            <Link
                                key={group.id}
                                href={`/security-devices/device-groups/${group.id}`}
                                className="group flex flex-col rounded-lg border p-4 transition-all hover:bg-muted/50 hover:shadow-md"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <GitBranch className="h-4 w-4 text-muted-foreground shrink-0" />
                                            <span className="text-sm font-semibold truncate">{group.name}</span>
                                        </div>
                                        {group.description && (
                                            <p className="mt-1 text-xs text-muted-foreground line-clamp-2">{group.description}</p>
                                        )}
                                    </div>
                                    <Badge variant={typeVariant(group.type)} className="text-[10px] shrink-0">
                                        {typeLabel(group.type)}
                                    </Badge>
                                </div>
                                <div className="mt-3 text-xs text-muted-foreground">
                                    {group.devices_count} device{group.devices_count !== 1 ? 's' : ''}
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : hasActiveFilters ? (
                    <EmptySearch onClear={clearFilters} title="No matching groups" />
                ) : (
                    <EmptyList
                        icon={GitBranch}
                        itemName="group"
                        createHref="/security-devices/device-groups/create"
                        createLabel="Create Group"
                        description="Create device groups to organise your hardware estate."
                    />
                )}

                {/* Pagination */}
                {(groups.meta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {groups.links.map((link, i) => (
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
