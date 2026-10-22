import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import {
    CredentialReferenceManagement,
    type CredentialReferenceWorkspace,
} from '@/components/security-devices/credential-reference-management';
import {
    MonitoringPolicySettings,
    type MonitoringPolicyWorkspace,
} from '@/components/security-devices/monitoring-policy-settings';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { readableKey, readablePhrase } from '@/lib/security-devices-labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    ClipboardList,
    FileClock,
    KeyRound,
    Layers,
    LayoutDashboard,
    ListChecks,
    RadioTower,
    Settings2,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

export type AuditEntry = {
    id: number;
    action: string;
    actor: string;
    record_type: string;
    record_reference: string | null;
    fields: string[];
    created_at: string | null;
};
type SettingsArea = { title: string; description: string; href: string };
type Profile = {
    id: number;
    name: string;
    description: string | null;
    interval_seconds: number;
    failure_confirmations: number;
    recovery_confirmations: number;
    stale_after_seconds: number;
    state: string;
};
type RetentionPolicy = {
    id: number;
    name: string;
    scope: string;
    site_id: number | null;
    device_id: number | null;
    data_class: string | null;
    privacy_class: string | null;
    raw_days: number;
    hourly_days: number;
    daily_days: number;
    legal_hold: boolean;
};
type RuntimeQueue = {
    state: string;
    pending: number | null;
    oldest_age_seconds: number | null;
    dead_letters: number | null;
    worker_state: string;
    heartbeat_age_seconds: number | null;
    dispatch_lag_seconds: number | null;
};

const actionLabels: Record<string, string> = {
    'device.update': 'Device updated',
    'monitoring.maintenance.created': 'Maintenance scheduled',
    'monitoring.maintenance.updated': 'Maintenance changed',
    'monitoring.maintenance.cancelled': 'Maintenance cancelled',
    'monitoring.maintenance.command_committed': 'Maintenance request saved',
    'monitoring.profile.created': 'Monitoring profile created',
    'monitoring.profile.updated': 'Monitoring profile changed',
    'monitoring.profile.deactivated': 'Monitoring profile turned off',
};
/** Plain sentence-case names for settings keys; the keys stay for logic. */
const settingLabels: Record<string, string> = {
    // Supported features
    classification_taxonomy: 'Classification',
    monitoring_profiles: 'Monitoring profiles',
    integration_site_mapping: 'Site mapping for integrations',
    discovery_candidates: 'Discovery results',
    monitoring_retention: 'Monitoring data retention',
    provider_capabilities: 'Provider capabilities',
    credential_leases: 'Credential leases',
    database_audit_immutability: 'Audit log protection',
    // Monitoring service states and queues
    operational: 'Operational',
    attention: 'Needs attention',
    not_observed: 'Not observed',
    scope_restricted: 'Restricted',
    events: 'Device events',
    checks: 'Monitoring checks',
    discovery: 'Discovery',
    provider: 'Provider sync',
    topology: 'Topology',
    maintenance: 'Maintenance',
    orchestration: 'Task scheduling',
    commands: 'Device commands',
    // Source defaults
    refresh_interval_minutes: 'Refresh interval (minutes)',
    alert_motion_events: 'Alert on motion events',
    alert_device_offline: 'Alert when a device goes offline',
    quiet_hours_start: 'Quiet hours start',
    quiet_hours_end: 'Quiet hours end',
    // Sources
    unifi: 'UniFi',
    hikvision: 'Hikvision',
    hikvision_recorder: 'Hikvision recorder',
    milesight: 'Milesight',
    queclink: 'Queclink',
};
const settingLabel = (key: string) => settingLabels[key] ?? readableKey(key);
const recordTypeLabels: Record<string, string> = {
    DeviceAssetLink: 'Device asset link',
    DeviceMaintenanceRecord: 'Maintenance record',
    MonitoringCoverageExpectation: 'Coverage expectation',
    MonitoringMaintenanceWindow: 'Maintenance window',
    MonitoringRetentionPolicy: 'Retention policy',
    IntegrationProviderConnection: 'Provider connection',
    IntegrationSiteConfig: 'Site integration settings',
    IntegrationSiteSecret: 'Site integration credential',
    IntegrationSyncLog: 'Sync log',
};
const recordTypeLabel = (type: string) =>
    recordTypeLabels[type] ?? readableKey(type);
/** Changed-field names, read mid-sentence ("Changed: name, Site"). */
const fieldLabels: Record<string, string> = {
    site_id: 'Site',
    device_id: 'device',
    monitor_id: 'monitor',
    upstream_monitor_id: 'upstream monitor',
    downstream_monitor_id: 'downstream monitor',
    assignable_id: 'assigned record',
    assignable_type: 'assigned record type',
    is_active: 'active',
    is_enabled: 'enabled',
    secret_last4: 'secret ending',
    mapped_external_site_name: 'provider Site name',
};
const fieldLabel = (key: string) => fieldLabels[key] ?? readablePhrase(key);

export function AuditEvidence({
    visible,
    entries,
}: {
    visible: boolean;
    entries: AuditEntry[];
}) {
    if (!visible)
        return (
            <SettingsNotice>
                You need report permission to view change history.
            </SettingsNotice>
        );
    if (!entries.length)
        return (
            <SettingsNotice>
                No changes are recorded in your current scope.
            </SettingsNotice>
        );
    return (
        <EntityCardGrid>
            {entries.map((entry) => (
                <EntityCard
                    key={entry.id}
                    meridian="neutral"
                    actions={[]}
                    icon={FileClock}
                    name={actionLabels[entry.action] ?? 'Configuration changed'}
                    subline={
                        <>
                            {entry.actor} ·{' '}
                            {entry.created_at
                                ? formatDateTime(entry.created_at)
                                : 'Time unavailable'}
                        </>
                    }
                    chips={
                        <StatusBadge variant="neutral">
                            {recordTypeLabel(entry.record_type)}
                        </StatusBadge>
                    }
                    footer={{
                        primary: entry.record_reference ?? 'Recorded change',
                        secondary: entry.fields.length
                            ? 'Changed: ' +
                              entry.fields.map(fieldLabel).join(', ')
                            : 'No changed-field details recorded',
                    }}
                />
            ))}
        </EntityCardGrid>
    );
}

function Sections({
    tabs,
    value,
    onChange,
    label,
}: {
    tabs: GroupedProfileNavTab[];
    value: string;
    onChange: (value: string) => void;
    label: string;
}) {
    const tabId = useId();
    return (
        <TierTwoTabs
            tabs={tabs}
            testIdPrefix={tabId}
            activeTab={value}
            onTab={onChange}
            ariaLabel={label}
            renderLink={(tab, className, inner, accessibility) => (
                <Button
                    variant="ghost"
                    key={tab.key}
                    className={className}
                    {...accessibility}
                    onClick={() => onChange(tab.key)}
                >
                    {inner}
                </Button>
            )}
        />
    );
}

type SettingsView = 'overview' | 'monitoring' | 'sources' | 'audit';
const views = [
    { key: 'overview' as const, label: 'Overview', icon: LayoutDashboard },
    { key: 'monitoring' as const, label: 'Monitoring', icon: Activity },
    { key: 'sources' as const, label: 'Sources & access', icon: KeyRound },
    { key: 'audit' as const, label: 'Change history', icon: FileClock },
];
export type SettingsAuditProps = {
    summary: { device_groups: number; audit_entries: number };
    areas: SettingsArea[];
    classificationDefaults: {
        state: string;
        values: Record<string, unknown>;
        note: string;
    };
    providerOperationalDefaults: Array<{
        provider: string;
        state: string;
        values: Record<string, unknown>;
    }>;
    credentialReferences: CredentialReferenceWorkspace;
    monitoringProfiles: Profile[];
    monitoringRetention: {
        policies: RetentionPolicy[];
        application_defaults: {
            raw_days: number;
            hourly_days: number;
            daily_days: number;
        };
        rule: string;
    };
    monitoringPolicyWorkspace: MonitoringPolicyWorkspace;
    monitoringRuntime: {
        state: string;
        workers: {
            state: string;
            available: number;
            total: number;
            attention: number;
            not_observed: number;
            note: string;
        };
        queues: Record<string, RuntimeQueue>;
        observed_at: string;
    };
    dataQuality: {
        visible_devices: number;
        unassigned_devices: number;
        duplicate_candidates: number;
        note: string;
    };
    featureSupport: Record<string, { state: string; note: string }>;
    audit: {
        visible: boolean;
        evidence_state: string;
        entries: AuditEntry[];
        limit: number;
    };
};

export default function SettingsAudit(props: SettingsAuditProps) {
    const actorId =
        usePage<{ auth: { user: { id: number } | null } }>().props.auth.user
            ?.id ?? 0;
    const workspace = props.monitoringPolicyWorkspace,
        credentials = props.credentialReferences;
    const authorityIdentity = JSON.stringify([
        actorId,
        workspace.actor_id ?? 0,
        workspace.visible,
        workspace.can_manage,
        workspace.can_manage_application,
        workspace.sites.map((site) => site.id),
        workspace.devices.map((device) => [device.id, device.site_id]),
        workspace.monitors.map((monitor) => [
            monitor.id,
            monitor.device_id,
            monitor.site_id,
        ]),
        credentials.visible,
        credentials.sites.map((site) => site.id),
        props.audit.visible,
    ]);
    return <SettingsAuditWorkspace key={authorityIdentity} {...props} />;
}

function SettingsAuditWorkspace(props: SettingsAuditProps) {
    const {
        summary,
        areas,
        classificationDefaults,
        providerOperationalDefaults,
        credentialReferences,
        monitoringPolicyWorkspace: workspace,
        monitoringRuntime: runtime,
        dataQuality,
        featureSupport,
        audit,
    } = props;
    const [view, setView] = useState<SettingsView>('overview'),
        [sourceView, setSourceView] = useState('overview'),
        [auditView, setAuditView] = useState('overview'),
        [query, setQuery] = useState(''),
        [draftOpen, setDraftOpen] = useState(false);
    const [leaving, setLeaving] = useState<{
        view?: SettingsView;
        href?: string;
    } | null>(null);
    const draft = useRef(false),
        confirmedLeave = useRef(false);
    useEffect(() => {
        draft.current = draftOpen;
    }, [draftOpen]);
    const select = (next: SettingsView) => {
        if (next === view) return;
        if (draftOpen) {
            setLeaving({ view: next });
            return;
        }
        setView(next);
        setQuery('');
    };
    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (draft.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', beforeUnload);
        const stop = router.on('before', (event) => {
            if (
                !draft.current ||
                confirmedLeave.current ||
                event.detail.visit.method !== 'get' ||
                event.detail.visit.url.pathname === window.location.pathname
            )
                return;
            event.preventDefault();
            setLeaving({ href: event.detail.visit.url.href });
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            stop();
        };
    }, []);
    const policyCount =
        workspace.profiles.length +
        workspace.coverage.length +
        workspace.dependencies.length +
        workspace.maintenance.length +
        workspace.retention.length;
    const review = (label: string, next: SettingsView) => (
        <Button variant="link" className="px-0" onClick={() => select(next)}>
            Review {label} <ArrowUpRight aria-hidden className="size-3.5" />
        </Button>
    );
    const matches = (...text: string[]) =>
        text.join(' ').toLowerCase().includes(query.toLowerCase());
    const sourceTabs = [
        { key: 'overview', label: 'Overview', icon: LayoutDashboard },
        { key: 'credentials', label: 'Access references', icon: KeyRound },
        { key: 'defaults', label: 'Source defaults', icon: Layers },
    ];
    const auditTabs = [
        { key: 'overview', label: 'Overview', icon: LayoutDashboard },
        { key: 'changes', label: 'Changes', icon: FileClock },
        { key: 'runtime', label: 'Monitoring service', icon: RadioTower },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Settings & audit',
                    href: '/security-devices/settings',
                },
            ]}
        >
            <Head title="Settings & audit - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Settings2}
                    title="Settings & audit"
                    subline="Security & Devices · monitoring, source access and change history"
                    titleChip={
                        draftOpen ? (
                            <StatusBadge variant="warning">
                                Draft open
                            </StatusBadge>
                        ) : undefined
                    }
                    actions={
                        view === 'monitoring' || view === 'audit' ? (
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder={
                                    view === 'monitoring'
                                        ? 'Search monitoring rules…'
                                        : 'Search recorded changes…'
                                }
                            />
                        ) : undefined
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Monitoring rules"
                                onClick={() => select('monitoring')}
                            >
                                <PageHeaderMeterBig>
                                    {workspace.visible
                                        ? policyCount
                                        : 'Restricted'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Saved rules in your scope
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Source references"
                                onClick={() => {
                                    select('sources');
                                    setSourceView('credentials');
                                }}
                            >
                                <PageHeaderMeterBig>
                                    {credentialReferences.visible
                                        ? credentialReferences.rows.length
                                        : 'Restricted'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Review access and rotation
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Device groups"
                                href="/security-devices/device-groups"
                            >
                                <PageHeaderMeterBig>
                                    {summary.device_groups}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Review grouped equipment
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Recorded changes"
                                onClick={() => {
                                    select('audit');
                                    setAuditView('changes');
                                }}
                            >
                                <PageHeaderMeterBig>
                                    {audit.visible
                                        ? summary.audit_entries
                                        : 'Restricted'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Current report scope
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={views}
                            value={view}
                            onSelect={select}
                            ariaLabel="Security & Devices settings views"
                        />
                    }
                />
                {view === 'overview' && (
                    <>
                        <div className="grid gap-5 md:grid-cols-2">
                            <ReviewCard icon={Activity} title="Monitoring">
                                <ReviewRow
                                    label="Saved rules"
                                    value={
                                        workspace.visible
                                            ? policyCount
                                            : 'Restricted'
                                    }
                                />
                                <ReviewRow
                                    label="Approved Sites"
                                    value={
                                        workspace.visible
                                            ? workspace.sites.length
                                            : 'Restricted'
                                    }
                                />
                                {review('monitoring', 'monitoring')}
                            </ReviewCard>
                            <ReviewCard
                                icon={KeyRound}
                                title="Sources & access"
                            >
                                <ReviewRow
                                    label="Access references"
                                    value={
                                        credentialReferences.visible
                                            ? credentialReferences.rows.length
                                            : 'Restricted'
                                    }
                                />
                                <ReviewRow
                                    label="Source defaults"
                                    value={providerOperationalDefaults.length}
                                />
                                {review('sources & access', 'sources')}
                            </ReviewCard>
                            <ReviewCard icon={ListChecks} title="Inventory">
                                <ReviewRow
                                    label="Visible devices"
                                    value={dataQuality.visible_devices}
                                />
                                <ReviewRow
                                    label="Unassigned"
                                    value={dataQuality.unassigned_devices}
                                />
                                <ReviewRow
                                    label="Possible duplicates"
                                    value={dataQuality.duplicate_candidates}
                                />
                                <Button variant="link" asChild className="px-0">
                                    <Link href="/security-devices/devices">
                                        Review equipment{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </Link>
                                </Button>
                            </ReviewCard>
                            <ReviewCard icon={FileClock} title="Change history">
                                <ReviewRow
                                    label="Recorded changes"
                                    value={
                                        audit.visible
                                            ? summary.audit_entries
                                            : 'Restricted'
                                    }
                                />
                                <ReviewRow
                                    label="Monitoring service"
                                    value={settingLabel(runtime.state)}
                                />
                                {review('change history', 'audit')}
                            </ReviewCard>
                        </div>
                        <section className="space-y-3">
                            <h2 className="text-section-title flex items-center gap-2">
                                <ShieldCheck className="size-4 text-primary" />
                                Related settings
                            </h2>
                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                {areas.map((area) => (
                                    <Button
                                        key={area.href}
                                        variant="link"
                                        asChild
                                        className="px-0"
                                    >
                                        <Link
                                            href={area.href}
                                            title={area.description}
                                        >
                                            {area.title}
                                            <ArrowUpRight className="size-3.5" />
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        </section>
                    </>
                )}
                {view === 'monitoring' && (
                    <MonitoringPolicySettings
                        workspace={workspace}
                        initialView="overview"
                        searchQuery={query}
                        onDraftChange={setDraftOpen}
                    />
                )}
                {view === 'sources' && (
                    <section className="space-y-5">
                        <Sections
                            tabs={sourceTabs}
                            value={sourceView}
                            onChange={setSourceView}
                            label="Source settings sections"
                        />
                        {sourceView === 'overview' && (
                            <div className="grid gap-5 md:grid-cols-2">
                                <ReviewCard
                                    icon={KeyRound}
                                    title="Access references"
                                >
                                    <ReviewRow
                                        label="References"
                                        value={
                                            credentialReferences.visible
                                                ? credentialReferences.rows
                                                      .length
                                                : 'Restricted'
                                        }
                                    />
                                    <p className="text-subtle">
                                        {credentialReferences.driver_note}
                                    </p>
                                    <Button
                                        variant="link"
                                        className="px-0"
                                        onClick={() =>
                                            setSourceView('credentials')
                                        }
                                    >
                                        Review access references{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </Button>
                                </ReviewCard>
                                <ReviewCard
                                    icon={Layers}
                                    title="Source defaults"
                                >
                                    <ReviewRow
                                        label="Sources"
                                        value={
                                            providerOperationalDefaults.length
                                        }
                                    />
                                    <p className="text-subtle">
                                        Review the defaults supplied by each
                                        owning integration.
                                    </p>
                                    <Button
                                        variant="link"
                                        className="px-0"
                                        onClick={() =>
                                            setSourceView('defaults')
                                        }
                                    >
                                        Review source defaults{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </Button>
                                </ReviewCard>
                            </div>
                        )}
                        {sourceView === 'credentials' && (
                            <CredentialReferenceManagement
                                workspace={credentialReferences}
                            />
                        )}
                        {sourceView === 'defaults' && (
                            <div className="space-y-5">
                                <ReviewCard
                                    icon={Layers}
                                    title="Classification"
                                >
                                    <p className="text-subtle">
                                        {classificationDefaults.note}
                                    </p>
                                </ReviewCard>
                                {providerOperationalDefaults.length ? (
                                    providerOperationalDefaults.map(
                                        (source) => (
                                            <ReviewCard
                                                key={source.provider}
                                                icon={RadioTower}
                                                title={settingLabel(
                                                    source.provider,
                                                )}
                                            >
                                                <ReviewRow
                                                    label="Recorded state"
                                                    value={
                                                        <StatusBadge
                                                            status={
                                                                source.state
                                                            }
                                                        />
                                                    }
                                                />
                                                {Object.entries(
                                                    source.values,
                                                ).map(([key, value]) => (
                                                    <ReviewRow
                                                        key={key}
                                                        label={settingLabel(
                                                            key,
                                                        )}
                                                        value={
                                                            typeof value ===
                                                            'boolean'
                                                                ? value
                                                                    ? 'On'
                                                                    : 'Off'
                                                                : typeof value ===
                                                                        'string' ||
                                                                    typeof value ===
                                                                        'number'
                                                                  ? String(
                                                                        value,
                                                                    )
                                                                  : 'Review in the owning integration'
                                                        }
                                                    />
                                                ))}
                                            </ReviewCard>
                                        ),
                                    )
                                ) : (
                                    <SettingsNotice>
                                        No source defaults are recorded in your
                                        scope.
                                    </SettingsNotice>
                                )}
                            </div>
                        )}
                    </section>
                )}
                {view === 'audit' && (
                    <section className="space-y-5">
                        <Sections
                            tabs={auditTabs}
                            value={auditView}
                            onChange={setAuditView}
                            label="Change history sections"
                        />
                        {auditView === 'overview' && (
                            <div className="grid gap-5 md:grid-cols-2">
                                <ReviewCard
                                    icon={FileClock}
                                    title="Recorded changes"
                                >
                                    <ReviewRow
                                        label="Records shown"
                                        value={
                                            audit.visible
                                                ? audit.entries.length
                                                : 'Restricted'
                                        }
                                    />
                                    <Button
                                        variant="link"
                                        className="px-0"
                                        onClick={() => setAuditView('changes')}
                                    >
                                        Review recorded changes{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </Button>
                                </ReviewCard>
                                <ReviewCard
                                    icon={RadioTower}
                                    title="Monitoring service"
                                >
                                    <ReviewRow
                                        label="Available workers"
                                        value={runtime.workers.available}
                                    />
                                    <ReviewRow
                                        label="Need attention"
                                        value={runtime.workers.attention}
                                    />
                                    <Button
                                        variant="link"
                                        className="px-0"
                                        onClick={() => setAuditView('runtime')}
                                    >
                                        Review monitoring service{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </Button>
                                </ReviewCard>
                                <ReviewCard
                                    icon={ClipboardList}
                                    title="Supported features"
                                >
                                    {Object.entries(featureSupport).map(
                                        ([key, feature]) => (
                                            <ReviewRow
                                                key={key}
                                                label={settingLabel(key)}
                                                value={
                                                    <StatusBadge
                                                        status={feature.state}
                                                        title={feature.note}
                                                    />
                                                }
                                            />
                                        ),
                                    )}
                                </ReviewCard>
                            </div>
                        )}
                        {auditView === 'changes' && (
                            <AuditEvidence
                                visible={audit.visible}
                                entries={audit.entries.filter((entry) =>
                                    matches(
                                        actionLabels[entry.action] ??
                                            'Configuration changed',
                                        entry.actor,
                                        recordTypeLabel(entry.record_type),
                                        ...entry.fields.map(fieldLabel),
                                    ),
                                )}
                            />
                        )}
                        {auditView === 'runtime' && (
                            <>
                                <SettingsNotice>
                                    {runtime.workers.note}
                                </SettingsNotice>
                                <div className="grid gap-5 md:grid-cols-2">
                                    {Object.entries(runtime.queues).map(
                                        ([name, queue]) => (
                                            <ReviewCard
                                                key={name}
                                                icon={RadioTower}
                                                title={settingLabel(name)}
                                            >
                                                <ReviewRow
                                                    label="State"
                                                    value={
                                                        <StatusBadge
                                                            status={queue.state}
                                                        />
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Pending"
                                                    value={
                                                        queue.pending === null
                                                            ? 'Restricted'
                                                            : queue.pending
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Needs follow-up"
                                                    value={
                                                        queue.dead_letters ===
                                                        null
                                                            ? 'Restricted'
                                                            : queue.dead_letters
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Worker"
                                                    value={settingLabel(
                                                        queue.worker_state,
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Last heartbeat"
                                                    value={
                                                        queue.heartbeat_age_seconds ===
                                                        null
                                                            ? 'Not observed'
                                                            : queue.heartbeat_age_seconds +
                                                              ' seconds ago'
                                                    }
                                                />
                                            </ReviewCard>
                                        ),
                                    )}
                                </div>
                            </>
                        )}
                    </section>
                )}
            </div>
            <ConfirmDialog
                open={leaving !== null}
                onClose={() => setLeaving(null)}
                title="Leave this settings draft?"
                description="The open draft may contain unsaved changes. An unconfirmed maintenance request remains available for recovery when you return."
                confirmText="Leave draft"
                cancelText="Keep reviewing"
                variant="default"
                onConfirm={() => {
                    const target = leaving;
                    setLeaving(null);
                    if (target?.href) {
                        confirmedLeave.current = true;
                        router.visit(target.href);
                    } else if (target?.view) {
                        setView(target.view);
                        setQuery('');
                        setDraftOpen(false);
                    }
                }}
            />
        </AppLayout>
    );
}
