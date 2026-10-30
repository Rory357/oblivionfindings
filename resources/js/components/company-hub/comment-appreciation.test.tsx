import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { CommentAppreciation } from './comment-appreciation';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('@/pages/company-hub/_api', () => ({ hubRequest: request }));
const comment = {
    id: 4,
    version: 2,
    can_appreciate: true,
    appreciation: { count: 3, mine: false },
};
beforeEach(() => {
    request.mockReset();
});

it('saves explicit appreciation state once, then supports removing appreciation', async () => {
    let finish!: (value: unknown) => void;
    request
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        )
        .mockResolvedValueOnce({
            saved: true,
            comment_id: 4,
            version: 2,
            appreciation: { count: 3, mine: false },
        });
    const onSaved = vi.fn();
    render(
        <CommentAppreciation
            actorId={11}
            pageId={8}
            revisionId={9}
            comment={comment}
            onSaved={onSaved}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    );
    expect(request).toHaveBeenCalledTimes(1);
    expect(request).toHaveBeenCalledWith(
        '/company-hub/pages/8/comments/4/appreciation',
        'PUT',
        { actor_id: 11, revision_id: 9, version: 2, active: true },
    );
    expect(onSaved).not.toHaveBeenCalled();
    await act(async () =>
        finish({
            saved: true,
            comment_id: 4,
            version: 2,
            appreciation: { count: 4, mine: true },
        }),
    );
    expect(
        screen.getByRole('button', {
            name: 'Remove appreciation from comment · 4 appreciations',
        }),
    ).toHaveAttribute('aria-pressed', 'true');
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Remove appreciation from comment · 4 appreciations',
        }),
    );
    await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(2));
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/pages/8/comments/4/appreciation',
        'PUT',
        { actor_id: 11, revision_id: 9, version: 2, active: false },
    );
});

it('does not change the visible count on failed or mismatched responses', async () => {
    request
        .mockRejectedValueOnce(
            new Error('This comment is no longer available.'),
        )
        .mockResolvedValueOnce({
            saved: true,
            comment_id: 99,
            version: 2,
            appreciation: { count: 4, mine: true },
        });
    const onSaved = vi.fn();
    render(
        <CommentAppreciation
            actorId={11}
            pageId={8}
            revisionId={9}
            comment={comment}
            onSaved={onSaved}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'This comment is no longer available.',
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    );
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Your appreciation could not be confirmed.',
        ),
    );
    expect(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    ).toHaveAttribute('aria-pressed', 'false');
    expect(onSaved).not.toHaveBeenCalled();
});

it('disables unavailable actions and drops a response after changing accounts', async () => {
    let finish!: (value: unknown) => void;
    request.mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const onSaved = vi.fn();
    const { rerender } = render(
        <CommentAppreciation
            actorId={11}
            pageId={8}
            revisionId={9}
            comment={comment}
            onSaved={onSaved}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    );
    rerender(
        <CommentAppreciation
            actorId={12}
            pageId={8}
            revisionId={9}
            comment={{ ...comment, can_appreciate: false }}
            onSaved={onSaved}
        />,
    );
    await act(async () =>
        finish({
            saved: true,
            comment_id: 4,
            version: 2,
            appreciation: { count: 4, mine: true },
        }),
    );
    expect(
        screen.getByRole('button', {
            name: 'Appreciate comment · 3 appreciations',
        }),
    ).toBeDisabled();
    expect(onSaved).not.toHaveBeenCalled();
});

it('keeps keyboard focus when refreshed conversation totals arrive', async () => {
    request.mockResolvedValue({
        saved: true,
        comment_id: 4,
        version: 2,
        appreciation: { count: 4, mine: true },
    });
    const onSaved = vi.fn();
    const { rerender } = render(
        <CommentAppreciation
            actorId={11}
            pageId={8}
            revisionId={9}
            comment={comment}
            onSaved={onSaved}
        />,
    );
    const button = screen.getByRole('button', {
        name: 'Appreciate comment · 3 appreciations',
    });
    button.focus();
    fireEvent.click(button);
    await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
    rerender(
        <CommentAppreciation
            actorId={11}
            pageId={8}
            revisionId={9}
            comment={{ ...comment, appreciation: { count: 5, mine: true } }}
            onSaved={onSaved}
        />,
    );
    expect(
        screen.getByRole('button', {
            name: 'Remove appreciation from comment · 5 appreciations',
        }),
    ).toHaveFocus();
});
