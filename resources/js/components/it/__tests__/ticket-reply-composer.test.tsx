import { Button } from '@/components/ui/button';
import type { ItCommentCommitted } from '@/hooks/it-ticket-comment-contract';
import type { ItDraftBrowserRestored } from '@/hooks/use-it-ticket-draft';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketReplyComposer } from '../ticket-reply-composer';

const mocks = vi.hoisted(() => ({
    commands: new Map(),
    drafts: new Map(),
    draftControls: new Map(),
    recoveries: new Map(),
    toast: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { success: mocks.toast } }));
vi.mock('@/components/it/ticket-draft-recovery', () => ({
    TicketDraftRecovery: ({
        draft,
        onResumeMemory,
        onStartNew,
        nextDraftAction,
    }: {
        draft: {
            testPurpose: string;
            state: string;
            startNew: () => Promise<boolean>;
        };
        onResumeMemory: (restored: ItDraftBrowserRestored) => void;
        onStartNew: () => void;
        nextDraftAction?: {
            label: string;
            onClick: () => void;
            disabled?: boolean;
        };
    }) => (
        <>
            {mocks.recoveries.has(draft.testPurpose) ? (
                <Button
                    onClick={() =>
                        onResumeMemory(mocks.recoveries.get(draft.testPurpose))
                    }
                >
                    Resume browser work
                </Button>
            ) : null}
            {draft.state === 'terminal' && nextDraftAction && (
                <Button
                    disabled={nextDraftAction.disabled}
                    onClick={nextDraftAction.onClick}
                >
                    {nextDraftAction.label}
                </Button>
            )}
            {draft.state === 'terminal' && !nextDraftAction && (
                <Button
                    onClick={async () => {
                        if (await draft.startNew()) onStartNew();
                    }}
                >
                    Start a new saved draft
                </Button>
            )}
        </>
    ),
}));
vi.mock('@/components/it/ticket-intake-draft', () => ({
    TicketDraftFiles: () => null,
}));
vi.mock('@/hooks/use-it-ticket-draft', async () => {
    const React = await import('react');
    return {
        useItTicketDraft: (options: {
            enabled: boolean;
            context: { purpose: string };
            onAccessLost: () => void;
        }) => {
            const [state, update] = React.useState(
                options.enabled ? 'ready' : 'disabled',
            );
            const control = React.useRef({
                clearOwned: vi.fn(),
                clearScope: vi.fn(),
                save: vi.fn().mockResolvedValue(true),
                startNew: vi.fn().mockResolvedValue(true),
                check: vi
                    .fn()
                    .mockResolvedValue({ capabilities: { read: true } }),
                register: vi.fn().mockReturnValue(true),
                acknowledge: vi.fn().mockReturnValue(true),
                expire: () => {},
                deny: () => {},
            });
            control.current.expire = () => update('session_expired');
            control.current.deny = () => {
                update('access_denied');
                options.onAccessLost();
            };
            mocks.draftControls.set(options.context.purpose, control.current);
            mocks.drafts.set(options.context.purpose, options);
            return {
                state,
                testPurpose: options.context.purpose,
                attachments: [],
                memoryNotices: [],
                errors: {},
                busy: false,
                memoryBlocked: false,
                browserBlocker: null,
                browserOutcomeUnknown: false,
                clearOwnedBrowserWork: control.current.clearOwned,
                clearBrowserWork: control.current.clearScope,
                isSaved: () => options.enabled,
                save: control.current.save,
                check: async () => {
                    const result = await control.current.check();
                    if (
                        ['consumed', 'discarded', 'expired'].includes(
                            result?.state,
                        )
                    )
                        update('terminal');
                    else if (result?.capabilities.read)
                        update(result.has_content ? 'available' : 'ready');
                    return result;
                },
                startNew: async () => {
                    const started = await control.current.startNew();
                    if (started) update('ready');
                    return started;
                },
                submissionReference: () => ({
                    draft_uuid: 'aaf5b224-7de3-45e9-886b-99662edcf0a3',
                    draft_revision: 2,
                    draft_actor_user_id: 3,
                }),
                registerRecoveredSubmission: control.current.register,
                acknowledgeConsumed: (...args: unknown[]) => {
                    const accepted = control.current.acknowledge(...args);
                    if (accepted) update('terminal');
                    return accepted;
                },
                acknowledgeReviewedBrowserWork: vi.fn().mockReturnValue(true),
            };
        },
    };
});
vi.mock('@/hooks/use-it-ticket-comment-command', async () => {
    const React = await import('react');
    return {
        useItTicketCommentCommand: (options: {
            isInternal: boolean;
            onCommitted: (result: ItCommentCommitted) => void;
            onAccessLost: () => void;
        }) => {
            const [state, update] = React.useState({
                stage: 'editing',
                references: [] as string[],
                frozenIntent: null as unknown,
                message: null as string | null,
                concealed: false,
                cancelled: null as { request_uuid: string } | null,
            });
            const control = React.useRef({
                submit: vi.fn(),
                cancel: vi.fn(),
                complete: (_result: ItCommentCommitted) => {},
                unknown: () => {},
                denied: () => {},
                prepare: vi.fn(),
                adopt: vi.fn().mockResolvedValue(true),
                accessCheck: vi.fn().mockResolvedValue(true),
                externalDenied: vi.fn(),
                release: vi.fn().mockReturnValue(true),
            });
            control.current.complete = (result) => {
                update((old) => ({
                    ...old,
                    stage: 'committed',
                    references: [],
                    frozenIntent: null,
                }));
                options.onCommitted(result);
            };
            control.current.unknown = () =>
                update((old) => ({
                    ...old,
                    stage: 'unknown',
                    message: 'The reply outcome is unconfirmed.',
                }));
            control.current.denied = () => {
                update((old) => ({ ...old, stage: 'access', concealed: true }));
                options.onAccessLost();
            };
            mocks.commands.set(
                options.isInternal ? 'internal' : 'public',
                control.current,
            );
            return {
                ...state,
                result: null,
                errors: {},
                settledOperationToken: 0,
                busy: state.stage === 'sending',
                canRetryExact: state.stage === 'unknown',
                requestUuid: state.references[0] ?? null,
                submit: (input: unknown) => {
                    control.current.submit(input);
                    update((old) => ({
                        ...old,
                        stage: 'sending',
                        references: ['frozen-reference'],
                        frozenIntent: input,
                    }));
                    return true;
                },
                prepareNext: () => {
                    control.current.prepare();
                    update({
                        stage: 'editing',
                        references: [],
                        frozenIntent: null,
                        message: null,
                        concealed: false,
                        cancelled: null,
                    });
                    return true;
                },
                stopWaiting: control.current.unknown,
                cancelReference: async (id: string) => {
                    const result = await control.current.cancel(id);
                    if (result?.comment_id) {
                        control.current.complete(result);
                        return true;
                    }
                    update((old) => ({
                        ...old,
                        stage: 'rejected',
                        references: [],
                        frozenIntent: null,
                        cancelled: { request_uuid: id },
                    }));
                    return true;
                },
                recoverReference: vi.fn(),
                retryExact: vi.fn(),
                checkCurrentAccess: async () => {
                    const verified = await control.current.accessCheck();
                    if (verified)
                        update((old) => ({
                            ...old,
                            concealed: false,
                            stage:
                                old.stage === 'rejected'
                                    ? 'rejected'
                                    : old.references.length
                                      ? 'unknown'
                                      : 'editing',
                        }));
                    return verified;
                },
                releaseKnownRejection: (version: number) => {
                    const released = control.current.release(version);
                    if (released)
                        update((old) => ({
                            ...old,
                            stage: 'editing',
                            references: [],
                            frozenIntent: null,
                        }));
                    return released;
                },
                denyCurrentAccess: () => {
                    control.current.externalDenied();
                    update((old) => ({
                        ...old,
                        stage: 'access',
                        concealed: true,
                        frozenIntent: null,
                    }));
                },
                adoptAuthorizedIntent: async (candidate: unknown) => {
                    const accepted = await control.current.adopt(candidate);
                    if (accepted)
                        update((old) => ({
                            ...old,
                            stage: 'unknown',
                            references: ['frozen-reference'],
                            frozenIntent: candidate,
                        }));
                    return accepted;
                },
            };
        },
    };
});

const props = {
    actorId: 3,
    ticketId: 4,
    expectedVersion: 7,
    draftsEnabled: false,
    canInternal: true,
};
const committed: ItCommentCommitted = {
    id: 4,
    canonical_ticket_id: 4,
    comment_id: 20,
    viewer_user_id: 3,
    request_uuid: 'b4f08c8d-0797-4789-905a-584942d0ec88',
    is_internal: false,
    lock_version: 9,
    replayed: false,
    delivery: { requested: true, attempt_statuses: { failed: 1 } },
};
const write = (value: string) =>
    fireEvent.change(screen.getByRole('textbox', { name: 'Your reply' }), {
        target: { value },
    });
beforeEach(() => {
    mocks.commands.clear();
    mocks.drafts.clear();
    mocks.draftControls.clear();
    mocks.recoveries.clear();
    mocks.toast.mockReset();
});
afterEach(() => vi.unstubAllGlobals());

const restoredReply = (withDraft = false): ItDraftBrowserRestored => ({
    snapshot: {
        fields: { body: 'Newer unsent browser text' },
        step_index: 0,
        base_ticket_version: 7,
    },
    files: [new File(['latest file'], 'newer.txt')],
    outcomeUnknown: true,
    canonicalOutcomeUnknown: true,
    current_ticket_version: 9,
    blocker: null,
    pendingComment: {
        actorId: 3,
        ticketId: 4,
        requestUuid: committed.request_uuid,
        expectedVersion: 5,
        isInternal: false,
        body: 'Original submitted text',
        files: [new File(['original file'], 'original.txt')],
        ...(withDraft
            ? {
                  draft: {
                      draft_uuid: 'aaf5b224-7de3-45e9-886b-99662edcf0a3',
                      draft_revision: 2,
                      draft_actor_user_id: 3,
                  },
              }
            : {}),
    },
});
const currentTicket = (version = 9) => ({
    ok: true,
    status: 200,
    json: async () => ({
        viewer_user_id: 3,
        can: { comment: true, internal: true },
        ticket: {
            id: 4,
            reference: 'IT-4',
            title: 'Current synthetic ticket',
            lock_version: version,
            status: 'open',
            priority: 'medium',
            assignee: null,
        },
    }),
});
const consumedDraft = (internal = false) => ({
    draft_uuid: 'aaf5b224-7de3-45e9-886b-99662edcf0a3',
    revision: 3,
    state: 'consumed',
    purpose: internal ? 'internal_note' : 'public_reply',
    audience: internal ? 'internal' : 'public',
    ticket_id: 4,
    context_key: 'ticket:4',
    has_content: false,
    capabilities: { read: false, start_new: true },
    blocker: null,
});
const finishSavedMessage = async (internal = false) => {
    if (internal)
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
    fireEvent.change(
        screen.getByRole('textbox', {
            name: internal ? 'Internal note' : 'Your reply',
        }),
        { target: { value: 'First saved message' } },
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: internal ? 'Add note' : 'Add reply',
        }),
    );
    const command = mocks.commands.get(internal ? 'internal' : 'public');
    await waitFor(() => expect(command.submit).toHaveBeenCalledOnce());
    act(() =>
        command.complete({
            ...committed,
            is_internal: internal,
            draft: {
                draft_uuid: consumedDraft().draft_uuid,
                submitted_revision: 2,
                revision: 3,
                state: 'consumed',
            },
        }),
    );
    const button = await screen.findByRole('button', {
        name: internal ? 'Add another internal note' : 'Write another reply',
    });
    return {
        command,
        button,
        draft: mocks.draftControls.get(
            internal ? 'internal_note' : 'public_reply',
        ),
    };
};

describe('ticket reply composer audience and commit lifecycle', () => {
    it.each([false, true])(
        'starts the next matching audience draft through one explicit action (internal %s)',
        async (internal) => {
            render(<TicketReplyComposer {...props} draftsEnabled />);
            const { command, draft, button } =
                await finishSavedMessage(internal);
            const textarea = screen.getByRole('textbox', {
                name: internal ? 'Internal note' : 'Your reply',
            });
            expect(textarea).toBeDisabled();
            expect(draft.check).not.toHaveBeenCalled();
            draft.check.mockResolvedValue(consumedDraft(internal));
            fireEvent.click(button);
            await waitFor(() => expect(textarea).toBeEnabled());
            expect(draft.check).toHaveBeenCalledOnce();
            expect(draft.startNew).toHaveBeenCalledOnce();
            expect(textarea).toHaveValue('');
            await waitFor(() => expect(textarea).toHaveFocus());
            expect(command.submit).toHaveBeenCalledOnce();
            expect(
                mocks.draftControls.get(
                    internal ? 'public_reply' : 'internal_note',
                ).startNew,
            ).not.toHaveBeenCalled();
            fireEvent.change(textarea, {
                target: { value: 'Second distinct message' },
            });
            fireEvent.click(
                screen.getByRole('button', {
                    name: internal ? 'Add note' : 'Add reply',
                }),
            );
            await waitFor(() =>
                expect(command.submit).toHaveBeenCalledTimes(2),
            );
            expect(command.submit.mock.calls[1][0]).toMatchObject({
                body: 'Second distinct message',
                expectedVersion: 9,
            });
        },
    );
    it.each([
        { draft_uuid: '3a966432-a1ce-40ef-8e9c-ac2716e1ecdd' },
        { revision: 4 },
        {
            state: 'active',
            has_content: true,
            capabilities: { read: true, start_new: false },
        },
        { audience: 'internal' },
        { capabilities: { read: false, start_new: false } },
        { blocker: { code: 'ticket_settled', message: 'Reopen this ticket.' } },
    ])(
        'does not rotate a mismatched, active or blocked saved generation: %j',
        async (change) => {
            render(<TicketReplyComposer {...props} draftsEnabled />);
            const { draft, button } = await finishSavedMessage();
            draft.check.mockResolvedValue({ ...consumedDraft(), ...change });
            fireEvent.click(button);
            await screen.findByText(/The saved draft changed/);
            expect(draft.startNew).not.toHaveBeenCalled();
            expect(
                screen.queryByRole('button', { name: 'Write another reply' }),
            ).not.toBeInTheDocument();
        },
    );
    it('keeps a failed next-message check retryable without rotating or claiming readiness', async () => {
        render(<TicketReplyComposer {...props} draftsEnabled />);
        const { draft, button } = await finishSavedMessage();
        draft.check.mockResolvedValue(null);
        fireEvent.click(button);
        await waitFor(() => expect(button).toBeEnabled());
        expect(draft.startNew).not.toHaveBeenCalled();
        expect(
            screen.getByRole('textbox', { name: 'Your reply' }),
        ).toBeDisabled();
        draft.check.mockResolvedValue(consumedDraft());
        fireEvent.click(button);
        await waitFor(() => expect(draft.startNew).toHaveBeenCalledOnce());
    });
    it.each(['session', 'access', 'unmount'])(
        'ignores a late successful next-message check after %s loss',
        async (loss) => {
            const { unmount } = render(
                <TicketReplyComposer {...props} draftsEnabled />,
            );
            const { draft, button } = await finishSavedMessage();
            let resolve!: (value: ReturnType<typeof consumedDraft>) => void;
            draft.check.mockImplementation(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
            fireEvent.click(button);
            if (loss === 'unmount') unmount();
            else
                act(() => (loss === 'session' ? draft.expire() : draft.deny()));
            await act(async () => resolve(consumedDraft()));
            expect(draft.startNew).not.toHaveBeenCalled();
        },
    );
    it('preserves a newer File selection when a recovered original reply has the same body and base version', async () => {
        const restored = restoredReply();
        restored.snapshot = {
            fields: { body: restored.pendingComment!.body },
            step_index: 0,
            base_ticket_version: 5,
        };
        mocks.recoveries.set('public_reply', restored);
        render(<TicketReplyComposer {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(screen.getByText('newer.txt')).toBeVisible(),
        );
        act(() =>
            mocks.commands
                .get('public')
                .complete({ ...committed, replayed: true }),
        );
        await waitFor(() =>
            expect(mocks.commands.get('public').prepare).toHaveBeenCalled(),
        );
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Original submitted text',
        );
        expect(screen.getByText('newer.txt')).toBeVisible();
        expect(mocks.drafts.get('public_reply').workingFiles[0]).toBe(
            restored.files[0],
        );
        expect(
            mocks.draftControls.get('public_reply').clearOwned,
        ).not.toHaveBeenCalled();
    });
    it.each([false, true])(
        'a cancellation finding a committed reply preserves the ACK hold until explicit review (saved drafts %s)',
        async (draftsEnabled) => {
            const restored = restoredReply(true);
            restored.snapshot = {
                fields: { body: restored.pendingComment!.body },
                step_index: 0,
                base_ticket_version: 5,
            };
            restored.files = [...restored.pendingComment!.files];
            mocks.recoveries.set('public_reply', restored);
            render(
                <TicketReplyComposer
                    {...props}
                    draftsEnabled={draftsEnabled}
                />,
            );
            mocks.draftControls
                .get('public_reply')
                .acknowledge.mockReturnValue(false);
            mocks.draftControls.get('public_reply').check.mockResolvedValue({
                state: 'consumed',
                capabilities: { read: false, start_new: true },
            });
            fireEvent.click(
                screen.getByRole('button', { name: 'Resume browser work' }),
            );
            await waitFor(() =>
                expect(
                    screen.getByRole('textbox', { name: 'Your reply' }),
                ).toHaveValue('Original submitted text'),
            );
            mocks.commands.get('public').cancel.mockResolvedValue({
                ...committed,
                replayed: true,
                draft: {
                    draft_uuid: restored.pendingComment!.draft!.draft_uuid,
                    submitted_revision: 2,
                    revision: 3,
                    state: 'consumed',
                },
            });
            fireEvent.click(
                screen.getByRole('button', { name: 'Cancel pending reply 1' }),
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Check and cancel pending reply',
                }),
            );
            await waitFor(() =>
                expect(
                    screen.getByText(
                        'The reply was added, but its saved draft could not be matched here. Review the saved draft before starting another reply.',
                    ),
                ).toBeVisible(),
            );
            expect(mocks.commands.get('public').prepare).not.toHaveBeenCalled();
            expect(
                mocks.draftControls.get('public_reply').clearOwned,
            ).not.toHaveBeenCalled();
            expect(
                screen.getByRole('textbox', { name: 'Your reply' }),
            ).toHaveValue('Original submitted text');
            expect(
                screen.getByRole('button', { name: 'Add reply' }),
            ).toBeDisabled();
            await waitFor(() =>
                expect(
                    screen.queryByRole('alertdialog'),
                ).not.toBeInTheDocument(),
            );
            const reviewFetch = vi.fn().mockResolvedValue(currentTicket());
            vi.stubGlobal('fetch', reviewFetch);
            await waitFor(() =>
                expect(
                    screen.getByRole('button', {
                        name: 'Review current ticket',
                    }),
                ).toBeEnabled(),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Review current ticket' }),
            );
            await waitFor(() =>
                expect(reviewFetch).toHaveBeenCalledWith(
                    `/it/tickets/${props.ticketId}`,
                    expect.objectContaining({ cache: 'no-store' }),
                ),
            );
            await waitFor(() =>
                expect(
                    screen.getByRole('button', {
                        name: 'Use this version and keep my draft',
                    }),
                ).toBeVisible(),
            );
            expect(mocks.commands.get('public').prepare).not.toHaveBeenCalled();
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Use this version and keep my draft',
                }),
            );
            await waitFor(() =>
                expect(
                    mocks.commands.get('public').prepare,
                ).toHaveBeenCalledOnce(),
            );
            expect(
                screen.getByRole('textbox', { name: 'Your reply' }),
            ).toHaveValue('Original submitted text');
            expect(screen.getByText('original.txt')).toBeVisible();
            if (draftsEnabled) {
                expect(
                    screen.getByRole('button', { name: 'Add reply' }),
                ).toBeDisabled();
                expect(
                    mocks.draftControls.get('public_reply').startNew,
                ).not.toHaveBeenCalled();
                fireEvent.click(
                    screen.getByRole('button', {
                        name: 'Start a new saved draft',
                    }),
                );
                await waitFor(() =>
                    expect(
                        screen.getByRole('button', { name: 'Add reply' }),
                    ).not.toBeDisabled(),
                );
                expect(
                    screen.getByRole('textbox', { name: 'Your reply' }),
                ).toHaveValue('Original submitted text');
                expect(screen.getByText('original.txt')).toBeVisible();
            }
            expect(
                screen.getByRole('button', { name: 'Add reply' }),
            ).not.toBeDisabled();
            expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
        },
    );
    it.each([{ actorId: 8 }, { ticketId: 12 }])(
        'replaces both audience forms when scope changes %j without rebinding old private work',
        (change) => {
            const view = render(<TicketReplyComposer {...props} />);
            write('Old actor public text');
            fireEvent.change(
                view.container.querySelector('input[type=file]')!,
                { target: { files: [new File(['x'], 'old-public.txt')] } },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Internal note' }),
            );
            fireEvent.change(
                screen.getByRole('textbox', { name: 'Internal note' }),
                { target: { value: 'Old actor internal text' } },
            );
            view.rerender(<TicketReplyComposer {...props} {...change} />);
            expect(
                screen.queryByDisplayValue('Old actor public text'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByDisplayValue('Old actor internal text'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('old-public.txt'),
            ).not.toBeInTheDocument();
            expect(
                mocks.drafts.get('public_reply').workingSnapshot.fields.body,
            ).toBe('');
            expect(
                mocks.drafts.get('internal_note').workingSnapshot.fields.body,
            ).toBe('');
            expect(mocks.drafts.get('public_reply').workingFiles).toEqual([]);
        },
    );

    it.each(['access', 'actor'] as const)(
        'purges both audience buffers when the host confirms %s loss',
        (accessState) => {
            const view = render(<TicketReplyComposer {...props} />);
            write('Public text before host denial');
            fireEvent.change(
                view.container.querySelector('input[type=file]')!,
                {
                    target: {
                        files: [new File(['fixture'], 'host-denied.txt')],
                    },
                },
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Internal note',
                }),
            );
            fireEvent.change(
                screen.getByRole('textbox', {
                    name: 'Internal note',
                }),
                {
                    target: { value: 'Internal text before host denial' },
                },
            );
            view.rerender(
                <TicketReplyComposer {...props} accessState={accessState} />,
            );
            expect(view.container.querySelector('textarea')).toBeNull();
            expect(
                screen.queryByText('host-denied.txt'),
            ).not.toBeInTheDocument();
            for (const purpose of ['public_reply', 'internal_note']) {
                expect(
                    mocks.draftControls.get(purpose).clearScope,
                ).toHaveBeenCalled();
                expect(
                    mocks.drafts.get(purpose).workingSnapshot.fields.body,
                ).toBe('');
                expect(mocks.drafts.get(purpose).workingFiles).toEqual([]);
            }
            expect(
                mocks.commands.get('public').externalDenied,
            ).toHaveBeenCalled();
            expect(
                mocks.commands.get('internal').externalDenied,
            ).toHaveBeenCalled();
            expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
            expect(mocks.toast).not.toHaveBeenCalled();
        },
    );

    it('conceals session-bound work and restores both audiences only after the host clears the session blocker', () => {
        const view = render(<TicketReplyComposer {...props} />);
        write('Public text retained for the same account');
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Internal note' }),
            {
                target: { value: 'Private text retained for the same account' },
            },
        );
        view.rerender(<TicketReplyComposer {...props} accessState="session" />);
        expect(view.container.querySelector('textarea')).toBeNull();
        for (const purpose of ['public_reply', 'internal_note']) {
            expect(
                mocks.draftControls.get(purpose).clearScope,
            ).not.toHaveBeenCalled();
        }
        expect(
            mocks.commands.get('public').externalDenied,
        ).not.toHaveBeenCalled();
        view.rerender(<TicketReplyComposer {...props} accessState={null} />);
        expect(
            screen.getByRole('textbox', { name: 'Internal note' }),
        ).toHaveValue('Private text retained for the same account');
        fireEvent.click(screen.getByRole('button', { name: 'Reply · draft' }));
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Public text retained for the same account',
        );
        expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
    });

    it('refreshes the committed conversation while retaining an unmatched saved draft', async () => {
        const refresh = vi.fn();
        render(
            <TicketReplyComposer {...props} draftsEnabled onPosted={refresh} />,
        );
        mocks.draftControls
            .get('public_reply')
            .acknowledge.mockReturnValue(false);
        write('Keep this draft until its acknowledgement is reviewed');
        fireEvent.click(screen.getByRole('button', { name: 'Add reply' }));
        await waitFor(() =>
            expect(mocks.commands.get('public').submit).toHaveBeenCalledOnce(),
        );
        act(() =>
            mocks.commands.get('public').complete({
                ...committed,
                draft: {
                    draft_uuid: 'aaf5b224-7de3-45e9-886b-99662edcf0a3',
                    submitted_revision: 2,
                    revision: 3,
                    state: 'consumed',
                },
            }),
        );
        expect(refresh).toHaveBeenCalledOnce();
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Keep this draft until its acknowledgement is reviewed',
        );
        expect(
            mocks.draftControls.get('public_reply').clearOwned,
        ).not.toHaveBeenCalled();
        expect(mocks.commands.get('public').prepare).not.toHaveBeenCalled();
        expect(
            screen.getByText(
                'The reply was added, but its saved draft could not be matched here. Review the saved draft before starting another reply.',
            ),
        ).toBeVisible();
    });

    it('purges confirmed denied text and files from the current audience RAM input', () => {
        const view = render(<TicketReplyComposer {...props} />);
        write('Denied message text');
        fireEvent.change(view.container.querySelector('input[type=file]')!, {
            target: { files: [new File(['x'], 'denied.txt')] },
        });
        const drafts = mocks.draftControls.get('public_reply');
        act(() => mocks.commands.get('public').denied());
        expect(drafts.clearScope).toHaveBeenCalled();
        expect(
            mocks.drafts.get('public_reply').workingSnapshot.fields.body,
        ).toBe('');
        expect(mocks.drafts.get('public_reply').workingFiles).toEqual([]);
        expect(
            screen.queryByDisplayValue('Denied message text'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('denied.txt')).not.toBeInTheDocument();
    });

    it('conceals and purges both audiences after generic403 until each receives fresh access proof', () => {
        render(<TicketReplyComposer {...props} />);
        write('Public actor-bound text');
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Internal note' }),
            { target: { value: 'Private sibling text' } },
        );
        act(() => mocks.commands.get('public').denied());
        expect(
            screen.queryByDisplayValue('Private sibling text'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('textbox', { name: 'Internal note' }),
        ).not.toBeInTheDocument();
        expect(
            mocks.draftControls.get('public_reply').clearScope,
        ).toHaveBeenCalled();
        expect(
            mocks.draftControls.get('internal_note').clearScope,
        ).toHaveBeenCalled();
        expect(
            mocks.drafts.get('internal_note').workingSnapshot.fields.body,
        ).toBe('');
        expect(
            screen.getByRole('button', { name: 'Review current ticket' }),
        ).toBeVisible();
    });

    it('registers the original pending body and version while retaining newer RAM text after commit', async () => {
        const restored = restoredReply(true);
        mocks.recoveries.set('public_reply', restored);
        render(<TicketReplyComposer {...props} />);
        const drafts = mocks.draftControls.get('public_reply');
        drafts.acknowledge.mockReturnValue(false);
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Your reply' }),
            ).toHaveValue('Newer unsent browser text'),
        );
        expect(mocks.commands.get('public').adopt).toHaveBeenCalledWith(
            restored.pendingComment,
        );
        expect(drafts.register).toHaveBeenCalledWith(
            {
                fields: { body: 'Original submitted text' },
                step_index: 0,
                base_ticket_version: 5,
            },
            restored.pendingComment!.draft,
        );
        expect(
            mocks.drafts.get('public_reply').workingSnapshot
                .base_ticket_version,
        ).toBe(7);
        act(() =>
            mocks.commands.get('public').complete({
                ...committed,
                replayed: true,
                draft: {
                    draft_uuid: restored.pendingComment!.draft!.draft_uuid,
                    submitted_revision: 2,
                    revision: 3,
                    state: 'consumed',
                },
            }),
        );
        await waitFor(() =>
            expect(mocks.commands.get('public').prepare).toHaveBeenCalled(),
        );
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Newer unsent browser text',
        );
        expect(
            screen.getByRole('textbox', { name: 'Your reply' }),
        ).not.toBeDisabled();
        expect(screen.getByText('newer.txt')).toBeVisible();
        expect(drafts.clearOwned).not.toHaveBeenCalled();
        expect(drafts.acknowledge.mock.calls[0][1]).toEqual(restored.snapshot);
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(currentTicket()));
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Use this version and keep my draft',
                }),
            ).toBeVisible(),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use this version and keep my draft',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Add reply' }),
        ).not.toBeDisabled();
    });

    it('a failed second RAM proof withholds restored content and offers a deliberate retry', async () => {
        const restored = restoredReply();
        mocks.recoveries.set('public_reply', restored);
        render(<TicketReplyComposer {...props} />);
        mocks.commands.get('public').adopt.mockResolvedValueOnce(false);
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Retry browser work restoration',
                }),
            ).toBeVisible(),
        );
        expect(
            screen.queryByDisplayValue('Newer unsent browser text'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('newer.txt')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Retry browser work restoration',
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Your reply' }),
            ).toHaveValue('Newer unsent browser text'),
        );
        expect(mocks.commands.get('public').adopt).toHaveBeenCalledTimes(2);
        expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
    });

    it('draft419 before a command keeps entered work concealed and provides login plus canonical draft retry', async () => {
        render(<TicketReplyComposer {...props} draftsEnabled />);
        write('Kept during draft session expiry');
        const drafts = mocks.draftControls.get('public_reply');
        act(() => drafts.expire());
        expect(
            screen.queryByDisplayValue('Kept during draft session expiry'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Sign in to recover draft' }),
        ).toHaveAttribute('href', '/login');
        expect(
            screen.getByRole('link', { name: 'Sign in to recover draft' }),
        ).toHaveAttribute('target', '_blank');
        expect(drafts.clearScope).not.toHaveBeenCalled();
        drafts.check.mockResolvedValueOnce(null);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved draft access' }),
        );
        await waitFor(() => expect(drafts.check).toHaveBeenCalledOnce());
        expect(
            screen.queryByDisplayValue('Kept during draft session expiry'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved draft access' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Your reply' }),
            ).toHaveValue('Kept during draft session expiry'),
        );
        expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
    });

    it('current-ticket review419 without a UUID conceals but preserves text and recovers through explicit current-version review', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValueOnce({ ok: false, status: 419 })
            .mockResolvedValue(currentTicket());
        vi.stubGlobal('fetch', fetch);
        const view = render(<TicketReplyComposer {...props} />);
        write('Kept through review session expiry');
        view.rerender(<TicketReplyComposer {...props} expectedVersion={8} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('link', { name: 'Sign in again' }),
            ).toBeVisible(),
        );
        expect(
            screen.queryByDisplayValue('Kept through review session expiry'),
        ).not.toBeInTheDocument();
        expect(
            mocks.draftControls.get('public_reply').clearScope,
        ).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Use this version and keep my draft',
                }),
            ).toBeVisible(),
        );
        expect(
            screen.queryByDisplayValue('Kept through review session expiry'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use this version and keep my draft',
            }),
        );
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Kept through review session expiry',
        );
        expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
    });
    it('cancels an uncertain command only after explicit confirmation and retains the entered text', async () => {
        render(<TicketReplyComposer {...props} />);
        write('Retained after cancellation');
        fireEvent.click(screen.getByRole('button', { name: 'Add reply' }));
        await waitFor(() =>
            expect(mocks.commands.get('public').submit).toHaveBeenCalledOnce(),
        );
        act(() => mocks.commands.get('public').unknown());
        expect(mocks.commands.get('public').cancel).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel pending reply 1' }),
        );
        expect(mocks.commands.get('public').cancel).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check and cancel pending reply',
            }),
        );
        await waitFor(() =>
            expect(mocks.commands.get('public').cancel).toHaveBeenCalledWith(
                'frozen-reference',
            ),
        );
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Retained after cancellation',
        );
        expect(mocks.toast).not.toHaveBeenCalled();
    });
    it('keeps independent public/internal text and never converts a private note when switching audience', () => {
        render(<TicketReplyComposer {...props} />);
        write('Public question Z');
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Internal note' }),
            { target: { value: 'Private diagnostics X' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reply · draft' }));
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Public question Z',
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Internal note · draft',
            }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Internal note' }),
        ).toHaveValue('Private diagnostics X');
        expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
        expect(mocks.commands.get('internal').submit).not.toHaveBeenCalled();
    });
    it('retains submitted text/files while the outcome is unknown and blocks a different new reply', async () => {
        const { container } = render(<TicketReplyComposer {...props} />);
        write('Exact reply Z');
        const file = new File(['synthetic'], 'evidence.txt', {
            type: 'text/plain',
        });
        fireEvent.change(container.querySelector('input[type=file]')!, {
            target: { files: [file] },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add reply' }));
        await waitFor(() =>
            expect(mocks.commands.get('public').submit).toHaveBeenCalledOnce(),
        );
        expect(mocks.commands.get('public').submit).toHaveBeenCalledWith({
            body: 'Exact reply Z',
            expectedVersion: 7,
            files: [file],
        });
        act(() => mocks.commands.get('public').unknown());
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'Exact reply Z',
        );
        expect(screen.getByText('evidence.txt')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Add reply' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Retry original reply' }),
        ).toBeVisible();
        expect(mocks.toast).not.toHaveBeenCalled();
    });
    it('clears only a confirmed matching message, reports delivery failure, and permits a second reply with the committed version', async () => {
        render(<TicketReplyComposer {...props} />);
        write('First reply');
        fireEvent.click(screen.getByRole('button', { name: 'Add reply' }));
        await waitFor(() =>
            expect(mocks.commands.get('public').submit).toHaveBeenCalledOnce(),
        );
        act(() => mocks.commands.get('public').complete(committed));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Your reply' }),
            ).toHaveValue(''),
        );
        expect(mocks.toast.mock.calls[0][0]).toMatch(/added/i);
        expect(mocks.toast.mock.calls[0][0]).toMatch(/failed/i);
        write('Second reply');
        fireEvent.click(screen.getByRole('button', { name: 'Add reply' }));
        await waitFor(() =>
            expect(mocks.commands.get('public').submit).toHaveBeenCalledTimes(
                2,
            ),
        );
        expect(
            mocks.commands.get('public').submit.mock.calls[1][0]
                .expectedVersion,
        ).toBe(9);
    });
    it('keeps a newer unsent message when recovering an earlier command receipt', () => {
        render(<TicketReplyComposer {...props} />);
        write('New unsent text');
        act(() =>
            mocks.commands
                .get('public')
                .complete({ ...committed, replayed: true }),
        );
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'New unsent text',
        );
    });
    it('does not quietly replace the original version when the ticket changes during editing', () => {
        const view = render(<TicketReplyComposer {...props} />);
        write('Retained proposal');
        view.rerender(<TicketReplyComposer {...props} expectedVersion={8} />);
        expect(
            screen.getByRole('button', { name: 'Add reply' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Review current ticket' }),
        ).toBeVisible();
        expect(
            mocks.drafts.get('public_reply').workingSnapshot
                .base_ticket_version,
        ).toBe(7);
    });
    it('removes private note controls and content when internal access is revoked', () => {
        const view = render(<TicketReplyComposer {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Internal note' }),
            { target: { value: 'Private retained note' } },
        );
        view.rerender(<TicketReplyComposer {...props} canInternal={false} />);
        expect(
            screen.queryByDisplayValue('Private retained note'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Internal note/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            '',
        );
    });
    it('conceals a denied message without falsely showing a successful post', () => {
        render(<TicketReplyComposer {...props} />);
        write('Private account-bound body');
        act(() => mocks.commands.get('public').denied());
        expect(
            screen.queryByDisplayValue('Private account-bound body'),
        ).not.toBeInTheDocument();
        expect(mocks.toast).not.toHaveBeenCalled();
    });
    it('rejects an over-limit file selection as a whole instead of silently dropping files', () => {
        const { container } = render(<TicketReplyComposer {...props} />);
        const six = Array.from(
            { length: 6 },
            (_, i) => new File(['x'], `file${i}.txt`, { type: 'text/plain' }),
        );
        fireEvent.change(container.querySelector('input[type=file]')!, {
            target: { files: six },
        });
        expect(
            screen.getByText(
                'Choose up to 5 files in total. Your existing files have been kept.',
            ),
        ).toBeVisible();
        expect(screen.queryByText('file0.txt')).not.toBeInTheDocument();
        expect(mocks.commands.get('public').submit).not.toHaveBeenCalled();
    });
});
