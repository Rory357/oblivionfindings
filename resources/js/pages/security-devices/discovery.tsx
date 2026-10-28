import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    EntityCard,
    EntityCardGrid,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { AddHubWizard } from '@/components/security-devices/add-hub-wizard';
import { CollectorInstallationDialog } from '@/components/security-devices/collector-installation-dialog';
import {
    CollectorEnrollmentDialog,
    CollectorRevocationDialog,
    type CollectorLifecycleTarget,
    type CollectorManagementSite,
} from '@/components/security-devices/collector-lifecycle-dialogs';
import {
    CandidateAdoptAllDialog,
    CandidateAdoptDialog,
    CandidateSameAsDialog,
    type CandidateActionTarget,
    type CandidateManagement,
} from '@/components/security-devices/discovery-candidate-dialogs';
import {
    DiscoveryScopeActionDialog,
    type DiscoveryScopeManagement,
    type GovernedDiscoveryScope,
} from '@/components/security-devices/discovery-scope-dialogs';
import {
    EditHubDialog,
    type EditHubTarget,
} from '@/components/security-devices/edit-hub-dialog';
import { SecurityDevicesSectionTabs } from '@/components/security-devices/security-devices-section-tabs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    Cable,
    CircleHelp,
    Eye,
    EyeOff,
    LayoutGrid,
    Link2,
    List,
    Network,
    Pencil,
    Play,
    Plus,
    Radar,
    RadioTower,
    RefreshCw,
    RotateCcw,
    SearchCheck,
    Server,
    ShieldCheck,
    ShieldX,
    type LucideIcon,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';

export type Collector = {
    id: number;
    name: string;
    site: { id: number; name: string; href: string } | null;
    reported_status: string;
    freshness_state: string;
    software_version?: string | null;
    last_seen_at: string | null;
    last_heartbeat_at?: string | null;
    heartbeat_lag_seconds: number | null;
    monitor_load: number;
    scope_load?: number;
    device_load: number;
    affected_monitors: number;
    affected_devices: number;
    covered_site_ids?: number[];
    covered_sites?: Array<{ id: number; name: string; href: string }>;
    impact_note: string;
    runtime_state?: string | null;
    backlog_items?: number;
    spool_bytes?: number;
    gap_count?: number;
    corrupted_frames?: number;
    clock_drift_seconds?: number | null;
    revoked_at?: string | null;
    actions?: {
        can_manage: boolean;
        update_url?: string | null;
        revoke_url: string | null;
        re_enrol_url: string | null;
    };
};

export type DiscoveryScopeRow = {
    id: number;
    name: string;
    status: string;
    site: { id: number; name: string; href: string } | null;
    collection_mode: string;
    collector_id?: number | null;
    collector_name?: string | null;
    runs_from?: string;
    collector: { id: number; name: string; state: string } | null;
    protocols: string[];
    network_ranges: number;
    seed_hosts: number;
    exclusions: number;
    port_bounds: number;
    max_targets_per_run: number;
    packets_per_second: number;
    schedule: string | null;
    actions?: {
        can_manage: boolean;
        update_url: string | null;
        apply_url: string | null;
        deactivate_url: string | null;
    };
};

export type DiscoveryRunRow = {
    id: number;
    run_uuid: string;
    scope_id: number;
    scope_name: string | null;
    status: string;
    collection_mode: 'central' | 'remote_collector';
    collector_id?: number | null;
    collector_name?: string | null;
    runs_from?: string;
    collector: { id: number; name: string; state: string } | null;
    trigger: string;
    planned: number;
    returned: number;
    pending: number;
    found: number;
    matched: number;
    proposed: number;
    changed: number;
    excluded: number;
    failed: number;
    unresolved: number;
    started_at: string | null;
    completed_at: string | null;
};

export type DiscoveryCandidateRow = {
    id: number;
    candidate_uuid: string;
    run_id: number;
    scope_id: number | null;
    scope_name?: string | null;
    site_id?: number | null;
    site_name?: string | null;
    decision: string;
    confidence: number;
    hostname?: string | null;
    ip_address?: string | null;
    mac_address?: string | null;
    mac_vendor?: string | null;
    type_label?: string | null;
    domain?: string | null;
    category?: string | null;
    subcategory?: string | null;
    manufacturer?: string | null;
    model?: string | null;
    suggested_name?: string | null;
    identification_confidence?: number | null;
    identification_confidence_band?: string | null;
    identification_reason?: string | null;
    open_ports?: number[];
    provider?: string | null;
    reasons: string[];
    canonical_device: { id: number; name: string; href: string } | null;
    review: { action: string | null; reviewed_at: string | null };
    actions?: {
        adopt_url: string | null;
        same_as_url: string | null;
        ignore_url: string | null;
    };
};

export type DiscoveryWorkspace = {
    tabs: Array<{ key: string; label: string }>;
    active_tab: string;
    boundary: { title: string; description: string; runtime_note: string };
    summary: {
        monitors: number;
        direct_monitors: number;
        remote_monitors: number;
        collectors: number;
        online_collectors: number;
        collection_paths_unavailable: number;
        affected_devices: number;
        scopes: number;
        runs: number;
        runs_shown: number;
        runs_truncated: boolean;
        candidates: number;
        candidates_shown: number;
        candidates_truncated: boolean;
        candidates_requiring_review: number;
    };
    direct_coverage: {
        path_label: string;
        monitors: number;
        devices: number;
        description: string;
    };
    collectors: Collector[];
    collector_management: {
        can_manage: boolean;
        issue_url: string | null;
        wizard_options_url?: string | null;
        wizard_issue_url?: string | null;
        sites: CollectorManagementSite[];
    };
    scope_management?: DiscoveryScopeManagement;
    candidate_management?: CandidateManagement;
    scopes: DiscoveryScopeRow[];
    runs: DiscoveryRunRow[];
    candidates: DiscoveryCandidateRow[];
    collection_paths: Array<{
        collector_id: number;
        collector_name: string;
        site: Collector['site'];
        state: string;
        monitor_load: number;
        device_load: number;
        affected_devices: number;
    }>;
    limitations: {
        unsupported_state: string;
        unsupported_note: string;
        not_configured_note: string;
        capacity_note: string;
    };
};

// Plain-language labels for returned codes (DESIGN.md: no raw enum values or
// Title Case in the UI). Unknown codes fall back to sentence case.
const knownLabels: Record<string, string> = {
    remote_collector: 'Remote collector',
    central: 'Main application',
    central_direct: 'Direct from the main application',
    direct: 'Direct from the main application',
};

function plain(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    const known = knownLabels[value];
    if (known) return known;
    const words = value.replace(/[_-]+/g, ' ').trim().toLocaleLowerCase();
    return words.charAt(0).toLocaleUpperCase() + words.slice(1);
}

function triggerLabel(trigger: string): string {
    const source = trigger.split(':')[0];
    if (source === 'manual') return 'Run now by a staff member';
    if (source === 'schedule' || source === 'scheduled') return 'Schedule';
    return plain(source);
}

function tone(value: string): StatusVariant {
    if (['available', 'online'].includes(value)) return 'success';
    if (['unavailable', 'offline', 'failed', 'revoked'].includes(value))
        return 'critical';
    if (['pending', 'queued', 'running', 'stale'].includes(value))
        return 'warning';
    return 'neutral';
}

function when(value: string | null): string {
    return formatDateTimeLong(value, 'Not reported');
}

export type DiscoveryPageProps = {
    workspace: DiscoveryWorkspace;
    actor_id: number;
    as_of: string;
};
// The returned "limitations" tab has no records to filter, so its notes are
// shown on Overview instead (DESIGN.md "Filterless rail tabs"); a
// ?tab=limitations link opens Overview.
type DiscoveryView =
    | 'overview'
    | 'scopes'
    | 'runs'
    | 'candidates'
    | 'collectors'
    | 'paths';
type DiscoveryRecordView = Exclude<DiscoveryView, 'overview'>;
type DiscoveryLayout = 'cards' | 'table';
type DiscoveryEntry = {
    key: string;
    name: string;
    subline: string;
    state: string;
    icon: LucideIcon;
    summary: string;
    site: Collector['site'];
    actions: MenuItem[];
    facts: Array<{ label: string; value: ReactNode }>;
    technical: Array<{ label: string; value: ReactNode }>;
    note?: string;
    deviceHref?: string;
    coveredSiteIds?: number[];
    progress?: { returned: number; planned: number };
};
const discoveryPath = '/security-devices/discovery';
const readProps = ['workspace', 'actor_id', 'as_of', 'auth'];
const breadcrumbs = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Security & Devices', href: '/security-devices' },
    { title: 'Discovery & collectors', href: discoveryPath },
];
const viewIcons: Record<DiscoveryView, LucideIcon> = {
    overview: Radar,
    scopes: Network,
    runs: Activity,
    candidates: SearchCheck,
    collectors: RadioTower,
    paths: Cable,
};
const viewLabels: Record<DiscoveryView, string> = {
    overview: 'Overview',
    scopes: 'Discovery scopes',
    runs: 'Runs',
    candidates: 'Candidates',
    collectors: 'Remote collectors',
    paths: 'Coverage & paths',
};
const stateFilterLabels: Record<DiscoveryRecordView, string> = {
    scopes: 'Status',
    runs: 'Status',
    candidates: 'Decision',
    collectors: 'Availability',
    paths: 'Availability',
};
const knownView = (value: string): DiscoveryView =>
    value in viewLabels ? (value as DiscoveryView) : 'overview';
/** Only the text a person can see on a row is searchable (not hrefs or references). */
const searchText = (entry: DiscoveryEntry) =>
    [
        entry.name,
        entry.subline,
        entry.summary,
        plain(entry.state),
        entry.site?.name,
    ]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase();
/**
 * Remount the page body (clearing drafts, readers, search and filters) only
 * when the viewer or their Site authority changes. A reload that merely
 * refreshes observations, such as a collector's heartbeat lag, must not
 * remount it, or a one-time enrolment token closes before it can be copied.
 */
const authorityKey = (
    data: DiscoveryPageProps,
    canManage: boolean,
    canViewDevices: boolean,
) =>
    JSON.stringify([
        data.actor_id,
        canManage,
        canViewDevices,
        (data.workspace.scope_management?.sites ?? []).map((site) => site.id),
        data.workspace.collector_management.sites.map((site) => site.id),
    ]);
const safeSiteHref = (site: Collector['site']) =>
    site && site.href === '/security-devices/sites/' + site.id
        ? site.href
        : undefined;
const scopeUrl = (id: number, url: string | null | undefined, suffix = '') =>
    url === discoveryPath + '/scopes/' + id + suffix ? url : null;
const collectorUrl = (
    id: number,
    url: string | null | undefined,
    suffix = '',
) => (url === discoveryPath + '/collectors/' + id + suffix ? url : null);

export default function DiscoveryCollectors(data: DiscoveryPageProps) {
    const { auth } = usePage<{
        auth?: {
            user?: { id?: number } | null;
            can?: {
                securityDevices?: {
                    viewAny?: boolean;
                    integrationsView?: boolean;
                    integrationsManage?: boolean;
                    devicesView?: boolean;
                };
            };
        };
    }>().props;
    const [sourceActor] = useState(data.actor_id);
    const [denied, setDenied] = useState(false);
    const [notice, setNotice] = useState('');
    const [activeTab, setActiveTab] = useState<DiscoveryView>(
        knownView(data.workspace.active_tab),
    );
    const [layout, setLayout] = useState<DiscoveryLayout>('table');
    const visible = Boolean(
        !denied &&
        data.actor_id > 0 &&
        sourceActor === data.actor_id &&
        auth?.user?.id === data.actor_id &&
        auth.can?.securityDevices?.viewAny === true &&
        auth.can.securityDevices.integrationsView === true,
    );
    const canManage =
        visible && auth?.can?.securityDevices?.integrationsManage === true;
    const canViewDevices =
        visible && auth?.can?.securityDevices?.devicesView === true;
    const denyAccess = useCallback(() => {
        setDenied(true);
        setNotice('');
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                const response = event.detail.response;
                if (typeof response.config.url !== 'string') return;
                let url: URL;
                try {
                    url = new URL(response.config.url, window.location.origin);
                } catch {
                    return;
                }
                if (
                    url.origin !== window.location.origin ||
                    (url.pathname !== discoveryPath &&
                        !url.pathname.startsWith(discoveryPath + '/'))
                )
                    return;
                if ([401, 403, 404].includes(response.status)) {
                    event.preventDefault();
                    denyAccess();
                } else if (response.config.method?.toLowerCase() === 'get') {
                    event.preventDefault();
                    setNotice(
                        'The records could not be refreshed. What you see is from the last successful read, with its original times. Try Refresh again.',
                    );
                }
            }),
        [denyAccess],
    );
    const refresh = () =>
        router.reload({
            only: readProps,
            preserveState: true,
            preserveScroll: true,
        });
    const select = (view: DiscoveryView) => {
        setActiveTab(view);
        router.get(
            discoveryPath,
            { tab: view },
            {
                only: readProps,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };
    if (!visible)
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Discovery & collectors" />
                <PageLayout
                    hero={
                        <PageHeader
                            icon={Radar}
                            title="Discovery & collectors"
                            subline="Approved Site discovery and collection paths"
                            titleChip={
                                <PageHeaderStatusChip variant="neutral">
                                    Access unavailable
                                </PageHeaderStatusChip>
                            }
                        />
                    }
                >
                    <EmptyState
                        icon={ShieldCheck}
                        title="Discovery records are unavailable"
                        description={
                            !data.actor_id
                                ? 'This read did not include actor metadata. Refresh to request an attributed discovery read.'
                                : 'Your current account needs Security & Devices access and permission to view integrations. Refresh to check current access.'
                        }
                        action={
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        discoveryPath,
                                        {},
                                        { preserveState: false },
                                    )
                                }
                            >
                                Refresh access
                            </Button>
                        }
                    />
                </PageLayout>
            </AppLayout>
        );
    return (
        <DiscoveryBody
            key={authorityKey(data, canManage, canViewDevices)}
            data={data}
            canManage={canManage}
            canViewDevices={canViewDevices}
            activeTab={activeTab}
            select={select}
            layout={layout}
            setLayout={setLayout}
            refresh={refresh}
            notice={notice}
            onAccessDenied={denyAccess}
        />
    );
}

function DiscoveryBody({
    data,
    canManage,
    canViewDevices,
    activeTab,
    select: navigate,
    layout,
    setLayout,
    refresh,
    notice,
    onAccessDenied,
}: {
    data: DiscoveryPageProps;
    canManage: boolean;
    canViewDevices: boolean;
    activeTab: DiscoveryView;
    select: (view: DiscoveryView) => void;
    layout: DiscoveryLayout;
    setLayout: (layout: DiscoveryLayout) => void;
    refresh: () => void;
    notice: string;
    onAccessDenied: () => void;
}) {
    const { workspace: w, actor_id, as_of } = data;
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState('all');
    const [stateFilter, setStateFilter] = useState('all');
    const select = (view: DiscoveryView) => {
        setSearch('');
        setStateFilter('all');
        navigate(view);
    };
    const [installationOpen, setInstallationOpen] = useState(false);
    const [hubWizardOpen, setHubWizardOpen] = useState(false);
    const [editingHub, setEditingHub] = useState<EditHubTarget | null>(null);
    // A snapshot, so a refreshed read (which follows issuing a token) cannot
    // close the one-time token before it is copied. Authority changes still
    // remount the body and clear it.
    const [replacement, setReplacement] =
        useState<CollectorLifecycleTarget | null>(null);
    const [revocationId, setRevocationId] = useState<number | null>(null);
    const [scopeDialogOpen, setScopeDialogOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [scopeAction, setScopeAction] = useState<{
        id: number;
        action: 'apply' | 'deactivate';
    } | null>(null);
    const [adoptTarget, setAdoptTarget] =
        useState<CandidateActionTarget | null>(null);
    const [sameAsTarget, setSameAsTarget] =
        useState<CandidateActionTarget | null>(null);
    const [adoptAllOpen, setAdoptAllOpen] = useState(false);
    const scopeManagement = w.scope_management ?? {
        can_manage: false,
        create_url: null,
        protocols: [],
        sites: [],
    };
    const candidateManagement: CandidateManagement = w.candidate_management ?? {
        can_adopt: false,
        can_same_as: false,
        can_ignore: false,
        adopt_all_url: null,
        adoptable_count: 0,
        taxonomy: {},
        domains: [],
        devices_by_site: {},
    };
    const mayManageScopes = canManage && scopeManagement.can_manage;
    const mayManageCollectors = canManage && w.collector_management.can_manage;
    const currentScope = (id: number) => w.scopes.find((row) => row.id === id);
    const currentCollector = (id: number) =>
        w.collectors.find((row) => row.id === id);
    const governedScope = (
        scope: DiscoveryScopeRow,
    ): GovernedDiscoveryScope => ({
        ...scope,
        actions: scope.actions ?? {
            can_manage: false,
            update_url: null,
            apply_url: null,
            deactivate_url: null,
        },
    });
    const lifecycleTarget = (
        collector: Collector,
    ): CollectorLifecycleTarget => ({
        id: collector.id,
        name: collector.name,
        site: collector.site,
        revoke_url: collectorUrl(
            collector.id,
            collector.actions?.revoke_url,
            '/revoke',
        ),
        re_enrol_url: collectorUrl(
            collector.id,
            collector.actions?.re_enrol_url,
            '/re-enrolment',
        ),
    });
    const collectorSitePermitted = (collector: {
        site: Collector['site'] | CollectorLifecycleTarget['site'];
    }) =>
        Boolean(
            collector.site &&
            w.collector_management.sites.some(
                (site) => site.id === collector.site?.id,
            ),
        );
    const editHubTarget = (collector: Collector): EditHubTarget => ({
        id: collector.id,
        name: collector.name,
        site: collector.site
            ? { id: collector.site.id, name: collector.site.name }
            : null,
        covered_site_ids: collector.covered_site_ids ?? [],
        update_url: collectorUrl(collector.id, collector.actions?.update_url),
    });
    const collectorActions = (collector: Collector): MenuItem[] =>
        !mayManageCollectors ||
        !collector.actions?.can_manage ||
        !collectorSitePermitted(collector)
            ? []
            : [
                  ...(collectorUrl(collector.id, collector.actions.update_url)
                      ? [
                            {
                                label: 'Edit hub',
                                icon: Pencil,
                                onClick: () =>
                                    setEditingHub(editHubTarget(collector)),
                            },
                        ]
                      : []),
                  ...(collectorUrl(
                      collector.id,
                      collector.actions.re_enrol_url,
                      '/re-enrolment',
                  )
                      ? [
                            {
                                label: 'Re-enrol collector',
                                icon: RotateCcw,
                                onClick: () =>
                                    setReplacement(lifecycleTarget(collector)),
                            },
                        ]
                      : []),
                  ...(collectorUrl(
                      collector.id,
                      collector.actions.revoke_url,
                      '/revoke',
                  )
                      ? [
                            {
                                label: 'Revoke collector',
                                icon: ShieldX,
                                danger: true,
                                onClick: () => setRevocationId(collector.id),
                            },
                        ]
                      : []),
              ];
    const scopeRunsFrom = (scope: DiscoveryScopeRow): string =>
        scope.runs_from ??
        (scope.collector
            ? 'Runs from: hub ' + scope.collector.name
            : scope.collector_name
              ? 'Runs from: hub ' + scope.collector_name
              : scope.collection_mode === 'remote_collector'
                ? 'Runs from: hub'
                : 'Runs from: server');
    const runRunsFrom = (run: DiscoveryRunRow): string =>
        run.runs_from ??
        (run.collection_mode === 'remote_collector'
            ? run.collector
                ? 'Runs from: hub ' + run.collector.name
                : run.collector_name
                  ? 'Runs from: hub ' + run.collector_name
                  : 'Runs from: hub'
            : 'Runs from: server');
    const scopes: DiscoveryEntry[] = w.scopes.map((scope) => ({
        key: 'scope:' + scope.id,
        name: scope.name,
        subline:
            (scope.site?.name ?? 'Site unavailable') +
            ' · ' +
            scopeRunsFrom(scope),
        state: scope.status,
        icon: Network,
        site: scope.site,
        summary:
            scope.network_ranges +
            ' network ranges · ' +
            scope.protocols.length +
            ' protocols',
        actions:
            !mayManageScopes || !scope.actions?.can_manage || !scope.site
                ? []
                : [
                      ...(scopeUrl(scope.id, scope.actions.update_url)
                          ? [
                                {
                                    label: 'Update',
                                    icon: Pencil,
                                    onClick: () => {
                                        setEditingId(scope.id);
                                        setScopeDialogOpen(true);
                                    },
                                },
                            ]
                          : []),
                      ...(scopeUrl(scope.id, scope.actions.apply_url, '/apply')
                          ? [
                                {
                                    label: 'Run now',
                                    icon: Play,
                                    onClick: () =>
                                        setScopeAction({
                                            id: scope.id,
                                            action: 'apply',
                                        }),
                                },
                            ]
                          : []),
                      ...(scopeUrl(
                          scope.id,
                          scope.actions.deactivate_url,
                          '/deactivate',
                      )
                          ? [
                                {
                                    label: 'Deactivate',
                                    icon: ShieldX,
                                    danger: true,
                                    onClick: () =>
                                        setScopeAction({
                                            id: scope.id,
                                            action: 'deactivate',
                                        }),
                                },
                            ]
                          : []),
                  ],
        facts: [
            { label: 'Site', value: scope.site?.name },
            { label: 'Status', value: plain(scope.status) },
            { label: 'Runs from', value: scopeRunsFrom(scope) },
            { label: 'Collection mode', value: plain(scope.collection_mode) },
            { label: 'Collector', value: scope.collector?.name },
            {
                label: 'Collector availability',
                value: scope.collector
                    ? plain(scope.collector.state)
                    : 'No collector configured',
            },
            {
                label: 'Protocols',
                value: scope.protocols
                    .map((protocol) => protocol.toUpperCase())
                    .join(', '),
            },
            {
                label: 'Schedule',
                value: scope.schedule ? 'Runs on a schedule' : 'Not scheduled',
            },
        ],
        technical: [
            { label: 'Scope ID', value: scope.id },
            { label: 'Schedule rule', value: scope.schedule ?? 'None' },
            { label: 'Network ranges', value: scope.network_ranges },
            { label: 'Seed hosts', value: scope.seed_hosts },
            { label: 'Exclusions', value: scope.exclusions },
            { label: 'Port bounds', value: scope.port_bounds },
            {
                label: 'Maximum targets per run',
                value: scope.max_targets_per_run,
            },
            { label: 'Packets per second', value: scope.packets_per_second },
        ],
        note: 'Saving a scope does not start a scan; Run now is a separate step. Network addresses and sign-in details stay hidden on this page.',
    }));
    const runs: DiscoveryEntry[] = w.runs.map((run) => ({
        key: 'run:' + run.id,
        name: run.scope_name ?? 'Discovery run #' + run.id,
        subline: runRunsFrom(run),
        state: run.status,
        icon: Activity,
        site: currentScope(run.scope_id)?.site ?? null,
        summary:
            run.returned +
            ' of ' +
            run.planned +
            ' results returned · ' +
            run.pending +
            ' pending',
        actions: [],
        progress: { returned: run.returned, planned: run.planned },
        facts: [
            { label: 'Run status', value: plain(run.status) },
            { label: 'Scope', value: run.scope_name },
            { label: 'Runs from', value: runRunsFrom(run) },
            { label: 'Collection mode', value: plain(run.collection_mode) },
            { label: 'Collector', value: run.collector?.name },
            {
                label: 'Collector availability',
                value: run.collector
                    ? plain(run.collector.state)
                    : 'No collector configured',
            },
            { label: 'Started at', value: when(run.started_at) },
            {
                label: 'Completed at',
                value: run.completed_at
                    ? when(run.completed_at)
                    : 'Not completed',
            },
            { label: 'Planned targets', value: run.planned },
            { label: 'Returned results', value: run.returned },
            { label: 'Pending targets', value: run.pending },
        ],
        technical: [
            { label: 'Run ID', value: run.id },
            { label: 'Run reference', value: run.run_uuid },
            { label: 'Scope ID', value: run.scope_id },
            { label: 'Started by', value: triggerLabel(run.trigger) },
            { label: 'Found', value: run.found },
            { label: 'Matched', value: run.matched },
            { label: 'Proposed', value: run.proposed },
            { label: 'Changed', value: run.changed },
            { label: 'Excluded', value: run.excluded },
            { label: 'Failed', value: run.failed },
            { label: 'Unresolved', value: run.unresolved },
        ],
        note:
            run.collection_mode === 'remote_collector' && run.pending > 0
                ? 'Some addresses are still waiting for the collector to report back, so this run is not finished yet.'
                : 'This is what the run found at the time. It does not show whether devices can be reached now.',
    }));
    const candidateTarget = (
        candidate: DiscoveryCandidateRow,
    ): CandidateActionTarget => ({
        id: candidate.id,
        hostname: candidate.hostname,
        ip_address: candidate.ip_address,
        mac_address: candidate.mac_address,
        mac_vendor: candidate.mac_vendor,
        type_label: candidate.type_label,
        domain: candidate.domain,
        category: candidate.category,
        subcategory: candidate.subcategory,
        manufacturer: candidate.manufacturer,
        model: candidate.model,
        suggested_name: candidate.suggested_name,
        identification_confidence: candidate.identification_confidence,
        identification_confidence_band:
            candidate.identification_confidence_band,
        identification_reason: candidate.identification_reason,
        site_id: candidate.site_id,
        site_name: candidate.site_name,
        adopt_url: candidate.actions?.adopt_url ?? null,
        same_as_url: candidate.actions?.same_as_url ?? null,
    });
    const candidateActions = (candidate: DiscoveryCandidateRow): MenuItem[] => {
        if (!canManage || !candidate.actions) return [];
        const items: MenuItem[] = [];
        if (candidateManagement.can_adopt && candidate.actions.adopt_url) {
            items.push({
                label: 'Add as device',
                icon: Plus,
                onClick: () => setAdoptTarget(candidateTarget(candidate)),
            });
        }
        if (candidateManagement.can_same_as && candidate.actions.same_as_url) {
            items.push({
                label: 'Same as…',
                icon: Link2,
                onClick: () => setSameAsTarget(candidateTarget(candidate)),
            });
        }
        if (candidateManagement.can_ignore && candidate.actions.ignore_url) {
            const ignoreUrl = candidate.actions.ignore_url;
            items.push({
                label: 'Ignore',
                icon: EyeOff,
                onClick: () => {
                    void axios.post(ignoreUrl).then(() => refresh());
                },
            });
        }
        return items;
    };
    const candidates: DiscoveryEntry[] = w.candidates.map((candidate) => {
        const hasSpecificSuggestedName =
            Boolean(candidate.suggested_name) &&
            candidate.suggested_name !== 'Discovered device' &&
            Boolean(
                candidate.hostname ||
                candidate.ip_address ||
                candidate.manufacturer ||
                candidate.model,
            );
        const brandModel = [candidate.manufacturer, candidate.model]
            .filter(Boolean)
            .join(' ');
        const hardwareHint = brandModel || candidate.mac_vendor || '';
        const sublineParts = [
            ...(candidate.ip_address &&
            (candidate.hostname || hasSpecificSuggestedName)
                ? [candidate.ip_address]
                : []),
            ...(hardwareHint ? [hardwareHint] : []),
            'Run #' + candidate.run_id,
        ];
        const cleanReason =
            candidate.identification_reason &&
            candidate.identification_reason !==
                'Reached on the network; no specific service banner reported'
                ? candidate.identification_reason
                : '';
        const baseObservation = candidate.type_label
            ? cleanReason &&
              cleanReason
                  .toLowerCase()
                  .startsWith(candidate.type_label.toLowerCase())
                ? cleanReason
                : candidate.type_label +
                  (cleanReason ? ' · ' + cleanReason : '')
            : '';
        const summary = baseObservation
            ? baseObservation +
              (typeof candidate.identification_confidence === 'number'
                  ? ' · ' +
                    candidate.identification_confidence +
                    '% match confidence'
                  : '')
            : candidate.confidence +
              '% decision confidence · ' +
              candidate.reasons.length +
              ' reasons';

        return {
            key: 'candidate:' + candidate.id,
            name:
                candidate.canonical_device && canViewDevices
                    ? candidate.canonical_device.name
                    : candidate.canonical_device
                      ? 'Matched device hidden'
                      : hasSpecificSuggestedName
                        ? (candidate.suggested_name as string)
                        : (candidate.hostname ??
                          candidate.ip_address ??
                          'Possible new device #' + candidate.id),
            subline: sublineParts.join(' · '),
            state: candidate.decision,
            icon: SearchCheck,
            site: candidate.scope_id
                ? (currentScope(candidate.scope_id)?.site ??
                  (candidate.site_id && candidate.site_name
                      ? {
                            id: candidate.site_id,
                            name: candidate.site_name,
                            href:
                                '/security-devices/sites/' + candidate.site_id,
                        }
                      : null))
                : null,
            summary,
            actions: candidateActions(candidate),
            deviceHref:
                canViewDevices &&
                candidate.canonical_device !== null &&
                candidate.canonical_device.href ===
                    '/security-devices/devices/' + candidate.canonical_device.id
                    ? candidate.canonical_device.href
                    : undefined,
            facts: [
                ...(candidate.type_label
                    ? [
                          {
                              label: 'Identified type',
                              value: candidate.type_label,
                          },
                      ]
                    : []),
                ...(brandModel
                    ? [{ label: 'Brand & model', value: brandModel }]
                    : []),
                ...(candidate.mac_vendor
                    ? [{ label: 'MAC vendor', value: candidate.mac_vendor }]
                    : []),
                ...(typeof candidate.identification_confidence === 'number'
                    ? [
                          {
                              label: 'Identification confidence',
                              value: candidate.identification_confidence + '%',
                          },
                      ]
                    : []),
                ...(candidate.identification_reason
                    ? [
                          {
                              label: 'Why we think so',
                              value: candidate.identification_reason,
                          },
                      ]
                    : []),
                ...(candidate.hostname
                    ? [{ label: 'Hostname', value: candidate.hostname }]
                    : []),
                ...(candidate.ip_address
                    ? [{ label: 'IP address', value: candidate.ip_address }]
                    : []),
                ...(candidate.mac_address
                    ? [{ label: 'MAC address', value: candidate.mac_address }]
                    : []),
                ...(candidate.open_ports && candidate.open_ports.length > 0
                    ? [
                          {
                              label: 'Open ports',
                              value: candidate.open_ports.join(', '),
                          },
                      ]
                    : []),
                { label: 'Decision', value: plain(candidate.decision) },
                {
                    label: 'Decision confidence',
                    value: candidate.confidence + '%',
                },
                {
                    label: 'Reasons',
                    value: candidate.reasons.length
                        ? plain(candidate.reasons.join(', '))
                        : 'No decision reason recorded',
                },
                {
                    label: 'Matched device',
                    value: canViewDevices
                        ? candidate.canonical_device?.name
                        : 'You need permission to view devices',
                },
                {
                    label: 'Review action',
                    value: candidate.review.action
                        ? plain(candidate.review.action)
                        : 'Not reviewed',
                },
                {
                    label: 'Reviewed at',
                    value: candidate.review.reviewed_at
                        ? when(candidate.review.reviewed_at)
                        : 'Not reviewed',
                },
            ],
            technical: [
                { label: 'Candidate ID', value: candidate.id },
                {
                    label: 'Candidate reference',
                    value: candidate.candidate_uuid,
                },
                { label: 'Run ID', value: candidate.run_id },
                { label: 'Scope ID', value: candidate.scope_id },
            ],
            note: 'Confidence and reasons help you review this match. They do not change the device record or identify a person.',
        };
    });
    const collectors: DiscoveryEntry[] = w.collectors.map((collector) => {
        const coveredSiteNames = (collector.covered_sites ?? [])
            .map((covered) => covered.name)
            .filter(Boolean);
        return {
            key: 'collector:' + collector.id,
            name: collector.name,
            subline:
                (collector.site?.name ?? 'No Site assigned') +
                (collector.software_version
                    ? ' · v' + collector.software_version
                    : '') +
                (coveredSiteNames.length > 0
                    ? ' · +' + coveredSiteNames.length + ' SD-WAN Sites'
                    : ''),
            state: collector.freshness_state,
            icon: RadioTower,
            site: collector.site,
            coveredSiteIds: collector.covered_site_ids,
            summary:
                collector.device_load +
                ' devices · ' +
                collector.monitor_load +
                ' checks' +
                (collector.scope_load !== undefined
                    ? ' · ' + collector.scope_load + ' scans'
                    : ''),
            actions: collectorActions(collector),
            facts: [
                { label: 'Site', value: collector.site?.name },
                {
                    label: 'Covered SD-WAN Sites',
                    value:
                        coveredSiteNames.length > 0
                            ? coveredSiteNames.join(', ')
                            : 'Home Site only',
                },
                {
                    label: 'Availability',
                    value: plain(collector.freshness_state),
                },
                {
                    label: 'Reported status',
                    value: plain(collector.reported_status),
                },
                {
                    label: 'Software version',
                    value: collector.software_version ?? 'Not reported',
                },
                {
                    label: 'Last heartbeat',
                    value: when(
                        collector.last_heartbeat_at ?? collector.last_seen_at,
                    ),
                },
                {
                    label: 'Heartbeat lag (seconds)',
                    value: collector.heartbeat_lag_seconds,
                },
                { label: 'Device load', value: collector.device_load },
                { label: 'Monitor load', value: collector.monitor_load },
                ...(collector.scope_load !== undefined
                    ? [{ label: 'Assigned scans', value: collector.scope_load }]
                    : []),
                {
                    label: 'Affected devices',
                    value: collector.affected_devices,
                },
                {
                    label: 'Affected monitors',
                    value: collector.affected_monitors,
                },
            ],
            technical: [
                { label: 'Collector ID', value: collector.id },
                { label: 'Runtime state', value: collector.runtime_state },
                { label: 'Backlog items', value: collector.backlog_items },
                { label: 'Spool bytes', value: collector.spool_bytes },
                { label: 'Sequence gaps', value: collector.gap_count },
                { label: 'Corrupt frames', value: collector.corrupted_frames },
                {
                    label: 'Clock drift (seconds)',
                    value: collector.clock_drift_seconds,
                },
                {
                    label: 'Revoked at',
                    value: collector.revoked_at
                        ? when(collector.revoked_at)
                        : 'Not revoked',
                },
            ],
            note: collector.impact_note,
        };
    });
    const paths: DiscoveryEntry[] = w.collection_paths.map((path) => ({
        key: 'path:' + path.collector_id,
        name: path.collector_name,
        subline: path.site?.name ?? 'No Site assigned',
        state: path.state,
        icon: Cable,
        site: path.site,
        summary:
            path.device_load + ' devices · ' + path.monitor_load + ' checks',
        actions: [],
        facts: [
            { label: 'Site', value: path.site?.name },
            { label: 'Availability', value: plain(path.state) },
            { label: 'Device load', value: path.device_load },
            { label: 'Monitor load', value: path.monitor_load },
            { label: 'Affected devices', value: path.affected_devices },
        ],
        technical: [{ label: 'Collector ID', value: path.collector_id }],
        note: 'This shows whether the collector can reach the Site. While it is unavailable, results from checks that rely on it cannot be trusted.',
    }));
    const rows = { scopes, runs, candidates, collectors, paths };
    const recordView = activeTab !== 'overview';
    const loadedRows = recordView ? rows[activeTab] : [];
    const allRows = [
        ...scopes,
        ...runs,
        ...candidates,
        ...collectors,
        ...paths,
    ];
    // Site filter: every view's records are Site-scoped. Options also include
    // the Sites the viewer manages, so the filter is there before any record.
    const siteOptions = [
        ...new Map(
            [
                ...allRows.flatMap((entry) => (entry.site ? [entry.site] : [])),
                ...scopeManagement.sites,
                ...w.collector_management.sites,
            ].map((site) => [String(site.id), site.name]),
        ).entries(),
    ]
        .map(([value, name]) => ({ value, label: name }))
        .sort((a, b) => a.label.localeCompare(b.label));
    const site = siteOptions.some((option) => option.value === siteFilter)
        ? siteFilter
        : 'all';
    const siteName = siteOptions.find((option) => option.value === site)?.label;
    const stateOptions = [...new Set(loadedRows.map((entry) => entry.state))]
        .sort()
        .map((value) => ({ value, label: plain(value) }));
    const state = stateOptions.some((option) => option.value === stateFilter)
        ? stateFilter
        : 'all';
    const query = search.trim().toLocaleLowerCase();
    const inSite = (entry: DiscoveryEntry) =>
        site === 'all' ||
        String(entry.site?.id) === site ||
        Boolean(entry.coveredSiteIds?.some((id) => String(id) === site));
    const matches = (entry: DiscoveryEntry) =>
        inSite(entry) && (!query || searchText(entry).includes(query));
    const globalMatches = allRows.filter(matches);
    const filtered = loadedRows.filter(
        (entry) => matches(entry) && (state === 'all' || entry.state === state),
    );
    const narrowed = query !== '' || site !== 'all' || state !== 'all';
    const siteCollectors = collectors.filter(inSite);
    const counts: Record<DiscoveryRecordView, number> = {
        scopes: w.summary.scopes,
        runs: w.summary.runs,
        candidates: w.summary.candidates,
        collectors: w.summary.collectors,
        paths: w.collection_paths.length,
    };
    const selectedScope = editingId === null ? null : currentScope(editingId);
    const actionScope = scopeAction ? currentScope(scopeAction.id) : null;
    const revocation =
        revocationId === null ? null : currentCollector(revocationId);
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Discovery & collectors" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={Radar}
                        title="Discovery & collectors"
                        wrapTitle
                        subline={
                            (as_of
                                ? 'Records as at ' + when(as_of) + ' NZ time'
                                : 'Read time not returned') +
                            ' · search covers the loaded records'
                        }
                        titleChip={
                            <PageHeaderStatusChip variant="neutral">
                                Configured collection
                            </PageHeaderStatusChip>
                        }
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Search loaded discovery records…"
                                    ariaLabel="Search loaded discovery records"
                                />
                                <PageHeaderGlassButton
                                    icon={RefreshCw}
                                    onClick={refresh}
                                >
                                    Refresh
                                </PageHeaderGlassButton>
                                {mayManageCollectors &&
                                    w.collector_management.sites.length > 0 && (
                                        <PageHeaderGlassButton
                                            icon={RadioTower}
                                            onClick={() =>
                                                setHubWizardOpen(true)
                                            }
                                        >
                                            Add a hub
                                        </PageHeaderGlassButton>
                                    )}
                                {mayManageScopes &&
                                    scopeManagement.create_url ===
                                        discoveryPath + '/scopes' &&
                                    scopeManagement.sites.length > 0 && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => {
                                                setEditingId(null);
                                                setScopeDialogOpen(true);
                                            }}
                                        >
                                            Add direct scope
                                        </PageHeaderPrimaryButton>
                                    )}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Configured checks"
                                    value={w.summary.monitors}
                                    onClick={() => select('paths')}
                                >
                                    <PageHeaderMeterDonut
                                        percent={
                                            w.summary.monitors
                                                ? (w.summary.direct_monitors /
                                                      w.summary.monitors) *
                                                  100
                                                : 0
                                        }
                                    />
                                    <PageHeaderMeterCaption>
                                        {w.summary.direct_monitors} direct ·{' '}
                                        {w.summary.remote_monitors} remote
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Discovery scopes"
                                    onClick={() => select('scopes')}
                                >
                                    <PageHeaderMeterBig>
                                        {w.summary.scopes}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Saved network ranges and protocols
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Runs"
                                    onClick={() => select('runs')}
                                >
                                    <PageHeaderMeterBig>
                                        {w.summary.runs}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {w.summary.runs_shown} shown here
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Candidates for review"
                                    tone={
                                        w.summary.candidates_requiring_review
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    onClick={() => select('candidates')}
                                >
                                    <PageHeaderMeterBig>
                                        {w.summary.candidates_requiring_review}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Of {w.summary.candidates} found across
                                        your Sites
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Collectors reporting"
                                    value={
                                        w.summary.online_collectors +
                                        '/' +
                                        w.summary.collectors
                                    }
                                    onClick={() => select('collectors')}
                                >
                                    <PageHeaderMeterDonut
                                        percent={
                                            w.summary.collectors
                                                ? (w.summary.online_collectors /
                                                      w.summary.collectors) *
                                                  100
                                                : 0
                                        }
                                    />
                                    <PageHeaderMeterCaption>
                                        Sent a recent heartbeat
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Paths unavailable"
                                    tone={
                                        w.summary.collection_paths_unavailable
                                            ? 'critical'
                                            : 'brand'
                                    }
                                    onClick={() => select('paths')}
                                >
                                    <PageHeaderMeterBig>
                                        {w.summary.collection_paths_unavailable}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {w.summary.affected_devices} devices
                                        affected
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                {(recordView || query !== '') && (
                                    <PageHeaderViewToggle
                                        value={layout}
                                        onChange={setLayout}
                                        ariaLabel="Discovery record layout"
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
                                )}
                                <PageHeaderFilterSelect
                                    label="Site"
                                    value={site}
                                    options={[
                                        { value: 'all', label: 'All Sites' },
                                        ...siteOptions,
                                    ]}
                                    onChange={setSiteFilter}
                                />
                                {recordView && stateOptions.length > 0 && (
                                    <PageHeaderFilterSelect
                                        label={stateFilterLabels[activeTab]}
                                        value={state}
                                        options={[
                                            { value: 'all', label: 'All' },
                                            ...stateOptions,
                                        ]}
                                        onChange={setStateFilter}
                                    />
                                )}
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                value={activeTab}
                                onSelect={select}
                                ariaLabel="Discovery views"
                                items={w.tabs
                                    .filter((tab) => tab.key in viewLabels)
                                    .map((tab) => ({
                                        key: knownView(tab.key),
                                        label: tab.label,
                                        icon: viewIcons[knownView(tab.key)],
                                    }))}
                            />
                        }
                    />
                }
            >
                <div className="space-y-5">
                    <SecurityDevicesSectionTabs
                        section="setup"
                        activeTab="discovery"
                    />
                    {notice && (
                        <Alert>
                            <AlertTitle>Records not refreshed</AlertTitle>
                            <AlertDescription>{notice}</AlertDescription>
                        </Alert>
                    )}
                    {!recordView && query !== '' && (
                        <DiscoveryRecords
                            rows={globalMatches}
                            title="Matching loaded discovery records"
                            loaded={allRows.length}
                            total={allRows.length}
                            layout={layout}
                            empty="No loaded records match this search"
                            narrowed
                        />
                    )}
                    {activeTab === 'overview' && (
                        <>
                            <ReviewCard
                                icon={ShieldCheck}
                                title={w.boundary.title}
                            >
                                <p className="text-subtle">
                                    {w.boundary.description}
                                </p>
                                <p className="text-caption mt-3">
                                    {w.boundary.runtime_note}
                                </p>
                            </ReviewCard>
                            <DirectCoverage workspace={w} />
                            <DiscoveryRecords
                                rows={siteCollectors.slice(0, 3)}
                                title={
                                    'Remote collection health' +
                                    (siteName ? ' · ' + siteName : '')
                                }
                                loaded={siteCollectors.length}
                                total={
                                    site === 'all'
                                        ? w.summary.collectors
                                        : undefined
                                }
                                layout="cards"
                                empty={
                                    site === 'all'
                                        ? 'No remote collectors configured'
                                        : 'No remote collectors at this Site'
                                }
                                narrowed={site !== 'all'}
                            />
                            <ReviewCard
                                icon={Activity}
                                title="How much history is shown"
                            >
                                <ReviewRow
                                    label="Runs shown"
                                    value={
                                        w.summary.runs_shown +
                                        ' of ' +
                                        w.summary.runs
                                    }
                                />
                                <ReviewRow
                                    label="Older runs left out"
                                    value={
                                        w.summary.runs_truncated
                                            ? 'Yes · the latest runs are shown'
                                            : 'No'
                                    }
                                />
                                <ReviewRow
                                    label="Candidates shown"
                                    value={
                                        w.summary.candidates_shown +
                                        ' of ' +
                                        w.summary.candidates
                                    }
                                />
                                <ReviewRow
                                    label="Candidates left out"
                                    value={
                                        w.summary.candidates_truncated
                                            ? 'Yes · candidates needing review are shown first'
                                            : 'No'
                                    }
                                />
                                <ReviewRow
                                    label="Candidates needing review"
                                    value={
                                        w.summary.candidates_requiring_review
                                    }
                                />
                            </ReviewCard>
                            <div className="grid gap-5 lg:grid-cols-3">
                                <ReviewCard
                                    icon={CircleHelp}
                                    title="Device capabilities"
                                >
                                    <StatusBadge variant="neutral">
                                        {plain(w.limitations.unsupported_state)}
                                    </StatusBadge>
                                    <p className="text-subtle mt-3">
                                        {w.limitations.unsupported_note}
                                    </p>
                                </ReviewCard>
                                <ReviewCard icon={Radar} title="Not configured">
                                    <p className="text-subtle">
                                        {w.limitations.not_configured_note}
                                    </p>
                                </ReviewCard>
                                <ReviewCard
                                    icon={RadioTower}
                                    title="Collector capacity"
                                >
                                    <p className="text-subtle">
                                        {w.limitations.capacity_note}
                                    </p>
                                </ReviewCard>
                            </div>
                        </>
                    )}
                    {activeTab === 'paths' && <DirectCoverage workspace={w} />}
                    {recordView && (
                        <>
                            {activeTab === 'collectors' &&
                                mayManageCollectors &&
                                w.collector_management.sites.length > 0 && (
                                    <div className="flex flex-wrap justify-end gap-2">
                                        {w.collector_management.issue_url ===
                                            discoveryPath +
                                                '/collectors/enrolments' && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setInstallationOpen(true)
                                                }
                                            >
                                                Set up collector
                                            </Button>
                                        )}
                                        <Button
                                            onClick={() =>
                                                setHubWizardOpen(true)
                                            }
                                        >
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add a hub
                                        </Button>
                                    </div>
                                )}
                            {activeTab === 'runs' &&
                                w.summary.runs_truncated && (
                                    <Alert>
                                        <AlertTitle>
                                            Only recent runs are shown
                                        </AlertTitle>
                                        <AlertDescription>
                                            Showing the latest{' '}
                                            {w.summary.runs_shown} of{' '}
                                            {w.summary.runs} runs. Search and
                                            filters cover the runs shown.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            {activeTab === 'candidates' &&
                                canManage &&
                                candidateManagement.can_adopt &&
                                candidateManagement.adopt_all_url &&
                                candidateManagement.adoptable_count > 0 && (
                                    <div className="flex justify-end">
                                        <Button
                                            onClick={() =>
                                                setAdoptAllOpen(true)
                                            }
                                        >
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add all
                                        </Button>
                                    </div>
                                )}
                            {activeTab === 'candidates' &&
                                w.summary.candidates_truncated && (
                                    <Alert>
                                        <AlertTitle>
                                            Not every candidate is shown
                                        </AlertTitle>
                                        <AlertDescription>
                                            Showing {w.summary.candidates_shown}{' '}
                                            of {w.summary.candidates}{' '}
                                            candidates. Candidates requiring
                                            review are prioritised.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            <DiscoveryRecords
                                rows={filtered}
                                title={viewLabels[activeTab]}
                                loaded={loadedRows.length}
                                total={
                                    site === 'all'
                                        ? counts[activeTab]
                                        : undefined
                                }
                                layout={layout}
                                empty={
                                    narrowed
                                        ? 'No loaded records match these filters'
                                        : 'No ' +
                                          viewLabels[
                                              activeTab
                                          ].toLocaleLowerCase() +
                                          ' in this Site view'
                                }
                                narrowed={narrowed}
                            />
                        </>
                    )}
                </div>
            </PageLayout>
            {mayManageCollectors && (
                <>
                    <CollectorInstallationDialog
                        open={installationOpen}
                        onClose={() => setInstallationOpen(false)}
                        sites={w.collector_management.sites}
                        actorId={actor_id}
                    />
                    <AddHubWizard
                        open={hubWizardOpen}
                        onClose={() => setHubWizardOpen(false)}
                        onConnected={refresh}
                        sites={w.collector_management.sites}
                        defaultSiteId={site !== 'all' ? Number(site) : null}
                        optionsUrl={
                            w.collector_management.wizard_options_url ??
                            undefined
                        }
                        issueUrl={
                            w.collector_management.wizard_issue_url ?? undefined
                        }
                    />
                    <EditHubDialog
                        open={editingHub !== null}
                        onOpenChange={(open) => {
                            if (!open) setEditingHub(null);
                        }}
                        hub={editingHub}
                        sites={w.collector_management.sites}
                        onSaved={refresh}
                    />
                </>
            )}
            {mayManageCollectors &&
                replacement &&
                collectorSitePermitted(replacement) && (
                    <CollectorEnrollmentDialog
                        open
                        onOpenChange={(open) => {
                            if (!open) setReplacement(null);
                        }}
                        issueUrl={w.collector_management.issue_url ?? ''}
                        sites={w.collector_management.sites}
                        replacement={replacement}
                        onIssued={refresh}
                    />
                )}
            {mayManageCollectors &&
                revocation &&
                collectorSitePermitted(revocation) && (
                    <CollectorRevocationDialog
                        open
                        onOpenChange={(open) => {
                            if (!open) setRevocationId(null);
                        }}
                        collector={lifecycleTarget(revocation)}
                        onRevoked={refresh}
                    />
                )}
            {mayManageScopes &&
                scopeDialogOpen &&
                (editingId === null || selectedScope) && (
                    <DiscoveryScopeWizard
                        key={editingId ?? 'new'}
                        management={scopeManagement}
                        scope={
                            selectedScope ? governedScope(selectedScope) : null
                        }
                        onClose={() => setScopeDialogOpen(false)}
                        onSaved={refresh}
                        onAccessDenied={onAccessDenied}
                    />
                )}
            {mayManageScopes && actionScope && scopeAction && (
                <DiscoveryScopeActionDialog
                    open
                    scope={governedScope(actionScope)}
                    action={scopeAction.action}
                    onOpenChange={(open) => {
                        if (!open) setScopeAction(null);
                    }}
                    onApplied={refresh}
                />
            )}
            {canManage && candidateManagement.can_adopt && (
                <>
                    <CandidateAdoptDialog
                        candidate={adoptTarget}
                        management={candidateManagement}
                        onClose={() => setAdoptTarget(null)}
                        onCompleted={refresh}
                    />
                    <CandidateAdoptAllDialog
                        open={adoptAllOpen}
                        adoptAllUrl={candidateManagement.adopt_all_url}
                        count={candidateManagement.adoptable_count}
                        siteId={site === 'all' ? null : Number(site)}
                        siteName={siteName}
                        onClose={() => setAdoptAllOpen(false)}
                        onCompleted={refresh}
                    />
                </>
            )}
            {canManage && candidateManagement.can_same_as && (
                <CandidateSameAsDialog
                    candidate={sameAsTarget}
                    management={candidateManagement}
                    onClose={() => setSameAsTarget(null)}
                    onCompleted={refresh}
                />
            )}
        </AppLayout>
    );
}

function DirectCoverage({ workspace: w }: { workspace: DiscoveryWorkspace }) {
    return (
        <ReviewCard icon={Cable} title={w.direct_coverage.path_label}>
            <ReviewRow
                label="Configured checks"
                value={w.direct_coverage.monitors}
            />
            <ReviewRow
                label="Configured devices"
                value={w.direct_coverage.devices}
            />
            <p className="text-subtle mt-3">{w.direct_coverage.description}</p>
            <Button asChild variant="outline" className="mt-3">
                <Link href="/security-devices/monitoring">Open Monitoring</Link>
            </Button>
        </ReviewCard>
    );
}
function DiscoveryRecords({
    rows,
    loaded,
    total,
    title: label,
    layout,
    empty,
    narrowed = false,
}: {
    rows: DiscoveryEntry[];
    loaded: number;
    /** Server total for the view; omitted when a Site filter narrows it. */
    total?: number;
    title: string;
    layout: DiscoveryLayout;
    empty: string;
    narrowed?: boolean;
}) {
    const [readingKey, setReadingKey] = useState<string | null>(null);
    const reading = rows.find((entry) => entry.key === readingKey);
    const context = useEntityContextMenu<DiscoveryEntry>();
    const actions = (entry: DiscoveryEntry): MenuItem[] => [
        {
            label: 'View details',
            icon: Eye,
            onClick: () => setReadingKey(entry.key),
        },
        ...entry.actions,
    ];
    return (
        <>
            <ListCaption
                title={label}
                caption={
                    rows.length +
                    ' of ' +
                    loaded +
                    ' loaded' +
                    (total === undefined ? '' : ' · ' + total + ' in scope')
                }
            />
            {rows.length === 0 ? (
                <EmptyState
                    icon={Radar}
                    title={empty}
                    description={
                        narrowed
                            ? 'Change or clear the search and filters to see more records.'
                            : 'Records appear here once they have been set up or reported.'
                    }
                />
            ) : layout === 'cards' ? (
                <EntityCardGrid>
                    {rows.map((entry) => (
                        <EntityCard
                            key={entry.key}
                            name={entry.name}
                            subline={entry.subline}
                            icon={entry.icon}
                            meridian="neutral"
                            actions={actions(entry)}
                            onOpen={() => setReadingKey(entry.key)}
                            onContextMenu={(event) =>
                                context.open(event, entry)
                            }
                            chips={
                                <StatusBadge variant={tone(entry.state)}>
                                    {plain(entry.state)}
                                </StatusBadge>
                            }
                            metric={
                                entry.progress
                                    ? {
                                          label: 'Results returned',
                                          value:
                                              entry.progress.returned +
                                              ' of ' +
                                              entry.progress.planned,
                                          percent: entry.progress.planned
                                              ? Math.min(
                                                    100,
                                                    (entry.progress.returned /
                                                        entry.progress
                                                            .planned) *
                                                        100,
                                                )
                                              : null,
                                      }
                                    : undefined
                            }
                            footer={{
                                primary: entry.summary,
                                secondary:
                                    entry.site?.name ??
                                    'Site context unavailable',
                                personIcon: entry.icon,
                            }}
                        />
                    ))}
                </EntityCardGrid>
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={(entry) => entry.key}
                    identityLabel="Record"
                    wrapIdentity
                    identity={(entry) => ({
                        name: entry.name,
                        subline: entry.subline,
                        icon: entry.icon,
                    })}
                    columns={[
                        {
                            key: 'state',
                            label: 'Returned state',
                            width: '1fr',
                            cell: (entry) => (
                                <StatusBadge variant={tone(entry.state)}>
                                    {plain(entry.state)}
                                </StatusBadge>
                            ),
                        },
                        {
                            key: 'observations',
                            label: 'Observations',
                            width: '2fr',
                            cell: (entry) => entry.summary,
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(entry) => setReadingKey(entry.key)}
                    onRowContextMenu={context.open}
                    rowHeight="content"
                    minWidth={800}
                />
            )}
            {context.ctx &&
                rows.some((entry) => entry.key === context.ctx?.record.key) && (
                    <EntityContextMenu
                        x={context.ctx.x}
                        y={context.ctx.y}
                        title={context.ctx.record.name}
                        icon={context.ctx.record.icon}
                        items={actions(context.ctx.record)}
                        onClose={context.close}
                    />
                )}
            {reading && (
                <DiscoveryReader
                    entry={reading}
                    onClose={() => setReadingKey(null)}
                />
            )}
        </>
    );
}
function DiscoveryReader({
    entry,
    onClose,
}: {
    entry: DiscoveryEntry;
    onClose: () => void;
}) {
    const [section, setSection] = useState(0);
    return (
        <WizardShell
            open
            onClose={onClose}
            title={'Discovery details · ' + entry.name}
            description="What was saved or reported for this record at its Site. Changes are made from the record's own page."
            railIcon={entry.icon}
            railTitle={entry.name}
            railSub={entry.subline}
            sequential={false}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={
                section === 0 ? 'Record observations' : 'Technical detail'
            }
            steps={[
                {
                    key: 'observations',
                    label: 'Record observations',
                    blurb: 'Site, status and times',
                    icon: Eye,
                },
                {
                    key: 'technical',
                    label: 'Technical detail',
                    blurb: 'References and exact counts',
                    icon: Activity,
                },
            ]}
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close details
                </Button>
            }
        >
            <WizardStepPane>
                <div className="space-y-5">
                    <ReviewCard
                        icon={entry.icon}
                        title={
                            section === 0
                                ? 'Record observations'
                                : 'Technical detail'
                        }
                    >
                        {(section === 0 ? entry.facts : entry.technical).map(
                            (fact) => (
                                <ReviewRow
                                    key={fact.label}
                                    label={fact.label}
                                    value={fact.value}
                                />
                            ),
                        )}
                    </ReviewCard>
                    {entry.note && <p className="text-subtle">{entry.note}</p>}
                    {section === 0 && safeSiteHref(entry.site) && (
                        <Button asChild variant="outline">
                            <Link href={safeSiteHref(entry.site)!}>
                                Open Site technology
                            </Link>
                        </Button>
                    )}
                    {section === 0 && entry.deviceHref && (
                        <Button asChild variant="outline">
                            <Link href={entry.deviceHref}>Open device</Link>
                        </Button>
                    )}
                    <p className="text-caption">
                        Times are NZ time. Each one is when that item was last
                        reported, which may be earlier than this read.
                    </p>
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

function list(value: string): string[] {
    return value
        .split(/\r?\n/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function ports(value: string): number[] {
    return value
        .split(/[\s,]+/)
        .map(Number)
        .filter((item) => Number.isInteger(item));
}

const DEFAULT_PORTS: Record<string, string> = {
    tcp: '22, 80, 443, 161, 5985, 5986',
    dns: '53',
    http: '80, 443',
    tls: '443',
    snmp: '161',
};

const EXTENDED_DEFAULT_PORTS: Record<string, string> = {
    tcp: '22, 80, 161, 443, 515, 554, 631, 1883, 3389, 5000, 5001, 5985, 5986, 8000, 8080, 8443, 9100',
    dns: '53',
    http: '80, 443, 5000, 5001, 8000, 8080, 8443',
    tls: '443, 8443',
    snmp: '161',
    mdns: '5353',
    ssdp: '1900',
    onvif: '3702',
};

const PORTLESS_PROTOCOLS = new Set(['icmp', 'arp']);

const EXTENDED_PROTOCOL_DEFAULTS = [
    'icmp',
    'arp',
    'tcp',
    'dns',
    'http',
    'tls',
    'snmp',
    'mdns',
    'ssdp',
    'onvif',
];

const LEGACY_PROTOCOL_DEFAULTS = ['icmp', 'tcp', 'dns', 'tls'];

const PROTOCOL_LABELS: Record<string, string> = {
    icmp: 'ICMP · Ping',
    arp: 'ARP · Local MAC table',
    tcp: 'TCP · Common ports',
    dns: 'DNS · Hostnames',
    http: 'HTTP · Web banners',
    tls: 'TLS · Certificates',
    snmp: 'SNMP · Network gear',
    mdns: 'mDNS · Local services',
    ssdp: 'SSDP · Plug and play',
    onvif: 'ONVIF · Cameras',
};

function scopeFailureMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors;
        if (errors && typeof errors === 'object') {
            const message = Object.values(errors as Record<string, unknown>)
                .flatMap((value) => (Array.isArray(value) ? value : [value]))
                .find(
                    (value): value is string =>
                        typeof value === 'string' && value.length > 0,
                );
            if (message) return message;
        }
    }

    return 'The scope was not saved. Check the entries and try again.';
}

export function DiscoveryScopeWizard({
    management,
    scope,
    onClose,
    onSaved,
    onAccessDenied,
}: {
    management: DiscoveryScopeManagement;
    scope: GovernedDiscoveryScope | null;
    onClose: () => void;
    onSaved: () => void;
    onAccessDenied?: () => void;
}) {
    const editing = scope !== null;
    const hasExtendedDiscovery = management.protocols.includes('onvif');
    const defaultPortMap = hasExtendedDiscovery
        ? EXTENDED_DEFAULT_PORTS
        : DEFAULT_PORTS;
    const [siteId, setSiteId] = useState(
        scope?.site?.id.toString() ??
            (management.sites.length === 1
                ? management.sites[0].id.toString()
                : ''),
    );
    const [collectorId, setCollectorId] = useState(
        String(scope?.collector_id ?? scope?.collector?.id ?? ''),
    );
    const [name, setName] = useState(scope?.name ?? '');
    const [cidrs, setCidrs] = useState('');
    const [seedHosts, setSeedHosts] = useState('');
    const [exclusions, setExclusions] = useState('');
    const [selectedProtocols, setSelectedProtocols] = useState<string[]>(
        scope?.protocols ??
            (hasExtendedDiscovery
                ? EXTENDED_PROTOCOL_DEFAULTS
                : LEGACY_PROTOCOL_DEFAULTS
            ).filter((protocol) => management.protocols.includes(protocol)),
    );
    const [allowedPorts, setAllowedPorts] = useState<Record<string, string>>(
        editing ? {} : { ...defaultPortMap },
    );
    const [snmpReference, setSnmpReference] = useState('');
    const [maxTargets, setMaxTargets] = useState(
        scope?.max_targets_per_run.toString() ?? '1024',
    );
    const [packetsPerSecond, setPacketsPerSecond] = useState(
        scope?.packets_per_second.toString() ?? '20',
    );
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [step, setStep] = useState(0);
    const [discardOpen, setDiscardOpen] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const active = useRef(true),
        pending = useRef(false),
        controller = useRef(new AbortController());
    useEffect(() => {
        active.current = true;
        const requestController = controller.current;
        return () => {
            active.current = false;
            requestController.abort();
        };
    }, []);
    const values = {
        siteId,
        collectorId,
        name,
        cidrs,
        seedHosts,
        exclusions,
        selectedProtocols,
        allowedPorts,
        snmpReference,
        maxTargets,
        packetsPerSecond,
    };
    const [baseline] = useState(values);
    const dirty = JSON.stringify(values) !== JSON.stringify(baseline);
    // Close always works, even while a save is in flight or after an unknown
    // outcome; those cases (and any unsaved entry) go through the shared
    // discard confirmation first.
    const close = () => {
        if (submitting || uncertain || dirty) setDiscardOpen(true);
        else onClose();
    };
    const discard = () => {
        // The scope endpoints take no request key, so a save whose outcome is
        // unknown is never resent: stop waiting for it and re-read the records
        // so the viewer can see whether it was saved.
        const unknownOutcome = pending.current || uncertain;
        controller.current.abort();
        if (unknownOutcome) onSaved();
        onClose();
    };
    const toggleProtocol = (protocol: string, checked: boolean) =>
        setSelectedProtocols((current) =>
            checked
                ? [...new Set([...current, protocol])]
                : current.filter((item) => item !== protocol),
        );
    const validSite =
        editing || management.sites.some((site) => String(site.id) === siteId);
    const selectedSiteNumeric = Number(
        editing ? (scope?.site?.id ?? 0) : siteId,
    );
    const eligibleHubs = (management.collectors ?? []).filter(
        (hub) =>
            !selectedSiteNumeric ||
            hub.authorised_site_ids.includes(selectedSiteNumeric),
    );
    const selectedHub = eligibleHubs.find(
        (hub) => String(hub.id) === collectorId,
    );
    const handleSiteChange = (nextSiteId: string) => {
        setSiteId(nextSiteId);
        const numeric = Number(nextSiteId);
        if (
            collectorId !== '' &&
            numeric > 0 &&
            !(management.collectors ?? []).some(
                (hub) =>
                    String(hub.id) === collectorId &&
                    hub.authorised_site_ids.includes(numeric),
            )
        ) {
            setCollectorId('');
        }
    };
    const validName = name.trim().length >= 3;
    const validIdentity = validName && validSite;
    const validNetworks = editing || list(cidrs).length > 0;
    const validProtocolChoice =
        selectedProtocols.length > 0 &&
        selectedProtocols.every((protocol) =>
            management.protocols.includes(protocol),
        );
    const validTargets =
        Number.isInteger(Number(maxTargets)) &&
        Number(maxTargets) >= 1 &&
        Number(maxTargets) <= 65536;
    const validRate =
        Number.isInteger(Number(packetsPerSecond)) &&
        Number(packetsPerSecond) >= 1 &&
        Number(packetsPerSecond) <= 1000;
    const validProtocols = validProtocolChoice && validTargets && validRate;
    const ready = validIdentity && validNetworks && validProtocols;
    // Completeness counts the actual required entries for this form.
    const requirements = [
        ...(editing ? [] : [validSite]),
        validName,
        ...(editing ? [] : [validNetworks]),
        validProtocolChoice,
        validTargets,
        validRate,
    ];
    const requirementsMet = requirements.filter(Boolean).length;
    const next = () => {
        const valid =
            step === 0
                ? validIdentity
                : step === 1
                  ? validNetworks
                  : validProtocols;
        if (valid) {
            setError(null);
            setStep(step + 1);
        } else
            setError(
                'Complete the required details in this step before continuing.',
            );
    };
    const submit = async () => {
        const url = editing
            ? scopeUrl(scope.id, scope.actions.update_url)
            : management.create_url === discoveryPath + '/scopes'
              ? management.create_url
              : null;
        if (
            !management.can_manage ||
            !url ||
            !ready ||
            pending.current ||
            uncertain
        )
            return;
        const payload: Record<string, unknown> = {
            name: name.trim(),
            protocols: selectedProtocols,
            max_targets_per_run: Number(maxTargets),
            packets_per_second: Number(packetsPerSecond),
        };
        if (!editing) {
            payload.site_id = Number(siteId);
            if (collectorId !== '') {
                payload.collector_id = Number(collectorId);
            }
        } else {
            const initialCollectorId = String(
                scope?.collector_id ?? scope?.collector?.id ?? '',
            );
            if (collectorId !== initialCollectorId) {
                payload.collector_id =
                    collectorId === '' ? null : Number(collectorId);
            }
        }
        if (cidrs.trim()) payload.cidrs = list(cidrs);
        if (seedHosts.trim()) payload.seed_hosts = list(seedHosts);
        if (exclusions.trim()) payload.exclusions = list(exclusions);
        const submittedPortBounds = Object.fromEntries(
            selectedProtocols
                .filter(
                    (protocol) =>
                        !PORTLESS_PROTOCOLS.has(protocol) &&
                        Boolean(allowedPorts[protocol]?.trim()),
                )
                .map((protocol) => [protocol, ports(allowedPorts[protocol])]),
        );
        if (Object.keys(submittedPortBounds).length)
            payload.port_bounds = submittedPortBounds;
        if (selectedProtocols.includes('snmp') && snmpReference.trim())
            payload.snmp_credential_reference = snmpReference.trim();
        pending.current = true;
        setSubmitting(true);
        setError(null);
        try {
            const response = editing
                ? await axios.patch(url, payload, {
                      signal: controller.current.signal,
                  })
                : await axios.post(url, payload, {
                      signal: controller.current.signal,
                  });
            if (!active.current) return;
            const returned = response.data?.scope;
            if (
                !returned ||
                !Number.isInteger(returned.id) ||
                returned.id <= 0 ||
                returned.site_id !== Number(siteId) ||
                (editing && returned.id !== scope.id)
            ) {
                setUncertain(true);
                setError(
                    'The reply to this save could not be checked, so the change may already be saved. Saving again is blocked; use Close and check saved scopes to see the current records.',
                );
                return;
            }
            onSaved();
            onClose();
        } catch (requestError) {
            if (!active.current || controller.current.signal.aborted) return;
            if (
                axios.isAxiosError(requestError) &&
                [401, 403, 404].includes(requestError.response?.status ?? 0)
            ) {
                onAccessDenied?.();
                return;
            }
            if (
                !axios.isAxiosError(requestError) ||
                !requestError.response ||
                requestError.response.status >= 500
            ) {
                setUncertain(true);
                setError(
                    'Save status is unknown: the change may already be saved, so saving again is blocked. Use Close and check saved scopes to see the current records.',
                );
            } else setError(scopeFailureMessage(requestError));
        } finally {
            if (active.current) {
                pending.current = false;
                setSubmitting(false);
            }
        }
    };
    if (!management.can_manage)
        return (
            <EmptyState
                icon={ShieldCheck}
                title="Scope management is unavailable"
                description="Current permission to manage integrations is required."
            />
        );
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={
                    editing
                        ? 'Update discovery scope'
                        : collectorId
                          ? 'Add hub discovery scope'
                          : 'Add direct discovery scope'
                }
                description="Save which networks discovery may check at one of your Sites. Saving does not start a scan."
                railIcon={Network}
                railTitle={
                    editing
                        ? 'Update scope'
                        : collectorId
                          ? 'Add hub scope'
                          : 'Add direct scope'
                }
                railSub={scope?.name ?? 'Approved Site discovery'}
                steps={[
                    {
                        key: 'identity',
                        label: 'Site and scope',
                        blurb: 'Approved Site and useful name',
                        icon: ShieldCheck,
                    },
                    {
                        key: 'networks',
                        label: 'Networks',
                        blurb: 'Approved ranges, seeds and exclusions',
                        icon: Network,
                    },
                    {
                        key: 'protocols',
                        label: 'Protocols and limits',
                        blurb: 'Allowed ports and bounded rate',
                        icon: Activity,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check configuration before saving',
                        icon: Eye,
                    },
                ]}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!submitting) setStep(index);
                }}
                pct={Math.round((requirementsMet / requirements.length) * 100)}
                pctLabel={
                    requirementsMet +
                    ' of ' +
                    requirements.length +
                    ' required details'
                }
                footerStart={
                    <>
                        <Button variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={submitting}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 3 ? (
                        <Button disabled={submitting} onClick={next}>
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={submitting || !ready || uncertain}
                            onClick={() => void submit()}
                        >
                            {submitting
                                ? 'Saving…'
                                : editing
                                  ? 'Apply scope update'
                                  : collectorId
                                    ? 'Add hub scope'
                                    : 'Add direct scope'}
                        </Button>
                    )
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        {error && (
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Scope save needs attention
                                </AlertTitle>
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {uncertain && (
                            <Button
                                variant="outline"
                                onClick={() => setDiscardOpen(true)}
                            >
                                Close and check saved scopes
                            </Button>
                        )}
                        <fieldset disabled={submitting} className="space-y-5">
                            {step === 0 && (
                                <>
                                    {!editing ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="discovery-scope-site">
                                                Site
                                            </Label>
                                            <Select
                                                value={siteId}
                                                onValueChange={handleSiteChange}
                                            >
                                                <SelectTrigger id="discovery-scope-site">
                                                    <SelectValue placeholder="Select an approved Site" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {management.sites.map(
                                                        (site) => (
                                                            <SelectItem
                                                                key={site.id}
                                                                value={site.id.toString()}
                                                            >
                                                                {site.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    ) : (
                                        <div className="rounded-lg border p-3 text-sm">
                                            <strong>
                                                {scope.site?.name ??
                                                    'Site unavailable'}
                                            </strong>
                                            <p className="text-muted-foreground">
                                                {collectorId
                                                    ? 'Runs from: hub ' +
                                                      (selectedHub?.name ??
                                                          scope.collector
                                                              ?.name ??
                                                          '#' + collectorId)
                                                    : 'Runs from: server'}
                                            </p>
                                        </div>
                                    )}
                                    {(eligibleHubs.length > 0 ||
                                        collectorId !== '') && (
                                        <div className="space-y-2">
                                            <Label>Runs from</Label>
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setCollectorId('')
                                                    }
                                                    className={`h-auto justify-start p-3 text-left whitespace-normal ${
                                                        collectorId === ''
                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                            : ''
                                                    }`}
                                                >
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-1.5 text-sm font-medium">
                                                            <Server className="h-3.5 w-3.5 text-muted-foreground" />
                                                            Server (direct when
                                                            routable)
                                                        </div>
                                                        <p className="text-xs font-normal text-muted-foreground">
                                                            Runs directly from
                                                            Oblivion Findings.
                                                        </p>
                                                    </div>
                                                </Button>
                                                {eligibleHubs.map((hub) => {
                                                    const selected =
                                                        collectorId ===
                                                        String(hub.id);
                                                    const remoteCover =
                                                        selectedSiteNumeric >
                                                            0 &&
                                                        hub.site_id !==
                                                            selectedSiteNumeric;
                                                    return (
                                                        <Button
                                                            key={hub.id}
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setCollectorId(
                                                                    String(
                                                                        hub.id,
                                                                    ),
                                                                )
                                                            }
                                                            className={`h-auto justify-start p-3 text-left whitespace-normal ${
                                                                selected
                                                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                                    : ''
                                                            }`}
                                                        >
                                                            <div className="space-y-1">
                                                                <div className="flex items-center gap-1.5 text-sm font-medium">
                                                                    <RadioTower className="h-3.5 w-3.5 text-muted-foreground" />
                                                                    Hub{' '}
                                                                    {hub.name}
                                                                </div>
                                                                <p className="text-xs font-normal text-muted-foreground">
                                                                    {remoteCover
                                                                        ? 'Covers this Site over SD-WAN or MPLS.'
                                                                        : 'Installed on this Site network.'}
                                                                </p>
                                                            </div>
                                                        </Button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                    <div className="space-y-2">
                                        <Label htmlFor="discovery-scope-name">
                                            Scope name
                                        </Label>
                                        <Input
                                            id="discovery-scope-name"
                                            value={name}
                                            onChange={(event) =>
                                                setName(event.target.value)
                                            }
                                            minLength={3}
                                            maxLength={128}
                                            required
                                        />
                                    </div>
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="discovery-scope-cidrs">
                                            {editing
                                                ? 'Replacement approved networks (optional)'
                                                : 'Approved networks'}
                                        </Label>
                                        <Textarea
                                            id="discovery-scope-cidrs"
                                            value={cidrs}
                                            onChange={(event) =>
                                                setCidrs(event.target.value)
                                            }
                                            rows={3}
                                            required={!editing}
                                            placeholder="10.44.0.0/16"
                                        />
                                        <p className="text-caption">
                                            One CIDR per line. Existing ranges
                                            remain hidden; leave blank while
                                            editing to preserve them.
                                        </p>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="discovery-scope-seeds">
                                                {editing
                                                    ? 'Replacement seed hosts (optional)'
                                                    : 'Seed hosts (optional)'}
                                            </Label>
                                            <Textarea
                                                id="discovery-scope-seeds"
                                                value={seedHosts}
                                                onChange={(event) =>
                                                    setSeedHosts(
                                                        event.target.value,
                                                    )
                                                }
                                                rows={3}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="discovery-scope-exclusions">
                                                {editing
                                                    ? 'Replacement exclusions (optional)'
                                                    : 'Exclusions (optional)'}
                                            </Label>
                                            <Textarea
                                                id="discovery-scope-exclusions"
                                                value={exclusions}
                                                onChange={(event) =>
                                                    setExclusions(
                                                        event.target.value,
                                                    )
                                                }
                                                rows={3}
                                            />
                                        </div>
                                    </div>
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">
                                            Native discovery protocols
                                        </legend>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {management.protocols.map(
                                                (protocol) => (
                                                    <label
                                                        key={protocol}
                                                        className="flex items-center gap-2 rounded-lg border p-3 text-sm"
                                                    >
                                                        <Checkbox
                                                            checked={selectedProtocols.includes(
                                                                protocol,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                toggleProtocol(
                                                                    protocol,
                                                                    Boolean(
                                                                        checked,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        <span>
                                                            {PROTOCOL_LABELS[
                                                                protocol
                                                            ] ??
                                                                protocol.toUpperCase()}
                                                        </span>
                                                    </label>
                                                ),
                                            )}
                                        </div>
                                    </fieldset>
                                    <div className="space-y-3">
                                        <div>
                                            <Label>
                                                Protocol-specific allowed ports
                                            </Label>
                                            <p className="text-caption">
                                                Each adapter is limited to its
                                                own approved ports. Leave every
                                                field blank while editing to
                                                preserve the hidden allowlist.
                                            </p>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {selectedProtocols
                                                .filter(
                                                    (protocol) =>
                                                        !PORTLESS_PROTOCOLS.has(
                                                            protocol,
                                                        ),
                                                )
                                                .map((protocol) => (
                                                    <div
                                                        key={protocol}
                                                        className="space-y-2"
                                                    >
                                                        <Label
                                                            htmlFor={`discovery-scope-ports-${protocol}`}
                                                        >
                                                            {protocol.toUpperCase()}{' '}
                                                            ports
                                                        </Label>
                                                        <Input
                                                            id={`discovery-scope-ports-${protocol}`}
                                                            value={
                                                                allowedPorts[
                                                                    protocol
                                                                ] ?? ''
                                                            }
                                                            onChange={(event) =>
                                                                setAllowedPorts(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [protocol]:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    }),
                                                                )
                                                            }
                                                            placeholder={
                                                                defaultPortMap[
                                                                    protocol
                                                                ] ??
                                                                'Approved ports'
                                                            }
                                                        />
                                                    </div>
                                                ))}
                                        </div>
                                    </div>
                                    {selectedProtocols.includes('snmp') ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="discovery-scope-snmp-reference">
                                                SNMP credential reference
                                                (optional)
                                            </Label>
                                            <Input
                                                id="discovery-scope-snmp-reference"
                                                value={snmpReference}
                                                onChange={(event) =>
                                                    setSnmpReference(
                                                        event.target.value,
                                                    )
                                                }
                                                autoComplete="off"
                                            />
                                            <p className="text-caption">
                                                Leave blank to check standard
                                                read-only system info, or enter
                                                a Site reference for SNMPv3.
                                            </p>
                                        </div>
                                    ) : null}
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="discovery-scope-target-limit">
                                                Maximum targets per run
                                            </Label>
                                            <Input
                                                id="discovery-scope-target-limit"
                                                type="number"
                                                min={1}
                                                max={65536}
                                                value={maxTargets}
                                                onChange={(event) =>
                                                    setMaxTargets(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="discovery-scope-rate">
                                                Packets per second
                                            </Label>
                                            <Input
                                                id="discovery-scope-rate"
                                                type="number"
                                                min={1}
                                                max={1000}
                                                value={packetsPerSecond}
                                                onChange={(event) =>
                                                    setPacketsPerSecond(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                </>
                            )}
                            {step === 3 && (
                                <div className="space-y-5">
                                    <ReviewCard
                                        icon={ShieldCheck}
                                        title="Site and scope"
                                        onEdit={() => setStep(0)}
                                    >
                                        <ReviewRow
                                            label="Site"
                                            value={
                                                scope?.site?.name ??
                                                management.sites.find(
                                                    (site) =>
                                                        String(site.id) ===
                                                        siteId,
                                                )?.name
                                            }
                                        />
                                        <ReviewRow
                                            label="Scope name"
                                            value={name.trim()}
                                        />
                                        <ReviewRow
                                            label="Runs from"
                                            value={
                                                collectorId
                                                    ? 'Runs from: hub ' +
                                                      (selectedHub?.name ??
                                                          scope?.collector
                                                              ?.name ??
                                                          '#' + collectorId)
                                                    : 'Runs from: server'
                                            }
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Network}
                                        title="Network bounds"
                                        onEdit={() => setStep(1)}
                                    >
                                        <ReviewRow
                                            label="Approved networks"
                                            value={
                                                cidrs.trim() ||
                                                (editing
                                                    ? 'Keep protected existing ranges'
                                                    : null)
                                            }
                                        />
                                        <ReviewRow
                                            label="Seed hosts"
                                            value={
                                                seedHosts.trim() ||
                                                (editing
                                                    ? 'Keep existing seeds'
                                                    : 'None entered')
                                            }
                                        />
                                        <ReviewRow
                                            label="Exclusions"
                                            value={
                                                exclusions.trim() ||
                                                (editing
                                                    ? 'Keep existing exclusions'
                                                    : 'None entered')
                                            }
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Activity}
                                        title="Protocols and limits"
                                        onEdit={() => setStep(2)}
                                    >
                                        <ReviewRow
                                            label="Protocols"
                                            value={selectedProtocols.join(', ')}
                                        />
                                        <ReviewRow
                                            label="Allowed ports"
                                            value={
                                                selectedProtocols
                                                    .filter((protocol) =>
                                                        allowedPorts[
                                                            protocol
                                                        ]?.trim(),
                                                    )
                                                    .map(
                                                        (protocol) =>
                                                            protocol +
                                                            ': ' +
                                                            allowedPorts[
                                                                protocol
                                                            ],
                                                    )
                                                    .join(' · ') ||
                                                (editing
                                                    ? 'Keep protected existing allowlist'
                                                    : 'None entered')
                                            }
                                        />
                                        <ReviewRow
                                            label="Maximum targets"
                                            value={maxTargets}
                                        />
                                        <ReviewRow
                                            label="Packets per second"
                                            value={packetsPerSecond}
                                        />
                                        {selectedProtocols.includes('snmp') && (
                                            <ReviewRow
                                                label="SNMP credential reference"
                                                value={
                                                    snmpReference
                                                        ? 'Site reference supplied'
                                                        : editing
                                                          ? 'Keep existing reference'
                                                          : 'Read-only system check (optional)'
                                                }
                                            />
                                        )}
                                    </ReviewCard>
                                    <p className="text-subtle">
                                        This saves scope configuration. Run now
                                        is a separate, authorised action. The
                                        server checks the current Site bounds
                                        and supported protocols before saving.
                                    </p>
                                </div>
                            )}
                        </fieldset>
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                onConfirm={discard}
                variant="destructive"
                title="Discard this discovery draft?"
                description={
                    submitting
                        ? 'Your save is still being sent. Closing stops waiting for it, but the change may still be saved. The scope records will refresh so you can check.'
                        : uncertain
                          ? 'Your last save may already have been saved. Your entries will be discarded and the scope records refreshed so you can check before making another change.'
                          : 'Your unsaved scope entries will be discarded.'
                }
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}
