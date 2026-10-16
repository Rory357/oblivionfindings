import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StoryDiscussion } from './_discussion';
import { PageParticipation } from './_participation';
import { hubContent, hubData } from './_test-fixtures';
import type { HubProps } from './_types';

const { post, reload, request, before } = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    request: vi.fn(),
    before: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    useForm: vi.fn(),
    router: { post, reload, on: before },
}));
vi.mock('./_api', () => ({ hubRequest: request }));
const comment = {
    id: 20,
    parent_id: null,
    name: 'Aroha Bennett',
    body: 'Could we include an early session?',
    created_at: '2026-10-14T01:00:00Z',
    version: 1,
    can_remove: false,
    can_hide: false,
    can_appreciate: false,
    appreciation: { count: 2, mine: false },
};
const activity: NonNullable<HubProps['participation']> = {
    actor_id: 11,
    page_id: 8,
    revision_id: 9,
    can_moderate: true,
    hidden_count: 1,
    polls: {},
    reactions: {},
    comments: [
        comment,
        {
            ...comment,
            id: 21,
            parent_id: 20,
            name: 'Jamie Rivers',
            body: 'I would join an early session.',
        },
    ],
};
const review = {
    actor_id: 11,
    page_id: 8,
    revision_id: 9,
    comments_enabled: true,
    hidden_comments: [],
};
const props = { activity, title: 'Learning week', commentsEnabled: true };
const open = () =>
    fireEvent.click(screen.getByRole('button', { name: 'View discussion' }));
const write = (body = 'Thanks for making time for this.') =>
    fireEvent.change(screen.getByRole('textbox'), { target: { value: body } });
const chooseTab = (name: RegExp | string) =>
    fireEvent.mouseDown(screen.getByRole('tab', { name }), {
        button: 0,
        ctrlKey: false,
    });
beforeEach(() => {
    post.mockReset();
    reload.mockReset();
    request.mockReset();
    before.mockReset();
    before.mockReturnValue(vi.fn());
    request.mockResolvedValue(review);
    window.history.replaceState({}, '', '/company-hub/read/learning-week');
    Element.prototype.scrollIntoView = vi.fn();
});

describe('story discussion panel', () => {
    it('omits an empty participation panel while retaining useful resource feedback and authorised moderation', () => {
        const data = hubData({
            selectedPage: {
                id: 8,
                revision_id: 9,
                slug: 'guide',
                kind: 'page',
                published_at: '2026-10-14T00:00:00Z',
                can_edit: false,
                content: {
                    ...hubContent,
                    comments_enabled: false,
                    reactions_enabled: false,
                },
            },
            participation: { ...activity, can_moderate: false, comments: [] },
        });
        const view = render(<PageParticipation data={data} />);
        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
        view.rerender(
            <PageParticipation
                data={{
                    ...data,
                    participation: {
                        ...data.participation!,
                        helpful: { count: 2, mine: false },
                    },
                }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Resource feedback' }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Yes, helpful · 2' }),
        ).toBeEnabled();
        view.rerender(
            <PageParticipation
                data={{ ...data, participation: { ...activity } }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Join the conversation' }),
        ).toBeVisible();
    });
    it('never sends sample helpful votes or reactions even when the fixture enables them', () => {
        const data = hubData({
            demo: true,
            selectedPage: {
                id: 8,
                revision_id: 9,
                slug: 'guide',
                kind: 'page',
                published_at: '2026-10-14T00:00:00Z',
                can_edit: false,
                content: { ...hubContent, comments_enabled: false },
            },
            participation: {
                ...activity,
                helpful: { count: 2, mine: false },
                reactions: { heart: { count: 3, mine: false } },
            },
        });
        render(<PageParticipation data={data} />);
        const helpful = screen.getByRole('button', {
            name: 'Yes, helpful · 2',
        });
        const appreciate = screen.getByRole('button', { name: 'Appreciate 3' });
        expect(helpful).toBeDisabled();
        expect(appreciate).toBeDisabled();
        fireEvent.click(helpful);
        fireEvent.click(appreciate);
        expect(post).not.toHaveBeenCalled();
    });
    it('connects the reader card to the panel and rejects mixed page participation', () => {
        const data = hubData({
            selectedPage: {
                id: 8,
                revision_id: 9,
                slug: 'learning-week',
                kind: 'news',
                published_at: '2026-10-14T00:00:00Z',
                can_edit: false,
                content: { ...hubContent, title: 'Learning week' },
            },
            participation: activity,
        });
        const view = render(<PageParticipation data={data} />);
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        open();
        expect(
            screen.getByRole('textbox', { name: 'Add a comment' }),
        ).toBeVisible();
        view.rerender(
            <PageParticipation
                data={{
                    ...data,
                    participation: { ...activity, revision_id: 19 },
                }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'View discussion' }),
        ).not.toBeInTheDocument();
    });

    it('defers a late moderation refresh when writing began after the action and confirms before changing release', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        render(
            <StoryDiscussion
                {...props}
                activity={{
                    ...activity,
                    comments: [{ ...comment, can_hide: true }],
                }}
            />,
        );
        open();
        const preventDefault = vi.fn();
        reload.mockImplementation(() =>
            before.mock.calls.at(-1)![1]({
                preventDefault,
                detail: {
                    visit: {
                        method: 'get',
                        url: '/company-hub/read/learning-week',
                    },
                },
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Hide comment' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm hide' }));
        write('Keep this unfinished thought.');
        await act(async () => finish(review));
        expect(reload).not.toHaveBeenCalled();
        expect(screen.getByRole('textbox')).toHaveValue(
            'Keep this unfinished thought.',
        );
        expect(
            screen.queryByRole('button', { name: 'Discard and continue' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh conversation' }),
        );
        expect(reload).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['participation', 'selectedPage'],
                preserveScroll: true,
            }),
        );
        expect(preventDefault).not.toHaveBeenCalled();
    });

    it('loads the paired story and discussion before allowing new writing after an automatic refresh', async () => {
        render(
            <StoryDiscussion
                {...props}
                activity={{
                    ...activity,
                    comments: [{ ...comment, can_hide: true }],
                }}
            />,
        );
        open();
        fireEvent.click(screen.getByRole('button', { name: 'Hide comment' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm hide' }));
        await act(async () => {});
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['participation', 'selectedPage'],
            }),
        );
        expect(screen.getByRole('textbox')).toBeDisabled();
        act(() => {
            reload.mock.calls[0][0].onSuccess();
            reload.mock.calls[0][0].onFinish();
        });
        expect(screen.getByRole('textbox')).toBeEnabled();
    });
    it('opens the authored conversation in a side panel without fetching hidden content', () => {
        render(<StoryDiscussion {...props} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        open();
        const panel = screen.getByRole('dialog', {
            name: 'A conversation worth joining',
        });
        expect(panel).toHaveAttribute('data-slot', 'sheet-content');
        expect(within(panel).getByText('Learning week')).toBeVisible();
        expect(
            within(panel).getByRole('article', {
                name: 'Comment by Aroha Bennett',
            }),
        ).toHaveTextContent(comment.body);
        expect(
            within(panel).getByRole('article', {
                name: 'Reply by Jamie Rivers',
            }),
        ).toHaveTextContent('I would join an early session.');
        expect(request).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps a draft across moderator tabs and close/reopen, while protecting page departure', async () => {
        render(<StoryDiscussion {...props} />);
        open();
        write();
        chooseTab(/Hidden comments/);
        await screen.findByText('No hidden comments to review.');
        expect(request).toHaveBeenCalledWith(
            '/company-hub/pages/8/moderation?actor_id=11&revision_id=9',
            'GET',
        );
        chooseTab('Discussion');
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue writing' }),
        );
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        expect(post).not.toHaveBeenCalled();
    });

    it('posts one reply with the current context and locks editing and dismissal until it finishes', () => {
        render(<StoryDiscussion {...props} />);
        open();
        fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
        expect(
            screen.getByRole('textbox', { name: 'Reply to Aroha Bennett' }),
        ).toHaveFocus();
        write('An early session would help us.');
        fireEvent.click(screen.getByRole('button', { name: 'Post reply' }));
        fireEvent.submit(
            screen.getByRole('button', { name: 'Posting…' }).closest('form')!,
        );
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.getByRole('dialog')).toBeVisible();
        expect(screen.getByRole('textbox')).toBeDisabled();
        expect(post).toHaveBeenCalledOnce();
        expect(post).toHaveBeenCalledWith(
            '/company-hub/pages/8/interactions',
            {
                kind: 'comment',
                body: 'An early session would help us.',
                parent_id: 20,
                actor_id: 11,
                revision_id: 9,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        act(() => {
            post.mock.calls[0][2].onSuccess({
                props: { participation: activity },
            });
            post.mock.calls[0][2].onFinish();
        });
        expect(screen.getByRole('status')).toHaveTextContent(
            'Your reply was posted.',
        );
        expect(
            screen.getByRole('textbox', { name: 'Add a comment' }),
        ).toHaveValue('');
    });

    it('retains writing after validation rejection and permits a corrected retry', () => {
        render(<StoryDiscussion {...props} />);
        open();
        write();
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        act(() => {
            post.mock.calls[0][2].onError({
                body: 'Please check this comment.',
            });
            post.mock.calls[0][2].onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Please check this comment.',
        );
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        write('A corrected contribution.');
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][1].body).toBe('A corrected contribution.');
    });

    it('blocks duplicate retry after an unconfirmed post and confirms before discarding to refresh', () => {
        render(<StoryDiscussion {...props} />);
        open();
        write();
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        act(() => post.mock.calls[0][2].onFinish());
        expect(screen.getByRole('alert')).toHaveTextContent(
            'We could not confirm your comment.',
        );
        expect(
            screen.getByRole('button', { name: 'Post comment' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check current conversation' }),
        );
        expect(reload).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['participation', 'selectedPage'],
            }),
        );
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        expect(screen.getByRole('textbox')).toBeDisabled();
        act(() => reload.mock.calls[0][0].onFinish());
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        expect(
            screen.getByRole('button', { name: 'Post comment' }),
        ).toBeDisabled();
        expect(post).toHaveBeenCalledOnce();
    });

    it('does not accept a redirect with a different account or release as a confirmed comment', () => {
        render(<StoryDiscussion {...props} />);
        open();
        write();
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        act(() => {
            post.mock.calls[0][2].onSuccess({
                props: { participation: { ...activity, revision_id: 19 } },
            });
            post.mock.calls[0][2].onFinish();
        });
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        expect(
            screen.getByRole('button', { name: 'Post comment' }),
        ).toBeDisabled();
    });

    it('drops old context and ignores its late result after an account change', () => {
        const view = render(<StoryDiscussion {...props} />);
        open();
        write('Old account writing.');
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        const callbacks = post.mock.calls[0][2];
        view.rerender(
            <StoryDiscussion
                {...props}
                activity={{ ...activity, actor_id: 12 }}
            />,
        );
        open();
        expect(screen.getByRole('textbox')).toHaveValue('');
        write('New account writing.');
        act(() => {
            callbacks.onSuccess({ props: { participation: activity } });
            callbacks.onFinish();
        });
        expect(screen.getByRole('textbox')).toHaveValue('New account writing.');
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Post comment' }),
        ).toBeEnabled();
    });

    it('retains a reply draft when its parent disappears and requires an explicit new-comment choice', () => {
        const view = render(<StoryDiscussion {...props} />);
        open();
        fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
        write();
        view.rerender(
            <StoryDiscussion
                {...props}
                activity={{ ...activity, comments: [] }}
            />,
        );
        expect(
            screen.getByText('This comment is no longer available.'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Post reply' }),
        ).toBeDisabled();
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for making time for this.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Use as a new comment' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        expect(post.mock.calls[0][1]).not.toHaveProperty('parent_id');
    });

    it('removes hidden review immediately when moderation permission is withdrawn', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(<StoryDiscussion {...props} />);
        open();
        chooseTab(/Hidden comments/);
        expect(request).toHaveBeenCalledOnce();
        view.rerender(
            <StoryDiscussion
                {...props}
                activity={{ ...activity, can_moderate: false }}
            />,
        );
        await act(async () =>
            finish({
                ...review,
                hidden_comments: [
                    {
                        ...comment,
                        body: 'Hidden moderator-only text',
                        can_restore: false,
                        hidden_reason: 'moderator_hidden',
                        hidden_at: comment.created_at,
                        parent_hidden: false,
                    },
                ],
            }),
        );
        expect(
            screen.queryByRole('tab', { name: /Hidden comments/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Hidden moderator-only text'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox')).toBeVisible();
    });

    it('supports direct conversation links and later hash navigation', () => {
        window.history.replaceState(
            {},
            '',
            '/company-hub/read/learning-week#hub-conversation',
        );
        render(<StoryDiscussion {...props} />);
        expect(screen.getByRole('dialog')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        fireEvent(window, new Event('hashchange'));
        expect(screen.getByRole('dialog')).toBeVisible();
    });

    it('allows moderator review while comments are closed and keeps previews read-only', async () => {
        const view = render(
            <StoryDiscussion {...props} commentsEnabled={false} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review hidden comments' }),
        );
        await screen.findByText('No hidden comments to review.');
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Discussion' })).toBeDisabled();
        view.rerender(<StoryDiscussion {...props} demo />);
        open();
        expect(
            screen.getByText('Comments are read-only in this preview.'),
        ).toBeVisible();
        expect(
            screen.queryByRole('tab', { name: /Hidden comments/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
});
