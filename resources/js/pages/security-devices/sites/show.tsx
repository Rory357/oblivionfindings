import { EntityChip } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import {
    CoverageIndicator,
    formatCoverage,
} from '@/components/security-devices/estate-operations';
import { SiteProfileAccessRequired } from '@/components/security-devices/permission-destinations';
import {
    SiteAlerts,
    SiteChanges,
    SiteCollectors,
    SiteContacts,
    SiteDevices,
    SiteEdges,
    SiteEvidenceFields,
    SiteEvidenceTime,
    SiteGroups,
    SiteItWork,
    SiteMaintenance,
    SiteMonitors,
    technologyLabel,
} from '@/components/security-devices/site-technology-records';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BellRing,
    Building2,
    Clock3,
    Cpu,
    LayoutGrid,
    List,
    Network,
    RadioTower,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { SiteTechnologyProps } from './_types';
export type { SiteTechnologyProps } from './_types';
type ProfileView =
    | 'overview'
    | 'network'
    | 'monitoring'
    | 'work'
    | 'changes'
    | 'devices';
const views = [
    { key: 'overview' as const, label: 'Overview', icon: Building2 },
    { key: 'network' as const, label: 'WAN & topology', icon: Network },
    { key: 'monitoring' as const, label: 'Monitoring', icon: Activity },
    { key: 'work' as const, label: 'Alerts & work', icon: BellRing },
    { key: 'changes' as const, label: 'Recent changes', icon: Clock3 },
    { key: 'devices' as const, label: 'Devices', icon: Cpu },
];
function hashView(): ProfileView {
    if (typeof window === 'undefined') return 'overview';
    const key = window.location.hash.replace(/^#site-/, '');
    return key === 'monitoring-failed'
        ? 'monitoring'
        : views.some((view) => view.key === key)
          ? (key as ProfileView)
          : 'overview';
}
function healthLabel(value: string) {
    return value === 'healthy'
        ? 'Healthy'
        : value === 'critical'
          ? 'Critical'
          : value === 'warning'
            ? 'Needs attention'
            : 'Unknown health';
}
export default function SiteTechnologyShow(props: SiteTechnologyProps) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    const identity = JSON.stringify([auth?.user?.id, auth?.can, props]);
    return <SiteTechnologyProfile key={identity} {...props} />;
}
function SiteTechnologyProfile(props: SiteTechnologyProps) {
    const {
        site,
        devices,
        summary,
        wan,
        topology,
        deviceGroups,
        monitoring,
        alerts,
        itWork,
        maintenance,
        collectors,
        changes,
        contacts,
        can,
    } = props;
    const [view, setView] = useState<ProfileView>(hashView);
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [search, setSearch] = useState('');
    const [failedOnly, setFailedOnly] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.location.hash === '#site-monitoring-failed',
    );
    useEffect(() => {
        const update = () => {
            setView(hashView());
            setFailedOnly(window.location.hash === '#site-monitoring-failed');
        };
        window.addEventListener('hashchange', update);
        return () => window.removeEventListener('hashchange', update);
    }, []);
    const selectView = (next: ProfileView, onlyFailed = false) => {
        setView(next);
        setFailedOnly(onlyFailed);
        const hash =
            '#site-' +
            (next === 'monitoring' && onlyFailed ? 'monitoring-failed' : next);
        if (window.location.hash !== hash) window.location.hash = hash;
    };
    const monitoringReadable = monitoring.monitored_devices !== null;
    const filteredDevices = devices.filter((device) =>
        [
            device.name,
            device.device_uid,
            device.category,
            device.subcategory,
            device.manufacturer,
            device.model,
            device.provider,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(search.trim().toLowerCase()),
    );
    const monitorRows = failedOnly
        ? monitoring.monitors.filter((monitor) =>
              ['failed', 'degraded'].includes(monitor.state),
          )
        : monitoring.monitors;
    const permission = (label: string) => (
        <p
            role="note"
            className="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground"
        >
            {label}
        </p>
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Sites', href: '/security-devices/sites' },
                {
                    title: site.name,
                    href: '/security-devices/sites/' + site.id,
                },
            ]}
        >
            <Head title={site.name + ' - Security & Devices'} />
            <PageLayout
                width="wide"
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/security-devices/sites"
                        icon={Building2}
                        title={site.name}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    summary.health === 'critical'
                                        ? 'critical'
                                        : summary.health === 'warning'
                                          ? 'warning'
                                          : summary.health === 'healthy'
                                            ? 'success'
                                            : 'neutral'
                                }
                            >
                                {healthLabel(summary.health)}
                            </PageHeaderStatusChip>
                        }
                        subline={
                            <>
                                <span className="block">
                                    {site.address ||
                                        site.city ||
                                        'No Site address recorded'}
                                </span>
                                <span className="block">
                                    {technologyLabel(site.type)} ·{' '}
                                    {site.is_active
                                        ? 'Active Site'
                                        : 'Inactive Site'}{' '}
                                    · Technology
                                </span>
                            </>
                        }
                        mobileSummary={
                            summary.devices +
                            ' devices · ' +
                            summary.attention_devices +
                            ' need attention'
                        }
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={(value) => {
                                        setSearch(value);
                                        selectView('devices');
                                    }}
                                    ariaLabel="Search devices at this Site"
                                    placeholder="Search Site devices…"
                                />
                                {can.view_site_profile ? (
                                    <PageHeaderGlassButton asChild>
                                        <Link
                                            href={
                                                '/sites/' +
                                                site.id +
                                                '?tab=technology'
                                            }
                                        >
                                            Open Site profile
                                        </Link>
                                    </PageHeaderGlassButton>
                                ) : (
                                    <SiteProfileAccessRequired className="text-band-foreground/80" />
                                )}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Devices"
                                    ariaLabel="View Site devices"
                                    onClick={() => {
                                        setSearch('');
                                        selectView('devices');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {summary.devices}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Within this approved Site
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Monitored devices"
                                    ariaLabel="View Site monitoring"
                                    onClick={() => selectView('monitoring')}
                                >
                                    <PageHeaderMeterBig>
                                        {monitoring.monitored_devices ??
                                            'Restricted'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {monitoringReadable
                                            ? formatCoverage(
                                                  summary.coverage_percent,
                                              )
                                            : 'Monitoring permission required'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Failed checks"
                                    ariaLabel="View failed Site checks"
                                    onClick={() =>
                                        selectView('monitoring', true)
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {monitoring.failed_monitors ??
                                            'Restricted'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Failed or degraded results
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Open IT work"
                                    ariaLabel="View Site IT work"
                                    onClick={() => selectView('work')}
                                >
                                    <PageHeaderMeterBig>
                                        {can.view_it_work
                                            ? (summary.open_it_work ??
                                              'Restricted')
                                            : 'Restricted'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {can.view_it_work
                                            ? 'Owned by IT & Support'
                                            : 'IT & Support permission required'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                {search ? (
                                    <PageHeaderFilterButton
                                        onClick={() => setSearch('')}
                                    >
                                        Clear device search
                                    </PageHeaderFilterButton>
                                ) : null}
                                <PageHeaderViewToggle
                                    value={layout}
                                    onChange={(value) =>
                                        setLayout(value as 'cards' | 'table')
                                    }
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
                            <PageHeaderRail<ProfileView>
                                items={views}
                                value={view}
                                onSelect={(next) => selectView(next)}
                                ariaLabel="Site technology sections"
                            />
                        }
                    />
                }
            >
                <section
                    id={'site-' + view}
                    aria-label={views.find((item) => item.key === view)?.label}
                    className="space-y-5"
                >
                    {view === 'overview' ? (
                        <>
                            <div className="grid gap-5 lg:grid-cols-2">
                                <ReviewCard
                                    icon={Building2}
                                    title="Technology at this Site"
                                >
                                    <SiteEvidenceFields
                                        items={[
                                            ['Devices', summary.devices],
                                            [
                                                'Need attention',
                                                summary.attention_devices,
                                            ],
                                            [
                                                'Offline devices',
                                                summary.offline_devices,
                                            ],
                                            [
                                                'Without monitoring',
                                                summary.unmonitored_devices,
                                            ],
                                            [
                                                'Active findings',
                                                summary.active_findings,
                                            ],
                                            [
                                                'Control Room alerts',
                                                can.view_control_room
                                                    ? summary.active_control_room_alerts
                                                    : null,
                                            ],
                                            [
                                                'Overdue maintenance',
                                                summary.overdue_maintenance,
                                            ],
                                        ]}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={RadioTower}
                                    title="Collection and observations"
                                >
                                    <ReviewRow
                                        label="Monitoring coverage"
                                        value={
                                            monitoringReadable
                                                ? formatCoverage(
                                                      summary.coverage_percent,
                                                  )
                                                : 'Restricted'
                                        }
                                    />
                                    <ReviewRow
                                        label="Collector state"
                                        value={
                                            monitoringReadable
                                                ? summary.collector.label
                                                : 'Monitoring permission required'
                                        }
                                    />
                                    <ReviewRow
                                        label="Local collectors"
                                        value={
                                            monitoringReadable
                                                ? summary.collector.count
                                                : 'Restricted'
                                        }
                                    />
                                    <ReviewRow
                                        label="Collector contact"
                                        value={
                                            monitoringReadable ? (
                                                <SiteEvidenceTime
                                                    value={
                                                        summary.collector
                                                            .last_seen_at
                                                    }
                                                    empty="No contact recorded"
                                                />
                                            ) : (
                                                'Restricted'
                                            )
                                        }
                                    />
                                    <ReviewRow
                                        label="Latest technology change"
                                        value={
                                            <SiteEvidenceTime
                                                value={summary.last_change_at}
                                                empty="No change recorded"
                                            />
                                        }
                                    />
                                </ReviewCard>
                            </div>
                            {monitoringReadable ? (
                                <SiteCollectors
                                    rows={collectors}
                                    view={layout}
                                />
                            ) : (
                                permission(
                                    'Monitoring permission is required to view collector details.',
                                )
                            )}
                            <SiteContacts rows={contacts} view={layout} />
                        </>
                    ) : null}
                    {view === 'network' ? (
                        <>
                            <div className="grid gap-5 lg:grid-cols-2">
                                <ReviewCard icon={Network} title="WAN context">
                                    <ReviewRow
                                        label="Context"
                                        value={wan.label}
                                    />
                                    <ReviewRow
                                        label="WAN device classification"
                                        value={
                                            wan.known
                                                ? 'Recorded'
                                                : 'Not recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Address"
                                        value={
                                            site.address ||
                                            site.city ||
                                            'No Site address recorded'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Network}
                                    title="Topology evidence"
                                >
                                    <ReviewRow
                                        label="Known links"
                                        value={topology.edge_count}
                                    />
                                    <ReviewRow
                                        label="Devices"
                                        value={topology.device_count}
                                    />
                                    <ReviewRow
                                        label="Device groups"
                                        value={deviceGroups.length}
                                    />
                                    <ReviewRow
                                        label="Relationship coverage"
                                        value={
                                            topology.is_complete
                                                ? 'Recorded'
                                                : 'Incomplete'
                                        }
                                    />
                                </ReviewCard>
                            </div>
                            {!topology.is_complete && devices.length > 1 ? (
                                <p className="rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-sm text-status-warning">
                                    Topology is incomplete. Unknown links are
                                    not treated as healthy paths.
                                </p>
                            ) : null}
                            <SiteDevices
                                rows={wan.devices}
                                view={layout}
                                title="WAN devices"
                            />
                            <SiteEdges rows={topology.edges} view={layout} />
                            <SiteGroups rows={deviceGroups} view={layout} />
                        </>
                    ) : null}
                    {view === 'monitoring' ? (
                        monitoringReadable ? (
                            <>
                                <ReviewCard
                                    icon={Activity}
                                    title="Monitoring coverage"
                                >
                                    <CoverageIndicator
                                        percent={summary.coverage_percent}
                                        monitored={
                                            monitoring.monitored_devices!
                                        }
                                        total={monitoring.total_devices}
                                    />
                                    <SiteEvidenceFields
                                        items={[
                                            [
                                                'Without monitoring',
                                                monitoring.unmonitored_devices,
                                            ],
                                            [
                                                'Failed or degraded checks',
                                                monitoring.failed_monitors,
                                            ],
                                            [
                                                'Uncertain checks',
                                                monitoring.uncertain_monitors,
                                            ],
                                        ]}
                                    />
                                </ReviewCard>
                                {failedOnly ? (
                                    <div className="flex items-center gap-2">
                                        <EntityChip>
                                            Failed or degraded checks
                                        </EntityChip>
                                        <PageHeaderFilterButton
                                            onClick={() =>
                                                selectView('monitoring')
                                            }
                                        >
                                            Show all checks
                                        </PageHeaderFilterButton>
                                    </div>
                                ) : null}
                                <SiteMonitors
                                    rows={monitorRows}
                                    view={layout}
                                />
                            </>
                        ) : (
                            permission(
                                'Monitoring permission is required. No restricted readings or collector details are shown.',
                            )
                        )
                    ) : null}
                    {view === 'work' ? (
                        <>
                            {can.view_control_room ? (
                                <SiteAlerts rows={alerts} view={layout} />
                            ) : (
                                permission(
                                    'Control Room permission is required.',
                                )
                            )}
                            {can.view_it_work ? (
                                <SiteItWork rows={itWork} view={layout} />
                            ) : (
                                permission(
                                    'IT & Support permission is required.',
                                )
                            )}
                            {summary.overdue_maintenance !== null ? (
                                <SiteMaintenance
                                    rows={maintenance}
                                    view={layout}
                                />
                            ) : (
                                permission(
                                    'Maintenance permission is required.',
                                )
                            )}
                        </>
                    ) : null}
                    {view === 'changes' ? (
                        <SiteChanges rows={changes} view={layout} />
                    ) : null}
                    {view === 'devices' ? (
                        <SiteDevices
                            rows={filteredDevices}
                            view={layout}
                            total={summary.devices}
                        />
                    ) : null}
                </section>
            </PageLayout>
        </AppLayout>
    );
}
