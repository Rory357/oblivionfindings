import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import { MaintenanceCompletionReview } from '@/components/security-devices/maintenance-completion-review';
import {
    MaintenanceRecords,
    maintenanceLabel,
} from '@/components/security-devices/maintenance-records';
import { SecurityDevicesSectionTabs } from '@/components/security-devices/security-devices-section-tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { LayoutGrid, List, RefreshCw, Search, Wrench } from 'lucide-react';
import { useState } from 'react';

type CurrentPermissions = {
    securityDevices?: {
        maintenanceView?: boolean;
        maintenanceManage?: boolean;
        devicesView?: boolean;
    };
};

type NamedLink = { id: number; name: string; href: string };
export type MaintenanceRow = {
    id: number;
    type: string;
    status: string;
    schedule_state: string;
    description: string;
    scheduled_for: string | null;
    completed_at: string | null;
    performed_by: string | null;
    vendor_reference: string | null;
    device: NamedLink & { domain: string | null; category: string | null };
    site: NamedLink | null;
};
export type MaintenanceWorkspace = {
    actor_id: number;
    tabs: Array<{ key: string; label: string }>;
    active_tab: string;
    boundary: { title: string; description: string; finance_note: string };
    summary: {
        total: number;
        overdue: number;
        due_soon: number;
        planned: number;
        in_progress: number;
        completed: number;
        calibration: number;
        firmware_configuration: number;
    };
    records: MaintenanceRow[];
    inventory: { total: number; shown: number; truncated: boolean };
    filters: {
        search: string | null;
        status: string | null;
        type: string | null;
        site_id: number | null;
        device_id: number | null;
        domain: string | null;
    };
    filter_options: {
        statuses: string[];
        types: string[];
        sites: Array<{ value: number; label: string }>;
        devices: Array<{ value: number; label: string }>;
    };
    permissions: { manage: boolean };
};
export default function Maintenance({
    workspace,
}: {
    workspace: MaintenanceWorkspace;
}) {
    // Partial actor metadata cannot establish ownership of retained private data.
    const [sourceActor] = useState(workspace.actor_id);
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: CurrentPermissions };
    }>().props;
    const visible = Boolean(
        auth?.user?.id &&
        sourceActor === workspace.actor_id &&
        workspace.actor_id === auth.user.id &&
        auth.can?.securityDevices?.maintenanceView === true,
    );
    return (
        <MaintenanceBody
            key={JSON.stringify([auth?.user?.id, auth?.can, workspace])}
            workspace={workspace}
            actor={auth?.user?.id ?? 0}
            visible={visible}
            canManage={
                visible &&
                workspace.permissions.manage &&
                auth?.can?.securityDevices?.maintenanceManage === true
            }
            profiles={auth?.can?.securityDevices?.devicesView === true}
            layout={layout}
            setLayout={setLayout}
        />
    );
}
function MaintenanceBody({
    workspace,
    actor,
    visible: receivedVisible,
    canManage,
    profiles,
    layout,
    setLayout,
}: {
    workspace: MaintenanceWorkspace;
    actor: number;
    visible: boolean;
    canManage: boolean;
    profiles: boolean;
    layout: 'cards' | 'table';
    setLayout: (value: 'cards' | 'table') => void;
}) {
    const [scopeDenied, setScopeDenied] = useState(false);
    const visible = receivedVisible && !scopeDenied;
    const [search, setSearch] = useState(
        visible ? (workspace.filters.search ?? '') : '',
    );
    const [review, setReview] = useState<MaintenanceRow | null>(null);
    const get = (changes: Record<string, string | number | null>) =>
        visible &&
        router.get(
            '/security-devices/maintenance',
            { ...workspace.filters, tab: workspace.active_tab, ...changes },
            { preserveState: true, preserveScroll: true },
        );
    const refresh = () => {
        if (!visible)
            return router.get(
                '/security-devices/maintenance',
                {},
                {
                    preserveState: false,
                    preserveScroll: true,
                },
            );
        return router.reload({
            only: ['workspace', 'auth'],
            onSuccess: (page) => {
                const data = page.props as {
                    workspace?: MaintenanceWorkspace;
                    auth?: {
                        user?: { id?: number };
                        can?: CurrentPermissions;
                    };
                };
                if (
                    page.component === 'security-devices/maintenance' &&
                    data.workspace?.actor_id === actor &&
                    data.auth?.user?.id === actor &&
                    data.auth?.can?.securityDevices?.maintenanceView === true
                )
                    setScopeDenied(false);
            },
        });
    };
    const denyCompletionScope = () => {
        setReview(null);
        setScopeDenied(true);
    };
    const selectScope = (tab: string) => {
        setSearch('');
        get({
            tab,
            search: null,
            status: null,
            type: null,
            site_id: null,
            device_id: null,
            domain: null,
        });
    };
    const options = (items: string[], label: string) => [
        { value: 'all', label },
        ...items.map((value) => ({ value, label: maintenanceLabel(value) })),
    ];
    const scopeOptions = (
        items: Array<{ value: number; label: string }>,
        label: string,
    ) => [
        { value: 'all', label },
        ...items.map((option) => ({ ...option, value: String(option.value) })),
    ];
    const tabCounts: Record<string, number> = {
        overview: workspace.summary.total,
        due: workspace.summary.overdue + workspace.summary.due_soon,
        planned: workspace.summary.planned,
        'in-progress': workspace.summary.in_progress,
        completed: workspace.summary.completed,
        calibration: workspace.summary.calibration,
        'firmware-configuration': workspace.summary.firmware_configuration,
    };
    const meters = [
        {
            label: 'Due or overdue',
            tab: 'due',
            value: workspace.summary.overdue + workspace.summary.due_soon,
            caption: `${workspace.summary.overdue} overdue · ${workspace.summary.due_soon} due soon`,
        },
        {
            label: 'Planned',
            tab: 'planned',
            value: workspace.summary.planned,
            caption: 'Later or awaiting a date',
        },
        {
            label: 'In progress',
            tab: 'in-progress',
            value: workspace.summary.in_progress,
            caption: 'Work records in progress',
        },
        {
            label: 'Completed',
            tab: 'completed',
            value: workspace.summary.completed,
            caption: 'Retained completed work',
        },
        {
            label: 'Calibration',
            tab: 'calibration',
            value: workspace.summary.calibration,
            caption: 'All calibration work',
        },
        {
            label: 'Firmware & configuration',
            tab: 'firmware-configuration',
            value: workspace.summary.firmware_configuration,
            caption: 'Scheduled technical work',
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Maintenance', href: '/security-devices/maintenance' },
            ]}
        >
            <Head title="Maintenance - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Wrench}
                    title="Maintenance"
                    mobileSummary={
                        visible
                            ? workspace.summary.total + ' retained work records'
                            : 'Maintenance records restricted'
                    }
                    subline="Device servicing, calibration and technical work across your permitted Sites"
                    actions={
                        <>
                            <PageHeaderSearch
                                ariaLabel="Search maintenance work"
                                placeholder="Search work, device, Site or reference…"
                                value={visible ? search : ''}
                                onChange={visible ? setSearch : () => undefined}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter')
                                        get({ search: search || null });
                                }}
                            />
                            <PageHeaderGlassButton
                                onClick={() => get({ search: search || null })}
                            >
                                <Search className="size-4" />
                                Search
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton onClick={refresh}>
                                <RefreshCw className="size-4" />
                                Refresh
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.tab}
                            label={meter.label}
                            onClick={() => selectScope(meter.tab)}
                        >
                            <PageHeaderMeterBig>
                                {visible ? meter.value : 'Restricted'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {visible
                                    ? meter.caption
                                    : 'Maintenance permission required'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="Site"
                                value={
                                    workspace.filters.site_id
                                        ? String(workspace.filters.site_id)
                                        : 'all'
                                }
                                options={
                                    visible
                                        ? scopeOptions(
                                              workspace.filter_options.sites,
                                              'All Sites',
                                          )
                                        : [
                                              {
                                                  value: 'all',
                                                  label: 'Restricted',
                                              },
                                          ]
                                }
                                onChange={(value) =>
                                    get({
                                        site_id:
                                            value === 'all'
                                                ? null
                                                : Number(value),
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Device"
                                value={
                                    workspace.filters.device_id
                                        ? String(workspace.filters.device_id)
                                        : 'all'
                                }
                                options={
                                    visible
                                        ? scopeOptions(
                                              workspace.filter_options.devices,
                                              'All devices',
                                          )
                                        : [
                                              {
                                                  value: 'all',
                                                  label: 'Restricted',
                                              },
                                          ]
                                }
                                onChange={(value) =>
                                    get({
                                        device_id:
                                            value === 'all'
                                                ? null
                                                : Number(value),
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Work type"
                                value={workspace.filters.type ?? 'all'}
                                options={
                                    visible
                                        ? options(
                                              workspace.filter_options.types,
                                              'All work types',
                                          )
                                        : [
                                              {
                                                  value: 'all',
                                                  label: 'Restricted',
                                              },
                                          ]
                                }
                                onChange={(value) =>
                                    get({
                                        type: value === 'all' ? null : value,
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Status"
                                value={workspace.filters.status ?? 'all'}
                                options={
                                    visible
                                        ? options(
                                              workspace.filter_options.statuses,
                                              'All statuses',
                                          )
                                        : [
                                              {
                                                  value: 'all',
                                                  label: 'Restricted',
                                              },
                                          ]
                                }
                                onChange={(value) =>
                                    get({
                                        status: value === 'all' ? null : value,
                                    })
                                }
                            />
                            {visible && workspace.filters.domain ? (
                                <PageHeaderFilterButton
                                    active
                                    onClick={() => get({ domain: null })}
                                >
                                    Clear domain scope:{' '}
                                    {maintenanceLabel(workspace.filters.domain)}
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
                            <PageHeaderMeterCaption>
                                {visible
                                    ? `${workspace.summary.total} retained records across permitted devices`
                                    : 'Maintenance records restricted'}
                            </PageHeaderMeterCaption>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={workspace.tabs.map((tab) => ({
                                ...tab,
                                count:
                                    visible &&
                                    !Object.values(workspace.filters).some(
                                        Boolean,
                                    )
                                        ? tabCounts[tab.key]
                                        : undefined,
                            }))}
                            value={workspace.active_tab}
                            onSelect={(tab) => get({ tab })}
                            ariaLabel="Maintenance views"
                        />
                    }
                />
                {visible ? (
                    <SecurityDevicesSectionTabs
                        section="alerts-upkeep"
                        activeTab="maintenance"
                    />
                ) : null}
                {visible ? (
                    <MaintenanceRecords
                        rows={workspace.records}
                        total={workspace.inventory.total}
                        shown={workspace.inventory.shown}
                        truncated={workspace.inventory.truncated}
                        view={layout}
                        profiles={profiles}
                        canManage={canManage}
                        onComplete={setReview}
                    />
                ) : (
                    <p role="note" className="text-subtle">
                        {scopeDenied
                            ? 'Completion is no longer permitted in this scope. Refresh Maintenance for current access.'
                            : 'Maintenance permission required. Refresh after your access changes.'}
                    </p>
                )}
                {visible && canManage && review ? (
                    <MaintenanceCompletionReview
                        row={review}
                        actor={actor}
                        onClose={() => setReview(null)}
                        onAuthorityDenied={denyCompletionScope}
                    />
                ) : null}
            </div>
        </AppLayout>
    );
}
