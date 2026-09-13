import { CsatRater } from '@/components/it/csat';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    on: vi.fn(),
    confetti: vi.fn(),
    actor: 3,
}));
vi.mock('axios', () => ({
    default: {
        post: mocks.post,
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: mocks.actor } } } }),
    router: { reload: mocks.reload, visit: mocks.visit, on: mocks.on },
}));
vi.mock('@/lib/confetti', () => ({ fireConfetti: mocks.confetti }));
const acknowledgement = (overrides: Record<string, unknown> = {}) => ({
    status: 200,
    data: {
        status: 'committed',
        data: {
            id: 4,
            viewer_user_id: 3,
            lock_version: 8,
            operation: 'csat.save',
            score: 4,
            comment: 'Restored access.',
            submitted_at: '2026-09-10T00:00:00Z',
            ...overrides,
        },
    },
});
const form = () => {
    const view = render(<CsatRater ticketId={4} expectedVersion={7} />);
    fireEvent.click(screen.getByRole('button', { name: 'Rate IT’s help' }));
    return view;
};
const propose = () => {
    fireEvent.click(screen.getByRole('radio', { name: '4 stars' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Feedback comment' }),
        { target: { value: 'Restored access.' } },
    );
};
const reviewResponse = (overrides: Record<string, unknown> = {}) => ({
    ok: true,
    json: async () => ({
        viewer_user_id: 3,
        can: { manage: false, rate: true },
        ticket: {
            id: 4,
            lock_version: 9,
            reference: 'IT-000004',
            title: 'Restored service',
            status: 'resolved',
            priority: 'normal',
            assignee: null,
            csat: {
                score: 2,
                comment: 'Earlier feedback.',
                submitted_at: '2026-09-10T00:00:00Z',
            },
            ...overrides,
        },
    }),
});
beforeEach(() => {
    vi.clearAllMocks();
    mocks.actor = 3;
    mocks.on.mockReturnValue(() => {});
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('saves only after explicit submit and an exact persisted feedback acknowledgement', async () => {
    mocks.post.mockResolvedValue(acknowledgement());
    form();
    propose();
    expect(mocks.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    await screen.findByText(
        'Your rating is saved. Thank you for the feedback.',
    );
    expect(mocks.post).toHaveBeenCalledWith(
        '/it/tickets/4/csat',
        {
            actor_user_id: 3,
            expected_version: 7,
            score: 4,
            comment: 'Restored access.',
        },
        expect.any(Object),
    );
    expect(mocks.reload).toHaveBeenCalledOnce();
    expect(mocks.confetti).not.toHaveBeenCalled();
});

it.each([
    { viewer_user_id: 9 },
    { score: 5 },
    { comment: 'Different feedback' },
    { lock_version: 6 },
    { submitted_at: null },
    { operation: 'resolution.confirm' },
])(
    'does not present unrelated or malformed success as saved feedback: %j',
    async (overrides) => {
        mocks.post.mockResolvedValue(acknowledgement(overrides));
        form();
        propose();
        fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
        await screen.findByRole('button', { name: 'Review current ticket' });
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(mocks.confetti).not.toHaveBeenCalled();
        expect(
            screen.getByRole('textbox', { name: 'Feedback comment' }),
        ).toHaveValue('Restored access.');
        expect(
            screen.getByRole('button', { name: 'Submit rating' }),
        ).toBeDisabled();
    },
);

it('shows the currently saved rating, keeps the proposal and requires a separate retry after review', async () => {
    mocks.post
        .mockRejectedValueOnce({ response: { status: 409 } })
        .mockResolvedValueOnce(acknowledgement({ lock_version: 10 }));
    const fetcher = vi.fn().mockResolvedValue(reviewResponse());
    vi.stubGlobal('fetch', fetcher);
    form();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Review current ticket' }),
    );
    await screen.findByText('Earlier feedback.');
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Keep my feedback against this version',
        }),
    );
    expect(mocks.post).toHaveBeenCalledOnce();
    expect(
        screen.getByRole('textbox', { name: 'Feedback comment' }),
    ).toHaveValue('Restored access.');
    fireEvent.click(screen.getByRole('button', { name: 'Update rating' }));
    await screen.findByText(
        'Your rating is saved. Thank you for the feedback.',
    );
    expect(mocks.post.mock.calls[1][1].expected_version).toBe(9);
});

it('stopping the wait ignores late success and keeps the result uncertain', async () => {
    let finish!: (value: ReturnType<typeof acknowledgement>) => void;
    mocks.post.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    form();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    expect(
        screen.getByRole('textbox', { name: 'Feedback comment' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
    await act(async () => finish(acknowledgement()));
    expect(screen.getByText(/The wait stopped/)).toBeInTheDocument();
    expect(mocks.reload).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Submit rating' }),
    ).toBeDisabled();
});

it('conceals session-expired feedback until same-actor current-ticket review', async () => {
    mocks.post.mockRejectedValue({ response: { status: 419 } });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reviewResponse()));
    form();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    await screen.findByRole('link', { name: 'Sign in again' });
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Review current ticket' }),
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Keep my feedback against this version',
        }),
    );
    expect(
        screen.getByRole('textbox', { name: 'Feedback comment' }),
    ).toHaveValue('Restored access.');
    expect(mocks.post).toHaveBeenCalledOnce();
});

it('access loss conceals feedback and an actor change cannot submit the old copy', async () => {
    mocks.post.mockRejectedValue({ response: { status: 403 } });
    const view = form();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    await screen.findByText(/Entered feedback is concealed/);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    mocks.actor = 9;
    view.rerender(<CsatRater ticketId={4} expectedVersion={7} />);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Rate IT’s help' }));
    expect(screen.getByRole('radio', { name: '4 stars' })).toHaveAttribute(
        'aria-checked',
        'false',
    );
});

it('guards cancellation and supports arrow-key selection without submitting', async () => {
    form();
    fireEvent.keyDown(screen.getByRole('radio', { name: '1 star' }), {
        key: 'ArrowRight',
    });
    expect(screen.getByRole('radio', { name: '2 stars' })).toHaveFocus();
    expect(screen.getByRole('radio', { name: '2 stars' })).toHaveAttribute(
        'aria-checked',
        'true',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel changes' }));
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('radio', { name: '2 stars' })).toHaveAttribute(
        'aria-checked',
        'true',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel changes' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard feedback' }));
    await waitFor(() =>
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument(),
    );
    expect(mocks.post).not.toHaveBeenCalled();
});

it('does not show a discard prompt for its own acknowledged refresh', async () => {
    const preventDefault = vi.fn();
    mocks.post.mockResolvedValue(acknowledgement());
    mocks.reload.mockImplementation(() => {
        const before = mocks.on.mock.calls.at(-1)?.[1];
        before?.({
            preventDefault,
            detail: { visit: { method: 'get', url: '/it/tickets/4' } },
        });
    });
    form();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    await screen.findByText(
        'Your rating is saved. Thank you for the feedback.',
    );
    expect(preventDefault).not.toHaveBeenCalled();
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
});

it.each(['Escape', 'Close editor', 'Close'])(
    'guards dirty modal dismissal through %s and starts clean after confirmed discard',
    async (action) => {
        form();
        propose();
        const dismiss = () => {
            if (action === 'Escape')
                fireEvent.keyDown(screen.getByRole('textbox'), {
                    key: 'Escape',
                });
            else
                fireEvent.click(
                    screen.getByRole('button', {
                        name: new RegExp(`^${action}$`),
                    }),
                );
        };
        dismiss();
        await screen.findByRole('alertdialog', {
            name: 'Discard this feedback?',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('textbox', { name: 'Feedback comment' }),
        ).toHaveValue('Restored access.');
        dismiss();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard feedback' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Rate IT’s help' }));
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.getByRole('radio', { name: '4 stars' })).toHaveAttribute(
            'aria-checked',
            'false',
        );
        expect(mocks.post).not.toHaveBeenCalled();
    },
);

it('keeps the dialog open during a pending save and guards dismissal after stopping the wait', async () => {
    mocks.post.mockReturnValue(new Promise(() => {}));
    form();
    propose();
    fireEvent.click(screen.getByRole('button', { name: 'Submit rating' }));
    expect(screen.getByRole('button', { name: 'Close editor' })).toBeDisabled();
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    fireEvent.click(screen.getByRole('button', { name: /^Close$/ }));
    expect(
        screen.getByRole('dialog', { name: 'Rate IT’s help' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
    fireEvent.click(screen.getByRole('button', { name: 'Close editor' }));
    await screen.findByRole('alertdialog');
    expect(
        screen.getByText(/does not undo a saved rating/),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(
        screen.getByRole('textbox', { name: 'Feedback comment' }),
    ).toHaveValue('Restored access.');
    expect(mocks.post).toHaveBeenCalledOnce();
});

it('opens from the compact saved score and closes an unchanged editor without a discard prompt', async () => {
    render(
        <CsatRater
            ticketId={4}
            expectedVersion={8}
            score={2}
            comment="Saved comment."
        />,
    );
    const trigger = screen.getByRole('button', { name: 'Edit rating' });
    trigger.focus();
    fireEvent.click(trigger);
    expect(
        screen.getByRole('textbox', { name: 'Feedback comment' }),
    ).toHaveValue('Saved comment.');
    expect(screen.getByRole('radio', { name: '2 stars' })).toHaveAttribute(
        'aria-checked',
        'true',
    );
    fireEvent.keyDown(screen.getByRole('textbox'), { key: 'Escape' });
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    await waitFor(() => expect(trigger).toHaveFocus());
    expect(mocks.post).not.toHaveBeenCalled();
});

it('returns keyboard focus to the retained comment when cancelling a nested discard prompt', async () => {
    form();
    propose();
    const comment = screen.getByRole('textbox', { name: 'Feedback comment' });
    comment.focus();
    fireEvent.keyDown(comment, { key: 'Escape' });
    const cancel = await screen.findByRole('button', { name: 'Cancel' });
    cancel.focus();
    fireEvent.click(cancel);
    await waitFor(() =>
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
    );
    await waitFor(() => expect(comment).toHaveFocus());
    expect(comment).toHaveValue('Restored access.');
    expect(mocks.post).not.toHaveBeenCalled();
});
