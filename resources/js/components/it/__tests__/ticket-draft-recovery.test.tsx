import type {
    ItDraftMetadata,
    ItDraftResumed,
} from '@/hooks/it-ticket-draft-contract';
import type { ItTicketDraftClient } from '@/hooks/use-it-ticket-draft';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TicketDraftRecovery } from '../ticket-draft-recovery';

const uuid = '9c3e9caa-4bc9-41ba-8b90-bf654120f167';
const metadata: ItDraftMetadata = {
    draft_uuid: uuid,
    purpose: 'public_reply',
    context_key: 'ticket:9',
    audience: 'public',
    ticket_id: 9,
    request_uuid: null,
    revision: 2,
    state: 'active',
    has_content: true,
    saved_at: '2026-09-09T10:00:00Z',
    expires_at: '2026-09-12T10:00:00Z',
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
};
const restored: ItDraftResumed = {
    draft: metadata,
    payload: { fields: { body: 'Saved public reply' }, step_index: 0 },
    attachments: [],
};
function client(
    overrides: Partial<ItTicketDraftClient> = {},
): ItTicketDraftClient {
    return {
        state: 'available',
        draft: metadata,
        current: null,
        reviewed: null,
        attachments: [],
        message: null,
        errors: {},
        pending: null,
        retryable: false,
        lastSavedKey: null,
        busy: false,
        persistenceEnabled: true,
        browserOutcomeUnknown: false,
        browserBlocker: null,
        memoryNotices: [],
        memoryWarning: null,
        memoryFailure: null,
        memoryBlocked: false,
        resumeMemory: vi.fn().mockResolvedValue(null),
        discardMemory: vi.fn().mockReturnValue(true),
        clearBrowserWork: vi.fn(),
        clearOwnedBrowserWork: vi.fn(),
        acknowledgeReviewedBrowserWork: vi.fn().mockReturnValue(true),
        check: vi.fn().mockResolvedValue(metadata),
        resume: vi.fn().mockResolvedValue(restored),
        review: vi.fn().mockResolvedValue(restored),
        save: vi.fn().mockResolvedValue(true),
        discard: vi.fn().mockResolvedValue(true),
        startNew: vi.fn().mockResolvedValue(true),
        upload: vi.fn().mockResolvedValue(true),
        remove: vi.fn().mockResolvedValue(true),
        retry: vi.fn().mockResolvedValue(true),
        adoptReviewed: vi.fn().mockReturnValue(true),
        canReleaseUpload: false,
        releasePendingUpload: vi.fn().mockReturnValue(true),
        cancel: vi.fn(),
        isSaved: vi.fn().mockReturnValue(false),
        submissionReference: vi.fn().mockReturnValue(null),
        registerRecoveredSubmission: vi.fn().mockReturnValue(false),
        acknowledgeConsumed: vi.fn().mockReturnValue(false),
        ...overrides,
    };
}
function props(draft = client()) {
    return {
        draft,
        snapshot: { fields: { body: 'Current public reply' }, step_index: 0 },
        hasLocalChanges: true,
        onResume: vi.fn(),
        onDiscarded: vi.fn(),
        onStartNew: vi.fn(),
        renderReview: (saved: ItDraftResumed) => (
            <p>{saved.payload.fields.body}</p>
        ),
    };
}
afterEach(cleanup);
describe('TicketDraftRecovery', () => {
    it.each(['public_reply', 'ticket_edit'] as const)(
        'keeps a pristine %s quiet without claiming a save',
        (purpose) => {
            const draft = client({
                state: 'ready',
                draft: {
                    ...metadata,
                    purpose,
                    has_content: false,
                    saved_at: null,
                    revision: 0,
                },
            });
            const { container } = render(
                <TicketDraftRecovery
                    {...props(draft)}
                    hasLocalChanges={false}
                />,
            );
            expect(container).toBeEmptyDOMElement();
            expect(draft.save).not.toHaveBeenCalled();
            expect(draft.discard).not.toHaveBeenCalled();
        },
    );
    it.each([
        [
            'ticket_changed',
            'Review the current ticket before applying this draft.',
        ],
        ['ticket_settled', 'Reopen this ticket before submitting this draft.'],
    ])(
        'keeps an untouched %s generation quiet, but shows the blocker for entered or saved work',
        (code, message) => {
            const blocker = {
                code,
                message,
            };
            const draft = client({
                state: 'ready',
                draft: {
                    ...metadata,
                    purpose: 'ticket_edit',
                    has_content: false,
                    revision: 0,
                    saved_at: null,
                    current_ticket_version: 8,
                    blocker,
                    capabilities: { ...metadata.capabilities, submit: false },
                },
                message: blocker.message,
            });
            const { container, rerender } = render(
                <TicketDraftRecovery
                    {...props(draft)}
                    hasLocalChanges={false}
                />,
            );
            expect(container).toBeEmptyDOMElement();
            expect(draft.save).not.toHaveBeenCalled();
            rerender(<TicketDraftRecovery {...props(draft)} hasLocalChanges />);
            expect(screen.getByRole('status')).toHaveTextContent(
                blocker.message,
            );
            expect(
                screen.getByRole('button', { name: 'Save draft' }),
            ).toBeEnabled();
            expect(draft.draft?.capabilities.submit).toBe(false);
            rerender(
                <TicketDraftRecovery
                    {...props(
                        client({
                            ...draft,
                            draft: { ...draft.draft!, has_content: true },
                        }),
                    )}
                    hasLocalChanges={false}
                />,
            );
            expect(screen.getByRole('status')).toHaveTextContent(
                blocker.message,
            );
        },
    );

    it('shows unsaved edits but does not offer to discard an empty saved generation', () => {
        const draft = client({
            state: 'ready',
            draft: { ...metadata, has_content: false },
        });
        render(<TicketDraftRecovery {...props(draft)} />);
        expect(screen.getByRole('status')).toHaveTextContent('not saved');
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeEnabled();
        expect(
            screen.queryByRole('button', { name: 'Discard saved draft' }),
        ).not.toBeInTheDocument();
    });
    it('keeps save available for saved text cleared back to the initial empty form', () => {
        const draft = client({
            state: 'ready',
            lastSavedKey: 'previous acknowledged content',
        });
        const { rerender } = render(
            <TicketDraftRecovery
                {...props(draft)}
                hasLocalChanges={false}
                snapshot={{ fields: { body: '' }, step_index: 0 }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeEnabled();
        rerender(
            <TicketDraftRecovery
                {...props({ ...draft, isSaved: () => true })}
                hasLocalChanges={false}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent('Draft saved.');
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeDisabled();
    });
    it('preserves an actionable error on a pristine draft without inventing changes', () => {
        const draft = client({
            state: 'ready',
            draft: { ...metadata, has_content: false },
            message: 'Draft service needs another check.',
            errors: { draft: 'Check your access.' },
        });
        render(
            <TicketDraftRecovery {...props(draft)} hasLocalChanges={false} />,
        );
        expect(screen.getByText('Check your access.')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeDisabled();
        expect(screen.queryByText(/latest changes/)).not.toBeInTheDocument();
    });
    it('keeps canonical pending files and browser warnings visible without local text', () => {
        const draft = client({
            state: 'ready',
            draft: {
                ...metadata,
                has_content: false,
                files: { ready: 0, pending: 1, cleanup_pending: 0 },
            },
            memoryWarning: 'Keep this form open.',
        });
        render(
            <TicketDraftRecovery {...props(draft)} hasLocalChanges={false} />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Keep this form open.',
        );
        expect(
            screen.getByRole('button', { name: 'Discard saved draft' }),
        ).toBeEnabled();
    });
    it('uses the host matched-commit action only for terminal state and preserves ordinary error recovery', () => {
        const action = { label: 'Write another reply', onClick: vi.fn() };
        const draft = client({ state: 'terminal', draft: null });
        const { rerender } = render(
            <TicketDraftRecovery {...props(draft)} nextDraftAction={action} />,
        );
        fireEvent.click(screen.getByRole('button', { name: action.label }));
        expect(action.onClick).toHaveBeenCalledOnce();
        expect(
            screen.queryByRole('button', { name: 'Check saved draft' }),
        ).not.toBeInTheDocument();
        rerender(
            <TicketDraftRecovery
                {...props({ ...draft, state: 'outcome_unknown' })}
                nextDraftAction={action}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Check saved draft' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: action.label }),
        ).not.toBeInTheDocument();
    });
    it('renders no control or action while recovery is disabled', () => {
        const { container } = render(
            <TicketDraftRecovery
                {...props(client({ state: 'disabled', draft: null }))}
            />,
        );
        expect(container).toBeEmptyDOMElement();
    });
    it('requires a deliberate confirmation before replacing local work and only hydrates after the acknowledgement', async () => {
        const values = props();
        render(<TicketDraftRecovery {...values} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume saved draft' }),
        );
        expect(values.draft.resume).not.toHaveBeenCalled();
        const dialog = screen.getByRole('alertdialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(values.onResume).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Resume saved draft' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Resume saved draft',
            }),
        );
        await waitFor(() =>
            expect(values.onResume).toHaveBeenCalledWith(restored),
        );
        expect(values.draft.save).not.toHaveBeenCalled();
    });
    it('keeps the host buffer when discard is unconfirmed and binds confirmation to its original generation', async () => {
        const draft = client({ discard: vi.fn().mockResolvedValue(false) });
        const values = props(draft);
        const { rerender } = render(<TicketDraftRecovery {...values} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard saved draft' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Discard draft',
            }),
        );
        await waitFor(() => expect(draft.discard).toHaveBeenCalledOnce());
        expect(values.onDiscarded).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard saved draft' }),
        );
        rerender(
            <TicketDraftRecovery
                {...values}
                draft={client({
                    draft: {
                        ...metadata,
                        draft_uuid: 'f024eb01-fa17-4cdb-a057-179d056973f6',
                    },
                })}
            />,
        );
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
    it('shows the host-defined saved fields and adopting a reviewed revision does not save', () => {
        const draft = client({
            state: 'conflict',
            current: metadata,
            reviewed: restored,
        });
        render(<TicketDraftRecovery {...props(draft)} />);
        expect(screen.getByText('Saved public reply')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use reviewed revision' }),
        );
        expect(draft.adoptReviewed).toHaveBeenCalledOnce();
        expect(draft.save).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();
    });
    it('exposes exact retry and cancellation separately and never labels unsaved work saved', () => {
        const draft = client({
            state: 'outcome_unknown',
            retryable: true,
            message: 'The upload result is unconfirmed.',
        });
        const { rerender } = render(<TicketDraftRecovery {...props(draft)} />);
        expect(screen.getByRole('alert')).toHaveTextContent('unconfirmed');
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry original command' }),
        );
        expect(draft.retry).toHaveBeenCalledOnce();
        expect(screen.queryByText('Draft saved.')).not.toBeInTheDocument();
        rerender(
            <TicketDraftRecovery
                {...props(draft)}
                draft={{
                    ...draft,
                    busy: true,
                    pending: 'upload',
                    state: 'saving',
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel wait' }));
        expect(draft.cancel).toHaveBeenCalledOnce();
        expect(
            screen.queryByRole('button', { name: 'Discard saved draft' }),
        ).not.toBeInTheDocument();
    });
});
