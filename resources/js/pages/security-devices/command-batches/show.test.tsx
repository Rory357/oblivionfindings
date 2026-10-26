import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CommandBatch, { type BatchPayload } from './show';

const mocks = vi.hoisted(() => ({
    actor: 7,
    permissions: {} as Record<string, boolean>,
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
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
                    can: { securityDevices: mocks.permissions },
                },
            },
        }),
        router: {
            ...actual.router,
            on: actual.router.on.bind(actual.router),
            post: mocks.post,
            reload: mocks.reload,
            visit: mocks.visit,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: React.ReactNode;
        breadcrumbs: { title: string; href: string }[];
    }) => (
        <>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((crumb) => (
                    <a key={crumb.href} href={crumb.href}>
                        {crumb.title}
                    </a>
                ))}
            </nav>
            {children}
        </>
    ),
}));
function fixture(): BatchPayload {
    const statuses = [
        'awaiting_approval',
        'ready',
        'reconciled',
        'failed',
        null,
        'running',
    ];
    const names = [
        'Awaiting Device',
        'Ready Device',
        'Verified gateway',
        'Failed Device',
        'Excluded Device',
        'Running Device',
    ];
    return {
        actor_id: 7,
        as_of: '2026-10-13T08:00:00Z',
        requestedById: 9,
        id: 42,
        uuid: 'batch-retained-42',
        workspace: 'network-it',
        workspaceHref: '/security-devices/network-it?tab=management',
        capability: 'network.restart',
        label: 'Restart network Devices',
        risk: 'high',
        confirmationMode: 'typed_acknowledgement',
        impact: 'Network connectivity can briefly stop.',
        expectedResult: 'Each Device returns to its recorded expected state.',
        reason: 'Approved maintenance window',
        safeParameters: { delay_seconds: 0, force: false },
        requestedBy: 'Requester A',
        requestedAt: '2026-10-13T07:00:00Z',
        impactAcknowledgedAt: '2026-10-13T06:59:00Z',
        status: 'executing',
        canApprove: true,
        canDispatch: true,
        summary: {
            selected: 6,
            included: 5,
            excluded: 1,
            sites: 2,
            awaitingApproval: 1,
            ready: 1,
            queuedOrRunning: 1,
            terminal: 2,
            reconciled: 1,
            failedOrBlocked: 1,
        },
        targets: statuses.map((status, index) => ({
            id: index + 11,
            position: index + 1,
            inclusionStatus: status === null ? 'excluded' : 'included',
            safeExclusionCode:
                status === null ? 'capability_unavailable' : null,
            safeExclusionReason:
                status === null
                    ? 'The Device does not support this command.'
                    : null,
            device: {
                id: index + 101,
                uid: `DEVICE-UID-${index + 101}`,
                name: names[index],
                category: 'network',
                subcategory: 'gateway',
                provider: 'unifi',
                status: 'active',
                health: 'unknown',
                href: `/security-devices/devices/${index + 101}?section=management`,
            },
            site: {
                id: index < 3 ? 4 : 5,
                name: index < 3 ? 'North House' : 'South House',
                href: `/security-devices/sites/${index < 3 ? 4 : 5}`,
            },
            command:
                status === null
                    ? null
                    : {
                          id: index + 201,
                          uuid: `child-retained-${index + 201}`,
                          status,
                          requestedBy: 'Requester A',
                          approvedBy: index > 0 ? 'Reviewer A' : null,
                          expiresAt: '2026-10-14T07:00:00Z',
                          safeFailureReason:
                              status === 'failed'
                                  ? 'The provider did not confirm execution.'
                                  : null,
                          expectedState: { reachable: true, retry_count: 0 },
                          nextAction:
                              status === 'reconciled'
                                  ? 'The expected Device state was freshly verified.'
                                  : status === 'running'
                                    ? 'Execution is in progress. Do not repeat the action.'
                                    : 'Review this child independently.',
                          latestAttempt: [
                              'reconciled',
                              'failed',
                              'running',
                          ].includes(status)
                              ? {
                                    number: 2,
                                    status:
                                        status === 'failed'
                                            ? 'failed'
                                            : 'succeeded',
                                    runtime: 'native-runtime',
                                    safeResult: {
                                        packet_loss_percent: 0,
                                        restarted: false,
                                    },
                                    safeFailureReason:
                                        status === 'failed'
                                            ? 'Attempt evidence is inconclusive.'
                                            : null,
                                    completedAt: '2026-10-13T07:30:00Z',
                                }
                              : null,
                          latestReconciliation:
                              status === 'reconciled'
                                  ? {
                                        outcome: 'matched',
                                        safeEvidenceSummary: {
                                            reachable: true,
                                            delay_ms: 0,
                                        },
                                        observedAt: '2026-10-13T07:31:00Z',
                                    }
                                  : null,
                      },
        })),
        exportHref: '/security-devices/command-batches/42/export',
        partialSemantics:
            'Each included Device has an independent signed request, approval, execution attempt, and reconciliation result. A failure or exclusion never converts another target into success.',
    };
}
function auth() {
    return {
        user: { id: mocks.actor },
        can: { securityDevices: mocks.permissions },
    };
}
function invalid(
    status: number,
    options: { method?: string; marker?: string; url?: string } = {},
) {
    const request =
        (mocks.reload.mock.invocationCallOrder.at(-1) ?? 0) >
        (mocks.visit.mock.invocationCallOrder.at(-1) ?? 0)
            ? mocks.reload.mock.calls.at(-1)?.[0]
            : mocks.visit.mock.calls.at(-1)?.[1];
    const event = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: {
            response: {
                status,
                config: {
                    method: options.method ?? 'get',
                    url: options.url ?? window.location.href,
                    headers: {
                        ...request?.headers,
                        ...(options.marker
                            ? { 'X-Command-Batch-Read': options.marker }
                            : {}),
                    },
                },
                data: 'PRIVATE raw backend error',
            },
        },
    });
    act(() => document.dispatchEvent(event));
    return event;
}
const target = (id: number) =>
    screen.getByRole('article', { name: new RegExp(`Batch target ${id}:`) });
async function evidence(id: number) {
    const row = target(id);
    fireEvent.keyDown(
        within(row).getByRole('button', { name: /Actions for/ }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'View evidence' }),
    );
    return screen.findByRole('dialog');
}
beforeEach(() => {
    mocks.actor = 7;
    mocks.permissions = {
        devicesView: true,
        commandsApprove: true,
        commandsAdmin: true,
    };
    mocks.post.mockReset();
    mocks.reload.mockReset();
    mocks.visit.mockReset();
    window.history.replaceState({}, '', '/security-devices/command-batches/42');
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('Command batch profile and retained evidence', () => {
    it('connects every truthful meter, keeps the Home trail and preserves the exact ledger destination', () => {
        render(<CommandBatch batch={fixture()} />);
        expect(
            screen.getByRole('heading', { name: 'Restart network Devices' }),
        ).toBeVisible();
        expect(
            within(
                screen.getByRole('navigation', { name: 'Breadcrumbs' }),
            ).getByRole('link', { name: 'Home' }),
        ).toHaveAttribute('href', '/dashboard');
        expect(
            screen.getByRole('link', { name: 'Download result ledger' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/command-batches/42/export',
        );
        expect(screen.getByText(/Read started.*2026/)).toBeVisible();
        for (const [label, id] of [
            ['Selected', 11],
            ['Awaiting approval', 11],
            ['Ready', 12],
            ['In progress', 16],
            ['Reconciled', 13],
            ['Failed or blocked', 14],
        ] as const) {
            fireEvent.click(
                screen.getByRole('button', {
                    name: `View ${label.toLowerCase()} targets`,
                }),
            );
            expect(target(id)).toBeVisible();
        }
        fireEvent.click(screen.getByRole('tab', { name: 'Excluded targets' }));
        expect(target(15)).toBeVisible();
        expect(
            screen.getByText('The Device does not support this command.'),
        ).toBeVisible();
    });
    it('retains every command, attempt and reconciliation field independently, including zero and false evidence', async () => {
        render(<CommandBatch batch={fixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'All targets' }));
        const dialog = await evidence(13);
        expect(within(dialog).getByText('DEVICE-UID-103')).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: 'Verified gateway' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/103?section=management',
        );
        expect(
            within(dialog).getByRole('link', { name: 'North House' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
        fireEvent.click(
            within(dialog).getByRole('button', { name: /Target selection/ }),
        );
        expect(
            within(dialog).getByText('Selection position'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: /Command request/ }),
        );
        expect(
            within(dialog).getByText('child-retained-203'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('Reviewer A')).toBeInTheDocument();
        expect(within(dialog).getByText('Retry count')).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: /Execution evidence/ }),
        );
        expect(within(dialog).getByText('native-runtime')).toBeInTheDocument();
        expect(within(dialog).getByText('0')).toBeInTheDocument();
        expect(within(dialog).getByText('No')).toBeInTheDocument();
        expect(within(dialog).getByText(/13 October 2026/)).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: /Reconciliation & next action/,
            }),
        );
        expect(within(dialog).getByText('Matched')).toBeInTheDocument();
        expect(within(dialog).getByText('Delay ms')).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'The expected Device state was freshly verified.',
            ),
        ).toBeInTheDocument();
    });
    it('keeps full request/impact fields and zero/false parameters in the overview', () => {
        render(<CommandBatch batch={fixture()} />);
        for (const text of [
            'batch-retained-42',
            'Network & IT',
            'Requester A',
            'Typed acknowledgement',
            'Approved maintenance window',
            'Network connectivity can briefly stop.',
            'Each Device returns to its recorded expected state.',
            'Delay seconds',
            'Force',
            '0',
            'No',
        ]) {
            expect(screen.getByText(text, { exact: true })).toBeInTheDocument();
        }
        expect(
            screen.getByText(/failure or exclusion never converts/),
        ).toBeInTheDocument();
        // Capability uses the server's label, workspace its real name and the
        // status chip sentence case — never the raw keys.
        expect(
            screen.getAllByText('Restart network Devices').length,
        ).toBeGreaterThan(1);
        expect(screen.getByText('Executing')).toBeInTheDocument();
        for (const raw of ['network-it', 'network restart', 'executing']) {
            expect(
                screen.queryByText(raw, { exact: true }),
            ).not.toBeInTheDocument();
        }
    });
    it('uses shared table, search and both menus while retaining exact Device and Site destinations', async () => {
        render(<CommandBatch batch={fixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'All targets' }));
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search batch targets' }),
            { target: { value: 'Verified gateway' } },
        );
        expect(screen.getAllByRole('article')).toHaveLength(1);
        fireEvent.contextMenu(
            within(target(13)).getByText('Verified gateway'),
            { clientX: 10, clientY: 10 },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Open Site' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith('/security-devices/sites/4');
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const row = screen.getByRole('row', { name: /Verified gateway/ });
        fireEvent.keyDown(
            within(row).getByRole('button', { name: /Actions for/ }),
            { key: 'Enter', code: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Open Device management',
            }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/devices/103?section=management',
        );
    });
    it('searches only the words shown for each target, never links or hidden fields', () => {
        render(<CommandBatch batch={fixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'All targets' }));
        const search = screen.getByRole('searchbox', {
            name: 'Search batch targets',
        });
        for (const hidden of [
            'section=management',
            '/security-devices/sites/4',
            'retry_count',
            'capability_unavailable',
        ]) {
            fireEvent.change(search, { target: { value: hidden } });
            expect(
                screen.getByText('No targets match this search'),
            ).toBeInTheDocument();
        }
        for (const [shown, count] of [
            ['DEVICE-UID-103', 1],
            ['South House', 3],
            ['does not support this command', 1],
            ['child-retained-204', 1],
            ['failed', 1],
        ] as const) {
            fireEvent.change(search, { target: { value: shown } });
            expect(screen.getAllByRole('article')).toHaveLength(count);
        }
    });
    it('limits the decision comment to the server maximum with a visible count', () => {
        render(<CommandBatch batch={fixture()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Review 1 requests' })[0],
        );
        const comment = screen.getByLabelText('Decision comment');
        expect(comment).toHaveAttribute('maxLength', '1000');
        fireEvent.change(comment, {
            target: { value: 'Reviewed Site impact' },
        });
        expect(comment).toHaveAccessibleDescription(
            'Minimum 10 characters. Maximum 1,000. 20 of 1,000 entered.',
        );
    });
    it('preserves the approval/rejection route and comment minimum, retains failed drafts and prevents duplicate decisions', () => {
        render(<CommandBatch batch={fixture()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Review 1 requests' })[0],
        );
        expect(
            screen.getByRole('button', { name: 'Record decision' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Decision comment'), {
            target: { value: 'Reviewed Site impact' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^Reject$/ }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Record decision' }),
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/command-batches/42/decision',
            { decision: 'rejected', comment: 'Reviewed Site impact' },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(
            screen.getByRole('button', { name: 'Recording…' }),
        ).toBeDisabled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
        act(() => mocks.post.mock.calls[0][2].onFinish());
        expect(screen.getByLabelText('Decision comment')).toHaveValue(
            'Reviewed Site impact',
        );
    });
    it('permits requester dispatch without admin authority and blocks self approval even with an approval capability', () => {
        mocks.permissions.commandsAdmin = false;
        render(<CommandBatch batch={{ ...fixture(), requestedById: 7 }} />);
        expect(
            screen.queryByRole('button', { name: 'Review 1 requests' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'Queue 1 ready requests',
            })[0],
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Queue ready requests' }),
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/command-batches/42/dispatch',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(
            screen.getByRole('button', { name: 'Queueing…' }),
        ).toBeDisabled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('requires canonical action flags and the exact current action permissions without an admin-to-approval fallback', () => {
        const data = fixture();
        const ui = render(<CommandBatch batch={data} />);
        delete mocks.permissions.commandsApprove;
        ui.rerender(<CommandBatch batch={data} />);
        expect(
            screen.queryByRole('button', { name: 'Review 1 requests' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: 'Queue 1 ready requests' })
                .length,
        ).toBeGreaterThan(0);
        mocks.permissions.commandsApprove = true;
        ui.rerender(
            <CommandBatch
                batch={{ ...data, canApprove: false, canDispatch: false }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Review 1 requests' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Queue 1 ready requests' }),
        ).not.toBeInTheDocument();
        mocks.permissions.commandsAdmin = false;
        ui.rerender(<CommandBatch batch={data} />);
        expect(
            screen.queryByRole('button', { name: 'Queue 1 ready requests' }),
        ).not.toBeInTheDocument();
    });
    it('withholds private records and clears open decision drafts when the current actor or read authority changes', () => {
        const data = fixture();
        const ui = render(<CommandBatch batch={data} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Review 1 requests' })[0],
        );
        fireEvent.change(screen.getByLabelText('Decision comment'), {
            target: { value: 'PRIVATE-DRAFT' },
        });
        mocks.actor = 8;
        ui.rerender(<CommandBatch batch={data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('batch-retained-42')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Download result ledger' }),
        ).not.toBeInTheDocument();
        mocks.actor = 7;
        ui.rerender(<CommandBatch batch={data} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Review 1 requests' })[0],
        );
        expect(screen.getByLabelText('Decision comment')).toHaveValue('');
        mocks.permissions.devicesView = false;
        ui.rerender(<CommandBatch batch={data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Command batch records are restricted',
        );
    });
    it.each([401, 403, 404])(
        'quarantines an exactly owned denied read %s and ignores its late success',
        (status) => {
            const data = fixture();
            render(<CommandBatch batch={data} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh results' }),
            );
            const options = mocks.reload.mock.calls[0][0];
            expect(options.only).toEqual(['batch', 'auth']);
            expect(invalid(status).defaultPrevented).toBe(true);
            expect(
                screen.queryByText('batch-retained-42'),
            ).not.toBeInTheDocument();
            act(() =>
                options.onSuccess({
                    component: 'security-devices/command-batches/show',
                    props: { batch: data, auth: auth() },
                }),
            );
            expect(screen.getByRole('status')).toHaveTextContent(
                'Command batch records are restricted',
            );
            expect(
                screen.queryByText('PRIVATE raw backend error'),
            ).not.toBeInTheDocument();
        },
    );
    it('retains dated reads after owned failure and leaves unrelated methods, URLs and request tokens to their owners', () => {
        render(<CommandBatch batch={fixture()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(invalid(403, { method: 'post' }).defaultPrevented).toBe(false);
        expect(
            invalid(403, {
                url: 'http://localhost/security-devices/command-batches/99',
            }).defaultPrevented,
        ).toBe(false);
        expect(invalid(403, { marker: 'stale-read' }).defaultPrevented).toBe(
            false,
        );
        expect(invalid(503).defaultPrevented).toBe(true);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Previous records remain dated',
        );
        expect(screen.getByText('batch-retained-42')).toBeInTheDocument();
        expect(
            screen.queryByText('PRIVATE raw backend error'),
        ).not.toBeInTheDocument();
    });
    it('refreshes executing records with current auth and preserves truthful empty, no-match and timezone states', () => {
        vi.useFakeTimers();
        const data = fixture();
        const ui = render(<CommandBatch batch={data} />);
        act(() => vi.advanceTimersByTime(10_000));
        expect(mocks.reload.mock.calls[0][0].only).toEqual(['batch', 'auth']);
        ui.rerender(
            <CommandBatch
                batch={{
                    ...data,
                    status: 'review_required',
                    as_of: '2026-10-13 08:00:00',
                    targets: [],
                    canApprove: false,
                    canDispatch: false,
                }}
            />,
        );
        expect(screen.getByText(/Time zone not recorded/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'All targets' }));
        expect(
            screen.getByText('No selected targets were retained'),
        ).toBeInTheDocument();
        ui.rerender(
            <CommandBatch batch={{ ...data, status: 'review_required' }} />,
        );
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search batch targets' }),
            { target: { value: 'No such target' } },
        );
        expect(
            screen.getByText('No targets match this search'),
        ).toBeInTheDocument();
    });
    it('does not confuse a denied read from an earlier mount with the current batch read', () => {
        const data = fixture();
        const old = render(<CommandBatch batch={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        const oldMarker =
            mocks.reload.mock.calls[0][0].headers['X-Command-Batch-Read'];
        old.unmount();
        render(<CommandBatch batch={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        const currentMarker =
            mocks.reload.mock.calls[1][0].headers['X-Command-Batch-Read'];
        expect(currentMarker).not.toBe(oldMarker);
        expect(invalid(403, { marker: oldMarker }).defaultPrevented).toBe(
            false,
        );
        expect(screen.getByText('batch-retained-42')).toBeInTheDocument();
    });
    it('withholds a changed batch until a complete read and ignores older callbacks', () => {
        const first = fixture();
        const ui = render(<CommandBatch batch={first} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        const older = mocks.reload.mock.calls[0][0];
        const next = { ...first, id: 99, uuid: 'newer-batch-99' };
        window.history.replaceState(
            {},
            '',
            '/security-devices/command-batches/99',
        );
        ui.rerender(<CommandBatch batch={next} />);
        expect(
            invalid(403, {
                url: 'https://unapproved.example/security-devices/command-batches/42',
            }).defaultPrevented,
        ).toBe(false);
        const ownOldUrl = new URL(
            '/security-devices/command-batches/42',
            window.location.origin,
        ).href;
        expect(invalid(403, { url: ownOldUrl }).defaultPrevented).toBe(true);
        act(() =>
            older.onSuccess({
                component: 'security-devices/command-batches/show',
                props: { batch: first, auth: auth() },
            }),
        );
        expect(screen.queryByText('newer-batch-99')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Approved maintenance window'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Command batch records are restricted',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            window.location.href,
            expect.objectContaining({ method: 'get', preserveState: false }),
        );
    });
    it('does not rebind retained private records when auth and batch actor metadata are replaced together', () => {
        const old = fixture();
        const ui = render(<CommandBatch batch={old} />);
        mocks.actor = 8;
        ui.rerender(<CommandBatch batch={{ ...old, actor_id: 8 }} />);
        expect(
            screen.queryByText('Approved maintenance window'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Download result ledger' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Review 1 requests' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Command batch records are restricted',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            window.location.href,
            expect.objectContaining({ method: 'get', preserveState: false }),
        );
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(mocks.visit.mock.calls[0][1]).not.toHaveProperty('only');
    });
    it.each([
        { id: 99 },
        { uuid: 'replacement-uuid' },
        { id: 99, uuid: 'replacement-uuid' },
    ])(
        'does not rebind retained private records when canonical batch metadata changes %j',
        (changes) => {
            const old = fixture();
            const ui = render(<CommandBatch batch={old} />);
            ui.rerender(<CommandBatch batch={{ ...old, ...changes }} />);
            expect(
                screen.queryByText('Approved maintenance window'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('link', { name: 'Download result ledger' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'Queue 1 ready requests',
                }),
            ).not.toBeInTheDocument();
            expect(screen.getByRole('status')).toHaveTextContent(
                'Command batch records are restricted',
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh results' }),
            );
            expect(mocks.visit).toHaveBeenCalledWith(
                window.location.href,
                expect.objectContaining({
                    method: 'get',
                    preserveState: false,
                }),
            );
            expect(mocks.reload).not.toHaveBeenCalled();
            expect(mocks.visit.mock.calls[0][1]).not.toHaveProperty('only');
        },
    );
    it('recovers a denied read through a complete non-preserving GET and cannot unlock the retained mount through its callback', () => {
        const old = fixture();
        const ui = render(<CommandBatch batch={old} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(invalid(403).defaultPrevented).toBe(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            window.location.href,
            expect.objectContaining({ method: 'get', preserveState: false }),
        );
        const options = mocks.visit.mock.calls[0][1];
        expect(options).not.toHaveProperty('only');
        act(() =>
            options.onSuccess({
                component: 'security-devices/command-batches/show',
                props: { batch: old, auth: auth() },
            }),
        );
        expect(
            screen.queryByText('Approved maintenance window'),
        ).not.toBeInTheDocument();
        ui.unmount();
        render(
            <CommandBatch batch={{ ...old, reason: 'Fresh complete read' }} />,
        );
        expect(screen.getByText('Fresh complete read')).toBeInTheDocument();
    });
    it('keeps same-actor view and decision drafts through a failed read and retry', () => {
        const data = fixture();
        render(<CommandBatch batch={data} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Ready to queue' }));
        const refresh = screen.getByRole('button', { name: 'Refresh results' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review 1 requests' }),
        );
        fireEvent.change(screen.getByLabelText('Decision comment'), {
            target: { value: 'Keep this valid decision draft' },
        });
        fireEvent.click(refresh);
        expect(invalid(503).defaultPrevented).toBe(true);
        expect(screen.getByLabelText('Decision comment')).toHaveValue(
            'Keep this valid decision draft',
        );
        fireEvent.click(refresh);
        expect(mocks.reload).toHaveBeenCalledTimes(2);
        expect(mocks.visit).not.toHaveBeenCalled();
        const retry = mocks.reload.mock.calls[1][0];
        act(() => {
            retry.onSuccess({
                component: 'security-devices/command-batches/show',
                props: { batch: data, auth: auth() },
            });
            retry.onFinish();
        });
        expect(screen.getByLabelText('Decision comment')).toHaveValue(
            'Keep this valid decision draft',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('tab', { name: 'Ready to queue' }),
        ).toHaveAttribute('aria-selected', 'true');
    });
    it('cancels a pending partial read before complete changed-actor recovery and correlates its retry exactly', () => {
        const data = fixture();
        const ui = render(<CommandBatch batch={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        const older = mocks.reload.mock.calls[0][0];
        const cancel = vi.fn(() => older.onCancel());
        act(() => older.onCancelToken({ cancel }));
        mocks.actor = 8;
        const replacement = { ...data, actor_id: 8 };
        ui.rerender(<CommandBatch batch={replacement} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(cancel).toHaveBeenCalledTimes(1);
        expect(mocks.visit).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(mocks.visit).toHaveBeenCalledTimes(1);
        act(() => {
            older.onSuccess({
                component: 'security-devices/command-batches/show',
                props: { batch: data, auth: auth() },
            });
            older.onFinish();
        });
        expect(
            invalid(403, { marker: older.headers['X-Command-Batch-Read'] })
                .defaultPrevented,
        ).toBe(false);
        expect(invalid(503).defaultPrevented).toBe(true);
        expect(
            screen.queryByText('Approved maintenance window'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        expect(mocks.visit).toHaveBeenCalledTimes(2);
        const retry = mocks.visit.mock.calls[1][1];
        expect(retry).toMatchObject({ method: 'get', preserveState: false });
        expect(retry).not.toHaveProperty('only');
        expect(retry).not.toHaveProperty('except');
        act(() => {
            retry.onSuccess({
                component: 'security-devices/command-batches/show',
                props: { batch: replacement, auth: auth() },
            });
            retry.onFinish();
        });
        expect(
            screen.queryByText('Approved maintenance window'),
        ).not.toBeInTheDocument();
        ui.unmount();
        render(
            <CommandBatch
                batch={{ ...replacement, reason: 'Fresh actor complete read' }}
            />,
        );
        expect(
            screen.getByText('Fresh actor complete read'),
        ).toBeInTheDocument();
    });
    it('matches the real backend binding fields and nested permission producer', () => {
        const controller = readFileSync(
            resolve(
                process.cwd(),
                'app/Domain/SecurityDevices/Management/Http/Controllers/DeviceCommandBatchController.php',
            ),
            'utf8',
        );
        const shared = readFileSync(
            resolve(
                process.cwd(),
                'app/Http/Middleware/HandleInertiaRequests.php',
            ),
            'utf8',
        );
        expect(controller).toContain("'actor_id' => (int) $user->id");
        expect(controller).toContain("'as_of' => $asOf->toISOString()");
        expect(controller).toContain(
            "'requestedById' => (int) $batch->requested_by_user_id",
        );
        const production =
            shared.match(
                /'securityDevices'\s*=>\s*\[([\s\S]*?)\n\s*\],/,
            )?.[1] ?? '';
        expect(production).toContain(
            "'devicesView' => $user->canDo('securityDevices.devices.view')",
        );
        expect(production).toContain(
            "'commandsApprove' => $user->canDo('securityDevices.commands.approve')",
        );
        expect(production).toContain(
            "'commandsAdmin' => $user->canDo('securityDevices.commands.admin')",
        );
    });
});
