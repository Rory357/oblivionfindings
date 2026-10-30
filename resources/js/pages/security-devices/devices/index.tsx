import {
    EmptyValue,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
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
import {
    AddDeviceDialog,
    useAddDeviceDialogState,
} from '@/components/security-devices/add-device-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Cctv,
    Cpu,
    Download,
    Eye,
    LayoutGrid,
    List,
    MonitorOff,
    Plus,
    Shield,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DeviceCard,
    deviceCategoryLabel,
    deviceMonitoringLabel,
    deviceMonitoringVariant,
    formatTimeSince,
    type DeviceListItem,
    type FilterOption,
    type Paginated,
} from './shared';

type SavedView = { key: string; label: string; count: number };
export type DevicesIndexProps = {
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
        categories?: FilterOption[];
        statuses: FilterOption[];
        healthStatuses: FilterOption[];
        providers: string[];
    };
    can: { create: boolean; export: boolean; bulk_select: boolean };
    exportHref: string;
    scopeLabel?: string | null;
};

export default function DevicesIndex(props: DevicesIndexProps) {
    const [listView, setListView] = useState<'cards' | 'table'>('cards');
    const actorId =
        usePage<{ auth: { user: { id: number } | null } }>().props.auth.user
            ?.id ?? 0;
    // Route/authority changes clear selected records and open registration drafts before paint.
    const identity = JSON.stringify([
        actorId,
        props.can,
        props.scopeLabel,
        props.filters,
        props.devices.meta.current_page,
        props.devices.data.map((device) => device.id),
    ]);
    return (
        <DeviceInventory
            key={identity}
            {...props}
            listView={listView}
            setListView={setListView}
        />
    );
}

function DeviceInventory({
    devices,
    stats,
    savedViews,
    filters,
    filterOptions,
    can,
    exportHref,
    scopeLabel,
    listView,
    setListView,
}: DevicesIndexProps & {
    listView: 'cards' | 'table';
    setListView: (view: 'cards' | 'table') => void;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

    const addDeviceDialog = useAddDeviceDialogState();
    const contextMenu = useEntityContextMenu<DeviceListItem>();
    const canSelect = can.bulk_select && can.export;
    const pageIds = devices.data.map((device) => device.id);
    const allPageSelected =
        pageIds.length > 0 && pageIds.every((id) => selectedIds.has(id));
    const somePageSelected = pageIds.some((id) => selectedIds.has(id));
    const currentView = filters.view || 'all';
    const hasActiveFilters = Object.entries(filters).some(
        ([key, value]) =>
            !['view', 'page', 'site_id'].includes(key) &&
            Boolean(value) &&
            value !== 'all',
    );
    const applyFilters = (changes: Record<string, string>) => {
        setSelectedIds(new Set());
        contextMenu.close();
        router.get(
            '/security-devices/devices',
            { ...filters, ...changes, page: '1' },
            { preserveState: true },
        );
    };
    const selectMeterView = (changes: Record<string, string>) => {
        setSelectedIds(new Set());
        setSearch('');
        contextMenu.close();
        router.get(
            '/security-devices/devices',
            {
                ...(filters.site_id ? { site_id: filters.site_id } : {}),
                ...changes,
                page: '1',
            },
            { preserveState: true },
        );
    };
    const clearFilters = () => {
        setSelectedIds(new Set());
        setSearch('');
        contextMenu.close();
        // A chosen Site is the current inventory scope, not a removable search filter.
        router.get(
            '/security-devices/devices',
            filters.site_id ? { site_id: filters.site_id, view: 'all' } : {},
            { preserveState: true },
        );
    };
    const toggleDevice = (id: number, checked: boolean) =>
        setSelectedIds((current) => {
            const next = new Set(current);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    const togglePage = (checked: boolean) =>
        setSelectedIds(checked ? new Set(pageIds) : new Set());
    const selectedExportHref = useMemo(() => {
        const [path, query = ''] = exportHref.split('?');
        const params = new URLSearchParams(query);
        params.set(
            'ids',
            Array.from(selectedIds)
                .sort((a, b) => a - b)
                .join(','),
        );
        return `${path}?${params}`;
    }, [exportHref, selectedIds]);
    const actionsFor = (device: DeviceListItem): MenuItem[] => [
        {
            label: 'Open device',
            icon: Eye,
            onClick: () =>
                router.visit(`/security-devices/devices/${device.id}`),
        },
        ...(canSelect
            ? [
                  {
                      label: selectedIds.has(device.id)
                          ? 'Deselect device'
                          : 'Select device',
                      icon: List,
                      onClick: () =>
                          toggleDevice(device.id, !selectedIds.has(device.id)),
                  },
              ]
            : []),
    ];
    const columns: EntityTableColumn<DeviceListItem>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '110px',
            cell: (device) => <StatusBadge status={device.status} />,
        },
        {
            key: 'health',
            label: 'Health',
            width: '130px',
            cell: (device) => (
                <StatusBadge
                    status={device.health_status}
                    label={
                        device.health_status === 'not_monitored'
                            ? 'Not monitored'
                            : undefined
                    }
                />
            ),
        },
        {
            key: 'type',
            label: 'Equipment',
            width: '1fr',
            cell: (device) => (
                <EntityChip>{deviceCategoryLabel(device.category)}</EntityChip>
            ),
        },
        {
            key: 'monitoring',
            label: 'Monitoring',
            width: '140px',
            cell: (device) =>
                device.monitoring_state ? (
                    <StatusBadge
                        variant={deviceMonitoringVariant(
                            device.monitoring_state,
                        )}
                        status={device.monitoring_state}
                        label={deviceMonitoringLabel(device.monitoring_state)}
                    />
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'assignment',
            label: 'Assigned to',
            width: '1fr',
            cell: (device) => device.assigned_to || <EmptyValue />,
        },
        {
            key: 'seen',
            label: 'Last seen',
            width: '130px',
            cell: (device) => (
                <span
                    title={
                        device.last_seen_at
                            ? formatDateTime(device.last_seen_at)
                            : undefined
                    }
                >
                    {formatTimeSince(device.last_seen_at)}
                </span>
            ),
        },
    ];
    const filter = (key: string, label: string, options: FilterOption[]) => (
        <PageHeaderFilterSelect
            key={key}
            label={label}
            value={filters[key] || 'all'}
            onChange={(value) => applyFilters({ [key]: value })}
            options={[
                { value: 'all', label: `All ${label.toLowerCase()}` },
                ...options.filter((option) => option.value !== 'all'),
            ]}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
            ]}
        >
            <Head title="All devices - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Cctv}
                    title="All devices"
                    subline={
                        scopeLabel
                            ? `${scopeLabel} · equipment and monitoring`
                            : 'Equipment, assignment and monitoring'
                    }
                    mobileSummary={`${devices.meta.total} matching devices`}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search devices…"
                                ariaLabel="Search devices"
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter')
                                        applyFilters({ search });
                                }}
                            />
                            {can.export ? (
                                <PageHeaderGlassButton asChild>
                                    <a
                                        href={exportHref}
                                        title="Export the full inventory available to your account"
                                    >
                                        <Download className="size-4" />
                                        Export inventory
                                    </a>
                                </PageHeaderGlassButton>
                            ) : null}
                            {can.create ? (
                                <PageHeaderPrimaryButton
                                    onClick={addDeviceDialog.openDialog}
                                >
                                    <Plus className="size-4" />
                                    Register device
                                </PageHeaderPrimaryButton>
                            ) : null}
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Devices"
                                onClick={() => selectMeterView({ view: 'all' })}
                            >
                                <PageHeaderMeterBig>
                                    {stats.total}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Inventory in your scope
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Active"
                                tone="success"
                                onClick={() =>
                                    selectMeterView({
                                        view: 'all',
                                        status: 'active',
                                    })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {stats.active}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Active equipment
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Offline"
                                tone={stats.offline > 0 ? 'critical' : 'brand'}
                                onClick={() =>
                                    selectMeterView({ view: 'offline' })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {stats.offline}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Review lost contact
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Needs attention"
                                tone={stats.attention > 0 ? 'warning' : 'brand'}
                                onClick={() =>
                                    selectMeterView({ view: 'needs_attention' })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {stats.attention}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Review current findings
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    filters={
                        <>
                            {filter('domain', 'Domains', filterOptions.domains)}
                            {filterOptions.categories &&
                            filterOptions.categories.length > 0
                                ? filter(
                                      'category',
                                      'Types',
                                      filterOptions.categories,
                                  )
                                : null}
                            {filter(
                                'status',
                                'Statuses',
                                filterOptions.statuses,
                            )}
                            {filter(
                                'health',
                                'Health states',
                                filterOptions.healthStatuses,
                            )}
                            {filterOptions.providers.length > 0
                                ? filter(
                                      'provider',
                                      'Providers',
                                      filterOptions.providers.map(
                                          (provider) => ({
                                              value: provider,
                                              label: provider,
                                          }),
                                      ),
                                  )
                                : null}
                            {filter('assigned', 'Assignments', [
                                { value: 'yes', label: 'Assigned' },
                                { value: 'no', label: 'Unassigned' },
                            ])}
                            {hasActiveFilters || currentView !== 'all' ? (
                                <PageHeaderFilterButton onClick={clearFilters}>
                                    Clear filters
                                </PageHeaderFilterButton>
                            ) : null}
                            <PageHeaderViewToggle
                                value={listView}
                                onChange={setListView}
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
                        <PageHeaderRail
                            items={savedViews
                                .filter(
                                    (view) =>
                                        view.count > 0 ||
                                        view.key === 'all' ||
                                        view.key === currentView,
                                )
                                .map((view) => ({
                                    ...view,
                                    icon:
                                        view.key === 'offline'
                                            ? MonitorOff
                                            : view.key === 'needs_attention'
                                              ? AlertTriangle
                                              : Activity,
                                }))}
                            value={currentView}
                            onSelect={(view) => applyFilters({ view })}
                            ariaLabel="Saved device views"
                        />
                    }
                />
                <ListCaption
                    title={
                        savedViews.find((view) => view.key === currentView)
                            ?.label ?? 'Devices'
                    }
                    caption={`${devices.data.length} of ${devices.meta.total} shown`}
                    right={
                        canSelect && devices.data.length > 0 ? (
                            <>
                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        aria-label="Select this page"
                                        checked={
                                            allPageSelected
                                                ? true
                                                : somePageSelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(checked) =>
                                            togglePage(checked === true)
                                        }
                                    />
                                    Select this page
                                </label>
                                <span
                                    className="text-caption"
                                    aria-live="polite"
                                >
                                    {selectedIds.size} selected
                                </span>
                                {selectedIds.size > 0 ? (
                                    <Button asChild size="sm">
                                        <a href={selectedExportHref}>
                                            <Download className="size-4" />
                                            Export selected
                                        </a>
                                    </Button>
                                ) : (
                                    <Button size="sm" disabled>
                                        <Download className="size-4" />
                                        Export selected
                                    </Button>
                                )}
                            </>
                        ) : undefined
                    }
                />
                {devices.data.length > 0 ? (
                    listView === 'cards' ? (
                        <EntityCardGrid>
                            {devices.data.map((device) => (
                                <DeviceCard
                                    key={device.id}
                                    device={device}
                                    selection={
                                        canSelect
                                            ? {
                                                  checked: selectedIds.has(
                                                      device.id,
                                                  ),
                                                  label: `Select ${device.name}`,
                                                  onToggle: (checked) =>
                                                      toggleDevice(
                                                          device.id,
                                                          checked,
                                                      ),
                                              }
                                            : undefined
                                    }
                                    actions={actionsFor(device)}
                                />
                            ))}
                        </EntityCardGrid>
                    ) : (
                        <EntityTable
                            rows={devices.data}
                            rowKey={(device) => device.id}
                            identityLabel="Device"
                            identityWidth="2fr"
                            identity={(device) => ({
                                icon: Cpu,
                                name: device.name,
                                subline: device.device_uid,
                            })}
                            columns={columns}
                            onOpen={(device) =>
                                router.visit(
                                    `/security-devices/devices/${device.id}`,
                                )
                            }
                            actionsFor={actionsFor}
                            hrefFor={(device) =>
                                `/security-devices/devices/${device.id}`
                            }
                            selection={
                                canSelect
                                    ? {
                                          keys: selectedIds,
                                          onToggle: (device, checked) =>
                                              toggleDevice(device.id, checked),
                                          labelFor: (device) =>
                                              `Select ${device.name}`,
                                      }
                                    : undefined
                            }
                            onRowContextMenu={contextMenu.open}
                        />
                    )
                ) : hasActiveFilters || currentView !== 'all' ? (
                    <EmptySearch
                        onClear={clearFilters}
                        searchTerm={filters.search}
                    />
                ) : (
                    <EmptyList
                        icon={Shield}
                        itemName="device"
                        onCreate={
                            can.create ? addDeviceDialog.openDialog : undefined
                        }
                        createLabel={can.create ? 'Register device' : undefined}
                    />
                )}
                <LaravelPagination
                    links={devices.links}
                    lastPage={devices.meta.last_page}
                />
                {contextMenu.ctx ? (
                    <EntityContextMenu
                        x={contextMenu.ctx.x}
                        y={contextMenu.ctx.y}
                        title={contextMenu.ctx.record.name}
                        icon={Cpu}
                        items={actionsFor(contextMenu.ctx.record)}
                        onClose={contextMenu.close}
                    />
                ) : null}
                {can.create ? (
                    <AddDeviceDialog
                        open={addDeviceDialog.open}
                        onClose={addDeviceDialog.closeDialog}
                        prefillDomain={
                            filters.domain && filters.domain !== 'all'
                                ? filters.domain
                                : ''
                        }
                    />
                ) : null}
            </div>
        </AppLayout>
    );
}
