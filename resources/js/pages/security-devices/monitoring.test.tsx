import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Monitoring, {
    MonitoringContent,
    type MonitoringWorkspace,
    type MonitorRow,
} from './monitoring';
const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
    actor: 7,
    profiles: true,
    events: true,
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: mocks.actor },
                    can: {
                        securityDevices: {
                            devicesView: mocks.profiles,
                            eventsView: mocks.events,
                            monitoringManage: true,
                        },
                    },
                },
            },
        }),
        router: {
            ...actual.router,
            on: (...args: Parameters<typeof actual.router.on>) =>
                actual.router.on(...args),
            get: mocks.get,
            visit: mocks.visit,
            reload: mocks.reload,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const remoteMonitor: MonitorRow = {
    id: 11,
    name: 'Remote SNMP',
    kind: 'snmp',
    reported_state: 'failed',
    effective_state: 'collection_unavailable',
    affects_availability: true,
    enabled: true,
    operational: true,
    suppressed_until: null,
    last_observation_at: '2026-07-19T08:00:00Z',
    freshness_state: 'stale',
    device: {
        id: 9,
        name: 'Remote switch',
        href: '/security-devices/devices/9',
        domain: 'it_infrastructure',
        category: 'networking',
    },
    site: {
        id: 4,
        name: 'Remote Site',
        href: '/security-devices/sites/4',
    },
    collection: {
        mode: 'remote_collector',
        collector_id: 3,
        collector_name: 'Remote collector',
        state: 'unavailable',
        last_seen_at: '2026-07-19T08:00:00Z',
    },
    latest_observation: null,
};
const collectionPath = {
    collector_id: 3,
    collector_name: 'Remote collector',
    state: 'unavailable',
    reported_status: 'offline',
    last_seen_at: '2026-07-19T08:00:00Z',
    heartbeat_lag_seconds: 600,
    site: {
        id: 4,
        name: 'Remote Site',
        href: '/security-devices/sites/4',
    },
    affected_monitors: 4,
    affected_devices: 3,
};
const monitoringWorkspace = {
    tabs: [{ key: 'findings', label: 'Findings' }],
    active_tab: 'findings',
    boundary: {
        title: 'Native monitoring',
        description: 'Direct first',
        privacy_note: 'No secrets',
        control_room_note: 'Control Room triage',
    },
    can: { view_control_room: false },
    summary: {
        total_devices: 3,
        total_monitors: 4,
        enabled_monitors: 4,
        direct_monitors: 0,
        remote_monitors: 4,
        monitored_devices: 3,
        unmonitored_devices: 0,
        healthy: 0,
        degraded: 0,
        failed: 0,
        unknown: 0,
        stale: 0,
        pending: 0,
        paused: 0,
        collection_paths_unavailable: 1,
        active_findings: 1,
    },
    findings: {
        monitors: [],
        collection_paths: [collectionPath],
        note: 'Grouped once.',
    },
    monitors: [remoteMonitor],
    inventory: { total: 1, shown: 1, truncated: false },
    coverage: {
        total_devices: 3,
        monitored_devices: 3,
        missing_devices: 0,
        paused_monitors: 0,
        fresh: 0,
        stale: 4,
        never_observed: 0,
        unsupported_state: 'not_assessed',
        unsupported_note: 'Not assessed.',
        by_kind: { snmp: 4 },
        by_site: [],
    },
    dependencies: {
        canonical_model_available: true,
        note: 'Dependency graph available.',
        suppressed_symptoms: 0,
        records: [],
        collection_paths: [collectionPath],
    },
    trends: [],
    collection: {
        direct: { label: 'Main application', monitors: 0, devices: 0 },
        direct_sites: [
            {
                site: {
                    id: 2,
                    name: 'Central Site',
                    href: '/security-devices/sites/2',
                },
                state: 'ready',
                proof_state: 'verified',
                label: 'Direct monitoring proven',
                note: 'The main application has current direct evidence for this Site; no collector is required.',
                devices: 3,
                monitored_devices: 2,
                unmonitored_devices: 1,
                direct_monitors: 4,
                direct_devices: 2,
                remote_monitors: 0,
                disabled_monitors: 0,
                durable_direct_evidence: 4,
                fresh: 4,
                stale: 0,
                never_observed: 0,
                attention: 0,
                evidence_at: '2026-07-19T08:00:00Z',
                evidence_age_seconds: 20,
                runtime: {
                    state: 'available',
                    available: 4,
                    required: 4,
                    components: {
                        orchestration: 'available',
                        checks: 'available',
                        events: 'available',
                        topology: 'available',
                    },
                },
                topology: {
                    state: 'current',
                    source: 'native:snmp',
                    captured_at: '2026-07-19T07:59:00Z',
                    node_count: 3,
                    edge_count: 2,
                    change_count: 0,
                },
                discovery: {
                    state: 'current',
                    scopes: 1,
                    completed_at: '2026-07-19T07:58:00Z',
                },
            },
        ],
        remote_paths: [collectionPath],
    },
    runtime: {
        state: 'attention',
        workers: {
            state: 'not_observed',
            available: 0,
            total: 8,
            attention: 0,
            not_observed: 8,
            note: 'No heartbeat evidence.',
        },
        queues: {
            events: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            checks: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            discovery: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            provider: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            topology: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            maintenance: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            orchestration: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            commands: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
        },
        listeners: {},
        external_heartbeat: {
            state: 'disabled',
            reason_code: null,
            last_sent_age_seconds: null,
            last_evaluated_age_seconds: null,
            note: 'Configure an independently hosted dead-man monitor for total outage detection.',
        },
        storage: {
            time_series: { state: 'not_configured', series: 0 },
            snapshots: { state: 'not_observed', records: 0, available: 0 },
        },
        collectors: {
            total: 1,
            available: 0,
            degraded: 0,
            unavailable: 1,
            revoked: 0,
            backlog_items: 0,
            gaps: 0,
        },
        observed_at: '2026-07-19T08:00:00Z',
    },
    delivery: {
        contracts: {
            envelope_current: 2,
            envelope_accepted: [1, 2],
            payloads: {
                observation: { current: 2, accepted: [1, 2] },
                event: { current: 2, accepted: [1, 2] },
            },
            commands: {
                standard_current: 6,
                break_glass_current: 7,
                accepted: [2, 3, 4, 5, 6, 7],
                retry_policy: 'reconcile_before_retry',
            },
        },
        dead_letters: {
            visible: false,
            total: null,
            shown: 0,
            truncated: false,
            rows: [],
            note: 'Recovery restricted.',
        },
    },
    storage: {
        time_series: {
            state: 'not_configured',
            series: 0,
            series_shown: 0,
            series_truncated: false,
            available: 0,
            missing: 0,
            unavailable: 0,
            capacity_evidence: [],
        },
        retention: {
            policies: [],
            explanation: 'Most restrictive policy applies.',
        },
    },
    filters: {
        search: null,
        state: null,
        kind: null,
        site_id: null,
        device_id: null,
        collection_mode: null,
    },
    filter_options: { states: [], kinds: [], sites: [], devices: [] },
} satisfies MonitoringWorkspace;
export function monitoringFixture(): MonitoringWorkspace {
    const data: MonitoringWorkspace = structuredClone(monitoringWorkspace);
    data.actor_id = 7;
    data.active_tab = 'overview';
    data.tabs = [
        { key: 'overview', label: 'Overview' },
        { key: 'findings', label: 'Findings' },
        { key: 'coverage', label: 'Coverage' },
        { key: 'dependencies', label: 'Dependencies' },
        { key: 'trends', label: 'Trends' },
        { key: 'collection', label: 'Data collection' },
    ];
    data.summary.total_monitors = 101;
    data.summary.healthy = 81;
    data.summary.active_findings = 9;
    data.summary.paused = 3;
    data.inventory = { total: 12, shown: 2, truncated: true };
    const direct: MonitorRow = {
        ...structuredClone(remoteMonitor),
        id: 12,
        name: 'Remote SNMP',
        reported_state: 'healthy',
        effective_state: 'healthy',
        last_state_changed_at: '2026-10-12T05:00:00Z',
        finding_scope: 'monitor',
        freshness_state: 'fresh',
        profile: { id: 7, name: 'Standard policy' },
        device: {
            ...remoteMonitor.device,
            id: 10,
            name: 'Central switch',
            href: '/security-devices/devices/10',
        },
        site: {
            id: 2,
            name: 'Central Site',
            href: '/security-devices/sites/2',
        },
        collection: {
            mode: 'direct',
            label: 'Main application over site connectivity',
            state: 'available',
        },
        latest_observation: {
            state: 'healthy',
            value: '0',
            unit: 'ms',
            latency_ms: 0,
            observed_at: '2026-10-12T06:00:00Z',
        },
        actions: {
            actor_id: 7,
            review_token: 'a'.repeat(64),
            can_manage: true,
            update_url: '/security-devices/monitoring/native-monitors/12',
            deactivate_url:
                '/security-devices/monitoring/native-monitors/12/deactivate',
        },
    };
    data.monitors = [structuredClone(remoteMonitor), direct];
    data.filter_options = {
        states: ['healthy', 'collection_unavailable', 'paused'],
        kinds: ['icmp', 'snmp'],
        sites: [
            { value: 2, label: 'Central Site' },
            { value: 4, label: 'Remote Site' },
        ],
        devices: [
            { value: 9, label: 'Remote switch' },
            { value: 10, label: 'Central switch' },
        ],
    };
    data.monitor_management = {
        actor_id: 7,
        can_manage: true,
        create_url: '/security-devices/monitoring/native-monitors',
        kinds: [{ value: 'icmp', label: 'ICMP availability' }],
        profiles: [{ id: 7, name: 'Standard policy' }],
        devices: [
            {
                id: 10,
                name: 'Central switch',
                site: { id: 2, name: 'Central Site' },
            },
        ],
    };
    return data;
}
afterEach(cleanup);
beforeEach(() => {
    mocks.get.mockReset();
    mocks.visit.mockReset();
    mocks.reload.mockReset();
    mocks.actor = 7;
    mocks.profiles = true;
    mocks.events = true;
    localStorage.clear();
});
describe('Monitoring shared records and scope', () => {
    it('does not rebind received private checks when actor metadata changes together', () => {
        const data = monitoringFixture();
        const ui = render(<Monitoring workspace={data} />);
        expect(screen.getAllByText('Remote SNMP').length).toBeGreaterThan(0);
        mocks.actor = 8;
        ui.rerender(<Monitoring workspace={{ ...data, actor_id: 8 }} />);
        expect(screen.queryByText('Remote SNMP')).not.toBeInTheDocument();
        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh monitoring' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/monitoring',
            expect.objectContaining({ preserveState: false }),
        );
    });
    it('withholds retained checks after current nested event-view authority is withdrawn', () => {
        const workspace = monitoringFixture();
        const ui = render(<Monitoring workspace={workspace} />);
        expect(screen.getAllByText('Remote SNMP').length).toBeGreaterThan(0);
        mocks.events = false;
        ui.rerender(<Monitoring workspace={workspace} />);
        expect(screen.queryByText('Remote SNMP')).not.toBeInTheDocument();
        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Monitoring is being refreshed for your current access.',
            ),
        ).toBeInTheDocument();
    });

    it('keeps full-scope meters separate from a bounded filtered inventory and clears unrelated meter filters', () => {
        const data = monitoringFixture();
        data.filters = { ...data.filters, search: 'private draft', site_id: 4 };
        render(<Monitoring workspace={data} />);
        expect(
            screen.getByRole('button', { name: 'View checks' }),
        ).toHaveTextContent('101');
        expect(screen.getByText('2 of 12 shown')).toBeInTheDocument();
        expect(
            screen.getByText(/Showing 2 of 12 matching checks/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'View healthy' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/monitoring',
            { tab: 'overview', state: 'healthy' },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'View findings' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/monitoring',
            { tab: 'findings' },
            { preserveState: true, preserveScroll: true },
        );
    });
    it('submits monitor search to Overview and retains server filters when changing ordinary evidence tabs', () => {
        const data = monitoringFixture();
        data.active_tab = 'coverage';
        data.filters.site_id = 4;
        render(<Monitoring workspace={data} />);
        const input = screen.getByRole('searchbox');
        fireEvent.change(input, { target: { value: 'switch' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/monitoring',
            { ...data.filters, tab: 'overview', search: 'switch' },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Trends' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/monitoring',
            { ...data.filters, tab: 'trends' },
            { preserveState: true, preserveScroll: true },
        );
        expect(
            screen.getByText(/Monitor search opens Overview/),
        ).toBeInTheDocument();
    });
    it('keeps zero values, operational state, both clocks and exact duplicate identity in full details with focus return', async () => {
        const data = monitoringFixture();
        render(<Monitoring workspace={data} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const row = screen.getByRole('row', { name: /Monitor #12/ });
        fireEvent.keyDown(row, { key: 'Enter' });
        const detail = screen.getByRole('dialog');
        expect(within(detail).getByText('#12')).toBeInTheDocument();
        expect(
            within(detail).getByText('Central switch (#10)'),
        ).toBeInTheDocument();
        expect(
            within(detail).getByText('Last state change'),
        ).toBeInTheDocument();
        expect(within(detail).getByText('Finding scope')).toBeInTheDocument();
        expect(within(detail).getByText('0ms')).toBeInTheDocument();
        expect(within(detail).getByText('0')).toBeInTheDocument();
        expect(
            within(detail).getByText('Standard policy (#7)'),
        ).toBeInTheDocument();
        expect(
            within(detail).getByRole('link', { name: 'Central switch (#10)' }),
        ).toHaveAttribute('href', '/security-devices/devices/10');
        fireEvent.keyDown(detail, { key: 'Escape' });
        await waitFor(() =>
            expect(
                within(row).getByRole('button', {
                    name: 'View evidence for Remote SNMP',
                }),
            ).toHaveFocus(),
        );
    });
    it('opens exact monitor actions from the shared menu and hides profile destinations without their permission', async () => {
        mocks.profiles = false;
        const data = monitoringFixture();
        render(<Monitoring workspace={data} />);
        expect(
            screen.queryByRole('link', { name: 'Remote switch' }),
        ).not.toBeInTheDocument();
        fireEvent.keyDown(
            screen.getAllByRole('button', {
                name: 'Actions for Remote SNMP',
            })[1],
            { key: 'ArrowDown' },
        );
        expect(
            await screen.findByRole('menuitem', {
                name: 'Open device profile',
            }),
        ).toHaveAttribute('aria-disabled', 'true');
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Update monitor' }),
        );
        fireEvent.click(screen.getByText('Device and policy', { exact: true }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText('Monitor name')).toHaveValue(
            'Remote SNMP',
        );
        expect(within(dialog).getByText('Central switch')).toBeInTheDocument();
    });
    it('clears private details, local search and management drafts on actor or response replacement', () => {
        const data = monitoringFixture();
        const { rerender, unmount } = render(<Monitoring workspace={data} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'private search' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Add check' }),
        );
        fireEvent.click(screen.getByText('Device and policy', { exact: true }));
        fireEvent.change(screen.getByLabelText('Monitor name'), {
            target: { value: 'PRIVATE-DRAFT' },
        });
        mocks.actor = 8;
        rerender(<Monitoring workspace={data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Monitoring is being refreshed for your current access.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Remote SNMP')).not.toBeInTheDocument();
        data.actor_id = 8;
        if (data.monitor_management) data.monitor_management.actor_id = 8;
        rerender(<Monitoring workspace={data} />);
        expect(screen.queryByText('Remote SNMP')).not.toBeInTheDocument();
        unmount();
        // A complete Inertia visit remounts the page with an attributed new read.
        const freshRead = render(<Monitoring workspace={data} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'View evidence for Remote SNMP',
            })[0],
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        freshRead.rerender(
            <Monitoring workspace={{ ...data, monitors: [] }} />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('keeps unknown evidence neutral and distinguishes absence of findings from proof of healthy collection', () => {
        const data = monitoringFixture();
        data.active_tab = 'findings';
        data.findings = {
            monitors: [],
            collection_paths: [],
            note: 'No findings in current scope.',
        };
        render(<MonitoringContent workspace={data} />);
        expect(
            screen.getByText('No collection paths in this view'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('All collection paths are reporting'),
        ).not.toBeInTheDocument();
    });
    it('does not turn unknown queue counts into zero in table or detail views', () => {
        const data = monitoringFixture();
        render(<MonitoringContent workspace={data} view="table" />);
        const queue = screen.getByRole('row', { name: /Commands/ });
        expect(
            within(queue).getByText('Counts restricted'),
        ).toBeInTheDocument();
        expect(
            within(queue).getByText('Count unavailable'),
        ).toBeInTheDocument();
        fireEvent.keyDown(queue, { key: 'Enter' });
        const detail = screen.getByRole('dialog');
        expect(
            within(detail).getByText('Counts restricted'),
        ).toBeInTheDocument();
        expect(
            within(detail).getByText('Count unavailable'),
        ).toBeInTheDocument();
    });
    it('reports an unavailable dependency model honestly and retains readiness detail beyond its scan summary', () => {
        const data = monitoringFixture();
        data.active_tab = 'dependencies';
        data.dependencies.canonical_model_available = false;
        const { rerender } = render(<MonitoringContent workspace={data} />);
        expect(
            screen.getByText('Dependency model unavailable'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Dependency model active'),
        ).not.toBeInTheDocument();
        rerender(
            <MonitoringContent
                workspace={{ ...data, active_tab: 'collection' }}
                view="table"
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Central Site',
            }),
        );
        const detail = screen.getByRole('dialog');
        for (const label of [
            'Topology changes',
            'Topology source',
            'Discovery scopes',
            'Evidence age',
            'Durable direct observations',
            'Disabled checks',
        ])
            expect(within(detail).getByText(label)).toBeInTheDocument();
    });
    it('keeps the complete runtime snapshot behind its compact summary, including watchdog ages and every listener', () => {
        const data = monitoringFixture();
        render(<MonitoringContent workspace={data} view="table" />);
        const row = screen.getByRole('row', { name: /Runtime health/ });
        fireEvent.keyDown(row, { key: 'Enter' });
        const detail = screen.getByRole('dialog');
        for (const label of [
            'Last delivered age',
            'Last evaluated age',
            'Buffered items',
            'Collection gaps',
            'Snapshots available/retained',
            'Watchdog evidence',
            'Workers not observed',
        ])
            expect(within(detail).getByText(label)).toBeInTheDocument();
        expect(within(detail).queryAllByText(/^Listener: /)).toHaveLength(
            Object.keys(data.runtime.listeners).length,
        );
    });
    it('distinguishes an empty permitted monitor scope, empty search and absence of independent findings', () => {
        const data = monitoringFixture();
        data.monitors = [];
        data.inventory = { total: 0, shown: 0, truncated: false };
        const { rerender } = render(<MonitoringContent workspace={data} />);
        expect(
            screen.getByText('No monitoring checks in this permitted scope'),
        ).toBeInTheDocument();
        rerender(
            <MonitoringContent
                workspace={{
                    ...data,
                    filters: { ...data.filters, search: 'no result' },
                }}
            />,
        );
        expect(
            screen.getByText('No monitors match these filters'),
        ).toBeInTheDocument();
        rerender(
            <MonitoringContent
                workspace={{ ...data, active_tab: 'findings' }}
            />,
        );
        expect(
            screen.getByRole('heading', {
                name: 'No independent monitor findings',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('No monitors match these filters'),
        ).not.toBeInTheDocument();
    });
});
