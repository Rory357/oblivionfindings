import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MaintenancePolicyPreview, {
    maintenancePreviewInstant,
    strictPreviewUtc,
    type MaintenanceChoices,
    type MaintenancePreview,
} from './maintenance-policy-preview';

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    refresh: vi.fn(),
    actor: 7,
    groups: true,
    monitoring: true,
}));
vi.mock('axios', () => ({ default: { get: mocks.get } }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: { id: mocks.actor },
                can: {
                    securityDevices: {
                        groupsManage: mocks.groups,
                        monitoringManage: mocks.monitoring,
                    },
                },
            },
        },
    }),
    router: { get: mocks.refresh },
}));
const props = () => ({
    group: { id: 17, name: 'Synthetic group' },
    member: {
        id: 41,
        name: 'Synthetic gateway',
        device_uid: 'SYN-41',
        assigned_to: 'Synthetic Site',
        assignment_type: 'site',
    },
    actorId: 7,
    sourceActor: 7,
    asOf: '2026-10-20T10:00:00Z',
    onClose: vi.fn(),
});
const choices = (): MaintenanceChoices => ({
    schema_version: 1,
    mode: 'choices',
    actor_id: 7,
    as_of: '2026-10-20T10:01:00.000000Z',
    owner: 'Monitoring',
    capability: 'technical_maintenance_notification_ticket_suppression',
    supported_dimensions: ['site', 'device', 'monitor'],
    precedence: ['monitor', 'device', 'site'],
    group_inheritance: {
        supported: false,
        reason_code: 'group_not_policy_dimension',
    },
    commits_change: false,
    execution_authority: false,
    provider_action: false,
    health_claim: false,
    safety_response_suppression: false,
    group: props().group,
    device: { id: 41, name: 'Synthetic gateway', device_uid: 'SYN-41' },
    site: { id: 9, name: 'Synthetic Site' },
    monitors: [
        {
            id: 12,
            device_id: 41,
            name: 'Synthetic reachability',
            kind: 'ping',
            is_enabled: true,
        },
    ],
    monitor_limit: 50,
    truncated: false,
    state: 'ready',
});
const preview = (): MaintenancePreview => {
    const {
        monitors,
        monitor_limit: _limit,
        truncated: _truncated,
        state: _state,
        ...common
    } = choices();
    return {
        ...common,
        mode: 'preview',
        monitor: monitors[0],
        requested_at: '2026-10-20T10:00:00.000000Z',
        resolution: {
            schema_version: 1,
            owner: 'Monitoring',
            basis: 'occurrence_effective_maintenance_revisions',
            observed_at: '2026-10-20T10:00:00.000000Z',
            interpreted_at: '2026-10-20T10:01:00.000000Z',
            clock_uncertainty_seconds: null,
            state: 'none',
            reason_code: 'no_applicable_maintenance',
            winner: null,
            matching: [],
            overridden: [],
            assignment: null,
        },
    };
};
type RecordedRule = NonNullable<MaintenancePreview['resolution']['winner']>;
function oneOffRule(
    id: number,
    scope: 'monitor' | 'device' | 'site',
): RecordedRule {
    return {
        revision_id: id,
        window_id: id,
        version: 1,
        actor_id: 7,
        operation: 'create',
        effective_from: '2026-10-20T08:00:00.000000Z',
        recorded_at: '2026-10-20T08:00:00.000000Z',
        snapshot_hash: String(id).padStart(64, '0'),
        snapshot: {
            id,
            version: 1,
            state: 'active',
            site_id: 9,
            device_id: scope === 'device' ? 41 : null,
            monitor_id: scope === 'monitor' ? 12 : null,
            monitor_device_id: scope === 'monitor' ? 41 : null,
            name: `Synthetic ${scope} one-off ${id}`,
            reason: 'Planned technical maintenance',
            policy: 'suppress_notifications_and_ticketing',
            starts_at: '2026-10-20T09:00:00+00:00',
            ends_at: '2026-10-20T12:00:00+00:00',
            recurrence: null,
            recurrence_until: null,
            timezone: 'Pacific/Auckland',
            dst_overlap: 'first',
            dst_gap: 'reject',
            created_by_user_id: 7,
            updated_by_user_id: 7,
            cancelled_at: null,
            cancelled_by_user_id: null,
            cancellation_reason: null,
        },
    };
}
function appliedPreview(): MaintenancePreview {
    const value = preview(),
        winner = oneOffRule(101, 'monitor');
    value.resolution = {
        ...value.resolution,
        state: 'applied',
        reason_code: 'most_specific_occurrence_rule',
        winner,
        matching: [winner],
        overridden: [oneOffRule(102, 'device'), oneOffRule(103, 'site')],
    };
    return value;
}
function deferred() {
    let resolve!: (value: { data: unknown }) => void;
    let reject!: (error: unknown) => void;
    const promise = new Promise<{ data: unknown }>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}
async function selectMonitor() {
    fireEvent.click(await screen.findByRole('combobox', { name: 'Monitor' }));
    fireEvent.click(
        await screen.findByRole('option', { name: /Synthetic reachability/ }),
    );
}
async function ready() {
    const view = render(<MaintenancePolicyPreview {...props()} />);
    await selectMonitor();
    return view;
}
async function showResult() {
    mocks.get
        .mockResolvedValueOnce({ data: choices() })
        .mockResolvedValueOnce({ data: preview() });
    const view = await ready();
    fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
    await screen.findByText('No applicable maintenance');
    return view;
}
beforeEach(() => {
    mocks.get.mockReset();
    mocks.refresh.mockReset();
    mocks.actor = 7;
    mocks.groups = true;
    mocks.monitoring = true;
});
afterEach(cleanup);

describe('group maintenance preview read lifecycle', () => {
    it('shows an applied one-off winner and retains its matching and overridden records without rewriting null recurrence', async () => {
        const data = appliedPreview();
        mocks.get
            .mockResolvedValueOnce({ data: choices() })
            .mockResolvedValueOnce({ data });
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        await screen.findByText('Applicable technical maintenance rule');
        expect(
            screen.getByText('Synthetic monitor one-off 101'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Read full records' }),
        );
        expect(
            screen.getByText('Winning revision: Synthetic monitor one-off 101'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Overridden revision: Synthetic device one-off 102',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Overridden revision: Synthetic site one-off 103'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Does not repeat')).toHaveLength(3);
        expect(data.resolution.winner?.snapshot.recurrence).toBeNull();
        expect(
            data.resolution.overridden.every(
                (rule) => rule.snapshot.recurrence === null,
            ),
        ).toBe(true);
    });
    it('retains both matching one-off records in a conflict without inventing a winner', async () => {
        const data = preview();
        data.resolution = {
            ...data.resolution,
            state: 'conflict',
            reason_code: 'equal_priority_rules',
            matching: [oneOffRule(104, 'monitor'), oneOffRule(105, 'monitor')],
        };
        mocks.get
            .mockResolvedValueOnce({ data: choices() })
            .mockResolvedValueOnce({ data });
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        await screen.findByText('Conflicting maintenance rules');
        fireEvent.click(
            screen.getByRole('button', { name: 'Read full records' }),
        );
        expect(screen.getAllByText('Does not repeat')).toHaveLength(2);
        expect(
            screen.getByText(
                'Equal-priority revision: Synthetic monitor one-off 104',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Equal-priority revision: Synthetic monitor one-off 105',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Winning revision:/)).not.toBeInTheDocument();
    });
    it.each([42, [], {}, false])(
        'withholds a winning result with malformed recurrence %j as an unchecked read, not a denial',
        async (recurrence) => {
            const data = appliedPreview();
            data.resolution.winner!.snapshot.recurrence =
                recurrence as unknown as string;
            mocks.get
                .mockResolvedValueOnce({ data: choices() })
                .mockResolvedValueOnce({ data })
                .mockResolvedValueOnce({ data: appliedPreview() });
            await ready();
            fireEvent.click(
                screen.getByRole('button', { name: 'Preview policy' }),
            );
            await screen.findByText(/couldn.t be checked/);
            expect(
                screen.queryByText('Synthetic monitor one-off 101'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Refresh group' }),
            ).not.toBeInTheDocument();
            fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
            await screen.findByText('Applicable technical maintenance rule');
            expect(mocks.get).toHaveBeenCalledTimes(3);
        },
    );
    it('still withholds an applied one-off result for another monitor', async () => {
        const data = appliedPreview();
        data.resolution.winner!.snapshot.monitor_id = 999;
        mocks.get
            .mockResolvedValueOnce({ data: choices() })
            .mockResolvedValueOnce({ data });
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        await screen.findByText(/couldn.t be checked/);
        expect(
            screen.queryByText('Synthetic monitor one-off 101'),
        ).not.toBeInTheDocument();
    });
    it('reads only the current member and selected instant and exposes honest full records', async () => {
        await showResult();
        expect(mocks.get).toHaveBeenNthCalledWith(
            1,
            '/security-devices/device-groups/17/maintenance-policy-preview',
            expect.objectContaining({
                params: {
                    mode: 'choices',
                    expected_actor_id: '7',
                    device_id: '41',
                },
            }),
        );
        expect(mocks.get).toHaveBeenNthCalledWith(
            2,
            '/security-devices/device-groups/17/maintenance-policy-preview',
            expect.objectContaining({
                params: {
                    mode: 'preview',
                    expected_actor_id: '7',
                    device_id: '41',
                    monitor_id: '12',
                    at: '2026-10-20T10:00:00Z',
                },
            }),
        );
        expect(screen.getByText(/does not change rules/)).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Read full records' }),
        );
        // Raw identifiers and UTC values stay in the collapsed technical details.
        const technical = screen
            .getByText('Exact chosen instant')
            .closest('details')!;
        expect(technical).not.toHaveAttribute('open');
        expect(screen.getByText('Exact chosen instant')).not.toBeVisible();
        expect(screen.getAllByText('2026-10-20T10:00:00.000000Z')).toHaveLength(
            2,
        );
        expect(
            screen.getByText('Clock uncertainty seconds').parentElement,
        ).toHaveTextContent('Not recorded');
        expect(screen.getByText('Time checked')).toBeVisible();
        expect(screen.getByText('none')).not.toBeVisible();
        expect(screen.queryByText(/Pacific\/Auckland/)).not.toBeInTheDocument();
    });
    it.each([
        [401, /signed out/],
        [403, /access to maintenance previews has changed/],
        [404, /no longer available/],
    ])(
        'withdraws private results on a current %s denial and requires a full group read',
        async (status, message) => {
            await showResult();
            mocks.get.mockRejectedValueOnce({
                response: { status },
                message: 'PRIVATE BACKEND DETAIL',
            });
            fireEvent.click(
                screen.getByRole('button', { name: 'Preview policy' }),
            );
            await screen.findByText(message);
            expect(
                screen.queryByText('No applicable maintenance'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Synthetic Site'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText(/PRIVATE BACKEND/),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh group' }),
            );
            expect(mocks.refresh).toHaveBeenCalledWith(
                '/security-devices/device-groups/17',
                {},
                { preserveState: false, preserveScroll: true },
            );
        },
    );
    it('asks the person to wait when previews are rate limited, keeping the dated result', async () => {
        await showResult();
        mocks.get.mockRejectedValueOnce({ response: { status: 429 } });
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        await screen.findByText(/Too many previews/);
        expect(screen.getByText('Previous preview for')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Refresh group' }),
        ).not.toBeInTheDocument();
    });
    it.each([
        { response: { status: 503 }, message: 'PRIVATE BACKEND DETAIL' },
        { code: 'ECONNABORTED', message: 'PRIVATE BACKEND timeout' },
        { code: 'ERR_NETWORK', message: 'PRIVATE BACKEND offline' },
    ])(
        'retains a dated prior preview and recovers from a failed read: %j',
        async (failure) => {
            await showResult();
            mocks.get.mockRejectedValueOnce(failure);
            expect(mocks.get.mock.calls[1][1].timeout).toBe(30_000);
            fireEvent.click(
                screen.getByRole('button', { name: 'Preview policy' }),
            );
            await screen.findByText(/previous preview is retained/);
            expect(
                screen.getByText('Previous preview for'),
            ).toBeInTheDocument();
            expect(
                screen.queryByText(/PRIVATE BACKEND/),
            ).not.toBeInTheDocument();
            mocks.get.mockResolvedValueOnce({ data: preview() });
            fireEvent.click(
                screen.getByRole('button', { name: 'Preview policy' }),
            );
            await waitFor(() =>
                expect(
                    screen.queryByText(/previous preview is retained/),
                ).not.toBeInTheDocument(),
            );
        },
    );
    it('ignores a late result after current permission loss, including a later permission restoration', async () => {
        const late = deferred();
        mocks.get
            .mockResolvedValueOnce({ data: choices() })
            .mockReturnValueOnce(late.promise);
        const view = await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        mocks.monitoring = false;
        view.rerender(<MaintenancePolicyPreview {...props()} />);
        await screen.findByText(/access to maintenance previews has changed/);
        mocks.monitoring = true;
        view.rerender(<MaintenancePolicyPreview {...props()} />);
        await act(async () => late.resolve({ data: preview() }));
        expect(
            screen.queryByText('No applicable maintenance'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Preview policy' }),
        ).not.toBeInTheDocument();
    });
    it('rejects a paired actor and page-provenance change within the same mounted reader', async () => {
        const view = await showResult();
        mocks.actor = 8;
        view.rerender(
            <MaintenancePolicyPreview
                {...props()}
                actorId={8}
                sourceActor={8}
            />,
        );
        expect(
            screen.queryByText('No applicable maintenance'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/access to maintenance previews has changed/),
        ).toBeInTheDocument();
    });
    it.each(['member', 'group'])(
        'clears retained results after a current %s change',
        async (change) => {
            const view = await showResult();
            const changed = props();
            if (change === 'member')
                changed.member.assigned_to = 'Another Site';
            else changed.group.id = 18;
            view.rerender(<MaintenancePolicyPreview {...changed} />);
            expect(
                screen.queryByText('No applicable maintenance'),
            ).not.toBeInTheDocument();
            expect(screen.getByText(/no longer available/)).toBeInTheDocument();
        },
    );
    it('rejects a response for another selected instant without showing its contents', async () => {
        const wrong = preview();
        wrong.requested_at = '2026-10-21T10:00:00.000000Z';
        mocks.get
            .mockResolvedValueOnce({ data: choices() })
            .mockResolvedValueOnce({ data: wrong });
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        await screen.findByText(/couldn.t be checked/);
        expect(
            screen.queryByText('No applicable maintenance'),
        ).not.toBeInTheDocument();
    });
    it('clears the selected monitor when a refreshed list withdraws it', async () => {
        mocks.get.mockResolvedValueOnce({ data: choices() });
        await ready();
        mocks.get.mockResolvedValueOnce({
            data: { ...choices(), monitors: [], state: 'no_monitors' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh monitor choices' }),
        );
        // A changed list is current, checked data — not a denial.
        await screen.findByText(/monitor you chose is no longer listed/);
        expect(
            screen.queryByRole('combobox', { name: 'Monitor' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/No current monitors are available/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Preview policy' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Refresh group' }),
        ).not.toBeInTheDocument();
    });
    it('drops monitors read for an old Site when a preview reports a changed Site', async () => {
        const moved = preview();
        moved.site = { id: 10, name: 'Another Site' };
        mocks.get
            .mockResolvedValueOnce({ data: choices() })
            .mockResolvedValueOnce({ data: moved });
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Preview policy' }));
        await screen.findByText(
            /Site has changed since the monitors were loaded/,
        );
        expect(
            screen.queryByRole('combobox', { name: 'Monitor' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Load current monitors' }),
        ).toBeVisible();
        expect(screen.queryByText('Another Site')).not.toBeInTheDocument();
    });
    it('distinguishes no current monitors from a failed read', async () => {
        mocks.get.mockResolvedValueOnce({
            data: { ...choices(), monitors: [], state: 'no_monitors' },
        });
        render(<MaintenancePolicyPreview {...props()} />);
        await screen.findByText(/No current monitors are available/);
        expect(
            screen.getByRole('button', { name: 'Preview policy' }),
        ).toBeDisabled();
    });
    it('cancels an owned pending read on close', async () => {
        const late = deferred();
        mocks.get.mockReturnValueOnce(late.promise);
        const p = props();
        render(<MaintenancePolicyPreview {...p} />);
        fireEvent.click(screen.getAllByRole('button', { name: /^Close$/ })[1]);
        expect(mocks.get.mock.calls[0][1].signal.aborted).toBe(true);
        await act(async () => late.resolve({ data: choices() }));
        expect(screen.queryByText('Synthetic Site')).not.toBeInTheDocument();
        expect(p.onClose).toHaveBeenCalledOnce();
    });
});

describe('exact preview time', () => {
    it('requires an explicit repeated NZ time choice and rejects the skipped local time', () => {
        expect(maintenancePreviewInstant('2026-04-05T02:30', '').at).toBeNull();
        expect(maintenancePreviewInstant('2026-04-05T02:30', '+13:00').at).toBe(
            '2026-04-04T13:30:00Z',
        );
        expect(maintenancePreviewInstant('2026-04-05T02:30', '+12:00').at).toBe(
            '2026-04-04T14:30:00Z',
        );
        expect(maintenancePreviewInstant('2026-09-27T02:30', '').at).toBeNull();
    });
    it('preserves exact microseconds and rejects impossible or server-invalid UTC inputs', () => {
        const at = '2026-10-20T10:00:00.123456Z';
        expect(maintenancePreviewInstant('', '', at).at).toBe(at);
        for (const value of [
            '0000-10-20T10:00:00Z',
            '2026-02-30T10:00:00Z',
            '2026-10-20T10:00:60Z',
            '2026-10-20T10:00:00.1234567Z',
            '2026-10-20T10:00:00+00:00',
        ])
            expect(strictPreviewUtc(value), value).toBe(false);
    });
});
