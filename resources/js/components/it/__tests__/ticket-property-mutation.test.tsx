import { Button } from '@/components/ui/button';
import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTicketPropertyMutation } from '../ticket-property-mutation';

const mocks = vi.hoisted(() => ({
    reload: vi.fn(),
    visit: vi.fn(),
    on: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload: mocks.reload, visit: mocks.visit, on: mocks.on },
}));
vi.mock('sonner', () => ({
    toast: { success: mocks.success, error: mocks.error },
}));
const failure = (status?: number, data: unknown = {}) => ({
    isAxiosError: true,
    response: status ? { status, data } : undefined,
});
const ack = (
    viewer = 11,
    version = 4,
    extra: Record<string, unknown> = {},
) => ({
    status: 200,
    data: {
        status: 'committed',
        data: {
            id: 42,
            viewer_user_id: viewer,
            lock_version: version,
            ...extra,
        },
    },
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((yes) => {
        resolve = yes;
    });
    return { promise, resolve };
}
const currentTicket = (viewer = 11, canManage = true) => ({
    ok: true,
    status: 200,
    json: async () => ({
        viewer_user_id: viewer,
        can: { manage: canManage },
        ticket: {
            id: 42,
            reference: 'IT-000042',
            lock_version: 8,
            title: 'Current support request',
            status: 'in_progress',
            priority: 'normal',
            assignee: null,
        },
    }),
});
function Fixture({
    actorId = 11,
    version = 3,
    enabled = false,
    field = 'subcategory',
    recovery = false,
}: {
    actorId?: number;
    version?: number;
    enabled?: boolean;
    field?: string;
    recovery?: boolean;
}) {
    const mutation = useTicketPropertyMutation({
        actorId,
        draftsEnabled: enabled,
        recoveryTicket: recovery
            ? { id: 42, lock_version: version }
            : undefined,
    });
    return (
        <>
            <Button
                onClick={() =>
                    mutation.submit(42, version, {
                        [field]:
                            field === 'priority'
                                ? 'urgent'
                                : 'Private proposed value',
                    })
                }
            >
                Change property
            </Button>
            {mutation.recovery}
        </>
    );
}
async function review() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Review current ticket' }),
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Use this version and keep my draft',
        }),
    );
}
const draftUuid = '65033544-6f51-4c74-8da9-4c9f3f2d0c16';
function draftMetadata(overrides: Record<string, unknown> = {}) {
    return {
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
        expires_at: '2026-10-01T00:00:00+00:00',
        base_ticket_version: 3,
        current_ticket_version: 3,
        files: { ready: 0, pending: 0, cleanup_pending: 0 },
        capabilities: {
            read: true,
            save: true,
            submit: true,
            discard: true,
            start_new: false,
        },
        blocker: null,
        ...overrides,
    };
}

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

describe('canonical ticket property recovery', () => {
    it('explicitly discards an adopted unsent proposal without writing or leaving a recovery copy', async () => {
        authorizeMemory();
        const original = render(<Fixture field="priority" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Deliberately abandoned private proposal' },
        });
        original.unmount();
        const recovered = render(<Fixture recovery />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Discard local proposal',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByText('Deliberately abandoned private proposal'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard local proposal' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Discard local proposal',
            }),
        );
        expect(
            screen.queryByText('Deliberately abandoned private proposal'),
        ).not.toBeInTheDocument();
        expect(axios.patch).not.toHaveBeenCalled();
        recovered.unmount();
        render(<Fixture recovery />);
        expect(
            screen.queryByRole('button', { name: 'Resume browser work' }),
        ).not.toBeInTheDocument();
    });
    it('conceals a current proposal immediately when the page loses its permitted recovery record', async () => {
        const page = render(<Fixture field="priority" recovery />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Private reason at revoked record' },
        });
        page.rerender(<Fixture field="priority" recovery={false} />);
        expect(
            screen.queryByDisplayValue('Private reason at revoked record'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(axios.patch).not.toHaveBeenCalled();
    });
    it('recovers the controlled reason after native unmount only after current access authorization', async () => {
        authorizeMemory();
        const original = render(<Fixture field="priority" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Private reason typed before navigation' },
        });
        original.unmount();
        render(<Fixture recovery version={9} />);
        expect(
            screen.queryByDisplayValue(
                'Private reason typed before navigation',
            ),
        ).not.toBeInTheDocument();
        expect(axios.patch).not.toHaveBeenCalled();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit explanation' }),
        );
        expect(
            await screen.findByDisplayValue(
                'Private reason typed before navigation',
            ),
        ).toBeVisible();
        expect(axios.post).toHaveBeenCalledWith(
            '/it/drafts/validate-local-candidate',
            expect.objectContaining({
                base_ticket_version: 3,
                actor_user_id: 11,
            }),
            expect.any(Object),
        );
        expect(axios.patch).not.toHaveBeenCalled();
        vi.mocked(axios.patch).mockResolvedValueOnce(ack());
        fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(axios.patch).toHaveBeenLastCalledWith(
            '/it/tickets/42',
            expect.objectContaining({
                priority_reason: 'Private reason typed before navigation',
                expected_version: 3,
            }),
            expect.any(Object),
        );
    });
    it('restores an unknown property submission without replay and requires explicit current-ticket review', async () => {
        authorizeMemory(8);
        vi.mocked(axios.patch).mockRejectedValueOnce(failure(503));
        const original = render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByText(/The result is unconfirmed/);
        original.unmount();
        render(<Fixture recovery version={8} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        await screen.findByText('Private proposed value');
        expect(axios.patch).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByRole('button', { name: 'Discard local proposal' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
        await review();
        vi.mocked(axios.patch).mockResolvedValueOnce(ack(11, 9));
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply my changes' }),
        );
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(axios.patch).toHaveBeenLastCalledWith(
            '/it/tickets/42',
            expect.objectContaining({
                expected_version: 8,
                subcategory: 'Private proposed value',
            }),
            expect.any(Object),
        );
    });
    it('conceals browser property work after current access is revoked', async () => {
        const original = render(<Fixture field="priority" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Concealed former access reason' },
        });
        original.unmount();
        vi.mocked(axios.post).mockRejectedValueOnce(failure(403));
        render(<Fixture recovery />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        await screen.findByText(/Your account or ticket access changed/);
        expect(
            screen.queryByDisplayValue('Concealed former access reason'),
        ).not.toBeInTheDocument();
        expect(axios.patch).not.toHaveBeenCalled();
    });
    beforeEach(() => {
        clearItTicketDraftMemory();
        vi.clearAllMocks();
        mocks.on.mockReturnValue(() => {});
        vi.spyOn(axios, 'patch');
        vi.spyOn(axios, 'request');
        vi.spyOn(axios, 'post');
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(currentTicket()));
    });
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        localStorage.clear();
    });

    it('sends the original actor and displayed version and announces only the exact commit', async () => {
        vi.mocked(axios.patch).mockResolvedValueOnce(ack());
        const view = render(<Fixture field="priority" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Confirmed impact requires this priority.' },
        });
        view.rerender(<Fixture field="priority" version={8} />);
        fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(axios.patch).toHaveBeenCalledWith(
            '/it/tickets/42',
            {
                priority: 'urgent',
                priority_reason: 'Confirmed impact requires this priority.',
                expected_version: 3,
                actor_user_id: 11,
            },
            expect.objectContaining({
                headers: { Accept: 'application/json' },
            }),
        );
        expect(axios.request).not.toHaveBeenCalled();
        expect(mocks.reload).toHaveBeenCalledOnce();
    });

    it.each([
        { status: 200, data: '<html>Login</html>' },
        { status: 200, data: {} },
        {
            status: 200,
            data: { status: 'committed', data: { id: 42, lock_version: 4 } },
        },
    ])(
        'keeps the proposal when a successful HTTP response has no trustworthy acknowledgement',
        async (response) => {
            vi.mocked(axios.patch).mockResolvedValueOnce(response);
            render(<Fixture />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Change property' }),
            );
            expect(
                await screen.findByText(/The result is unconfirmed/),
            ).toBeVisible();
            expect(screen.getByText('Private proposed value')).toBeVisible();
            expect(
                screen.getByRole('button', { name: 'Apply my changes' }),
            ).toBeDisabled();
            expect(mocks.success).not.toHaveBeenCalled();
            expect(mocks.reload).not.toHaveBeenCalled();
        },
    );

    it('requires read-only current review followed by a distinct explicit reapply after conflict', async () => {
        vi.mocked(axios.patch)
            .mockRejectedValueOnce(
                failure(409, {
                    errors: { expected_version: ['Changed elsewhere.'] },
                }),
            )
            .mockResolvedValueOnce(ack(11, 9));
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByText('Changed elsewhere.');
        await review();
        expect(axios.patch).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Private proposed value')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply my changes' }),
        );
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.patch).mock.calls[1][1]).toEqual({
            subcategory: 'Private proposed value',
            expected_version: 8,
            actor_user_id: 11,
        });
    });

    it('retains rejection details and does not treat validation as a successful mutation', async () => {
        vi.mocked(axios.patch).mockRejectedValueOnce(
            failure(422, {
                errors: {
                    subcategory: ['This classification is unavailable.'],
                },
            }),
        );
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        expect(
            await screen.findByText('This classification is unavailable.'),
        ).toBeVisible();
        expect(screen.getByText('Private proposed value')).toBeVisible();
        expect(mocks.success).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
    });

    it.each([403, 404])(
        'conceals a denied proposal rather than leaving private values in a recovery panel (%s)',
        async (status) => {
            vi.mocked(axios.patch).mockRejectedValueOnce(failure(status));
            render(<Fixture />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Change property' }),
            );
            await screen.findByText(/The proposed changes are concealed/);
            expect(
                screen.queryByText('Private proposed value'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Apply my changes' }),
            ).not.toBeInTheDocument();
        },
    );

    it('conceals a response from a different signed-in actor and never reloads as success', async () => {
        vi.mocked(axios.patch).mockResolvedValueOnce(ack(12));
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByText(/The proposed changes are concealed/);
        expect(
            screen.queryByText('Private proposed value'),
        ).not.toBeInTheDocument();
        expect(mocks.success).not.toHaveBeenCalled();
    });

    it('keeps session-expired values concealed until the original actor reviews current permission', async () => {
        vi.mocked(axios.patch).mockRejectedValueOnce(failure(419));
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByRole('link', { name: 'Sign in again' });
        expect(
            screen.queryByText('Private proposed value'),
        ).not.toBeInTheDocument();
        await review();
        expect(screen.getByText('Private proposed value')).toBeVisible();
        expect(axios.patch).toHaveBeenCalledTimes(1);
    });

    it.each([currentTicket(12), currentTicket(11, false)])(
        'denies current review when actor or work permission differs',
        async (response) => {
            vi.mocked(axios.patch).mockRejectedValueOnce(failure());
            vi.mocked(fetch).mockResolvedValueOnce(response as Response);
            render(<Fixture />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Change property' }),
            );
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Review current ticket',
                }),
            );
            await screen.findByText(/The proposed changes are concealed/);
            expect(
                screen.queryByText('Private proposed value'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Current support request'),
            ).not.toBeInTheDocument();
        },
    );

    it('confirms Escape, keeps the local proposal after acknowledged close, and ignores a late save response', async () => {
        const pending = deferred<ReturnType<typeof ack>>();
        vi.mocked(axios.patch).mockReturnValueOnce(pending.promise);
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        const confirm = await screen.findByRole('alertdialog');
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.getByText('Private proposed value')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Return to ticket' }),
        );
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Keep proposal and return',
            }),
        );
        await act(async () => {
            pending.resolve(ack());
            await pending.promise;
        });
        expect(mocks.success).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Review retained ticket changes',
            }),
        );
        expect(screen.getByText('Private proposed value')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
    });

    it('ignores an old actor response after the page account changes', async () => {
        const pending = deferred<ReturnType<typeof ack>>();
        vi.mocked(axios.patch).mockReturnValueOnce(pending.promise);
        const view = render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        view.rerender(<Fixture actorId={12} />);
        await act(async () => {
            pending.resolve(ack());
            await pending.promise;
        });
        expect(
            screen.queryByText('Private proposed value'),
        ).not.toBeInTheDocument();
        expect(mocks.success).not.toHaveBeenCalled();
    });

    it('blocks a normal page visit until its pending proposal is explicitly acknowledged', async () => {
        vi.mocked(axios.patch).mockRejectedValueOnce(failure());
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByText(/The result is unconfirmed/);
        const before = mocks.on.mock.calls.at(-1)?.[1];
        const event = {
            detail: { visit: { method: 'get', url: '/it' } },
            preventDefault: vi.fn(),
        };
        act(() => before(event));
        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(mocks.visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Leave page',
            }),
        );
        expect(mocks.visit).toHaveBeenCalledOnce();
    });

    it('allows its confirmed refresh, then guards navigation for a new proposal', async () => {
        const committedRefresh = {
            detail: { visit: { method: 'get', url: '/it/tickets/42' } },
            preventDefault: vi.fn(),
        };
        mocks.reload.mockImplementationOnce(() => {
            const before = mocks.on.mock.calls.at(-1)?.[1];
            before(committedRefresh);
        });
        vi.mocked(axios.patch).mockResolvedValueOnce(ack());
        render(<Fixture />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(committedRefresh.preventDefault).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();

        vi.mocked(axios.patch).mockRejectedValueOnce(failure());
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByText(/The result is unconfirmed/);
        const before = mocks.on.mock.calls.at(-1)?.[1];
        const ordinaryVisit = {
            detail: { visit: { method: 'get', url: '/it' } },
            preventDefault: vi.fn(),
        };
        act(() => before(ordinaryVisit));
        expect(ordinaryVisit.preventDefault).toHaveBeenCalledOnce();
        expect(await screen.findByRole('alertdialog')).toBeVisible();
    });

    it('saves and consumes only the exact configured ticket-edit draft before announcing success', async () => {
        vi.mocked(axios.request)
            .mockResolvedValueOnce({
                status: 200,
                data: { draft: draftMetadata() },
            })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: draftMetadata({ revision: 1, has_content: true }),
                },
            });
        vi.mocked(axios.patch).mockResolvedValueOnce(
            ack(11, 4, {
                draft: {
                    draft_uuid: draftUuid,
                    submitted_revision: 1,
                    revision: 2,
                    state: 'consumed',
                },
            }),
        );
        render(<Fixture enabled />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(1));
        expect(axios.patch).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
        await waitFor(
            () =>
                expect(
                    screen.getByRole('button', { name: 'Apply my changes' }),
                ).toBeEnabled(),
            { timeout: 2500 },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply my changes' }),
        );
        await waitFor(() => expect(mocks.success).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.patch).mock.calls[0][1]).toMatchObject({
            actor_user_id: 11,
            expected_version: 3,
            draft_uuid: draftUuid,
            draft_revision: 1,
            draft_actor_user_id: 11,
        });
    });

    it('keeps the explanation in the property snapshot before confirmation and preserves it when the reason editor reopens', async () => {
        vi.mocked(axios.request)
            .mockResolvedValueOnce({
                status: 200,
                data: { draft: draftMetadata() },
            })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: draftMetadata({ revision: 1, has_content: true }),
                },
            });
        const view = render(<Fixture enabled field="priority" />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Partly prepared impact explanation' },
        });
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2), {
            timeout: 2500,
        });
        expect(vi.mocked(axios.request).mock.calls[1][0]).toMatchObject({
            method: 'patch',
            data: {
                fields: {
                    priority: 'urgent',
                    priority_reason: 'Partly prepared impact explanation',
                },
                base_ticket_version: 3,
            },
        });
        expect(axios.patch).not.toHaveBeenCalled();
        view.rerender(<Fixture enabled field="priority" version={8} />);
        expect(screen.getByLabelText('Reason for change')).toHaveValue(
            'Partly prepared impact explanation',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit explanation' }),
        );
        expect(screen.getByLabelText('Reason for change')).toHaveValue(
            'Partly prepared impact explanation',
        );
        expect(axios.patch).not.toHaveBeenCalled();
    });

    it('resumes only explicitly and preserves an older saved base until current review and a distinct save', async () => {
        const stale = draftMetadata({
            revision: 1,
            has_content: true,
            base_ticket_version: 2,
            current_ticket_version: 8,
            capabilities: {
                read: true,
                save: true,
                submit: false,
                discard: true,
                start_new: false,
            },
            blocker: {
                code: 'ticket_changed',
                message:
                    'Review the current ticket before applying this draft.',
            },
        });
        vi.mocked(axios.request)
            .mockResolvedValueOnce({ status: 200, data: { draft: stale } })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: stale,
                    payload: {
                        fields: { subcategory: 'Earlier saved value' },
                        step_index: 0,
                    },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: draftMetadata({
                        revision: 2,
                        has_content: true,
                        base_ticket_version: 8,
                        current_ticket_version: 8,
                    }),
                },
            });
        render(<Fixture enabled />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume saved draft' }),
        );
        expect(axios.request).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByText('Earlier saved value'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Resume saved draft',
            }),
        );
        await screen.findByText('Earlier saved value');
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
        expect(axios.request).toHaveBeenCalledTimes(2);
        await review();
        expect(axios.patch).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Apply my changes' }),
            ).toBeEnabled(),
        );
        expect(vi.mocked(axios.request).mock.calls[2][0]).toMatchObject({
            method: 'patch',
            data: {
                expected_revision: 1,
                base_ticket_version: 8,
                fields: { subcategory: 'Earlier saved value' },
            },
        });
    });

    it('conceals private proposed values after draft-session expiry while keeping session recovery controls', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(failure(419));
        render(<Fixture enabled />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await screen.findByRole('link', { name: 'Sign in again' });
        expect(
            screen.queryByText('Private proposed value'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Check saved draft' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
        expect(axios.patch).not.toHaveBeenCalled();
    });

    it('freezes draft writes after a canonical commit without its exact consumption acknowledgement', async () => {
        vi.mocked(axios.request)
            .mockResolvedValueOnce({
                status: 200,
                data: { draft: draftMetadata() },
            })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: draftMetadata({ revision: 1, has_content: true }),
                },
            });
        vi.mocked(axios.patch).mockResolvedValueOnce(ack());
        render(<Fixture enabled />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change property' }),
        );
        await waitFor(
            () =>
                expect(
                    screen.getByRole('button', { name: 'Apply my changes' }),
                ).toBeEnabled(),
            { timeout: 2500 },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply my changes' }),
        );
        await screen.findByText(/The result is unconfirmed/);
        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
        expect(screen.getByText('Private proposed value')).toBeVisible();
        expect(axios.request).toHaveBeenCalledTimes(2);
        expect(mocks.success).not.toHaveBeenCalled();
    });
});
