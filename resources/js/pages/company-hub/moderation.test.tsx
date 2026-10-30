import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CommentActions, ModerationReview } from './_moderation';
import type { HubProps } from './_types';
const { request, reload, remove } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    remove: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { reload, delete: remove } }));
const activity: NonNullable<HubProps['participation']> = {
    actor_id: 11,
    page_id: 8,
    revision_id: 9,
    can_moderate: true,
    hidden_count: 3,
    polls: {},
    reactions: {},
    comments: [],
};
const comment = {
    id: 20,
    parent_id: null,
    name: 'Aroha',
    body: 'Hidden review text',
    created_at: '2026-10-13T01:00:00Z',
    version: 4,
    hidden_reason: 'moderator_hidden',
    parent_hidden: false,
    hidden_at: '2026-10-13T02:00:00Z',
    can_restore: true,
};
const review = {
    actor_id: 11,
    page_id: 8,
    revision_id: 9,
    comments_enabled: true,
    hidden_comments: [
        comment,
        {
            ...comment,
            id: 21,
            body: null,
            hidden_reason: 'author_removed',
            can_restore: false,
        },
        {
            ...comment,
            id: 22,
            parent_id: 20,
            body: 'Inherited reply',
            hidden_reason: 'parent_hidden',
            parent_hidden: true,
            can_restore: false,
        },
    ],
};
describe('comment moderation interface', () => {
    beforeEach(() => {
        request.mockReset();
        reload.mockReset();
        remove.mockReset();
    });
    it('loads only on explicit review and restores with current account, revision and comment version', async () => {
        request.mockResolvedValueOnce(review).mockResolvedValueOnce({
            ...review,
            hidden_comments: [review.hidden_comments[1]],
        });
        render(<ModerationReview activity={activity} />);
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review hidden comments (3)' }),
        );
        await screen.findByText('Hidden review text');
        expect(
            screen.getByRole('dialog', { name: 'Hidden comments' }),
        ).toHaveAttribute('data-slot', 'sheet-content');
        expect(
            screen.getAllByRole('button', { name: 'Restore comment' }),
        ).toHaveLength(1);
        expect(screen.getByText(/Removed by its author/)).toBeVisible();
        expect(screen.getByText(/Restore the parent first/)).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Restore comment' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenLastCalledWith(
                '/company-hub/pages/8/interactions/20/moderation',
                'PUT',
                { actor_id: 11, revision_id: 9, version: 4, hidden: false },
            ),
        );
        await waitFor(() =>
            expect(
                screen.queryByText('Hidden review text'),
            ).not.toBeInTheDocument(),
        );
        expect(screen.queryByText('Inherited reply')).not.toBeInTheDocument();
        expect(reload).toHaveBeenCalledTimes(1);
    });
    it('denies mismatched account responses and hides cached review text after a stale restore rejection', async () => {
        request.mockResolvedValueOnce({ ...review, actor_id: 99 });
        const view = render(<ModerationReview activity={activity} />);
        fireEvent.click(screen.getByRole('button', { name: /Review hidden/ }));
        await screen.findByRole('alert');
        expect(
            screen.queryByText('Hidden review text'),
        ).not.toBeInTheDocument();
        view.unmount();
        request
            .mockResolvedValueOnce(review)
            .mockRejectedValueOnce(
                new Error(
                    'This comment has changed. Reload the moderation view.',
                ),
            );
        render(<ModerationReview activity={activity} />);
        fireEvent.click(screen.getByRole('button', { name: /Review hidden/ }));
        await screen.findByText('Hidden review text');
        fireEvent.click(
            screen.getByRole('button', { name: 'Restore comment' }),
        );
        await screen.findByText(/This comment has changed/);
        expect(
            screen.queryByText('Hidden review text'),
        ).not.toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });
    it('ignores a response after the review closes and never exposes moderator controls to readers', async () => {
        let finish!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const view = render(<ModerationReview activity={activity} />);
        fireEvent.click(screen.getByRole('button', { name: /Review hidden/ }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Close moderation review' }),
        );
        await act(async () => finish(review));
        expect(
            screen.queryByText('Hidden review text'),
        ).not.toBeInTheDocument();
        view.rerender(
            <ModerationReview
                activity={{ ...activity, can_moderate: false }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /Review hidden/ }),
        ).not.toBeInTheDocument();
    });
    it('confirms moderator hiding separately from irreversible author removal and does not claim success on an error', async () => {
        request.mockRejectedValueOnce(
            new Error(
                'Your signed-in account changed. Reload the conversation.',
            ),
        );
        render(
            <CommentActions
                activity={activity}
                comment={{ ...comment, can_hide: true, can_remove: true }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Hide comment' }));
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm hide' }));
        await screen.findByRole('alert');
        expect(reload).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Remove comment' }));
        expect(screen.getByText(/It cannot be restored/)).toBeVisible();
        expect(remove).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        expect(remove).toHaveBeenCalledWith(
            '/company-hub/pages/8/interactions/20',
            expect.objectContaining({
                data: { actor_id: 11, revision_id: 9, version: 4 },
            }),
        );
    });
});
