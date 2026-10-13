import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import {
    AddDeviceDialog,
    useAddDeviceDialogState,
} from '@/components/security-devices/add-device-dialog';
import {
    estateDistributions,
    estateQueues,
    EstateRecords,
    estateRecords,
    type EstatePermissions,
    type EstateView,
} from '@/components/security-devices/estate-records';
import type { EstateProps } from '@/components/security-devices/estate-types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Cctv, Cpu, LayoutGrid, List, Plus, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
export type { EstateProps } from '@/components/security-devices/estate-types';
export default function Dashboard(data: EstateProps) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const visible = Boolean(
        auth?.user?.id &&
        data.actor_id === auth.user.id &&
        auth.can?.['securityDevices.viewAny'] === true,
    );
    const devices =
        visible &&
        data.can.view_devices &&
        auth?.can?.['securityDevices.devices.view'] === true;
    const permission: EstatePermissions = {
        devices,
        events:
            devices &&
            data.can.view_events &&
            auth?.can?.['securityDevices.events.view'] === true,
        maintenance:
            devices &&
            data.can.view_maintenance &&
            auth?.can?.['securityDevices.maintenance.view'] === true,
        it: visible && auth?.can?.['it.view'] === true,
        controlRoom:
            visible &&
            (auth?.can?.['controlRoom.viewAny'] === true ||
                auth?.can?.['controlRoom.alerts.view'] === true),
    };
    return (
        <DashboardBody
            key={JSON.stringify([auth, data])}
            data={data}
            actor={auth?.user?.id ?? 0}
            visible={visible}
            permission={permission}
            create={
                visible &&
                data.can.create &&
                auth?.can?.['securityDevices.devices.create'] === true
            }
            layout={layout}
            setLayout={setLayout}
        />
    );
}
function DashboardBody({
    data,
    actor,
    visible: receivedVisible,
    permission,
    create,
    layout,
    setLayout,
}: {
    data: EstateProps;
    actor: number;
    visible: boolean;
    permission: EstatePermissions;
    create: boolean;
    layout: 'cards' | 'table';
    setLayout: (layout: 'cards' | 'table') => void;
}) {
    const [view, setView] = useState<EstateView>('overview'),
        [search, setSearch] = useState(''),
        [denied, setDenied] = useState(false),
        [notice, setNotice] = useState('');
    const add = useAddDeviceDialogState(),
        live = useRef(true),
        refreshing = useRef(false);
    const visible = receivedVisible && !denied;
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
        };
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                if (!live.current || !refreshing.current) return;
                const response = event.detail.response;
                if (
                    response.config.method?.toLowerCase() !== 'get' ||
                    typeof response.config.url !== 'string'
                )
                    return;
                let target: URL;
                try {
                    target = new URL(
                        response.config.url,
                        window.location.origin,
                    );
                } catch {
                    return;
                }
                if (
                    target.origin !== window.location.origin ||
                    target.pathname !== '/security-devices'
                )
                    return;
                event.preventDefault();
                refreshing.current = false;
                if ([401, 403, 404].includes(response.status)) {
                    setDenied(true);
                    setSearch('');
                    add.closeDialog();
                } else
                    setNotice(
                        'The estate could not be refreshed. The previous records are still shown with their read time. Try Refresh again.',
                    );
            }),
        [add],
    );
    const refresh = () => {
        if (refreshing.current) return;
        refreshing.current = true;
        setNotice('');
        router.reload({
            only: [
                'actor_id',
                'as_of',
                'stats',
                'domainSummary',
                'healthSummary',
                'attentionDevices',
                'recentEvents',
                'overdueMaintenance',
                'groupCount',
                'operations',
                'can',
                'auth',
            ],
            onSuccess: (page) => {
                if (!live.current) return;
                const props = page.props as {
                    actor_id?: number;
                    auth?: {
                        user?: { id?: number };
                        can?: Record<string, unknown>;
                    };
                };
                if (
                    page.component === 'security-devices/dashboard' &&
                    props.actor_id === actor &&
                    props.auth?.user?.id === actor &&
                    props.auth.can?.['securityDevices.viewAny'] === true
                )
                    setDenied(false);
                else {
                    setDenied(true);
                    setSearch('');
                    add.closeDialog();
                }
            },
            onFinish: () => {
                if (live.current) refreshing.current = false;
            },
        });
    };
    const select = (key: EstateView) => {
        if (visible) {
            setView(key);
            setSearch('');
        }
    };
    const views: Array<{ key: EstateView; label: string }> = [
        { key: 'overview', label: 'Overview' },
        { key: 'sites', label: 'Affected Sites' },
        { key: 'devices', label: 'Devices & domains' },
        { key: 'findings', label: 'Recent findings' },
        { key: 'work', label: 'Overdue work' },
        { key: 'changes', label: 'Recent changes' },
    ];
    const meters: Array<{
        label: string;
        value: number | string;
        caption: string;
        view: EstateView;
    }> = [
        {
            label: 'Affected Sites',
            value: permission.devices
                ? data.operations.summary.affected_sites
                : 'Restricted',
            caption: 'Recorded issues or overdue work',
            view: 'sites',
        },
        {
            label: 'Active findings',
            value: permission.events
                ? data.operations.summary.active_findings
                : 'Restricted',
            caption: 'Permitted findings in the past 24 hours',
            view: 'findings',
        },
        {
            label: 'Monitoring coverage',
            value: permission.events
                ? data.operations.coverage.percent === null
                    ? 'Not measured'
                    : data.operations.coverage.percent + '%'
                : 'Restricted',
            caption: 'Devices with an enabled monitor',
            view: 'overview',
        },
        {
            label: 'Open IT work',
            value: permission.it
                ? (data.operations.summary.open_it_work ?? 'Restricted')
                : 'Restricted',
            caption: 'Existing IT work queue',
            view: 'overview',
        },
        {
            label: 'Device records',
            value: permission.devices ? data.stats.totalDevices : 'Restricted',
            caption: 'Current permitted device scope',
            view: 'devices',
        },
        {
            label: 'Overdue work',
            value: permission.maintenance
                ? (data.stats.overdueMaintenance ?? 'Restricted')
                : 'Restricted',
            caption: 'Scheduled maintenance past its date',
            view: 'work',
        },
    ];
    const match = (rows: ReturnType<typeof estateRecords>) =>
        rows.filter((row) =>
            [
                row.name,
                row.subline,
                String(row.footer ?? ''),
                ...row.facts.map(
                    ([label, value]) =>
                        label +
                        ' ' +
                        (typeof value === 'string' || typeof value === 'number'
                            ? value
                            : ''),
                ),
            ]
                .join(' ')
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()),
        );
    const rows = visible ? match(estateRecords(data, permission, view)) : [],
        queues = visible ? match(estateQueues(data, permission)) : [],
        distributions = visible
            ? match(estateDistributions(data, permission))
            : [];
    const restricted =
        view === 'sites' || view === 'devices' || view === 'changes'
            ? !permission.devices
            : view === 'findings'
              ? !permission.events
              : view === 'work'
                ? !permission.maintenance
                : false;
    const descriptions: Record<EstateView, string> = {
        overview:
            'Counts and queues from the existing device, monitoring and work records.',
        sites: 'Sites with a recorded issue, missing monitoring or overdue work. Current placement is not historical custody.',
        devices:
            'Up to 10 permitted devices flagged by recorded health or offline status. Readings may be old.',
        findings:
            'Up to 10 critical or warning events in the permitted past 24-hour window.',
        work: 'Up to 10 scheduled maintenance records past their date. Existing work remains attached to its device.',
        changes:
            'Up to 12 permitted record and event changes returned by the server. These are stored changes, not a live feed.',
    };
    const empty = search
        ? 'No matching records'
        : view === 'sites'
          ? data.stats.totalDevices === 0
              ? 'No devices registered; health is not measured'
              : 'No affected Sites in this result'
          : view === 'devices'
            ? 'No devices needing attention in this result'
            : view === 'findings'
              ? 'No recent findings in this result'
              : view === 'work'
                ? 'No overdue work in this result'
                : 'No recent changes in this result';
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
            ]}
        >
            <Head title="Estate - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Cctv}
                    title="Security & Devices estate"
                    subline="Operational records and work across your approved Sites."
                    mobileSummary={
                        visible
                            ? permission.devices
                                ? `${data.stats.totalDevices} device records · ${data.operations.summary.affected_sites} affected Sites`
                                : 'Device details restricted'
                            : 'Estate records restricted'
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                ariaLabel="Search estate records"
                                placeholder="Search this view's records…"
                                value={visible ? search : ''}
                                onChange={(value) =>
                                    visible && setSearch(value)
                                }
                            />
                            <PageHeaderGlassButton onClick={refresh}>
                                <RefreshCw className="size-4" />
                                Refresh
                            </PageHeaderGlassButton>
                            {visible && permission.devices ? (
                                <PageHeaderGlassButton asChild>
                                    <Link href="/security-devices/devices">
                                        <Cpu className="size-4" />
                                        All devices
                                    </Link>
                                </PageHeaderGlassButton>
                            ) : null}
                            {visible && create ? (
                                <PageHeaderGlassButton onClick={add.openDialog}>
                                    <Plus className="size-4" />
                                    Register device
                                </PageHeaderGlassButton>
                            ) : null}
                        </>
                    }
                    meters={meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.label}
                            label={meter.label}
                            ariaLabel={`Open ${views.find((tab) => tab.key === meter.view)?.label.toLowerCase()} view`}
                            onClick={() => select(meter.view)}
                        >
                            <PageHeaderMeterBig>
                                {visible ? meter.value : 'Restricted'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {visible
                                    ? meter.caption
                                    : 'Estate access required'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    filters={
                        <>
                            {visible && search ? (
                                <PageHeaderFilterButton
                                    onClick={() => setSearch('')}
                                >
                                    Clear search
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
                        <PageHeaderRail
                            items={views}
                            value={view}
                            onSelect={select}
                            ariaLabel="Estate views"
                        />
                    }
                />
                {!visible ? (
                    <p
                        role="status"
                        className="rounded-xl border bg-card p-5 text-sm"
                    >
                        Estate records are restricted. Ask an administrator to
                        restore access, then Refresh.
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Read started{' '}
                            {formatDateTimeLong(
                                data.as_of,
                                'at a time not recorded',
                            )}
                            . Stored records can change between refreshes.
                        </p>
                        {notice ? (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {notice}
                            </p>
                        ) : null}
                        {restricted ? (
                            <p
                                role="status"
                                className="rounded-xl border bg-card p-5 text-sm"
                            >
                                {view === 'findings'
                                    ? 'Event and device'
                                    : view === 'work'
                                      ? 'Maintenance and device'
                                      : 'Device'}{' '}
                                access is required for this view. Ask an
                                administrator.
                            </p>
                        ) : (
                            <>
                                <EstateRecords
                                    rows={rows}
                                    view={layout}
                                    title={
                                        views.find((tab) => tab.key === view)
                                            ?.label || 'Estate records'
                                    }
                                    description={descriptions[view]}
                                    empty={empty}
                                />
                                {view === 'overview' ? (
                                    <EstateRecords
                                        rows={queues}
                                        view={layout}
                                        title="Work queues"
                                        description="Open the existing owner to review or assign work. A zero-item queue does not establish device health."
                                        empty={
                                            search
                                                ? 'No matching queues'
                                                : 'No work queues available'
                                        }
                                    />
                                ) : null}
                                {view === 'devices' ? (
                                    <EstateRecords
                                        rows={distributions}
                                        view={layout}
                                        title="Domains and recorded health"
                                        description="Scoped device counts by workspace and recorded classification."
                                        empty={
                                            search
                                                ? 'No matching distributions'
                                                : 'No distribution records available'
                                        }
                                    />
                                ) : null}
                                {view === 'sites' && permission.devices ? (
                                    <Button asChild variant="outline">
                                        <Link href="/security-devices/sites">
                                            View all Sites
                                        </Link>
                                    </Button>
                                ) : null}
                                {view === 'findings' && permission.events ? (
                                    <Button asChild variant="outline">
                                        <Link href="/security-devices/alerts-events">
                                            View all findings
                                        </Link>
                                    </Button>
                                ) : null}
                                {view === 'work' && permission.maintenance ? (
                                    <Button asChild variant="outline">
                                        <Link href="/security-devices/maintenance-health">
                                            View all maintenance & health
                                        </Link>
                                    </Button>
                                ) : null}
                            </>
                        )}
                    </>
                )}
            </div>
            {visible && create ? (
                <AddDeviceDialog open={add.open} onClose={add.closeDialog} />
            ) : null}
        </AppLayout>
    );
}
