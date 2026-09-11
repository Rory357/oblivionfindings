import {
    clearItTicketDraftMemory,
    useItTicketDraftMemory,
} from '@/hooks/use-it-ticket-draft-memory';
import {
    act,
    cleanup,
    fireEvent,
    render,
    renderHook,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketRoutingDialog } from '../ticket-routing-dialog';
import { TicketWaitingDialog } from '../ticket-waiting-dialog';

const mocks = vi.hoisted(() => ({
    actor: 11,
    drafts: false,
    close: vi.fn(),
    outcome: vi.fn(),
    success: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    on: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { user: { id: mocks.actor } },
            draftRecovery: { enabled: mocks.drafts },
        },
    }),
    router: { reload: mocks.reload, visit: mocks.visit, on: mocks.on },
}));
vi.mock('sonner', () => ({ toast: { success: mocks.success } }));
const reason = 'Private evidence retained for this dependency.';
const response = (data: unknown) => ({ status: 200, data });
const committed = (actor = 11, extra = {}) =>
    response({
        status: 'committed',
        data: { id: 42, viewer_user_id: actor, lock_version: 8, ...extra },
    });
const fail = (status?: number, data = {}) => ({
    isAxiosError: true,
    response: status ? { status, data } : undefined,
});
const current = (actor = 11, manage = true) => ({
    ok: true,
    status: 200,
    json: async () => ({
        viewer_user_id: actor,
        can: { manage },
        ticket: {
            id: 42,
            reference: 'IT-000042',
            lock_version: 9,
            title: 'Current request',
            status: 'in_progress',
            priority: 'normal',
            assignee: null,
        },
    }),
});
function Fixture({
    kind = 'waiting',
    bulk = false,
    ids = [42],
    version = 7,
}: {
    kind?: 'waiting' | 'routing';
    bulk?: boolean;
    ids?: number[];
    version?: number;
}) {
    return kind === 'waiting' ? (
        <TicketWaitingDialog
            open
            onOpenChange={mocks.close}
            scope={bulk ? 'bulk' : 'single'}
            ticketIds={ids}
            expectedVersions={Object.fromEntries(
                ids.map((id) => [id, version]),
            )}
            onBulkResult={mocks.outcome}
        />
    ) : (
        <TicketRoutingDialog
            ticket={{
                id: 42,
                lock_version: version,
                assignee: { id: 30, name: 'Technician' },
                routing: {
                    queue: { id: 1, name: 'Service desk' },
                    owner: { id: 20, name: 'Owner' },
                    team: null,
                },
            }}
            queues={[{ id: 1, name: 'Service desk' }]}
            agents={[
                { id: 20, name: 'Owner' },
                { id: 30, name: 'Technician' },
            ]}
            onClose={mocks.close}
        />
    );
}
const enter = (kind: 'waiting' | 'routing' = 'waiting') =>
    fireEvent.change(
        screen.getByLabelText(
            kind === 'waiting'
                ? 'Reason for waiting'
                : 'Reason for routing change',
        ),
        { target: { value: reason } },
    );
const send = (kind: 'waiting' | 'routing' = 'waiting', bulk = false) =>
    fireEvent.click(
        screen.getByRole('button', {
            name:
                kind === 'routing'
                    ? 'Save routing'
                    : bulk
                      ? 'Set 2 tickets waiting'
                      : 'Set waiting',
        }),
    );
async function review() {
    fireEvent.click(
        await screen.findByRole('button', { name: 'Review current ticket' }),
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Use this version and keep my draft',
        }),
    );
}
function bulkResult(
    items = [
        { id: 42, status: 'updated', message: 'Updated.' },
        { id: 43, status: 'stale', message: 'Changed elsewhere.' },
    ],
) {
    return {
        resource: 'tickets',
        action: 'status',
        selected: items.length,
        updated: items.filter((i) => i.status === 'updated').length,
        unchanged: items.filter((i) => i.status === 'unchanged').length,
        rejected: items.filter(
            (i) => !['updated', 'unchanged'].includes(i.status),
        ).length,
        items,
    };
}
const draftUuid = 'ba35f9ab-87a8-4fa7-868c-d4cb1a5d850a';
const metadata = (extra = {}) => ({
    draft_uuid: draftUuid,
    purpose: 'ticket_edit',
    context_key: 'ticket:42',
    audience: 'internal',
    ticket_id: 42,
    request_uuid: null,
    revision: 0,
    state: 'active',
    has_content: false,
    saved_at: null,
    expires_at: '2026-10-01T00:00:00Z',
    base_ticket_version: 7,
    current_ticket_version: 7,
    files: { ready: 0, pending: 0, cleanup_pending: 0 },
    capabilities: {
        read: true,
        save: true,
        submit: true,
        discard: true,
        start_new: false,
    },
    blocker: null,
    ...extra,
});

function authorizeMemory(version = 3) {
    vi.mocked(axios.post).mockImplementation(async (_url, raw) => {
        const input = raw as Record<string, unknown>;
        return {
            status: 200,
            data: {
                candidate: {
                    kind: 'memory',
                    memory_uuid: input.memory_uuid,
                    candidate_uuid: input.candidate_uuid,
                    actor_user_id: input.actor_user_id,
                    purpose: input.purpose,
                    context_key: 'ticket:42',
                    base_ticket_version: input.base_ticket_version,
                    current_ticket_version: version,
                    authorized: true,
                    capabilities: {
                        submit: input.base_ticket_version === version,
                    },
                    blocker:
                        input.base_ticket_version === version
                            ? null
                            : {
                                  code: 'ticket_changed',
                                  message: 'Review the current ticket.',
                              },
                },
            },
        };
    });
}

describe('waiting and routing command recovery', () => {
    it.each(['waiting', 'routing'] as const)(
        'restores %s browser fields and the original version only after authorization',
        async (kind) => {
            authorizeMemory(9);
            const first = render(<Fixture kind={kind} />);
            enter(kind);
            first.unmount();
            render(<Fixture kind={kind} version={99} />);
            expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Resume browser work',
                }),
            );
            expect(await screen.findByDisplayValue(reason)).toBeVisible();
            expect(axios.post).toHaveBeenCalledWith(
                '/it/drafts/validate-local-candidate',
                expect.objectContaining({
                    base_ticket_version: 7,
                    actor_user_id: 11,
                }),
                expect.any(Object),
            );
            expect(axios.request).not.toHaveBeenCalled();
            expect(
                screen.getByRole('button', {
                    name: kind === 'waiting' ? 'Set waiting' : 'Save routing',
                }),
            ).toBeDisabled();
            await review();
            vi.mocked(axios.request).mockResolvedValueOnce(
                response({
                    status: 'committed',
                    data: { id: 42, viewer_user_id: 11, lock_version: 10 },
                }),
            );
            send(kind);
            await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
            expect(axios.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({ expected_version: 9 }),
                }),
            );
        },
    );
    it('retains an unknown waiting submission across unmount without replaying it', async () => {
        authorizeMemory(7);
        vi.mocked(axios.request).mockRejectedValueOnce(fail(503));
        const first = render(<Fixture />);
        enter();
        send();
        await screen.findByText(/The outcome is unconfirmed/);
        first.unmount();
        render(<Fixture />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        await screen.findByDisplayValue(reason);
        expect(
            screen.getByRole('button', { name: 'Set waiting' }),
        ).toBeDisabled();
        expect(axios.request).toHaveBeenCalledTimes(1);
        await review();
        expect(
            screen.getByRole('button', { name: 'Set waiting' }),
        ).toBeEnabled();
        expect(axios.request).toHaveBeenCalledTimes(1);
    });
    it('rejects another editor’s incompatible waiting state before adopting its browser copy', async () => {
        authorizeMemory(7);
        const source = renderHook(() =>
            useItTicketDraftMemory({
                enabled: true,
                persistenceEnabled: false,
                draft: null,
                actorId: 11,
                context: { purpose: 'ticket_edit', ticketId: 42 },
                outcomeUnknown: false,
                workingDirty: true,
                workingSnapshot: {
                    fields: { status: 'in_progress', waiting_reason: reason },
                    step_index: 0,
                    base_ticket_version: 7,
                },
            }),
        );
        source.unmount();
        render(<Fixture />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        await screen.findByText(/Open the matching ticket editor/);
        expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Resume browser work' }),
        ).toBeEnabled();
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.post).toHaveBeenCalledTimes(1);
    });
    it('does not offer another actor’s waiting browser work or persist bulk form context', async () => {
        const first = render(<Fixture />);
        enter();
        first.unmount();
        mocks.actor = 12;
        const second = render(<Fixture />);
        expect(
            screen.queryByRole('button', { name: 'Resume browser work' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
        second.unmount();
        mocks.actor = 11;
        const bulk = render(<Fixture bulk ids={[42, 43]} />);
        enter();
        bulk.unmount();
        render(<Fixture />);
        expect(
            screen.queryByRole('button', { name: 'Resume browser work' }),
        ).not.toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });
    beforeEach(() => {
        clearItTicketDraftMemory();
        vi.clearAllMocks();
        mocks.actor = 11;
        mocks.drafts = false;
        mocks.on.mockReturnValue(() => {});
        vi.spyOn(axios, 'request');
        vi.spyOn(axios, 'post');
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(current()));
    });
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        localStorage.clear();
    });
    it.each(['waiting', 'routing'] as const)(
        'binds %s to its original actor and displayed version through refreshed props',
        async (kind) => {
            vi.mocked(axios.request).mockResolvedValueOnce(committed());
            const view = render(<Fixture kind={kind} />);
            enter(kind);
            view.rerender(<Fixture kind={kind} version={9} />);
            send(kind);
            await waitFor(() => expect(mocks.close).toHaveBeenCalledOnce());
            expect(vi.mocked(axios.request).mock.calls[0][0]).toMatchObject({
                method: 'patch',
                url: '/it/tickets/42',
                data: {
                    actor_user_id: 11,
                    expected_version: 7,
                    [kind === 'waiting' ? 'waiting_reason' : 'routing_reason']:
                        reason,
                },
                headers: { Accept: 'application/json' },
            });
            expect(mocks.success).toHaveBeenCalledOnce();
            expect(mocks.reload).toHaveBeenCalledOnce();
        },
    );
    it.each(['waiting', 'routing'] as const)(
        'retains %s after an unconfirmed success response and requires explicit review',
        async (kind) => {
            vi.mocked(axios.request).mockResolvedValueOnce(
                response('<html>Login page</html>'),
            );
            render(<Fixture kind={kind} />);
            enter(kind);
            send(kind);
            await screen.findByText(/The outcome is unconfirmed/);
            expect(screen.getByDisplayValue(reason)).toBeVisible();
            expect(mocks.close).not.toHaveBeenCalled();
            expect(mocks.success).not.toHaveBeenCalled();
            expect(
                screen.getByRole('button', {
                    name: kind === 'routing' ? 'Save routing' : 'Set waiting',
                }),
            ).toBeDisabled();
            await review();
            expect(axios.request).toHaveBeenCalledTimes(1);
            expect(screen.getByDisplayValue(reason)).toBeVisible();
        },
    );
    it('preserves a rejected waiting draft and applies a newly reviewed version only on another explicit click', async () => {
        vi.mocked(axios.request)
            .mockRejectedValueOnce(
                fail(409, {
                    errors: { expected_version: ['Changed elsewhere.'] },
                }),
            )
            .mockResolvedValueOnce(committed(11, { lock_version: 10 }));
        render(<Fixture />);
        enter();
        send();
        await screen.findByText('Changed elsewhere.');
        await review();
        expect(axios.request).toHaveBeenCalledTimes(1);
        send();
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toMatchObject({
            expected_version: 9,
            waiting_reason: reason,
        });
    });
    it('leaves validation errors editable without replacing their entered evidence', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(
            fail(422, {
                errors: { waiting_reason: ['Explain the dependency.'] },
            }),
        );
        render(<Fixture />);
        enter();
        send();
        await screen.findAllByText('Explain the dependency.');
        expect(screen.getByDisplayValue(reason)).toBeEnabled();
        expect(mocks.close).not.toHaveBeenCalled();
        expect(mocks.success).not.toHaveBeenCalled();
    });
    it.each([403, 404])(
        'conceals private fields when current access is denied (%s)',
        async (status) => {
            vi.mocked(axios.request).mockRejectedValueOnce(fail(status));
            render(<Fixture />);
            enter();
            send();
            await screen.findByText(/The entered details are concealed/);
            expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
            expect(mocks.success).not.toHaveBeenCalled();
        },
    );
    it('conceals session-expired routing until matching actor and work permission are reviewed', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(fail(419));
        render(<Fixture kind="routing" />);
        enter('routing');
        send('routing');
        await screen.findByRole('link', { name: 'Sign in again' });
        expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
        await review();
        expect(screen.getByDisplayValue(reason)).toBeVisible();
        expect(axios.request).toHaveBeenCalledTimes(1);
    });
    it.each([current(12), current(11, false)])(
        'purges retained evidence when current review is another actor or lacks work permission',
        async (result) => {
            vi.mocked(axios.request).mockRejectedValueOnce(fail());
            vi.mocked(fetch).mockResolvedValueOnce(result as Response);
            render(<Fixture />);
            enter();
            send();
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Review current ticket',
                }),
            );
            await screen.findByText(/The entered details are concealed/);
            expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
        },
    );
    it('ignores a late acknowledgement after an account change', async () => {
        let settle!: (value: ReturnType<typeof committed>) => void;
        vi.mocked(axios.request).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    settle = resolve;
                }),
        );
        const view = render(<Fixture />);
        enter();
        send();
        mocks.actor = 12;
        view.rerender(<Fixture />);
        await act(async () => settle(committed()));
        expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
        expect(mocks.success).not.toHaveBeenCalled();
    });
    it('retains exact original bulk IDs and versions and delays partial-outcome selection changes until acknowledged close', async () => {
        const result = bulkResult();
        vi.mocked(axios.request).mockResolvedValueOnce(
            response({ status: 'completed', viewer_user_id: 11, result }),
        );
        const view = render(<Fixture bulk ids={[42, 43]} />);
        enter();
        view.rerender(<Fixture bulk ids={[42, 44]} version={10} />);
        send('waiting', true);
        await screen.findByText('1 updated · 0 unchanged · 1 not applied');
        expect(screen.getByDisplayValue(reason)).toBeVisible();
        expect(mocks.outcome).not.toHaveBeenCalled();
        expect(vi.mocked(axios.request).mock.calls[0][0].data).toMatchObject({
            actor_user_id: 11,
            ids: [42, 43],
            expected_versions: { 42: 7, 43: 7 },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Close waiting form' }),
        );
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByDisplayValue(reason)).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Close waiting form' }),
        );
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Leave form',
            }),
        );
        expect(mocks.outcome).toHaveBeenCalledWith(result);
        expect(mocks.close).toHaveBeenCalledOnce();
        expect(mocks.success).not.toHaveBeenCalled();
    });
    it.each(['actor', 'selection', 'action', 'counts'] as const)(
        'does not accept a bulk acknowledgement for another %s',
        async (invalid) => {
            const result = bulkResult([
                { id: 42, status: 'updated', message: 'Updated.' },
                { id: 43, status: 'updated', message: 'Updated.' },
            ]);
            if (invalid === 'selection') result.items[1].id = 99;
            if (invalid === 'action') result.action = 'close';
            if (invalid === 'counts') {
                result.updated = 0;
                result.unchanged = 2;
            }
            vi.mocked(axios.request).mockResolvedValueOnce(
                response({
                    status: 'completed',
                    viewer_user_id: invalid === 'actor' ? 12 : 11,
                    result,
                }),
            );
            render(<Fixture bulk ids={[42, 43]} />);
            enter();
            send('waiting', true);
            await screen.findByText(
                invalid === 'actor'
                    ? /The entered details are concealed/
                    : /The outcome is unconfirmed/,
            );
            expect(mocks.close).not.toHaveBeenCalled();
            expect(mocks.outcome).not.toHaveBeenCalled();
            expect(mocks.success).not.toHaveBeenCalled();
        },
    );
    it.each(['waiting', 'routing'] as const)(
        'confirms Escape for a dirty %s form',
        async (kind) => {
            render(<Fixture kind={kind} />);
            enter(kind);
            fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
            fireEvent.click(
                within(await screen.findByRole('alertdialog')).getByRole(
                    'button',
                    { name: 'Cancel' },
                ),
            );
            expect(screen.getByDisplayValue(reason)).toBeVisible();
            expect(mocks.close).not.toHaveBeenCalled();
            expect(axios.request).not.toHaveBeenCalled();
        },
    );
    it('confirms normal navigation and permits cancelling a wait without claiming cancellation of the write', async () => {
        let settle!: (value: ReturnType<typeof committed>) => void;
        vi.mocked(axios.request).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    settle = resolve;
                }),
        );
        render(<Fixture />);
        enter();
        const event = {
            detail: { visit: { method: 'get', url: '/it' } },
            preventDefault: vi.fn(),
        };
        act(() => mocks.on.mock.calls.at(-1)?.[1](event));
        expect(event.preventDefault).toHaveBeenCalledOnce();
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        send();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel wait' }));
        await act(async () => settle(committed()));
        expect(mocks.close).not.toHaveBeenCalled();
        expect(mocks.success).not.toHaveBeenCalled();
        expect(screen.getByDisplayValue(reason)).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Set waiting' }),
        ).toBeDisabled();
    });
    it('requires exact saved ticket-edit fields and consumed generation before confirming waiting', async () => {
        mocks.drafts = true;
        vi.mocked(axios.request)
            .mockResolvedValueOnce(response({ draft: metadata() }))
            .mockResolvedValueOnce(
                response({
                    draft: metadata({ revision: 1, has_content: true }),
                }),
            )
            .mockResolvedValueOnce(
                committed(11, {
                    draft: {
                        draft_uuid: draftUuid,
                        submitted_revision: 1,
                        revision: 2,
                        state: 'consumed',
                    },
                }),
            );
        render(<Fixture />);
        await waitFor(() =>
            expect(screen.getByLabelText('Reason for waiting')).toBeEnabled(),
        );
        enter();
        await waitFor(
            () =>
                expect(
                    screen.getByRole('button', { name: 'Set waiting' }),
                ).toBeEnabled(),
            { timeout: 2500 },
        );
        send();
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[2][0].data).toMatchObject({
            draft_uuid: draftUuid,
            draft_revision: 1,
            draft_actor_user_id: 11,
            expected_version: 7,
            waiting_reason: reason,
        });
    });

    it('does not overwrite a saved draft for different ticket properties after explicit resume', async () => {
        mocks.drafts = true;
        const other = metadata({ revision: 1, has_content: true });
        vi.mocked(axios.request)
            .mockResolvedValueOnce(response({ draft: other }))
            .mockResolvedValueOnce(
                response({
                    draft: other,
                    payload: {
                        fields: {
                            priority: 'urgent',
                            priority_reason:
                                'Earlier separate priority decision',
                        },
                        step_index: 0,
                    },
                    attachments: [],
                }),
            );
        render(<Fixture />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume saved draft' }),
        );
        await screen.findByText(
            /This saved draft contains different ticket properties/,
        );
        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Set waiting' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Discard saved draft' }),
        ).toBeEnabled();
        expect(axios.request).toHaveBeenCalledTimes(2);
    });

    it('conceals an enabled draft session failure while leaving recovery controls usable', async () => {
        mocks.drafts = true;
        vi.mocked(axios.request).mockRejectedValueOnce(fail(419));
        render(
            <TicketWaitingDialog
                open
                onOpenChange={mocks.close}
                scope="single"
                ticketIds={[42]}
                expectedVersions={{ 42: 7 }}
                current={{
                    party: 'vendor',
                    reason,
                    since: null,
                    since_human: null,
                }}
            />,
        );
        await screen.findByRole('link', { name: 'Sign in again' });
        expect(screen.queryByDisplayValue(reason)).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Check saved draft' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Set waiting' }),
        ).toBeDisabled();
    });
});
