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
import {
    BulkManagementWorkspace,
    type BulkManagementWorkspaceData,
} from '@/components/security-devices/bulk-management-workspace';
import {
    FacilitiesWorkspacePanels,
    type FacilitiesWorkspaceData,
} from '@/components/security-devices/facilities-workspace';
import {
    HealthcareWorkspacePanels,
    type HealthcareWorkspaceData,
} from '@/components/security-devices/healthcare-workspace';
import {
    NetworkItWorkspacePanels,
    type NetworkItWorkspaceData,
} from '@/components/security-devices/network-it-workspace';
import {
    SecurityDevicesWorkspaceShell,
    type SecurityDevicesWorkspace,
} from '@/components/security-devices/security-devices-workspace-shell';
import {
    SecurityWorkspacePanels,
    type SecurityWorkspaceData,
} from '@/components/security-devices/security-workspace';
import {
    TrackingWorkspacePanels,
    type TrackingWorkspaceData,
} from '@/components/security-devices/tracking-workspace';
import { Button } from '@/components/ui/button';
import { EmptySearch, EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Building2,
    Cctv,
    Cpu,
    Eye,
    HeartPulse,
    Key,
    LayoutGrid,
    List,
    Plus,
    Server,
    Siren,
    Smartphone,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import {
    DeviceCard,
    deviceMonitoringVariant,
    formatTimeSince,
    type DeviceListItem,
    type FilterOption,
    type Paginated,
} from './devices/shared';

type CategoryStats = {
    total: number;
    active: number;
    offline: number;
    attention: number;
    bySubcategory: Record<string, number>;
};
export type PageConfig = {
    slug: string;
    title: string;
    description: string;
    emptyTitle: string;
    emptyDescription: string;
    icon: string;
    domain: string;
    categories: string[] | null;
};
export type CategoryPageProps = {
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
    can: { registerDevice: boolean };
};
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
function singularizeTitle(title: string) {
    if (title.endsWith('ies')) return `${title.slice(0, -3)}y`;
    return title.endsWith('s') ? title.slice(0, -1) : title;
}

export function CategorySearchInput({
    title,
    value,
    onChange,
    onSubmit,
}: {
    title: string;
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
}) {
    const name = `Search ${title.toLowerCase()} device inventory`;
    return (
        <PageHeaderSearch
            ariaLabel={name}
            placeholder="Search device inventory…"
            value={value}
            onChange={onChange}
            onKeyDown={(event) => {
                if (event.key === 'Enter') onSubmit();
            }}
        />
    );
}
export function CategoryRegisterAction({
    canRegister,
    label,
    onRegister,
    onDark = true,
}: {
    onDark?: boolean;
    canRegister: boolean;
    label: string;
    onRegister: () => void;
}) {
    if (!canRegister) return null;
    if (!onDark)
        return (
            <Button type="button" size="sm" onClick={onRegister}>
                <Plus className="size-4" aria-hidden="true" />
                {label}
            </Button>
        );
    return (
        <PageHeaderPrimaryButton onClick={onRegister}>
            <Plus className="size-4" aria-hidden="true" />
            {label}
        </PageHeaderPrimaryButton>
    );
}
function domainInventoryHref(
    domain: string,
    query: Record<string, string> = {},
) {
    const params = new URLSearchParams({ domain, ...query });
    return `/security-devices/devices?${params}`;
}
function workspaceTabHref(
    workspace: SecurityDevicesWorkspace,
    filters: Record<string, string | undefined>,
    tab: string,
) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value && !['page', 'tab'].includes(key)) params.set(key, value);
    }
    params.set('tab', tab);
    return `${workspace.canonicalHref}?${params}`;
}
export function CategoryWorkspaceHero({
    pageConfig,
    workspace,
    filters,
    canRegister,
    registerLabel,
    onRegister,
    search,
    filterControls,
}: {
    pageConfig: PageConfig;
    workspace: SecurityDevicesWorkspace;
    filters: Record<string, string | undefined>;
    canRegister: boolean;
    registerLabel: string;
    onRegister: () => void;
    search?: ReactNode;
    filterControls?: ReactNode;
}) {
    const PageIcon = iconMap[pageConfig.icon] ?? Server;
    const freshness = workspace.freshness;
    return (
        <PageHeader
            icon={PageIcon}
            title={pageConfig.title}
            subline={pageConfig.description}
            mobileSummary={`${workspace.summary.devices} devices · ${workspace.summary.attention} need attention`}
            actions={
                <>
                    {search}
                    <CategoryRegisterAction
                        canRegister={canRegister}
                        label={registerLabel}
                        onRegister={onRegister}
                    />
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Devices"
                        href={domainInventoryHref(pageConfig.domain)}
                    >
                        <PageHeaderMeterBig>
                            {workspace.summary.devices}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Equipment in this domain
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Need attention"
                        tone={
                            workspace.summary.attention > 0
                                ? 'warning'
                                : 'brand'
                        }
                        href={domainInventoryHref(pageConfig.domain, {
                            view: 'needs_attention',
                        })}
                    >
                        <PageHeaderMeterBig>
                            {workspace.summary.attention}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Review current findings
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Without monitoring"
                        tone={
                            workspace.summary.unmonitored > 0
                                ? 'warning'
                                : 'brand'
                        }
                        href={domainInventoryHref(pageConfig.domain, {
                            view: 'unmonitored',
                        })}
                    >
                        <PageHeaderMeterBig>
                            {workspace.summary.unmonitored}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {workspace.summary.monitored} devices have
                            monitoring
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Latest report"
                        tone={freshness.state === 'stale' ? 'warning' : 'brand'}
                        href={domainInventoryHref(pageConfig.domain, {
                            sort: 'last_seen_at',
                            direction: 'desc',
                        })}
                    >
                        <PageHeaderMeterBig>
                            <span
                                title={
                                    freshness.observedAt
                                        ? formatDateTime(freshness.observedAt)
                                        : undefined
                                }
                            >
                                {freshness.observedAt
                                    ? formatRelative(freshness.observedAt)
                                    : 'Not observed'}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {freshness.state === 'current'
                                ? 'Latest report is current'
                                : freshness.state === 'stale'
                                  ? 'Latest report is stale'
                                  : 'No recent report known'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={filterControls}
            rail={
                <PageHeaderRail
                    items={workspace.tabs.map((tab) => ({
                        key: tab.key,
                        label: tab.label,
                        icon:
                            tab.key === 'overview'
                                ? LayoutGrid
                                : tab.key === 'devices'
                                  ? Cpu
                                  : undefined,
                    }))}
                    value={workspace.activeTab}
                    onSelect={(tab) =>
                        router.get(
                            workspaceTabHref(workspace, filters, tab),
                            {},
                            { preserveState: true },
                        )
                    }
                    ariaLabel={`${workspace.title} workspace views`}
                    decorations={Object.fromEntries(
                        workspace.tabs
                            .filter((tab) => tab.state !== 'available')
                            .map((tab) => [
                                tab.key,
                                <StatusBadge key={tab.key} variant="neutral">
                                    {tab.stateLabel}
                                </StatusBadge>,
                            ]),
                    )}
                />
            }
        />
    );
}

export default function CategoryPage(props: CategoryPageProps) {
    const actorId =
        usePage<{ auth: { user: { id: number } | null } }>().props.auth.user
            ?.id ?? 0;
    const identity = JSON.stringify([
        actorId,
        props.pageConfig.slug,
        props.can,
        props.workspace.tabs.map((tab) => [tab.key, tab.state]),
        props.bulkManagement?.canObserve,
        props.bulkManagement?.canRequest,
    ]);
    return <CategoryWorkspace key={identity} {...props} />;
}
function CategoryWorkspace(props: CategoryPageProps) {
    const {
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
        can,
    } = props;
    const [listView, setListView] = useState<'cards' | 'table'>('cards');
    const [search, setSearch] = useState(filters.search ?? '');
    useEffect(() => setSearch(filters.search ?? ''), [filters.search]);
    const addDeviceDialog = useAddDeviceDialogState();
    const PageIcon = iconMap[pageConfig.icon] ?? Server;
    const registerLabel =
        pageConfig.slug.includes('-') ||
        ['security', 'healthcare', 'tracking'].includes(pageConfig.slug)
            ? 'Register device'
            : `Register ${singularizeTitle(pageConfig.title)}`;
    const showInventory =
        workspace.activeTabState === 'available' && !bulkManagement;
    const applyFilters = (changes: Record<string, string>) =>
        router.get(
            workspace.canonicalHref,
            { ...filters, ...changes, page: '1' },
            { preserveState: true },
        );
    const clearFilters = () => {
        setSearch('');
        router.get(
            workspace.canonicalHref,
            {
                tab: workspace.activeTab,
                ...(filters.device_id ? { device_id: filters.device_id } : {}),
            },
            { preserveState: true },
        );
    };
    const hasActiveFilters = Object.entries(filters).some(
        ([key, value]) =>
            !['tab', 'device_id', 'page'].includes(key) &&
            value &&
            value !== 'all',
    );
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
                { title: pageConfig.title, href: workspace.canonicalHref },
            ]}
        >
            <Head title={`${pageConfig.title} - Security & Devices`} />
            <div className="space-y-5">
                <CategoryWorkspaceHero
                    pageConfig={pageConfig}
                    workspace={workspace}
                    filters={filters}
                    canRegister={can.registerDevice}
                    registerLabel={registerLabel}
                    onRegister={addDeviceDialog.openDialog}
                    search={
                        showInventory ? (
                            <CategorySearchInput
                                title={pageConfig.title}
                                value={search}
                                onChange={setSearch}
                                onSubmit={() => applyFilters({ search })}
                            />
                        ) : undefined
                    }
                    filterControls={
                        showInventory ? (
                            <>
                                {filterOptions.categories &&
                                filterOptions.categories.length > 1
                                    ? filter(
                                          'category',
                                          'Categories',
                                          filterOptions.categories,
                                      )
                                    : null}
                                {filterOptions.subcategories.length > 0
                                    ? filter(
                                          'subcategory',
                                          'Device types',
                                          filterOptions.subcategories.map(
                                              (option) => ({
                                                  ...option,
                                                  label: `${option.label} (${stats.bySubcategory[option.value] ?? 0})`,
                                              }),
                                          ),
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
                                {hasActiveFilters ? (
                                    <PageHeaderFilterButton
                                        onClick={clearFilters}
                                    >
                                        Clear inventory filters
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
                        ) : undefined
                    }
                />
                <SecurityDevicesWorkspaceShell
                    workspace={workspace}
                    filters={filters}
                    showNavigation={false}
                    showSummary={false}
                    showFreshness={false}
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
                                    view={listView}
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
                            <CategoryInventory
                                key={JSON.stringify([
                                    filters,
                                    devices.data.map((device) => [
                                        device.id,
                                        device.name,
                                        device.assigned_to,
                                    ]),
                                ])}
                                devices={devices}
                                listView={listView}
                                onClear={clearFilters}
                                hasActiveFilters={Boolean(hasActiveFilters)}
                                search={filters.search}
                                config={pageConfig}
                                icon={PageIcon}
                                register={
                                    <CategoryRegisterAction
                                        onDark={false}
                                        canRegister={can.registerDevice}
                                        label={registerLabel}
                                        onRegister={addDeviceDialog.openDialog}
                                    />
                                }
                            />
                        </>
                    )}
                </SecurityDevicesWorkspaceShell>
                {can.registerDevice ? (
                    <AddDeviceDialog
                        open={addDeviceDialog.open}
                        onClose={addDeviceDialog.closeDialog}
                        prefillDomain={pageConfig.domain}
                    />
                ) : null}
            </div>
        </AppLayout>
    );
}
function CategoryInventory({
    devices,
    listView,
    onClear,
    hasActiveFilters,
    search,
    config,
    icon: Icon,
    register,
}: {
    devices: Paginated<DeviceListItem>;
    listView: 'cards' | 'table';
    onClear: () => void;
    hasActiveFilters: boolean;
    search?: string;
    config: PageConfig;
    icon: LucideIcon;
    register: ReactNode;
}) {
    const contextMenu = useEntityContextMenu<DeviceListItem>();
    const actionsFor = (device: DeviceListItem): MenuItem[] => [
        {
            label: 'Open device',
            icon: Eye,
            onClick: () =>
                router.visit(`/security-devices/devices/${device.id}`),
        },
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
            width: '110px',
            cell: (device) => <StatusBadge status={device.health_status} />,
        },
        {
            key: 'type',
            label: 'Equipment',
            width: '1fr',
            cell: (device) => (
                <EntityChip>{device.category.replaceAll('_', ' ')}</EntityChip>
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
    return (
        <section
            aria-label={`${config.title} device inventory`}
            className="space-y-5"
        >
            <ListCaption
                title="Device inventory"
                caption={`${devices.data.length} of ${devices.meta.total} shown`}
            />
            <p className="text-caption">
                Search and inventory filters apply to this device list.
            </p>
            {devices.data.length > 0 ? (
                listView === 'cards' ? (
                    <EntityCardGrid>
                        {devices.data.map((device) => (
                            <DeviceCard key={device.id} device={device} />
                        ))}
                    </EntityCardGrid>
                ) : (
                    <EntityTable
                        rows={devices.data}
                        rowKey={(device) => device.id}
                        identityLabel="Device"
                        identity={(device) => ({
                            icon: Icon,
                            name: device.name,
                            subline: device.device_uid,
                        })}
                        columns={columns}
                        actionsFor={actionsFor}
                        hrefFor={(device) =>
                            `/security-devices/devices/${device.id}`
                        }
                        onOpen={(device) =>
                            router.visit(
                                `/security-devices/devices/${device.id}`,
                            )
                        }
                        onRowContextMenu={contextMenu.open}
                    />
                )
            ) : hasActiveFilters ? (
                <EmptySearch
                    onClear={onClear}
                    searchTerm={search}
                    title={`No matching ${config.title.toLowerCase()} found`}
                />
            ) : (
                <EmptyState
                    icon={Icon}
                    title={config.emptyTitle}
                    description={config.emptyDescription}
                    action={register}
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
                    icon={Icon}
                    items={actionsFor(contextMenu.ctx.record)}
                    onClose={contextMenu.close}
                />
            ) : null}
        </section>
    );
}
