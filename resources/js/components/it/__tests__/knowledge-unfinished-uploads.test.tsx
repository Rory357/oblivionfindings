import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import { KnowledgeUnfinishedUploads } from '../knowledge-unfinished-uploads';

const initial = {
    files: [
        {
            id: 13,
            name: 'Private unfinished.pdf',
            state: 'scan_unavailable',
            expected_version: 4,
            created_at: '2026-09-13T00:00:00Z',
            can_retry: true,
        },
    ],
    next_before_id: null,
};
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('retries the original file and version then refreshes only after a matching committed response', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockResolvedValue({
            data: {
                actor_user_id: 7,
                article_id: 23,
                file_id: 13,
                state: 'ready',
            },
        });
    const reload = vi
        .spyOn(router, 'reload')
        .mockImplementation(() => undefined);
    render(
        <KnowledgeUnfinishedUploads
            initial={initial}
            actorId={7}
            articleId={23}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry check and save' }),
    );
    await waitFor(() => expect(reload).toHaveBeenCalledOnce());
    expect(post).toHaveBeenCalledWith('/it/knowledge/23/files/13/retry', {
        actor_user_id: 7,
        lock_version: 4,
    });
});

it('conceals unfinished upload metadata when the reply belongs to a different account', async () => {
    vi.spyOn(axios, 'post').mockResolvedValue({
        data: { actor_user_id: 8, article_id: 23, file_id: 13, state: 'ready' },
    });
    const reload = vi
        .spyOn(router, 'reload')
        .mockImplementation(() => undefined);
    render(
        <KnowledgeUnfinishedUploads
            initial={initial}
            actorId={7}
            articleId={23}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry check and save' }),
    );
    await waitFor(() =>
        expect(
            screen.queryByText('Private unfinished.pdf'),
        ).not.toBeInTheDocument(),
    );
    expect(reload).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Check upload status' }),
    ).toBeEnabled();
});

it('retains an uncertain upload and offers a status check without automatically retrying', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValue(new Error('Connection interrupted'));
    render(
        <KnowledgeUnfinishedUploads
            initial={initial}
            actorId={7}
            articleId={23}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry check and save' }),
    );
    await screen.findByRole('alert');
    expect(screen.getByText('Private unfinished.pdf')).toBeVisible();
    expect(post).toHaveBeenCalledOnce();
    expect(
        screen.getByRole('button', { name: 'Check upload status' }),
    ).toBeEnabled();
});
