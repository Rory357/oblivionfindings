import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ResolveTicketDialog } from '../resolve-ticket-dialog';

const navigation = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    actor: 10,
    drafts: false,
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        visit: navigation.visit,
        reload: navigation.reload,
        on: () => () => undefined,
    },
    usePage: () => ({
        props: {
            auth: { user: { id: navigation.actor } },
            draftRecovery: { enabled: navigation.drafts },
        },
    }),
}));
vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        get: vi.fn(),
        request: vi.fn(),
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
const post = vi.mocked(axios.post);
const get = vi.mocked(axios.get);
const request = vi.mocked(axios.request);
const draftUuid = '9c3e9caa-4bc9-41ba-8b90-bf654120f167';
const metadata = (overrides: Record<string, unknown> = {}) => ({
    draft_uuid: draftUuid,
    purpose: 'public_resolution',
    context_key: 'ticket:1',
    audience: 'public',
    ticket_id: 1,
    request_uuid: null,
    revision: 0,
    state: 'active',
    has_content: false,
    saved_at: null,
    expires_at: '2026-10-01T00:00:00Z',
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
});
const ticket = {
    id: 1,
    lock_version: 3,
    reference: 'IT-000001',
    title: 'Synthetic unresolved fault',
};
const note = 'Replaced the cable and verified connectivity.';
const verification = 'Confirmed access and checked a second connection.';
const resolution = { code: 'restored', summary: note, verification };
const committed = {
    status: 'committed',
    data: { id: 1, lock_version: 4, viewer_user_id: 10, resolution },
};
const current = (version = 5, status = 'in_progress', viewer = 10) => ({
    viewer_user_id: viewer,
    ticket: { ...ticket, lock_version: version, status },
    can: { manage: true },
});
function enter() {
    fireEvent.change(
        screen.getByRole('textbox', { name: /^Resolution note/ }),
        { target: { value: note } },
    );
    fireEvent.click(screen.getByRole('button', { name: /Service restored/ }));
    fireEvent.change(
        screen.getByRole('textbox', { name: /^How was it checked/ }),
        { target: { value: verification } },
    );
}
function submit() {
    fireEvent.click(screen.getByRole('button', { name: 'Resolve ticket' }));
}
beforeEach(() => {
    clearItTicketDraftMemory();
    post.mockReset();
    get.mockReset();
    request.mockReset();
    navigation.visit.mockReset();
    navigation.reload.mockReset();
    navigation.actor = 10;
    navigation.drafts = false;
    post.mockResolvedValue({ status: 200, data: committed });
});

describe('resolution lifecycle', () => {
    it('shows recorded verification during uncertain-result review without inferring that this attempt succeeded', async () => {
        post.mockRejectedValueOnce(new Error('Connection lost'));
        get.mockResolvedValueOnce({
            status: 200,
            data: {
                ...current(6, 'resolved'),
                ticket: {
                    ...current(6, 'resolved').ticket,
                    resolution: {
                        code: 'workaround',
                        summary: 'Earlier recorded fix.',
                        verification: 'Earlier verified access.',
                    },
                },
            },
        });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Review current ticket',
            }),
        );
        await screen.findByText('Earlier recorded fix.');
        expect(screen.getByText(/Earlier verified access/)).toBeVisible();
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
        expect(post).toHaveBeenCalledOnce();
    });

    it('requires an explicitly selected outcome and verification without inventing defaults', () => {
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
            { target: { value: note } },
        );
        expect(
            screen.getByRole('button', { name: /Service restored/ }),
        ).toHaveAttribute('aria-pressed', 'false');
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: /Workaround provided/ }),
        );
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        fireEvent.change(
            screen.getByRole('textbox', { name: /^How was it checked/ }),
            { target: { value: verification } },
        );
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeEnabled();
        expect(post).not.toHaveBeenCalled();
    });

    it.each([
        { ...resolution, code: 'workaround' },
        { ...resolution, summary: 'An unrelated explanation.' },
        { ...resolution, verification: 'A different check.' },
    ])(
        'does not accept acknowledgement for different resolution evidence: %j',
        async (saved) => {
            post.mockResolvedValueOnce({
                status: 200,
                data: {
                    ...committed,
                    data: { ...committed.data, resolution: saved },
                },
            });
            render(
                <ResolveTicketDialog
                    ticket={ticket}
                    onClose={() => undefined}
                />,
            );
            enter();
            submit();
            await screen.findByRole('button', {
                name: 'Review current ticket',
            });
            expect(
                screen.queryByText('Resolved', { exact: true }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('textbox', { name: /^How was it checked/ }),
            ).toHaveValue(verification);
        },
    );

    it('restores a legacy note without claiming its missing outcome or checks were recorded', async () => {
        navigation.drafts = true;
        const saved = metadata({
            has_content: true,
            revision: 1,
            saved_at: '2026-09-09T00:00:00Z',
        });
        request
            .mockResolvedValueOnce({ status: 200, data: { draft: saved } })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: saved,
                    payload: {
                        fields: { note, notify_requester: true },
                        step_index: 0,
                    },
                    attachments: [],
                },
            });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume saved draft' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toHaveValue(note),
        );
        expect(
            screen.getByRole('textbox', { name: /^How was it checked/ }),
        ).toHaveValue('');
        expect(
            screen.getByRole('button', { name: /Service restored/ }),
        ).toHaveAttribute('aria-pressed', 'false');
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    it.each([
        { ticket_id: 1, viewer_user_id: 10, linked: true },
        { ticket_id: 2, viewer_user_id: 10, linked: false },
        { ticket_id: 1, viewer_user_id: 11, linked: false },
    ])(
        'only exposes the current actor and ticket corrective link: %j',
        async ({ ticket_id, viewer_user_id, linked }) => {
            post.mockRejectedValue({
                response: {
                    status: 422,
                    data: {
                        code: 'ticket_resolution_blocked',
                        message: 'Required evidence needs review.',
                        blocker: {
                            ticket_id,
                            viewer_user_id,
                            kind: 'task',
                            record_id: 9,
                        },
                    },
                },
            });
            render(
                <ResolveTicketDialog
                    ticket={ticket}
                    onClose={() => undefined}
                />,
            );
            enter();
            submit();
            expect(
                await screen.findByText('Required evidence needs review.'),
            ).toBeVisible();
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toHaveValue(note);
            const link = screen.queryByRole('link', {
                name: 'Review required task (opens new tab)',
            });
            if (linked) {
                expect(link).toHaveAttribute(
                    'href',
                    '/it/tickets/1?tab=tasks#task-9',
                );
                expect(link).toHaveAttribute('target', '_blank');
            } else expect(link).not.toBeInTheDocument();
            expect(navigation.visit).not.toHaveBeenCalled();
        },
    );

    it('recovers the last unsaved note with persistence disabled, preserving its old base until a deliberate current-ticket review', async () => {
        const first = render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        fireEvent.change(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
            {
                target: { value: `${note} Last edit.` },
            },
        );
        first.unmount();
        post.mockImplementation(async (url, data) => {
            if (String(url).endsWith('/validate-local-candidate')) {
                const body = data as Record<string, unknown>;
                return {
                    status: 200,
                    data: {
                        candidate: {
                            kind: 'memory',
                            memory_uuid: body.memory_uuid,
                            candidate_uuid: body.candidate_uuid,
                            actor_user_id: 10,
                            purpose: 'public_resolution',
                            context_key: 'ticket:1',
                            base_ticket_version: body.base_ticket_version,
                            current_ticket_version: 4,
                            authorized: true,
                            capabilities: { submit: false },
                            blocker: {
                                code: 'stale_ticket',
                                message: 'Review the current ticket.',
                            },
                        },
                    },
                };
            }
            return {
                status: 200,
                data: {
                    status: 'committed',
                    data: {
                        id: 1,
                        lock_version: 6,
                        viewer_user_id: 10,
                        resolution: {
                            ...resolution,
                            summary: `${note} Last edit.`,
                        },
                    },
                },
            };
        });
        get.mockResolvedValue({ status: 200, data: current(5) });
        render(
            <ResolveTicketDialog
                ticket={{ ...ticket, lock_version: 4 }}
                onClose={() => undefined}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toHaveValue(`${note} Last edit.`),
        );
        expect(post.mock.calls[0][1]).toMatchObject({
            base_ticket_version: 3,
            fields: { note: `${note} Last edit.` },
        });
        expect(request).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Use this version and keep my note',
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Resolve ticket' }),
            ).toBeEnabled(),
        );
        submit();
        await screen.findByText('Resolved', { exact: true });
        expect(post.mock.calls[1][1]).toMatchObject({
            expected_version: 5,
            note: `${note} Last edit.`,
        });
    });

    it('retains the failed resolution note after a definitive 422 and later native-traversal unmount', async () => {
        post.mockRejectedValueOnce({
            response: {
                status: 422,
                data: { errors: { note: ['A prerequisite is incomplete.'] } },
            },
        });
        const first = render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        await screen.findAllByText('A prerequisite is incomplete.');
        first.unmount();
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        expect(
            screen.getByRole('button', { name: 'Resume browser work' }),
        ).toBeEnabled();
        expect(
            screen.queryByText(/earlier result unconfirmed/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue('');
    });

    it('resumes explicitly, preserves the saved base version, then saves a reviewed version and consumes the exact generation', async () => {
        navigation.drafts = true;
        const stale = metadata({
            revision: 1,
            has_content: true,
            base_ticket_version: 1,
            current_ticket_version: 3,
            capabilities: {
                read: true,
                save: true,
                submit: false,
                discard: true,
                start_new: false,
            },
            blocker: {
                code: 'stale_ticket',
                message: 'Review the current ticket version.',
            },
        });
        request
            .mockResolvedValueOnce({ status: 200, data: { draft: stale } })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: stale,
                    payload: {
                        fields: {
                            note,
                            resolution_code: 'restored',
                            resolution_verification: verification,
                            notify_requester: false,
                        },
                        step_index: 0,
                    },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: metadata({
                        revision: 2,
                        has_content: true,
                        saved_at: '2026-09-09T00:00:00Z',
                    }),
                },
            });
        get.mockResolvedValueOnce({ status: 200, data: current(3) });
        post.mockResolvedValueOnce({
            status: 200,
            data: {
                ...committed,
                data: {
                    ...committed.data,
                    draft: {
                        draft_uuid: draftUuid,
                        submitted_revision: 2,
                        revision: 3,
                        state: 'consumed',
                    },
                },
            },
        });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        const resume = await screen.findByRole('button', {
            name: 'Resume saved draft',
        });
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue('');
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(resume);
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toHaveValue(note),
        );
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        expect(request).toHaveBeenCalledTimes(2);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Use this version and keep my note',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Resolve ticket' }),
            ).toBeEnabled(),
        );
        expect(request.mock.calls[2][0]).toMatchObject({
            method: 'patch',
            data: {
                expected_revision: 1,
                fields: { note, notify_requester: false },
                base_ticket_version: 3,
            },
        });
        submit();
        await screen.findByText('Resolved', { exact: true });
        expect(post.mock.calls[0][1]).toMatchObject({
            note,
            expected_version: 3,
            draft_uuid: draftUuid,
            draft_revision: 2,
            draft_actor_user_id: 10,
        });
    });

    it('autosaves an exact snapshot and blocks draft mutation after an unknown resolve acknowledgement', async () => {
        navigation.drafts = true;
        request
            .mockResolvedValueOnce({ status: 200, data: { draft: metadata() } })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: metadata({
                        revision: 1,
                        has_content: true,
                        saved_at: '2026-09-09T00:00:00Z',
                    }),
                },
            });
        // A valid resolution result without its submitted draft consumption is incomplete.
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toBeEnabled(),
        );
        enter();
        await waitFor(() => expect(request).toHaveBeenCalledTimes(2), {
            timeout: 2000,
        });
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Resolve ticket' }),
            ).toBeEnabled(),
        );
        submit();
        await screen.findByText(/resolution result is unknown/i);
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Discard saved draft' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toBeDisabled();
        expect(request).toHaveBeenCalledTimes(2);
        const departure = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(departure);
        expect(departure.defaultPrevented).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(await screen.findByRole('alertdialog')).toHaveTextContent(
            'Leave with an unconfirmed outcome?',
        );
    });

    it('allows closing an acknowledged saved draft without falsely discarding it', async () => {
        navigation.drafts = true;
        request
            .mockResolvedValueOnce({ status: 200, data: { draft: metadata() } })
            .mockResolvedValueOnce({
                status: 200,
                data: {
                    draft: metadata({
                        revision: 1,
                        has_content: true,
                        saved_at: '2026-09-09T00:00:00Z',
                    }),
                },
            });
        const close = vi.fn();
        render(<ResolveTicketDialog ticket={ticket} onClose={close} />);
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toBeEnabled(),
        );
        enter();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await screen.findByText('Draft saved.');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(close).toHaveBeenCalledOnce();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(
            request.mock.calls.some(([call]) => call.method === 'delete'),
        ).toBe(false);
    });

    it('purges the note after current saved-draft access is revoked', async () => {
        navigation.drafts = true;
        request
            .mockResolvedValueOnce({ status: 200, data: { draft: metadata() } })
            .mockRejectedValueOnce({
                response: { status: 403, data: { code: 'access_unavailable' } },
            });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toBeEnabled(),
        );
        enter();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await screen.findByRole('link', { name: 'Reload ticket' });
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
    it('requires a meaningful note and a strictly committed result before showing success', async () => {
        const close = vi.fn();
        render(<ResolveTicketDialog ticket={ticket} onClose={close} />);
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        enter();
        submit();
        await screen.findByText('Resolved', { exact: true });
        expect(post).toHaveBeenCalledWith(
            '/it/tickets/1/resolve',
            {
                note,
                resolution_code: 'restored',
                resolution_verification: verification,
                notify_requester: true,
                expected_version: 3,
                actor_user_id: 10,
            },
            expect.objectContaining({
                headers: { Accept: 'application/json' },
                signal: expect.any(AbortSignal),
            }),
        );
        expect(
            screen.getByRole('link', { name: 'View resolved ticket' }),
        ).toHaveAttribute('href', '/it/tickets/1');
        expect(
            screen.queryByText(
                /requester has been emailed|auto-closes in 7 days/i,
            ),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(close).toHaveBeenCalledOnce();
        expect(navigation.reload).toHaveBeenCalledOnce();
    });

    it('preserves failed text and focuses the real required-task blocker', async () => {
        post.mockRejectedValueOnce({
            response: {
                status: 422,
                data: {
                    code: 'ticket_resolution_blocked',
                    message:
                        'Complete the required recovery check before resolving.',
                },
            },
        });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        const alert = await screen.findByRole('alert');
        await waitFor(() => expect(alert).toHaveFocus());
        expect(alert).toHaveTextContent('Complete the required recovery check');
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).not.toBeDisabled();
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
    });

    it.each([
        {},
        { status: 'committed', data: { id: 2, lock_version: 4 } },
        { status: 'committed', data: { id: 1, lock_version: 3 } },
    ])(
        'does not treat a malformed acknowledgement as success %#',
        async (data) => {
            post.mockResolvedValueOnce({ status: 200, data });
            render(
                <ResolveTicketDialog
                    ticket={ticket}
                    onClose={() => undefined}
                />,
            );
            enter();
            submit();
            await screen.findByText(/resolution result is unknown/i);
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toHaveValue(note);
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toBeDisabled();
            expect(
                screen.queryByText('Resolved', { exact: true }),
            ).not.toBeInTheDocument();
        },
    );

    it('reviews a conflict without resubmitting and requires deliberate current-version adoption', async () => {
        post.mockRejectedValueOnce({
            response: { status: 409, data: { code: 'stale_ticket' } },
        }).mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'committed',
                data: {
                    id: 1,
                    lock_version: 6,
                    viewer_user_id: 10,
                    resolution,
                },
            },
        });
        get.mockResolvedValueOnce({ status: 200, data: current(5) });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Review current ticket',
            }),
        );
        await screen.findByRole('region', { name: 'Current ticket review' });
        expect(post).toHaveBeenCalledTimes(1);
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use this version and keep my note',
            }),
        );
        submit();
        await screen.findByText('Resolved', { exact: true });
        expect(post.mock.calls[1][1]).toMatchObject({
            note,
            expected_version: 5,
        });
    });

    it('requires HTTP 200 as well as a committed body', async () => {
        post.mockResolvedValueOnce({ status: 202, data: committed });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        await screen.findByText(/resolution result is unknown/i);
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
    });

    it('keeps current-version adoption unavailable after an unconfirmed review response', async () => {
        post.mockRejectedValueOnce(new Error('Connection lost'));
        get.mockResolvedValueOnce({ status: 202, data: current(5) });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Review current ticket',
            }),
        );
        await screen.findByText(/Current details could not be confirmed/);
        expect(
            screen.queryByRole('button', {
                name: 'Use this version and keep my note',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('never infers an unknown request succeeded from an already-resolved current record', async () => {
        post.mockRejectedValueOnce(new Error('Connection lost'));
        get.mockResolvedValueOnce({
            status: 200,
            data: current(5, 'resolved'),
        });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Review current ticket',
            }),
        );
        await screen.findByText(/already settled/);
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Use this version and keep my note',
            }),
        ).not.toBeInTheDocument();
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('restores an uncertain resolution after traversal without permitting another submission before explicit review', async () => {
        post.mockRejectedValueOnce(new Error('Response lost'));
        const first = render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        await screen.findByText(/resolution result is unknown/);
        first.unmount();
        post.mockImplementation(async (_url, input) => {
            const body = input as Record<string, unknown>;
            return {
                status: 200,
                data: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: body.memory_uuid,
                        candidate_uuid: body.candidate_uuid,
                        actor_user_id: 10,
                        purpose: 'public_resolution',
                        context_key: 'ticket:1',
                        base_ticket_version: body.base_ticket_version,
                        current_ticket_version: 3,
                        authorized: true,
                        capabilities: { submit: true },
                        blocker: null,
                    },
                },
            };
        });
        get.mockResolvedValue({ status: 200, data: current(3) });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue('');
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: /^Resolution note/ }),
            ).toHaveValue(note),
        );
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Resolve ticket' }),
        ).toBeDisabled();
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Use this version and keep my note',
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Resolve ticket' }),
            ).toBeEnabled(),
        );
        expect(post).toHaveBeenCalledTimes(2); // failed resolve + permission proof; no implicit submission
    });

    it('cancels only the wait and ignores a late successful response', async () => {
        let resolve!: (value: { data: typeof committed }) => void;
        post.mockReturnValueOnce(
            new Promise((yes) => {
                resolve = yes;
            }),
        );
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel wait' }));
        expect(post.mock.calls[0][2]?.signal?.aborted).toBe(true);
        await act(async () => resolve({ data: committed }));
        expect(
            screen.queryByText('Resolved', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
        expect(screen.getByText(/wait was cancelled/)).toBeVisible();
    });

    it('requires a dirty-close decision and Cancel retains the note', async () => {
        const close = vi.fn();
        const form = render(
            <ResolveTicketDialog ticket={ticket} onClose={close} />,
        );
        enter();
        fireEvent.keyDown(document, { key: 'Escape' });
        const dialog = await screen.findByRole('alertdialog');
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue(note);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Discard and close',
            }),
        );
        expect(close).toHaveBeenCalledOnce();
        form.unmount();
        render(<ResolveTicketDialog ticket={ticket} onClose={close} />);
        expect(
            screen.queryByRole('button', { name: 'Resume browser work' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue('');
    });

    it('conceals the note on session loss and purges it if a different account reviews', async () => {
        post.mockRejectedValueOnce({ response: { status: 419, data: {} } });
        get.mockResolvedValueOnce({
            status: 200,
            data: current(5, 'in_progress', 11),
        });
        render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        submit();
        await screen.findByRole('link', { name: 'Sign in' });
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        const leave = await screen.findByRole('alertdialog');
        expect(leave).toHaveTextContent('Leave with an unconfirmed outcome?');
        fireEvent.click(within(leave).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        await screen.findByRole('link', { name: 'Reload ticket' });
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.getByText(/signed-in account changed/)).toBeVisible();
    });

    it('immediately removes the local buffer when the page actor changes', () => {
        const { rerender } = render(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        enter();
        navigation.actor = 11;
        rerender(
            <ResolveTicketDialog ticket={ticket} onClose={() => undefined} />,
        );
        expect(
            screen.getByRole('textbox', { name: /^Resolution note/ }),
        ).toHaveValue('');
    });
});
