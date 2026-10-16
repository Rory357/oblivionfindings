import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { hubRequest, HubRequestError } from './_api';
import {
    RecognitionConversation,
    type SocialKudos,
} from './_recognition-conversation';

vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        ...props
    }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a {...props}>{children}</a>
    ),
    router: { on: () => () => {} },
}));
const post: SocialKudos = {
    id: 1,
    from: 'Aroha',
    to: 'Jamie',
    to_id: 2,
    message: 'Thank you',
    category: null,
    created_at: '2026-10-16T00:00:00Z',
    can_reply: true,
    reactions: {},
    replies: [
        {
            id: 4,
            name: 'Jamie',
            body: 'Happy to help',
            version: 1,
            can_edit: true,
            can_remove: true,
        },
    ],
};
beforeEach(() => {
    vi.mocked(hubRequest).mockReset();
});

it('posts a threaded reply to its parent and displays the canonical conversation', async () => {
    const change = vi.fn();
    vi.mocked(hubRequest).mockResolvedValue({
        ...post,
        replies: [
            ...post.replies,
            { id: 5, parent_id: 4, name: 'You', body: 'It meant a lot.' },
        ],
    });
    render(
        <RecognitionConversation
            kudos={post}
            actorId={7}
            writable
            onChange={change}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Reply to Jamie' }), {
        target: { value: 'It meant a lot.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Post' }));
    await screen.findByText('Your comment was posted.');
    const form = vi.mocked(hubRequest).mock.calls[0][2] as FormData;
    expect(form.get('parent_id')).toBe('4');
    expect(form.get('actor_id')).toBe('7');
    expect(change).toHaveBeenCalledWith(
        expect.objectContaining({
            replies: expect.arrayContaining([
                expect.objectContaining({ parent_id: 4 }),
            ]),
        }),
    );
});
it('keeps editing text after a conflict and refreshes the version before retry', async () => {
    vi.mocked(hubRequest)
        .mockRejectedValueOnce(
            new HubRequestError('Someone changed this comment.', 409),
        )
        .mockResolvedValueOnce({
            ...post,
            replies: [
                { ...post.replies[0], version: 2, body: 'Updated elsewhere' },
            ],
        })
        .mockResolvedValueOnce({
            ...post,
            replies: [
                { ...post.replies[0], version: 3, body: 'My revised comment' },
            ],
        });
    render(
        <RecognitionConversation
            kudos={post}
            actorId={7}
            writable
            onChange={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Edit your comment' }),
        { target: { value: 'My revised comment' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await screen.findByText('Someone changed this comment.');
    expect(
        screen.getByRole('textbox', { name: 'Edit your comment' }),
    ).toHaveValue('My revised comment');
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Refresh conversation' }),
    );
    await screen.findByText('Updated elsewhere');
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() =>
        expect(hubRequest).toHaveBeenLastCalledWith(
            '/company-hub/recognition/1/replies/4',
            'PATCH',
            expect.objectContaining({ version: 2, body: 'My revised comment' }),
        ),
    );
});
it('retains an uncertain send and prevents duplicate posting until refreshed', async () => {
    vi.mocked(hubRequest).mockRejectedValue(
        new TypeError('Network unavailable'),
    );
    render(
        <RecognitionConversation
            kudos={post}
            actorId={7}
            writable
            onChange={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByRole('textbox', { name: 'Write a comment' }), {
        target: { value: 'Thank you again' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Post' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Network unavailable',
    );
    expect(
        screen.getByRole('textbox', { name: 'Write a comment' }),
    ).toHaveValue('Thank you again');
    expect(screen.getByRole('button', { name: 'Post' })).toBeDisabled();
    expect(hubRequest).toHaveBeenCalledTimes(1);
});
it('requires a moderation reason and never offers reply on removed placeholders', async () => {
    vi.mocked(hubRequest).mockResolvedValue({ ...post, replies: [] });
    render(
        <RecognitionConversation
            kudos={{
                ...post,
                replies: [
                    { id: 6, name: 'Colleague', body: null, removed: true },
                    {
                        id: 7,
                        name: 'Colleague',
                        body: 'Hidden text',
                        hidden: true,
                        can_moderate: true,
                        version: 3,
                    },
                ],
            }}
            actorId={7}
            writable
            onChange={vi.fn()}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Reply' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Restore' }));
    expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Moderation reason' }),
        { target: { value: 'Reviewed and appropriate' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
    await waitFor(() =>
        expect(hubRequest).toHaveBeenCalledWith(
            '/company-hub/recognition/1/replies/7/moderation',
            'PATCH',
            {
                actor_id: 7,
                version: 3,
                hidden: false,
                reason: 'Reviewed and appropriate',
            },
        ),
    );
});
