import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DiscoveryCollectors, {
    type DiscoveryPageProps,
    type DiscoveryWorkspace,
} from './discovery';

const mocks = vi.hoisted(() => ({
    auth: {
        user: { id: 17 },
        can: {
            securityDevices: {
                viewAny: true,
                integrationsView: true,
                integrationsManage: true,
                devicesView: true,
            },
        },
    },
    get: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    invalid: undefined as undefined | ((event: any) => void),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: { title: string; href: string }[];
    }) => (
        <main>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((crumb) => (
                    <a key={crumb.href} href={crumb.href}>
                        {crumb.title}
                    </a>
                ))}
            </nav>
            {children}
        </main>
    ),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        preserveState: _state,
        preserveScroll: _scroll,
        ...props
    }: any) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { auth: mocks.auth } }),
    router: {
        get: mocks.get,
        reload: mocks.reload,
        on: (_name: string, callback: (event: any) => void) => {
            mocks.invalid = callback;
            return () => {
                mocks.invalid = undefined;
            };
        },
    },
}));
vi.mock('axios', () => ({
    default: {
        post: mocks.post,
        patch: mocks.patch,
        isAxiosError: (error: any) => error?.isAxiosError === true,
    },
}));

const workspace: DiscoveryWorkspace = {
    tabs: [
        { key: 'overview', label: 'Overview' },
        { key: 'scopes', label: 'Discovery scopes' },
        { key: 'runs', label: 'Runs' },
        { key: 'candidates', label: 'Candidates' },
        { key: 'collectors', label: 'Remote collectors' },
        { key: 'paths', label: 'Coverage & paths' },
        { key: 'limitations', label: 'Limitations' },
    ],
    active_tab: 'overview',
    boundary: {
        title: 'Direct first, collectors only where needed',
        description:
            'One organisation across approved Sites uses direct collection where connectivity permits it.',
        runtime_note:
            'Governed scopes and immutable run summaries from the native runtime.',
    },
    summary: {
        monitors: 7,
        direct_monitors: 3,
        remote_monitors: 4,
        collectors: 1,
        online_collectors: 0,
        collection_paths_unavailable: 1,
        affected_devices: 3,
        scopes: 1,
        runs: 30,
        runs_shown: 1,
        runs_truncated: true,
        candidates: 250,
        candidates_shown: 1,
        candidates_truncated: true,
        candidates_requiring_review: 125,
    },
    direct_coverage: {
        path_label: 'Collector-free monitor configuration',
        monitors: 3,
        devices: 2,
        description:
            'Configured without a Site collector. Live reachability remains in Monitoring.',
    },
    collectors: [
        {
            id: 3,
            name: 'Remote Harbour collector',
            site: {
                id: 1,
                name: 'Harbour House',
                href: '/security-devices/sites/1',
            },
            reported_status: 'offline',
            freshness_state: 'unavailable',
            last_seen_at: '2026-10-13T03:00:00Z',
            heartbeat_lag_seconds: 600,
            monitor_load: 4,
            device_load: 3,
            affected_monitors: 4,
            affected_devices: 3,
            impact_note:
                'Downstream monitor results are uncertain until this collection path reports again.',
            runtime_state: 'disconnected',
            backlog_items: 8,
            spool_bytes: 123,
            gap_count: 2,
            corrupted_frames: 1,
            clock_drift_seconds: 7,
            revoked_at: null,
            actions: {
                can_manage: true,
                revoke_url: '/security-devices/discovery/collectors/3/revoke',
                re_enrol_url: null,
            },
        },
    ],
    collector_management: {
        can_manage: true,
        issue_url: '/security-devices/discovery/collectors/enrolments',
        sites: [{ id: 1, name: 'Harbour House' }],
    },
    scope_management: {
        can_manage: true,
        create_url: '/security-devices/discovery/scopes',
        protocols: ['icmp', 'tcp', 'dns', 'http', 'tls', 'snmp'],
        sites: [{ id: 1, name: 'Harbour House' }],
    },
    scopes: [
        {
            id: 7,
            name: 'Approved Harbour network',
            status: 'active',
            site: {
                id: 1,
                name: 'Harbour House',
                href: '/security-devices/sites/1',
            },
            collection_mode: 'direct',
            collector: null,
            protocols: ['icmp', 'tcp'],
            network_ranges: 2,
            seed_hosts: 3,
            exclusions: 4,
            port_bounds: 1,
            max_targets_per_run: 512,
            packets_per_second: 10,
            schedule: '0 6 * * *',
            actions: {
                can_manage: true,
                update_url: '/security-devices/discovery/scopes/7',
                apply_url: '/security-devices/discovery/scopes/7/apply',
                deactivate_url:
                    '/security-devices/discovery/scopes/7/deactivate',
            },
        },
    ],
    runs: [
        {
            id: 51,
            run_uuid: '018f0000-0000-7000-8000-000000000951',
            scope_id: 7,
            scope_name: 'Approved Harbour network',
            status: 'running',
            collection_mode: 'remote_collector',
            collector: {
                id: 3,
                name: 'Remote Harbour collector',
                state: 'unavailable',
            },
            trigger: 'manual:user:17',
            planned: 20,
            returned: 12,
            pending: 8,
            found: 3,
            matched: 2,
            proposed: 1,
            changed: 2,
            excluded: 1,
            failed: 4,
            unresolved: 5,
            started_at: '2026-10-13T02:00:00Z',
            completed_at: null,
        },
    ],
    candidates: [
        {
            id: 72,
            candidate_uuid: '018f0000-0000-7000-8000-000000000972',
            run_id: 51,
            scope_id: 7,
            decision: 'review',
            confidence: 70,
            reasons: ['identity_changed', 'ambiguous_match'],
            canonical_device: {
                id: 9,
                name: 'Private Harbour switch',
                href: '/security-devices/devices/9',
            },
            review: { action: null, reviewed_at: null },
        },
    ],
    collection_paths: [
        {
            collector_id: 3,
            collector_name: 'Remote Harbour collector',
            site: {
                id: 1,
                name: 'Harbour House',
                href: '/security-devices/sites/1',
            },
            state: 'unavailable',
            monitor_load: 4,
            device_load: 3,
            affected_devices: 3,
        },
    ],
    limitations: {
        unsupported_state: 'not_assessed',
        unsupported_note:
            'No capability record exists; support is not guessed from model names.',
        not_configured_note:
            'Devices without checks remain in Monitoring coverage.',
        capacity_note:
            'Exact counts do not establish measured collector capacity.',
    },
};
function fixture(next: Partial<DiscoveryWorkspace> = {}): DiscoveryPageProps {
    return {
        actor_id: 17,
        as_of: '2026-10-13T03:10:00Z',
        workspace: { ...workspace, ...next },
    };
}
function view(name: string) {
    fireEvent.click(screen.getByRole('tab', { name }));
}
function openRow(name: string) {
    fireEvent.click(screen.getByText(name).closest('[role="row"]')!);
}
function rowMenu(name: string) {
    fireEvent.contextMenu(screen.getByText(name).closest('[role="row"]')!);
}
function reviewField(label: string) {
    return screen.getByText(label).closest('.wizard-review-row')!;
}
function beginCreate() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Create direct scope' }),
    );
}
function fillCreate() {
    fireEvent.change(screen.getByLabelText('Scope name'), {
        target: { value: 'Approved outreach network' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Approved networks'), {
        target: { value: '10.44.0.0/16' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
beforeEach(() => {
    vi.clearAllMocks();
    mocks.invalid = undefined;
    mocks.auth = {
        user: { id: 17 },
        can: {
            securityDevices: {
                viewAny: true,
                integrationsView: true,
                integrationsManage: true,
                devicesView: true,
            },
        },
    };
    mocks.post.mockResolvedValue({
        data: {
            scope: {
                id: 81,
                site_id: 1,
                name: 'Approved outreach network',
                status: 'active',
            },
        },
    });
    mocks.patch.mockResolvedValue({
        data: {
            scope: {
                id: 7,
                site_id: 1,
                name: 'Approved Harbour network',
                status: 'active',
            },
        },
    });
    vi.stubGlobal('fetch', vi.fn());
});

describe('Discovery shared views and evidence', () => {
    it('uses Home breadcrumbs and six useful meters with scoped source counts', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        expect(
            within(
                screen.getByRole('navigation', { name: 'Breadcrumbs' }),
            ).getByRole('link', { name: 'Home' }),
        ).toHaveAttribute('href', '/dashboard');
        expect(screen.getByText(/Read started/)).toHaveTextContent(
            '13 October 2026',
        );
        expect(screen.getByText('3 direct · 4 remote')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'View candidates for review' }),
        );
        expect(screen.getByRole('tab', { name: 'Candidates' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByText('1 of 1 loaded · 250 in scope')).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Candidates requiring review are prioritised',
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/discovery',
            { tab: 'candidates' },
            expect.objectContaining({
                only: ['workspace', 'actor_id', 'as_of', 'auth'],
            }),
        );
        expect(mocks.post).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });
    it('shows bounded run progress as returned evidence rather than completed support', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        view('Runs');
        expect(screen.getByRole('alert')).toHaveTextContent(
            'latest 1 of 30 runs',
        );
        expect(
            screen.getByText('12 of 20 results returned · 8 pending'),
        ).toBeVisible();
        openRow('Approved Harbour network');
        expect(reviewField('Run status')).toHaveTextContent('Running');
        expect(reviewField('Completed at')).toHaveTextContent('Not completed');
        expect(
            screen.getByText(/Pending targets await collector evidence/),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: /^Technical detail/ }),
        );
        expect(reviewField('Run reference')).toHaveTextContent(
            workspace.runs[0].run_uuid,
        );
        expect(reviewField('Changed')).toHaveTextContent('2');
        expect(reviewField('Failed')).toHaveTextContent('4');
        expect(reviewField('Unresolved')).toHaveTextContent('5');
    });
    it('retains all scope bounds, schedule and source reference facts in the shared reader', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        view('Discovery scopes');
        openRow('Approved Harbour network');
        expect(reviewField('Schedule')).toHaveTextContent('0 6 * * *');
        expect(reviewField('Protocols')).toHaveTextContent('icmp, tcp');
        expect(
            screen.getByRole('link', { name: 'Open Site technology' }),
        ).toHaveAttribute('href', '/security-devices/sites/1');
        fireEvent.click(
            screen.getByRole('button', { name: /^Technical detail/ }),
        );
        expect(reviewField('Network ranges')).toHaveTextContent('2');
        expect(reviewField('Seed hosts')).toHaveTextContent('3');
        expect(reviewField('Exclusions')).toHaveTextContent('4');
        expect(reviewField('Port bounds')).toHaveTextContent('1');
        expect(reviewField('Maximum targets per run')).toHaveTextContent('512');
        expect(reviewField('Packets per second')).toHaveTextContent('10');
    });
    it('retains collector exact load, lag, uncertainty, buffer and clock facts', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        view('Remote collectors');
        openRow('Remote Harbour collector');
        expect(reviewField('Heartbeat lag (seconds)')).toHaveTextContent('600');
        expect(reviewField('Affected monitors')).toHaveTextContent('4');
        expect(
            screen.getByText(/Downstream monitor results are uncertain/),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: /^Technical detail/ }),
        );
        expect(reviewField('Backlog items')).toHaveTextContent('8');
        expect(reviewField('Spool bytes')).toHaveTextContent('123');
        expect(reviewField('Sequence gaps')).toHaveTextContent('2');
        expect(reviewField('Corrupt frames')).toHaveTextContent('1');
        expect(reviewField('Clock drift (seconds)')).toHaveTextContent('7');
        expect(reviewField('Revoked at')).toHaveTextContent('Not revoked');
    });
    it('keeps unlinked proposed candidates inspectable without canonical navigation', () => {
        render(
            <DiscoveryCollectors
                {...fixture({
                    candidates: [
                        {
                            ...workspace.candidates[0],
                            canonical_device: null,
                            decision: 'proposed',
                        },
                    ],
                })}
            />,
        );
        view('Candidates');
        expect(screen.getByText('Proposed Device #72')).toBeVisible();
        openRow('Proposed Device #72');
        expect(
            screen.getByRole('dialog', {
                name: 'Discovery details · Proposed Device #72',
            }),
        ).toBeVisible();
        expect(reviewField('Canonical Device')).toHaveTextContent('—');
        expect(
            screen.queryByRole('link', { name: 'Open canonical Device' }),
        ).not.toBeInTheDocument();
    });
    it('keeps candidate reasons, review and canonical links separate from an approval', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        view('Candidates');
        openRow('Private Harbour switch');
        expect(reviewField('Decision confidence')).toHaveTextContent('70%');
        expect(reviewField('Reasons')).toHaveTextContent(
            'identity_changed, ambiguous_match',
        );
        expect(reviewField('Review action')).toHaveTextContent('Not reviewed');
        expect(
            screen.getByRole('link', { name: 'Open canonical Device' }),
        ).toHaveAttribute('href', '/security-devices/devices/9');
        expect(
            screen.getByText(/do not approve a canonical Device change/),
        ).toBeVisible();
    });
    it('shares operational actions between table and card menus without starting a run', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        view('Discovery scopes');
        rowMenu('Approved Harbour network');
        expect(screen.getByRole('menuitem', { name: 'Run now' })).toBeVisible();
        expect(screen.getByRole('menuitem', { name: 'Update' })).toBeVisible();
        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
        fireEvent.click(screen.getByRole('radio', { name: 'Cards' }));
        const card = screen
            .getByText('Approved Harbour network')
            .closest('[role="button"]')!;
        fireEvent.contextMenu(card);
        expect(screen.getByRole('menuitem', { name: 'Run now' })).toBeVisible();
        expect(
            screen.getByRole('menuitem', { name: 'Deactivate' }),
        ).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('searches loaded records in overview and keeps the server-bounded search scope clear', () => {
        render(<DiscoveryCollectors {...fixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'Search loaded discovery records',
            }),
            { target: { value: 'Approved Harbour network' } },
        );
        expect(
            screen.getByText('Matching loaded discovery records'),
        ).toBeVisible();
        expect(screen.getAllByText('Approved Harbour network')).toHaveLength(2);
        expect(screen.getByText(/search covers loaded records/)).toBeVisible();
        expect(mocks.reload).not.toHaveBeenCalled();
    });
    it('uses useful empty and unknown-capability states without inventing a live collector', () => {
        render(
            <DiscoveryCollectors
                {...fixture({
                    scopes: [],
                    runs: [],
                    candidates: [],
                    collectors: [],
                    collection_paths: [],
                    summary: {
                        ...workspace.summary,
                        scopes: 0,
                        runs: 0,
                        runs_shown: 0,
                        runs_truncated: false,
                        candidates: 0,
                        candidates_shown: 0,
                        candidates_truncated: false,
                        collectors: 0,
                        online_collectors: 0,
                        collection_paths_unavailable: 0,
                    },
                })}
            />,
        );
        view('Discovery scopes');
        expect(
            screen.getByText('No discovery scopes in this Site view'),
        ).toBeVisible();
        view('Runs');
        expect(screen.getByText('No runs in this Site view')).toBeVisible();
        view('Limitations');
        expect(screen.getByText('Not Assessed')).toBeVisible();
        expect(
            screen.getByText(workspace.limitations.unsupported_note),
        ).toBeVisible();
        expect(screen.queryByText(/^Live$/)).not.toBeInTheDocument();
    });
});

describe('Discovery current actor, permissions and Site scope', () => {
    it.each(['actor', 'viewAny', 'integrationsView'] as const)(
        'clears private records/readers on %s authority loss',
        (loss) => {
            const data = fixture();
            const result = render(<DiscoveryCollectors {...data} />);
            view('Candidates');
            openRow('Private Harbour switch');
            if (loss === 'actor') mocks.auth.user.id = 18;
            else mocks.auth.can.securityDevices[loss] = false;
            result.rerender(<DiscoveryCollectors {...data} />);
            expect(
                screen.getByText('Discovery records are unavailable'),
            ).toBeVisible();
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.queryByText('Private Harbour switch'),
            ).not.toBeInTheDocument();
        },
    );
    it('refuses to reauthorise retained workspace data when actor metadata also changes in a partial visit', () => {
        const data = fixture();
        const result = render(<DiscoveryCollectors {...data} />);
        mocks.auth.user.id = 18;
        result.rerender(<DiscoveryCollectors {...data} actor_id={18} />);
        expect(
            screen.getByText('Discovery records are unavailable'),
        ).toBeVisible();
        expect(
            screen.queryByText('Remote Harbour collector'),
        ).not.toBeInTheDocument();
    });
    it('states missing actor metadata honestly and performs no background work', () => {
        render(<DiscoveryCollectors {...fixture()} actor_id={0} />);
        expect(
            screen.getByText(/did not include actor metadata/),
        ).toBeVisible();
        expect(fetch).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('closes private management drafts when current management permission is withdrawn while keeping authorised reads', () => {
        const data = fixture();
        const result = render(<DiscoveryCollectors {...data} />);
        beginCreate();
        fireEvent.change(screen.getByLabelText('Scope name'), {
            target: { value: 'Private unsaved network draft' },
        });
        mocks.auth.can.securityDevices.integrationsManage = false;
        result.rerender(<DiscoveryCollectors {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('Private unsaved network draft'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Create direct scope' }),
        ).not.toBeInTheDocument();
        view('Discovery scopes');
        rowMenu('Approved Harbour network');
        expect(
            screen.queryByRole('menuitem', { name: 'Run now' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'View details' }),
        ).toBeVisible();
    });
    it('redacts canonical Device names and links when device viewing is withdrawn', () => {
        mocks.auth.can.securityDevices.devicesView = false;
        render(<DiscoveryCollectors {...fixture()} />);
        view('Candidates');
        expect(screen.getByText('Canonical Device unavailable')).toBeVisible();
        expect(
            screen.queryByText('Private Harbour switch'),
        ).not.toBeInTheDocument();
        openRow('Canonical Device unavailable');
        expect(
            screen.queryByRole('link', { name: 'Open canonical Device' }),
        ).not.toBeInTheDocument();
    });
    it('clears a search containing an old canonical Device name when device visibility changes', () => {
        const data = fixture();
        const result = render(<DiscoveryCollectors {...data} />);
        view('Candidates');
        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'Search loaded discovery records',
            }),
            { target: { value: 'Private Harbour switch' } },
        );
        mocks.auth.can.securityDevices.devicesView = false;
        result.rerender(<DiscoveryCollectors {...data} />);
        expect(
            screen.getByRole('searchbox', {
                name: 'Search loaded discovery records',
            }),
        ).toHaveValue('');
        expect(
            screen.queryByText('Private Harbour switch'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Canonical Device unavailable')).toBeVisible();
    });
    it('clears an open scope draft when the canonical returned Site/record scope changes', () => {
        const data = fixture();
        const result = render(<DiscoveryCollectors {...data} />);
        beginCreate();
        fireEvent.change(screen.getByLabelText('Scope name'), {
            target: { value: 'Unsent Harbour draft' },
        });
        result.rerender(
            <DiscoveryCollectors
                {...fixture({
                    scopes: [],
                    scope_management: {
                        ...workspace.scope_management!,
                        sites: [{ id: 2, name: 'Outreach Site' }],
                    },
                })}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('Unsent Harbour draft'),
        ).not.toBeInTheDocument();
    });
    it('quarantines an owned denied read and preserves dated reads after an ordinary refresh failure', () => {
        const result = render(<DiscoveryCollectors {...fixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['workspace', 'actor_id', 'as_of', 'auth'],
            }),
        );
        act(() =>
            mocks.invalid?.({
                preventDefault: vi.fn(),
                detail: {
                    response: {
                        status: 503,
                        config: {
                            method: 'get',
                            url: '/security-devices/discovery',
                        },
                    },
                },
            }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Previous observations retain their source times',
        );
        act(() =>
            mocks.invalid?.({
                preventDefault: vi.fn(),
                detail: {
                    response: {
                        status: 403,
                        config: {
                            method: 'get',
                            url: '/security-devices/discovery',
                        },
                    },
                },
            }),
        );
        expect(
            screen.getByText('Discovery records are unavailable'),
        ).toBeVisible();
        result.unmount();
    });
});

describe('Discovery scope wizard draft and canonical payload', () => {
    it('reviews a new direct configuration before sending its unchanged bounded payload', async () => {
        render(<DiscoveryCollectors {...fixture()} />);
        beginCreate();
        fillCreate();
        expect(
            screen.getByText(/Run now is a separate, authorised action/),
        ).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct scope' }),
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/discovery/scopes',
            {
                name: 'Approved outreach network',
                protocols: ['icmp', 'tcp', 'dns', 'tls'],
                max_targets_per_run: 1024,
                packets_per_second: 20,
                site_id: 1,
                cidrs: ['10.44.0.0/16'],
                port_bounds: {
                    tcp: [22, 80, 443, 161, 5985, 5986],
                    dns: [53],
                    tls: [443],
                },
            },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        await screen.findByRole('heading', { name: 'Discovery & collectors' });
        expect(mocks.reload).toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('keeps hidden existing network bounds when updating the visible scope configuration', async () => {
        render(<DiscoveryCollectors {...fixture()} />);
        view('Discovery scopes');
        rowMenu('Approved Harbour network');
        fireEvent.click(screen.getByRole('menuitem', { name: 'Update' }));
        fireEvent.click(screen.getByRole('button', { name: /^Review/ }));
        expect(
            screen.getByText('Keep protected existing ranges'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply scope update' }),
        );
        expect(mocks.patch).toHaveBeenCalledWith(
            '/security-devices/discovery/scopes/7',
            {
                name: 'Approved Harbour network',
                protocols: ['icmp', 'tcp'],
                max_targets_per_run: 512,
                packets_per_second: 10,
            },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        await screen.findByRole('heading', { name: 'Discovery & collectors' });
        expect(mocks.reload).toHaveBeenCalled();
    });
    it('preserves required entries through Back, validation failure and cancelled draft discard', async () => {
        mocks.post.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: { errors: { name: ['Choose a unique scope name.'] } },
            },
        });
        render(<DiscoveryCollectors {...fixture()} />);
        beginCreate();
        fillCreate();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText('Maximum targets per run')).toHaveValue(
            1024,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct scope' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Choose a unique scope name.',
        );
        expect(mocks.reload).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('heading', {
                name: 'Discard this discovery draft?',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        fireEvent.click(
            screen.getByRole('button', { name: /^Site and scope/ }),
        );
        expect(screen.getByLabelText('Scope name')).toHaveValue(
            'Approved outreach network',
        );
        fireEvent.click(screen.getByRole('button', { name: /^Networks/ }));
        expect(screen.getByLabelText('Approved networks')).toHaveValue(
            '10.44.0.0/16',
        );
    });
    it('withholds success and repeated writes after an unknown save outcome', async () => {
        mocks.post.mockRejectedValueOnce(new Error('Lost response'));
        render(<DiscoveryCollectors {...fixture()} />);
        beginCreate();
        fillCreate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct scope' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Save status is unknown',
        );
        expect(
            screen.getByRole('button', { name: 'Create direct scope' }),
        ).toBeDisabled();
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(screen.getByText('10.44.0.0/16')).toBeVisible();
    });
    it('closes the draft and private view when a scope save denies current access', async () => {
        mocks.post.mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403, data: { message: 'PRIVATE server data' } },
        });
        render(<DiscoveryCollectors {...fixture()} />);
        beginCreate();
        fillCreate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct scope' }),
        );
        expect(
            await screen.findByText('Discovery records are unavailable'),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('PRIVATE server data'),
        ).not.toBeInTheDocument();
    });
});
