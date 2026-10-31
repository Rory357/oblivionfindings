import {
    EntityChip,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import { Eye, GitBranch, LayoutGrid, List, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import type { Paginated } from '../devices/shared';
import { GroupWizard, groupTypeLabel } from './_dialogs';

export type GroupItem = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    devices_count: number;
    created_at: string | null;
};
export type GroupIndexProps = {
    groups: Paginated<GroupItem>;
    filters: Record<string, string>;
};
type GroupView = 'all' | 'with_devices' | 'empty';
const pageUrl = '/security-devices/device-groups';
export default function DeviceGroupsIndex(props: GroupIndexProps) {
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    return (
        <GroupList
            key={JSON.stringify([auth?.user?.id, auth?.can, props])}
            {...props}
            layout={layout}
            setLayout={setLayout}
        />
    );
}
function GroupList({
    groups,
    filters,
    layout,
    setLayout,
}: GroupIndexProps & {
    layout: 'cards' | 'table';
    setLayout: (view: 'cards' | 'table') => void;
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [created, setCreated] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [view, setView] = useState<GroupView>('all');
    const applyFilters = (next: Record<string, string>) =>
        router.get(
            pageUrl,
            { ...filters, ...next, page: '1' },
            { preserveState: true },
        );
    const clearFilters = () => {
        setSearch('');
        setView('all');
        router.get(pageUrl, {}, { preserveState: true });
    };
    const hasServerFilters = Boolean(
        filters.search || (filters.type && filters.type !== 'all'),
    );
    const rows = groups.data.filter(
        (group) =>
            view === 'all' ||
            (view === 'empty'
                ? group.devices_count === 0
                : group.devices_count > 0),
    );
    const headerViews = [
        {
            key: 'all' as const,
            label: 'Current page',
            count: groups.data.length,
        },
        {
            key: 'with_devices' as const,
            label: 'With devices',
            count: groups.data.filter((group) => group.devices_count > 0)
                .length,
        },
        {
            key: 'empty' as const,
            label: 'Empty groups',
            count: groups.data.filter((group) => group.devices_count === 0)
                .length,
        },
    ];
    const actionsFor = (group: GroupItem): MenuItem[] => [
        {
            label: 'Open group',
            icon: Eye,
            onClick: () => router.visit(`${pageUrl}/${group.id}`),
        },
    ];
    const fields: EntityTableColumn<GroupItem>[] = [
        {
            key: 'type',
            label: 'Group type',
            width: '130px',
            cell: (group) => (
                <EntityChip>{groupTypeLabel(group.type)}</EntityChip>
            ),
        },
        {
            key: 'members',
            label: 'Devices in group',
            width: '130px',
            cell: (group) => <EntityChip>{group.devices_count}</EntityChip>,
        },
        {
            key: 'description',
            label: 'Description',
            width: '1.3fr',
            cell: (group) => group.description || 'No description recorded',
        },
        {
            key: 'created',
            label: 'Created',
            width: '150px',
            cell: (group) =>
                group.created_at
                    ? formatDateTime(group.created_at)
                    : 'No time recorded',
        },
    ];
    const columns: EntityTableColumn<GroupItem>[] = [
        {
            key: 'details',
            label: 'Group details',
            width: '1fr',
            cell: (record) => (
                <div className="space-y-1">
                    {fields.map((field) => (
                        <ReviewRow
                            key={field.key}
                            label={field.label}
                            value={field.cell(record)}
                        />
                    ))}
                </div>
            ),
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Device groups', href: pageUrl },
            ]}
        >
            <Head title="Device groups - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={GitBranch}
                    title="Device groups"
                    subline="Organise approved devices for management, reporting and operational visibility"
                    mobileSummary={`${groups.meta.total} matching groups · page ${groups.meta.current_page} of ${groups.meta.last_page}`}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter')
                                        applyFilters({ search });
                                }}
                                ariaLabel="Search all device groups"
                                placeholder="Search group names and descriptions…"
                            />
                            <PageHeaderGlassButton
                                icon={Search}
                                onClick={() => applyFilters({ search })}
                            >
                                Search
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={() => {
                                    setCreated(false);
                                    setCreateOpen(true);
                                }}
                            >
                                Add device group
                            </PageHeaderPrimaryButton>
                        </>
                    }
                    meters={
                        // Membership counts live on the rail (this page only);
                        // the header carries the one server total.
                        <PageHeaderMeterBlock
                            label="Matching groups"
                            ariaLabel="View current group results"
                            onClick={() => setView('all')}
                        >
                            <PageHeaderMeterBig>
                                {groups.meta.total}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Across all pages of this search
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="All group types"
                                value={filters.type || 'all'}
                                options={[
                                    'location',
                                    'functional',
                                    'vendor',
                                    'maintenance',
                                    'custom',
                                ].map((value) => ({
                                    value,
                                    label: groupTypeLabel(value),
                                }))}
                                onChange={(type) => applyFilters({ type })}
                            />
                            {hasServerFilters || search || view !== 'all' ? (
                                <PageHeaderFilterButton onClick={clearFilters}>
                                    Clear filters
                                </PageHeaderFilterButton>
                            ) : null}
                            <PageHeaderViewToggle
                                value={layout}
                                onChange={setLayout}
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'table',
                                        label: 'Table',
                                        icon: List,
                                    },
                                ]}
                            />
                        </>
                    }
                    rail={
                        <PageHeaderRail<GroupView>
                            items={headerViews}
                            value={view}
                            onSelect={setView}
                            ariaLabel="Device group views"
                        />
                    }
                />
                <section
                    aria-label="Device group records"
                    className="space-y-3"
                >
                    {groups.data.length === 0 && !hasServerFilters ? (
                        <EmptyList
                            icon={GitBranch}
                            itemName="group"
                            onCreate={() => {
                                setCreated(false);
                                setCreateOpen(true);
                            }}
                            createLabel="Add device group"
                            description="Add a group to organise your approved devices."
                        />
                    ) : rows.length === 0 ? (
                        <EmptySearch
                            title="No matching groups"
                            onClear={clearFilters}
                        />
                    ) : (
                        <EvidenceRecords<GroupItem>
                            key={view}
                            title="Device groups"
                            description={`${rows.length} shown from ${groups.data.length} groups on this page · ${groups.meta.total} matching groups across all pages. Devices can belong to more than one group.`}
                            rows={rows}
                            total={groups.meta.total}
                            view={layout}
                            rowKey={(group) => group.id}
                            identity={(group) => ({
                                icon: GitBranch,
                                name: group.name,
                                subline: groupTypeLabel(group.type),
                            })}
                            hrefFor={(group) => `${pageUrl}/${group.id}`}
                            actionsFor={actionsFor}
                            cardFor={(group) => ({
                                icon: GitBranch,
                                name: group.name,
                                subline: groupTypeLabel(group.type),
                                meridian: 'neutral',
                                chips: (
                                    <EntityChip>
                                        {group.devices_count === 0
                                            ? 'Empty group'
                                            : 'Has devices'}
                                    </EntityChip>
                                ),
                                metric: {
                                    label: 'Devices in group',
                                    value: group.devices_count,
                                    percent: null,
                                },
                                alerts: (
                                    <p className="line-clamp-2">
                                        {group.description ||
                                            'No description recorded'}
                                    </p>
                                ),
                                footer: {
                                    primary: 'Created',
                                    secondary: group.created_at
                                        ? formatDateTime(group.created_at)
                                        : 'No time recorded',
                                },
                            })}
                            columns={columns}
                            compactColumns={[fields[0], fields[1], fields[3]]}
                            empty="No groups on this page."
                            icon={GitBranch}
                            minWidth={850}
                        />
                    )}
                    <LaravelPagination
                        links={groups.links}
                        lastPage={groups.meta.last_page}
                    />
                    <p className="text-caption">
                        Search and group type apply across all pages. The
                        membership tabs apply to the current page.
                    </p>
                </section>
            </div>
            <GroupWizard
                open={createOpen}
                onSaved={() => setCreated(true)}
                onClose={() => {
                    setCreateOpen(false);
                    if (created)
                        router.reload({
                            only: ['groups'],
                            preserveScroll: true,
                        });
                }}
            />
        </AppLayout>
    );
}
