import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BlockContent } from './_blocks';
import { RecognitionBoard } from './_recognition';
import { RecognitionCard } from './_recognition-card';
import { hubData } from './_test-fixtures';
import type { HubKudos, HubRecognition } from './_types';

const { request, post, reload, replyPost } = vi.hoisted(() => ({
    request: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    replyPost: vi.fn(),
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post, reload, on: vi.fn(() => vi.fn()) },
    useForm: (initial: { body: string; kind: string }) => {
        const [draft, setDraft] = useState(initial);
        return {
            data: draft,
            errors: {},
            processing: false,
            setData: (field: string, value: string) =>
                setDraft((current) => ({ ...current, [field]: value })),
            reset: () => setDraft(initial),
            post: (url: string, options: unknown) =>
                replyPost(url, draft, options),
        };
    },
}));
const person: HubKudos = {
    id: 10,
    message: 'A warm welcome to the learning circle.',
    from: 'Jamie',
    to: 'Aroha',
    to_id: 11,
    recipient_type: 'person',
    category: 'Teamwork',
    created_at: '2026-10-13T01:00:00Z',
    can_reply: false,
    reactions: { heart: { count: 2, mine: false } },
    replies: [],
    team_ids: [3],
    team_name: 'Learning',
};
const team: HubKudos = {
    ...person,
    id: 20,
    message: 'Every contribution made a difference.',
    to: 'Garden team',
    to_id: null,
    recipient_type: 'team',
    to_department_id: 4,
    team_name: 'Community garden',
    team_ids: [4],
    category: 'Kindness',
    created_at: '2026-10-12T01:00:00Z',
    reactions: { heart: { count: 12, mine: false } },
};
const recognition: HubRecognition = {
    actor_id: 1,
    version: 4,
    can_feature: true,
    spotlight: person,
    selection_unavailable: false,
};
const data = hubData({
    kudos: [person, team],
    recognition,
    capabilities: { recognise: true },
    interests: {
        actor_id: 11,
        topics: [],
        teams: [],
        version: 1,
        available_topics: [],
        available_teams: [
            { id: 3, name: 'Learning' },
            { id: 4, name: 'Community garden' },
        ],
    },
});

beforeEach(() => {
    request.mockReset();
    post.mockReset();
    reload.mockReset();
    replyPost.mockReset();
});

it('keeps spotlight changes from replacing a card with an unsent comment', () => {
    render(
        <RecognitionBoard
            data={{ ...data, kudos: [{ ...person, can_reply: true }, team] }}
        />,
    );
    const card = within(
        screen.getByRole('article', { name: 'Shout-out for Aroha' }),
    );
    fireEvent.click(card.getByRole('button', { name: 'Reply' }));
    fireEvent.change(card.getByRole('textbox', { name: 'Write a comment' }), {
        target: { value: 'A thought to keep' },
    });
    expect(
        screen.getByRole('button', { name: 'Feature Garden team' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
    ).toBeDisabled();
    fireEvent.change(card.getByRole('textbox', { name: 'Write a comment' }), {
        target: { value: '' },
    });
    expect(
        screen.getByRole('button', { name: 'Feature Garden team' }),
    ).toBeEnabled();
});

it('shows an immediate independent reaction and rolls it back when saving is not confirmed', () => {
    render(
        <RecognitionCard
            kudos={{
                ...person,
                reactions: {
                    ...person.reactions,
                    party: { count: 4, mine: true },
                },
            }}
            data={data}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Appreciate Aroha' }));
    expect(
        screen.getByRole('button', { name: 'Appreciate Aroha' }),
    ).toHaveAttribute('aria-pressed', 'true');
    expect(
        screen.getByRole('button', { name: 'Celebrate Aroha' }),
    ).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('7 reactions')).toBeVisible();
    expect(post).toHaveBeenCalledTimes(1);
    act(() => post.mock.calls[0][2].onFinish());
    expect(
        screen.getByRole('button', { name: 'Appreciate Aroha' }),
    ).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByText('6 reactions')).toBeVisible();
    expect(screen.getByRole('alert')).toHaveTextContent('could not confirm');
});

it('reconciles a successful reaction against the returned server counts', () => {
    const view = render(<RecognitionCard kudos={person} data={data} />);
    fireEvent.click(screen.getByRole('button', { name: 'Appreciate Aroha' }));
    act(() => post.mock.calls[0][2].onSuccess());
    view.rerender(
        <RecognitionCard
            kudos={{
                ...person,
                reactions: { heart: { count: 5, mine: true } },
            }}
            data={data}
        />,
    );
    act(() => post.mock.calls[0][2].onFinish());
    expect(screen.getByText('5 reactions')).toBeVisible();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('reveals and focuses the exact authorised older recognition through active filters without duplicates', async () => {
    const view = render(<RecognitionBoard data={data} />);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
        { target: { value: 'no match' } },
    );
    const older = { ...person, id: 300, to: 'Older colleague' };
    view.rerender(
        <RecognitionBoard
            data={{ ...data, selectedRecognition: older, recognitionId: 300 }}
        />,
    );
    const article = await screen.findByRole('article', {
        name: 'Shout-out for Older colleague',
    });
    await waitFor(() => expect(article).toHaveFocus());
    expect(
        screen.getAllByRole('article', {
            name: 'Shout-out for Older colleague',
        }),
    ).toHaveLength(1);
    view.rerender(
        <RecognitionBoard
            data={{
                ...data,
                kudos: [older, ...data.kudos],
                selectedRecognition: older,
                recognitionId: 300,
            }}
        />,
    );
    expect(
        screen.getAllByRole('article', {
            name: 'Shout-out for Older colleague',
        }),
    ).toHaveLength(1);
    view.rerender(
        <RecognitionBoard
            data={{
                ...data,
                selectedRecognition: null,
                recognitionId: null,
                recognitionUnavailable: true,
            }}
        />,
    );
    expect(
        screen.queryByRole('article', {
            name: 'Shout-out for Older colleague',
        }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByText('This shout-out is no longer available to you.'),
    ).toBeVisible();
});

it('removes an authored reply through its own conversation and applies the canonical response', async () => {
    request.mockResolvedValue({ ...person, replies: [] });
    render(
        <RecognitionBoard
            data={{
                ...data,
                kudos: [
                    {
                        ...person,
                        replies: [
                            {
                                id: 31,
                                name: 'Jamie',
                                body: 'Glad to be part of it.',
                                version: 2,
                                can_remove: true,
                            },
                        ],
                    },
                ],
            }}
        />,
    );
    const board = within(screen.getByRole('region', { name: 'Shout-outs' }));
    fireEvent.click(board.getByText('1 reply'));
    fireEvent.click(board.getByRole('button', { name: 'Remove' }));
    await act(async () => {
        fireEvent.click(board.getByRole('button', { name: 'Confirm' }));
    });
    expect(request).toHaveBeenCalledWith(
        '/company-hub/recognition/10/replies/31',
        'DELETE',
        { actor_id: 1, version: 2 },
    );
    expect(board.queryByText('Glad to be part of it.')).not.toBeInTheDocument();
});
it('opens replies on demand, retains a collapsed draft and posts to the same shout-out', async () => {
    let finish!: (value: unknown) => void;
    request.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    render(
        <RecognitionCard kudos={{ ...person, can_reply: true }} data={data} />,
    );
    expect(
        screen.queryByRole('textbox', { name: 'Write a comment' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Write a comment' }), {
        target: { value: 'Thank you for making everyone feel welcome.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Close replies' }));
    expect(
        screen.queryByRole('textbox', { name: 'Write a comment' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reply' })).toHaveFocus();
    fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
    expect(
        screen.getByRole('textbox', { name: 'Write a comment' }),
    ).toHaveValue('Thank you for making everyone feel welcome.');
    fireEvent.click(screen.getByRole('button', { name: 'Post' }));
    expect(request).toHaveBeenCalledWith(
        '/company-hub/recognition/10/replies',
        'POST',
        expect.any(FormData),
    );
    expect(request.mock.calls[0][2].get('body')).toBe(
        'Thank you for making everyone feel welcome.',
    );
    expect(request.mock.calls[0][2].get('actor_id')).toBe('1');
    expect(
        screen.getByRole('textbox', { name: 'Write a comment' }),
    ).toHaveValue('Thank you for making everyone feel welcome.');
    await act(async () =>
        finish({
            ...person,
            can_reply: true,
            replies: [
                {
                    id: 42,
                    name: 'You',
                    body: 'Thank you for making everyone feel welcome.',
                },
            ],
        }),
    );
    expect(
        screen.getByRole('textbox', { name: 'Write a comment' }),
    ).toHaveValue('');
    expect(screen.getByRole('status')).toHaveTextContent(
        'Your comment was posted.',
    );
});

it('still refreshes a removed reply when the conversation is collapsed while saving', async () => {
    let finish!: (value: unknown) => void;
    request.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    render(
        <RecognitionCard
            kudos={{
                ...person,
                replies: [
                    {
                        id: 31,
                        name: 'Jamie',
                        body: 'Thanks!',
                        version: 1,
                        can_remove: true,
                    },
                ],
            }}
            data={data}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: '1 reply' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
    fireEvent.click(screen.getByRole('button', { name: 'Close replies' }));
    await act(async () => finish({ ...person, replies: [] }));
    expect(
        screen.queryByRole('button', { name: '1 reply' }),
    ).not.toBeInTheDocument();
});

it('does not expose reaction, reply or reply-removal writes in a block preview', () => {
    render(
        <BlockContent
            block={{
                id: 'recognition',
                type: 'shoutouts',
                title: 'Recognition',
            }}
            preview
            data={{
                ...data,
                kudos: [
                    {
                        ...person,
                        can_reply: true,
                        replies: [
                            {
                                id: 31,
                                name: 'Jamie',
                                body: 'A thoughtful welcome.',
                                version: 1,
                                can_remove: true,
                            },
                        ],
                    },
                ],
            }}
            onEvent={vi.fn()}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Appreciate Aroha' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: '1 reply' }));
    expect(screen.getByText('A thoughtful welcome.')).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Remove' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('textbox', { name: 'Write a comment' }),
    ).not.toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});

it('clears an empty search without removing the real feed or its shared spotlight', () => {
    render(<RecognitionBoard data={data} />);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
        { target: { value: 'no matching moment' } },
    );
    expect(screen.getByText('No shout-outs in this view')).toBeVisible();
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Clear filters' })[0],
    );
    expect(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
    ).toHaveValue('');
    expect(
        within(screen.getByRole('region', { name: 'Shout-outs' })).getByRole(
            'heading',
            { name: 'Aroha' },
        ),
    ).toBeVisible();
    expect(
        within(
            screen.getByRole('region', { name: 'Recognition spotlight' }),
        ).getByRole('heading', { name: 'Aroha' }),
    ).toBeVisible();
});

describe('shared recognition spotlight', () => {
    it('opens the authorised spotlight conversation even when it is outside the loaded feed', () => {
        render(<RecognitionBoard data={{ ...data, kudos: [team] }} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search shout-outs' }),
            { target: { value: 'Garden' } },
        );
        expect(
            within(
                screen.getByRole('region', { name: 'Shout-outs' }),
            ).queryByRole('heading', { name: 'Aroha' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Read shout-out' }));
        expect(
            screen.getByRole('textbox', { name: 'Search shout-outs' }),
        ).toHaveValue('');
        const feed = within(screen.getByRole('region', { name: 'Shout-outs' }));
        expect(feed.getByRole('heading', { name: 'Aroha' })).toBeVisible();
        expect(
            feed.getByRole('button', { name: 'Appreciate Aroha' }),
        ).toBeEnabled();
    });
    it('filters actual team recognition and department scope without replacing the shared spotlight', async () => {
        render(<RecognitionBoard data={data} />);
        expect(
            screen.queryByRole('combobox', { name: 'Recognition team' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Filters' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Team recognition' }),
        );
        const cards = within(
            screen.getByRole('region', { name: 'Shout-outs' }),
        );
        expect(
            cards.queryByRole('heading', { name: 'Aroha' }),
        ).not.toBeInTheDocument();
        expect(
            cards.getByRole('heading', { name: 'Garden team' }),
        ).toBeVisible();
        expect(
            within(
                screen.getByRole('region', { name: 'Recognition spotlight' }),
            ).getByRole('heading', { name: 'Aroha' }),
        ).toBeVisible();
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Recognition team' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Learning' }),
        );
        expect(screen.getByText('No shout-outs in this view')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Team recognition' }),
        );
        expect(cards.getByRole('heading', { name: 'Aroha' })).toBeVisible();
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Recognition team' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'All teams' }),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Recognition order' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Most celebrated' }),
        );
        expect(
            cards
                .getAllByRole('heading', { level: 3 })
                .map((item) => item.textContent),
        ).toEqual(['Garden team', 'Aroha']);
        fireEvent.click(
            screen.getByRole('button', { name: 'Appreciate Garden team' }),
        );
        expect(post).toHaveBeenCalledWith(
            '/hr/feed/kudos/20/react',
            { emoji: 'heart' },
            expect.any(Object),
        );
    });
    it('waits for the server and advances the shared version on feature and clear', async () => {
        let finish!: (value: HubRecognition) => void;
        request.mockReturnValueOnce(
            new Promise<HubRecognition>((resolve) => {
                finish = resolve;
            }),
        );
        render(<RecognitionBoard data={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Feature Garden team' }),
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/recognition/spotlight',
            'PUT',
            { actor_id: 1, version: 4, kudos_id: 20 },
        );
        expect(
            within(
                screen.getByRole('region', { name: 'Recognition spotlight' }),
            ).getByRole('heading', { name: 'Aroha' }),
        ).toBeVisible();
        await act(async () =>
            finish({ ...recognition, version: 5, spotlight: team }),
        );
        expect(
            within(
                screen.getByRole('region', { name: 'Recognition spotlight' }),
            ).getByRole('heading', { name: 'Garden team' }),
        ).toBeVisible();
        request.mockResolvedValueOnce({
            ...recognition,
            version: 6,
            spotlight: null,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove spotlight' }),
        );
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Remove spotlight',
            }),
        );
        await act(async () => {});
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/recognition/spotlight',
            'PUT',
            { actor_id: 1, version: 5, kudos_id: null },
        );
        expect(
            screen.queryByRole('region', { name: 'Recognition spotlight' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Undo removal' }),
        ).toBeVisible();
    });
    it('cancels removal without a write and restores only through the acknowledged clear version', async () => {
        render(<RecognitionBoard data={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove spotlight' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep spotlight' }));
        expect(request).not.toHaveBeenCalled();
        request.mockResolvedValueOnce({
            ...recognition,
            version: 5,
            spotlight: null,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove spotlight' }),
        );
        await act(async () =>
            fireEvent.click(
                within(screen.getByRole('dialog')).getByRole('button', {
                    name: 'Remove spotlight',
                }),
            ),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/recognition/spotlight',
            'PUT',
            { actor_id: 1, version: 4, kudos_id: null },
        );
        request.mockResolvedValueOnce({ ...recognition, version: 6 });
        await act(async () =>
            fireEvent.click(
                screen.getByRole('button', { name: 'Undo removal' }),
            ),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/recognition/spotlight',
            'PUT',
            { actor_id: 1, version: 5, kudos_id: person.id },
        );
        expect(
            screen.getByRole('region', { name: 'Recognition spotlight' }),
        ).toHaveTextContent('Aroha');
        expect(
            screen.queryByRole('button', { name: 'Undo removal' }),
        ).not.toBeInTheDocument();
    });
    it('retains confirmation on a failed clear, locks duplicate requests and never rebases Undo over another editor', async () => {
        let fail!: (error: Error) => void;
        request.mockReturnValueOnce(
            new Promise((_resolve, reject) => {
                fail = reject;
            }),
        );
        render(<RecognitionBoard data={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove spotlight' }),
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Remove spotlight',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Removing…' }));
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(request).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('dialog')).toBeVisible();
        await act(async () =>
            fail(new Error('The preference could not be saved.')),
        );
        expect(
            within(screen.getByRole('dialog')).getByRole('alert'),
        ).toHaveTextContent('could not be saved');
        request.mockResolvedValueOnce({
            ...recognition,
            version: 5,
            spotlight: null,
        });
        await act(async () =>
            fireEvent.click(
                within(screen.getByRole('dialog')).getByRole('button', {
                    name: 'Remove spotlight',
                }),
            ),
        );
        request.mockRejectedValueOnce(
            new Error('Another editor changed the spotlight. Reload first.'),
        );
        await act(async () =>
            fireEvent.click(
                screen.getByRole('button', { name: 'Undo removal' }),
            ),
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Another editor');
        expect(
            screen.queryByRole('region', { name: 'Recognition spotlight' }),
        ).not.toBeInTheDocument();
        request.mockResolvedValueOnce({
            ...recognition,
            version: 7,
            spotlight: team,
        });
        await act(async () =>
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh spotlight' }),
            ),
        );
        expect(
            screen.queryByRole('button', { name: 'Undo removal' }),
        ).not.toBeInTheDocument();
        expect(request).toHaveBeenCalledTimes(4);
        expect(
            screen.getByRole('region', { name: 'Recognition spotlight' }),
        ).toHaveTextContent('Garden team');
    });
    it('drops a pending removal when the canonical selection changes and does not offer Undo for an unavailable selection', async () => {
        const view = render(<RecognitionBoard data={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove spotlight' }),
        );
        view.rerender(
            <RecognitionBoard
                data={{
                    ...data,
                    recognition: {
                        ...recognition,
                        version: 5,
                        spotlight: team,
                    },
                }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
        view.rerender(
            <RecognitionBoard
                data={{
                    ...data,
                    recognition: {
                        ...recognition,
                        version: 6,
                        spotlight: null,
                        selection_unavailable: true,
                    },
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear selection' }),
        );
        request.mockResolvedValueOnce({
            ...recognition,
            version: 7,
            spotlight: null,
            selection_unavailable: false,
        });
        await act(async () =>
            fireEvent.click(
                within(screen.getByRole('dialog')).getByRole('button', {
                    name: 'Remove spotlight',
                }),
            ),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/recognition/spotlight',
            'PUT',
            { actor_id: 1, version: 6, kudos_id: null },
        );
        expect(
            screen.queryByRole('button', { name: 'Undo removal' }),
        ).not.toBeInTheDocument();
    });
    it('rejects wrong-account results and preserves the confirmed selection on errors', async () => {
        request.mockResolvedValueOnce({
            ...recognition,
            actor_id: 99,
            version: 5,
            spotlight: { ...team, message: 'Wrong account text' },
        });
        render(<RecognitionBoard data={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Feature Garden team' }),
        );
        await act(async () => {});
        expect(screen.getByRole('alert')).toHaveTextContent('hub changed');
        expect(
            screen.queryByText('Wrong account text'),
        ).not.toBeInTheDocument();
        request.mockRejectedValueOnce(
            new Error('Another editor changed the spotlight. Reload first.'),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Feature Garden team' }),
        );
        await act(async () => {});
        expect(screen.getByRole('alert')).toHaveTextContent('Another editor');
        expect(
            within(
                screen.getByRole('region', { name: 'Recognition spotlight' }),
            ).getByRole('heading', { name: 'Aroha' }),
        ).toBeVisible();
        request.mockResolvedValueOnce({
            ...recognition,
            version: 6,
            spotlight: team,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh spotlight' }),
        );
        await act(async () => {});
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/recognition/spotlight',
            'GET',
            undefined,
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(
            within(
                screen.getByRole('region', { name: 'Recognition spotlight' }),
            ).getByRole('heading', { name: 'Garden team' }),
        ).toBeVisible();
    });
    it('drops old responses when permissions or actor context refresh and hides editor recovery from readers', async () => {
        let finish!: (value: HubRecognition) => void;
        request.mockReturnValueOnce(
            new Promise<HubRecognition>((resolve) => {
                finish = resolve;
            }),
        );
        const view = render(<RecognitionBoard data={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Feature Garden team' }),
        );
        view.rerender(
            <RecognitionBoard
                data={{
                    ...data,
                    recognition: {
                        actor_id: 2,
                        version: 5,
                        can_feature: false,
                        spotlight: null,
                        selection_unavailable: false,
                    },
                }}
            />,
        );
        await act(async () =>
            finish({
                ...recognition,
                version: 5,
                spotlight: { ...team, message: 'Late private text' },
            }),
        );
        expect(screen.queryByText('Late private text')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Feature/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Clear selection' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <RecognitionBoard
                data={{
                    ...data,
                    recognition: {
                        ...recognition,
                        spotlight: null,
                        selection_unavailable: true,
                    },
                }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Clear selection' }),
        ).toBeVisible();
        expect(
            screen.getByText(/selected shout-out is no longer available/),
        ).toBeVisible();
    });
    it('supports ordinary projections without spotlight context and gates editor controls in the showcase', () => {
        const view = render(
            <RecognitionBoard data={{ ...data, recognition: undefined }} />,
        );
        expect(
            screen.getByRole('heading', { name: 'Garden team' }),
        ).toBeVisible();
        view.rerender(<RecognitionBoard data={{ ...data, demo: true }} />);
        expect(
            screen.queryByRole('button', { name: /Feature/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Appreciate Garden team' }),
        ).toBeDisabled();
    });
});
