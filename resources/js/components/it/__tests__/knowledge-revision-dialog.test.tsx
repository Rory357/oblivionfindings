import type { KbRow } from '@/components/it/it-wizards';
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
import { afterEach, expect, it, vi } from 'vitest';
import { KnowledgeRevisionDialog } from '../knowledge-revision-dialog';

const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({ router: { post, reload: vi.fn() } }));
vi.mock('@/components/it/it-wizards', () => ({
    KbPreview: ({ body }: { body: string }) => <p>{body}</p>,
}));
vi.mock('@/components/it/knowledge-related-records', () => ({
    KnowledgeRelatedRecords: () => null,
}));

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    post.mockReset();
});

it('keeps publication identity and locks the confirmation while saving, then retains a failed proposal', async () => {
    const article = {
        id: 23,
        title: 'Synthetic runbook',
        lock_version: 4,
        status: 'published',
        can: { author: false, review: true, manage: true },
    } as KbRow;
    vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            actor_user_id: 7,
            article_id: 23,
            lock_version: 4,
            current_content: {
                title: 'Synthetic runbook',
                body: 'Current publication.',
            },
            working_copy: {
                status: 'in_review',
                content: {
                    title: 'Synthetic runbook',
                    body: 'Proposed recovery steps.',
                    review_due_at: '2026-10-12',
                },
            },
            revisions: [],
            next_before_revision: null,
        },
    });
    const close = vi.fn();
    render(
        <KnowledgeRevisionDialog
            article={article}
            actorId={7}
            onClose={close}
        />,
    );
    await waitFor(() =>
        expect(screen.getByText('Proposed recovery steps.')).toBeVisible(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Approve & publish' }));
    const confirmation = screen.getByRole('alertdialog');
    fireEvent.click(
        within(confirmation).getByRole('button', { name: 'Publish revision' }),
    );
    expect(
        within(confirmation).getByRole('button', { name: 'Saving…' }),
    ).toBeDisabled();
    expect(
        within(confirmation).getByRole('button', { name: 'Cancel' }),
    ).toBeDisabled();
    expect(
        within(confirmation).getByRole('heading', {
            name: 'Publish this reviewed revision?',
        }),
    ).toBeVisible();
    expect(
        screen.queryByText('Discard the proposed revision?'),
    ).not.toBeInTheDocument();
    fireEvent.keyDown(confirmation, { key: 'Escape' });
    expect(screen.getByRole('alertdialog')).toBeVisible();
    expect(close).not.toHaveBeenCalled();
    expect(post).toHaveBeenCalledOnce();
    expect(post.mock.calls[0]!.slice(0, 2)).toEqual([
        '/it/kb/23/publish',
        { actor_user_id: 7, lock_version: 4 },
    ]);
    const callbacks = post.mock.calls[0]![2] as {
        onError: (errors: Record<string, string>) => void;
        onFinish: () => void;
    };
    act(() => {
        callbacks.onError({
            article: 'Review ownership changed. Check the current owner.',
        });
        callbacks.onFinish();
    });
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    expect(screen.getByText('Proposed recovery steps.')).toBeVisible();
    expect(
        screen.getByText('Review ownership changed. Check the current owner.'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Approve & publish' }));
    expect(screen.getByRole('alertdialog')).toHaveTextContent(
        'Publish this reviewed revision?',
    );
});
