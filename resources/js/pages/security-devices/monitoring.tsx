import type { DeliveryRecovery } from '@/components/monitoring/delivery-recovery-card';
import { DeliveryRecoveryCard } from '@/components/monitoring/delivery-recovery-card';
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
    CapacityRecords,
    CollectionRecords,
    CoverageRecords,
    DependencyRecords,
    MonitorRecords,
    MonitoringFields,
    MonitoringState,
    QueueRecords,
    ReadinessRecords,
    RetentionRecords,
    RuntimeRecords,
    TrendRecords,
    monitoringLabel,
    type MonitoringOwners,
    type MonitoringView,
} from '@/components/security-devices/monitoring-operational-records';
import {
    NativeMonitorDeactivateDialog,
    NativeMonitorDialog,
    type NativeMonitorManagement,
    type NativeMonitorTarget,
} from '@/components/security-devices/native-monitor-dialogs';
import { NativeMonitorSavedRequests } from '@/components/security-devices/native-monitor-saved-requests';
import {
    ControlRoomAlertAccessRequired,
    type ControlRoomAlertAccess,
} from '@/components/security-devices/permission-destinations';
import { ReviewCard } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Gauge,
    LayoutGrid,
    List,
    Network,
    Plus,
    RefreshCw,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

type NamedLink = { id: number; name: string; href: string };

export type MonitorRow = {
    id: number;
    name: string;
    kind: string;
    reported_state: string;
    effective_state: string;
    affects_availability: boolean;
    enabled: boolean;
    operational: boolean;
    profile?: { id: number; name: string } | null;
    suppressed_until: string | null;
    suppression_reason?: string | null;
    root_cause?: {
        monitor_id: number;
        monitor_name: string;
        device_id: number;
        href: string;
    } | null;
    correlation?: {
        control_room: {
            id: number;
            reference: string | null;
            status: string | null;
            href: string | null;
            access: ControlRoomAlertAccess;
        } | null;
        it_incident: {
            id: number;
            reference: string | null;
            title: string;
            status: string;
            monitoring_recovered_at: string | null;
            href: string;
        } | null;
    } | null;
    last_observation_at: string | null;
    last_state_changed_at?: string | null;
    finding_scope?: string;
    freshness_state: string;
    device: NamedLink & { domain: string | null; category: string | null };
    site: NamedLink | null;
    collection:
        | {
              mode: 'remote_collector';
              collector_id: number;
              collector_name: string;
              state: string;
              last_seen_at: string | null;
          }
        | { mode: 'direct'; label: string; state: string };
    actions?: {
        actor_id?: number;
        review_token?: string | null;
        can_manage: boolean;
        update_url: string | null;
        deactivate_url: string | null;
    };
    latest_observation: {
        state: string;
        value: string | null;
        unit: string | null;
        latency_ms: number | null;
        observed_at: string | null;
    } | null;
};

export type CollectionPath = {
    collector_id: number | null;
    collector_name: string;
    state: string;
    reported_status: string;
    last_seen_at: string | null;
    heartbeat_lag_seconds: number | null;
    site: NamedLink | null;
    affected_monitors: number;
    affected_devices: number;
};

export type CentralSiteReadiness = {
    site: NamedLink;
    state: string;
    proof_state: string;
    label: string;
    note: string;
    devices: number;
    monitored_devices: number;
    unmonitored_devices: number;
    direct_monitors: number;
    direct_devices: number;
    remote_monitors: number;
    disabled_monitors: number;
    durable_direct_evidence: number;
    fresh: number;
    stale: number;
    never_observed: number;
    attention: number;
    evidence_at: string | null;
    evidence_age_seconds: number | null;
    runtime: {
        state: string;
        available: number;
        required: number;
        components: Record<string, string>;
    };
    topology: {
        state: string;
        source: string | null;
        captured_at: string | null;
        node_count: number;
        edge_count: number;
        change_count: number;
    };
    discovery: {
        state: string;
        scopes: number;
        completed_at: string | null;
    };
};

export type MonitoringWorkspace = {
    actor_id?: number;
    tabs: Array<{ key: string; label: string }>;
    active_tab: string;
    boundary: {
        title: string;
        description: string;
        privacy_note: string;
        control_room_note: string;
    };
    can: {
        view_control_room: boolean;
    };
    summary: {
        total_devices: number;
        total_monitors: number;
        enabled_monitors: number;
        direct_monitors: number;
        remote_monitors: number;
        monitored_devices: number;
        unmonitored_devices: number;
        healthy: number;
        degraded: number;
        failed: number;
        unknown: number;
        stale: number;
        pending: number;
        paused: number;
        collection_paths_unavailable: number;
        active_findings: number;
    };
    findings: {
        monitors: MonitorRow[];
        collection_paths: CollectionPath[];
        note: string;
    };
    monitors: MonitorRow[];
    monitor_management?: NativeMonitorManagement;
    inventory: { total: number; shown: number; truncated: boolean };
    coverage: {
        total_devices: number;
        monitored_devices: number;
        missing_devices: number;
        paused_monitors: number;
        fresh: number;
        stale: number;
        never_observed: number;
        unsupported_state: string;
        unsupported_note: string;
        by_kind: Record<string, number>;
        by_site: Array<{
            site: { id: number; name: string } | null;
            devices: number;
            monitored_devices: number;
            missing_devices: number;
        }>;
    };
    dependencies: {
        canonical_model_available: boolean;
        note: string;
        suppressed_symptoms: number;
        records: Array<{
            id: number;
            policy: string;
            source: string;
            confidence: number;
            review_state: string;
            site: NamedLink | null;
            upstream: {
                id: number | null;
                name: string | null;
                state: string | null;
                device_href: string | null;
            };
            downstream: {
                id: number | null;
                name: string | null;
                state: string | null;
                suppression_reason: string | null;
                device_href: string | null;
            };
        }>;
        collection_paths: CollectionPath[];
    };
    trends: Array<{
        monitor_id: number;
        monitor_name: string;
        device: NamedLink;
        samples: number;
        latest_value: string | null;
        previous_value: string | null;
        unit: string | null;
        direction: string;
        state_changes: number;
        retained_from: string | null;
        retained_to: string | null;
    }>;
    collection: {
        direct: { label: string; monitors: number; devices: number };
        direct_sites: CentralSiteReadiness[];
        remote_paths: CollectionPath[];
    };
    runtime: {
        state: string;
        workers: {
            state: string;
            available: number;
            total: number;
            attention: number;
            not_observed: number;
            note: string;
        };
        queues: Record<
            string,
            {
                state: string;
                pending: number | null;
                oldest_age_seconds: number | null;
                dead_letters: number | null;
                worker_state: string;
                heartbeat_age_seconds: number | null;
                dispatch_lag_seconds: number | null;
            }
        >;
        listeners: Record<
            string,
            { state: string; heartbeat_age_seconds: number | null }
        >;
        external_heartbeat: {
            state: string;
            reason_code: string | null;
            last_sent_age_seconds: number | null;
            last_evaluated_age_seconds: number | null;
            note: string;
        };
        storage: {
            time_series: { state: string; series: number };
            snapshots: { state: string; records: number; available: number };
        };
        collectors: {
            total: number;
            available: number;
            degraded: number;
            unavailable: number;
            revoked: number;
            backlog_items: number;
            gaps: number;
        };
        observed_at: string;
    };
    delivery: DeliveryRecovery;
    storage: {
        time_series: {
            state: string;
            series: number;
            series_shown: number;
            series_truncated: boolean;
            available: number;
            missing: number;
            unavailable: number;
            capacity_evidence: Array<{
                series_id: number;
                metric: string;
                unit: string;
                tier: string;
                device: NamedLink;
                value: string | null;
                p95: string | number | null;
                sample_count: number;
                observed_at: string | null;
                storage_state: string;
                projection: {
                    state: string;
                    threshold: number | null;
                    measured_from: string | null;
                    measured_to: string | null;
                    sample_count: number;
                    p95: number | null;
                    slope_per_day: number | null;
                    confidence: number;
                    threshold_at: string | null;
                };
            }>;
        };
        retention: {
            policies: Array<{
                id: number;
                name: string;
                scope: string;
                data_class: string | null;
                privacy_class: string | null;
                raw_days: number;
                hourly_days: number;
                daily_days: number;
                legal_hold: boolean;
            }>;
            explanation: string;
        };
    };
    filters: {
        search: string | null;
        state: string | null;
        kind: string | null;
        site_id: number | null;
        device_id: number | null;
        collection_mode: string | null;
    };
    filter_options: {
        states: string[];
        kinds: string[];
        sites: Array<{ value: number; label: string }>;
        devices: Array<{ value: number; label: string }>;
    };
};

export function MonitoringContent({
    workspace,
    onEditMonitor,
    onDeactivateMonitor,
    view = 'cards',
    owners = { profiles: true },
}: {
    workspace: MonitoringWorkspace;
    onEditMonitor?: (monitor: MonitorRow) => void;
    onDeactivateMonitor?: (monitor: MonitorRow) => void;
    view?: MonitoringView;
    owners?: MonitoringOwners;
}) {
    const shared = { view, owners };
    if (workspace.active_tab === 'findings')
        return (
            <div className="space-y-5">
                <CollectionRecords
                    {...shared}
                    rows={workspace.findings.collection_paths}
                    title="Collection-path findings"
                    description={workspace.findings.note}
                />
                <MonitorRecords
                    {...shared}
                    rows={workspace.findings.monitors}
                    title="Independent monitor findings"
                    empty="No independent monitor findings"
                    description="Collection-path symptoms are grouped separately."
                    onEdit={onEditMonitor}
                    onDeactivate={onDeactivateMonitor}
                />
            </div>
        );
    if (workspace.active_tab === 'coverage')
        return (
            <div className="space-y-5">
                <CoverageRecords
                    {...shared}
                    rows={workspace.coverage.by_site}
                />
                <ReviewCard icon={Gauge} title="Known coverage limits">
                    <MonitoringFields
                        items={[
                            [
                                'Permitted devices',
                                workspace.coverage.total_devices,
                            ],
                            [
                                'Monitored devices',
                                workspace.coverage.monitored_devices,
                            ],
                            [
                                'Devices missing coverage',
                                workspace.coverage.missing_devices,
                            ],
                            [
                                'Paused checks',
                                workspace.coverage.paused_monitors,
                            ],
                            ['Fresh checks', workspace.coverage.fresh],
                            ['Stale checks', workspace.coverage.stale],
                            [
                                'Never observed',
                                workspace.coverage.never_observed,
                            ],
                            [
                                'Unsupported equipment assessment',
                                <MonitoringState
                                    value={workspace.coverage.unsupported_state}
                                />,
                            ],
                            [
                                'Assessment explanation',
                                workspace.coverage.unsupported_note,
                            ],
                            ...Object.entries(workspace.coverage.by_kind).map(
                                ([kind, count]): [string, React.ReactNode] => [
                                    `Check type: ${monitoringLabel(kind)}`,
                                    count,
                                ],
                            ),
                        ]}
                    />
                </ReviewCard>
            </div>
        );
    if (workspace.active_tab === 'dependencies')
        return (
            <div className="space-y-5">
                <ReviewCard icon={Network} title="Dependency scope">
                    <p className="text-sm text-muted-foreground">
                        {workspace.dependencies.note}
                    </p>
                    <MonitoringFields
                        items={[
                            [
                                'Dependency model',
                                workspace.dependencies
                                    .canonical_model_available ? (
                                    <MonitoringState
                                        value="available"
                                        label="Canonical dependency model active"
                                    />
                                ) : (
                                    <MonitoringState
                                        value="not_assessed"
                                        label="Dependency model unavailable"
                                    />
                                ),
                            ],
                            [
                                'Suppressed symptoms',
                                `${workspace.dependencies.suppressed_symptoms} suppressed symptoms remain inspectable.`,
                            ],
                        ]}
                    />
                </ReviewCard>
                <DependencyRecords
                    {...shared}
                    rows={workspace.dependencies.records}
                />
                <CollectionRecords
                    {...shared}
                    rows={workspace.dependencies.collection_paths}
                />
            </div>
        );
    if (workspace.active_tab === 'trends')
        return (
            <div className="space-y-5">
                <TrendRecords {...shared} rows={workspace.trends} />
                <ReviewCard icon={Gauge} title="Retained history scope">
                    <p className="text-sm text-muted-foreground">
                        {workspace.storage.retention.explanation}
                    </p>
                    {workspace.storage.time_series.series_truncated ? (
                        <p className="text-caption">
                            Showing the{' '}
                            {workspace.storage.time_series.series_shown} most
                            recently active series below. Availability totals
                            cover all {workspace.storage.time_series.series}{' '}
                            retained series.
                        </p>
                    ) : null}
                    <MonitoringFields
                        items={[
                            [
                                'Series state',
                                <MonitoringState
                                    value={workspace.storage.time_series.state}
                                />,
                            ],
                            [
                                'Retained series',
                                workspace.storage.time_series.series,
                            ],
                            [
                                'Available',
                                workspace.storage.time_series.available,
                            ],
                            ['Missing', workspace.storage.time_series.missing],
                            [
                                'Unavailable',
                                workspace.storage.time_series.unavailable,
                            ],
                        ]}
                    />
                </ReviewCard>
                <CapacityRecords
                    {...shared}
                    rows={workspace.storage.time_series.capacity_evidence}
                    total={workspace.storage.time_series.series}
                />
                <RetentionRecords
                    view={view}
                    rows={workspace.storage.retention.policies}
                />
            </div>
        );
    if (workspace.active_tab === 'collection')
        return (
            <div className="space-y-5">
                <ReadinessRecords
                    {...shared}
                    rows={workspace.collection.direct_sites}
                />
                <ReviewCard icon={Network} title="Main application">
                    <p className="text-sm text-muted-foreground">
                        {workspace.collection.direct.label}
                    </p>
                    <MonitoringFields
                        items={[
                            [
                                'Direct checks',
                                workspace.collection.direct.monitors,
                            ],
                            [
                                'Direct devices',
                                workspace.collection.direct.devices,
                            ],
                        ]}
                    />
                </ReviewCard>
                <CollectionRecords
                    {...shared}
                    rows={workspace.collection.remote_paths}
                />
                <DeliveryRecoveryCard
                    delivery={workspace.delivery}
                    view={view}
                />
            </div>
        );
    return (
        <div className="space-y-5">
            <MonitorRecords
                {...shared}
                rows={workspace.monitors}
                empty={
                    Object.values(workspace.filters).some(Boolean)
                        ? 'No monitors match these filters'
                        : 'No monitoring checks in this permitted scope'
                }
                total={workspace.inventory.total}
                onEdit={onEditMonitor}
                onDeactivate={onDeactivateMonitor}
            />
            {workspace.inventory.truncated ? (
                <p
                    role="note"
                    className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning"
                >
                    Showing {workspace.inventory.shown} of{' '}
                    {workspace.inventory.total} matching checks. Refine the
                    monitor filters to inspect the remaining records.
                </p>
            ) : null}
            <RuntimeRecords runtime={workspace.runtime} view={view} />
            <QueueRecords view={view} queues={workspace.runtime.queues} />
        </div>
    );
}

export default function Monitoring({
    workspace,
}: {
    workspace: MonitoringWorkspace;
}) {
    const { auth } = usePage<{
        auth: { user?: { id: number }; can?: Record<string, boolean> };
    }>().props;
    const [view, setView] = useState<MonitoringView>(() => {
        try {
            return localStorage.getItem('sd.monitoring.view') === 'table'
                ? 'table'
                : 'cards';
        } catch {
            return 'cards';
        }
    });
    if (
        workspace.actor_id !== auth?.user?.id ||
        auth?.can?.['securityDevices.events.view'] !== true
    )
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    { title: 'Security & Devices', href: '/security-devices' },
                    {
                        title: 'Monitoring',
                        href: '/security-devices/monitoring',
                    },
                ]}
            >
                <Head title="Monitoring - Security & Devices" />
                <PageHeader
                    icon={Activity}
                    title="Monitoring"
                    subline="Refresh to load evidence for your current approved access."
                    actions={
                        <PageHeaderGlassButton
                            onClick={() =>
                                router.reload({ only: ['workspace'] })
                            }
                        >
                            Refresh monitoring
                        </PageHeaderGlassButton>
                    }
                />
                <p role="alert" className="text-sm text-muted-foreground">
                    Monitoring is being refreshed for your current access.
                </p>
            </AppLayout>
        );
    const scope = JSON.stringify([auth?.user?.id, auth?.can, workspace]);
    return (
        <MonitoringScreen
            key={scope}
            workspace={workspace}
            view={view}
            setView={(next) => {
                setView(next);
                try {
                    localStorage.setItem('sd.monitoring.view', next);
                } catch {
                    /* Layout preference is optional. */
                }
            }}
            owners={{
                profiles: auth?.can?.['securityDevices.devices.view'] === true,
            }}
        />
    );
}
function MonitoringScreen({
    workspace,
    view,
    setView,
    owners,
}: {
    workspace: MonitoringWorkspace;
    view: MonitoringView;
    setView: (view: MonitoringView) => void;
    owners: MonitoringOwners;
}) {
    const management: NativeMonitorManagement =
        workspace.monitor_management ?? {
            can_manage: false,
            create_url: null,
            kinds: [],
            profiles: [],
            devices: [],
        };
    const [savedRequestsOpen, setSavedRequestsOpen] = useState(false),
        [monitorDialogOpen, setMonitorDialogOpen] = useState(false),
        [editingMonitor, setEditingMonitor] =
            useState<NativeMonitorTarget | null>(null),
        [deactivatingMonitor, setDeactivatingMonitor] =
            useState<NativeMonitorTarget | null>(null),
        [search, setSearch] = useState(workspace.filters.search ?? '');
    const target = (monitor: MonitorRow): NativeMonitorTarget => ({
        id: monitor.id,
        name: monitor.name,
        kind: monitor.kind,
        enabled: monitor.enabled,
        affects_availability: monitor.affects_availability,
        profile: monitor.profile ?? null,
        device: {
            id: monitor.device.id,
            name: monitor.device.name,
            site: monitor.site
                ? { id: monitor.site.id, name: monitor.site.name }
                : undefined,
        },
        actions: monitor.actions ?? {
            can_manage: false,
            update_url: null,
            deactivate_url: null,
        },
    });
    const refresh = () =>
        router.reload({ only: ['workspace'], preserveScroll: true });
    const get = (changes: Record<string, string | number | null>) =>
        router.get(
            '/security-devices/monitoring',
            { ...workspace.filters, tab: 'overview', ...changes },
            { preserveState: true, preserveScroll: true },
        );
    const summary = (
        label: string,
        count: number,
        filter: Record<string, string>,
    ) => (
        <PageHeaderMeterBlock
            key={label}
            onClick={() =>
                router.get('/security-devices/monitoring', filter, {
                    preserveState: true,
                    preserveScroll: true,
                })
            }
            ariaLabel={`View ${label.toLowerCase()}`}
            label={label}
        >
            <PageHeaderMeterBig>{count}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>
                Across all permitted monitoring
            </PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Monitoring', href: '/security-devices/monitoring' },
            ]}
        >
            <Head title="Monitoring - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Activity}
                    title="Monitoring"
                    subline="Device checks, coverage and retained evidence across your permitted Sites."
                    mobileSummary={`${workspace.summary.total_monitors} checks · ${workspace.summary.active_findings} findings`}
                    actions={
                        <>
                            <PageHeaderSearch
                                ariaLabel="Search monitors"
                                placeholder="Search monitor, device or Site…"
                                value={search}
                                onChange={setSearch}
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
                            {management.can_manage && management.actor_id ? (
                                <PageHeaderGlassButton
                                    onClick={() => setSavedRequestsOpen(true)}
                                >
                                    Saved monitor changes
                                </PageHeaderGlassButton>
                            ) : null}
                            {management.can_manage &&
                            management.create_url ===
                                '/security-devices/monitoring/native-monitors' &&
                            management.devices.length > 0 &&
                            management.profiles.length > 0 ? (
                                <PageHeaderPrimaryButton
                                    onClick={() => {
                                        setEditingMonitor(null);
                                        setMonitorDialogOpen(true);
                                    }}
                                >
                                    <Plus className="size-4" />
                                    Create direct monitor
                                </PageHeaderPrimaryButton>
                            ) : null}
                            {workspace.can.view_control_room ? (
                                <PageHeaderGlassButton asChild>
                                    <Link href="/control-room">
                                        Open Control Room
                                    </Link>
                                </PageHeaderGlassButton>
                            ) : (
                                <ControlRoomAlertAccessRequired
                                    label="Control Room access required"
                                    className="min-h-0 text-band-foreground/75"
                                />
                            )}
                        </>
                    }
                    meters={[
                        summary('Checks', workspace.summary.total_monitors, {
                            tab: 'overview',
                        }),
                        summary('Healthy', workspace.summary.healthy, {
                            tab: 'overview',
                            state: 'healthy',
                        }),
                        summary('Findings', workspace.summary.active_findings, {
                            tab: 'findings',
                        }),
                        summary('Paused', workspace.summary.paused, {
                            tab: 'overview',
                            state: 'paused',
                        }),
                    ]}
                    filters={
                        <>
                            {workspace.active_tab === 'overview' ? (
                                <>
                                    {[
                                        {
                                            key: 'state' as const,
                                            label: 'All states',
                                            options:
                                                workspace.filter_options.states.map(
                                                    (value) => ({
                                                        value,
                                                        label: monitoringLabel(
                                                            value,
                                                        ),
                                                    }),
                                                ),
                                        },
                                        {
                                            key: 'kind' as const,
                                            label: 'All check types',
                                            options:
                                                workspace.filter_options.kinds.map(
                                                    (value) => ({
                                                        value,
                                                        label: monitoringLabel(
                                                            value,
                                                        ),
                                                    }),
                                                ),
                                        },
                                        {
                                            key: 'site_id' as const,
                                            label: 'All Sites',
                                            options:
                                                workspace.filter_options.sites.map(
                                                    (item) => ({
                                                        value: String(
                                                            item.value,
                                                        ),
                                                        label: item.label,
                                                    }),
                                                ),
                                        },
                                        {
                                            key: 'device_id' as const,
                                            label: 'All devices',
                                            options:
                                                workspace.filter_options.devices.map(
                                                    (item) => ({
                                                        value: String(
                                                            item.value,
                                                        ),
                                                        label: item.label,
                                                    }),
                                                ),
                                        },
                                        {
                                            key: 'collection_mode' as const,
                                            label: 'All collection modes',
                                            options: [
                                                {
                                                    value: 'direct',
                                                    label: 'Main application',
                                                },
                                                {
                                                    value: 'remote_collector',
                                                    label: 'Remote collector',
                                                },
                                            ],
                                        },
                                    ].map((item) => (
                                        <PageHeaderFilterSelect
                                            key={item.key}
                                            value={String(
                                                workspace.filters[item.key] ??
                                                    'all',
                                            )}
                                            label={item.label}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: item.label,
                                                },
                                                ...item.options,
                                            ]}
                                            onChange={(value) =>
                                                get({
                                                    [item.key]:
                                                        value === 'all'
                                                            ? null
                                                            : value,
                                                })
                                            }
                                        />
                                    ))}
                                    {Object.values(workspace.filters).some(
                                        Boolean,
                                    ) ? (
                                        <PageHeaderFilterButton
                                            onClick={() =>
                                                router.get(
                                                    '/security-devices/monitoring',
                                                    { tab: 'overview' },
                                                    {
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        >
                                            Clear monitor filters
                                        </PageHeaderFilterButton>
                                    ) : null}
                                </>
                            ) : (
                                <span className="text-[11.5px] text-band-foreground/70">
                                    These evidence views use the full permitted
                                    scope. Monitor search opens Overview.
                                </span>
                            )}
                            <PageHeaderViewToggle
                                value={view}
                                onChange={setView}
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
                            ariaLabel="Monitoring views"
                            items={workspace.tabs.map((tab) => ({
                                key: tab.key,
                                label: tab.label,
                            }))}
                            value={workspace.active_tab}
                            onSelect={(tab) =>
                                router.get(
                                    '/security-devices/monitoring',
                                    { ...workspace.filters, tab },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                    },
                                )
                            }
                        />
                    }
                />
                <ReviewCard icon={ShieldCheck} title={workspace.boundary.title}>
                    <p className="text-sm text-muted-foreground">
                        {workspace.boundary.description}
                    </p>
                    <p className="text-caption mt-2">
                        {workspace.boundary.privacy_note}
                    </p>
                    <p className="text-caption mt-1">
                        {workspace.boundary.control_room_note}
                    </p>
                </ReviewCard>
                <MonitoringContent
                    workspace={workspace}
                    view={view}
                    owners={owners}
                    onEditMonitor={(monitor) => {
                        setEditingMonitor(target(monitor));
                        setMonitorDialogOpen(true);
                    }}
                    onDeactivateMonitor={(monitor) =>
                        setDeactivatingMonitor(target(monitor))
                    }
                />
            </div>
            <NativeMonitorSavedRequests
                open={savedRequestsOpen}
                onOpenChange={setSavedRequestsOpen}
                actorId={management.actor_id ?? 0}
            />
            <NativeMonitorDialog
                open={monitorDialogOpen}
                onOpenChange={setMonitorDialogOpen}
                management={management}
                monitor={editingMonitor}
                onSaved={refresh}
            />
            <NativeMonitorDeactivateDialog
                open={deactivatingMonitor !== null}
                onOpenChange={(open) => {
                    if (!open) setDeactivatingMonitor(null);
                }}
                monitor={deactivatingMonitor}
                onDeactivated={refresh}
            />
        </AppLayout>
    );
}
