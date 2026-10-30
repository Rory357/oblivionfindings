import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import { HubBookmarkButton, HubBookmarksProvider } from './_bookmarks';
const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
const subject = { subject_type: 'page' as const, subject_id: 50 };
const version = 'a'.repeat(64);
const state = { actor_id: 11, ...subject, bookmarked: true, version };
const receipt = { ...state, saved: true, changed: true, previous_saved: false };
function Harness({
    actor = 11,
    saved = false,
    show = true,
}: {
    actor?: number;
    saved?: boolean;
    show?: boolean;
}) {
    return (
        <HubBookmarksProvider
            key={actor}
            actorId={actor}
            initial={saved ? [{ ...subject, version }] : []}
        >
            {show && (
                <HubBookmarkButton
                    subject={subject}
                    title="Learning week"
                    saved={saved}
                    compact
                />
            )}
        </HubBookmarksProvider>
    );
}
beforeEach(() => {
    request.mockReset().mockResolvedValue(receipt);
});
it('offers Undo only after a confirmed exact save and sends its exact inverse version', async () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'Save Learning week' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Learning week' }));
    expect(request).toHaveBeenCalledTimes(1);
    expect(request).toHaveBeenCalledWith('/company-hub/bookmarks', 'POST', {
        hub_receipt: true,
        actor_id: 11,
        ...subject,
        saved: true,
        expected_saved: false,
        expected_version: null,
    });
    await screen.findByRole('button', { name: 'Undo saved change' });
    request.mockResolvedValueOnce({
        ...receipt,
        bookmarked: false,
        version: null,
        previous_saved: true,
    });
    fireEvent.click(screen.getByRole('button', { name: 'Undo saved change' }));
    expect(request).toHaveBeenLastCalledWith('/company-hub/bookmarks', 'POST', {
        hub_receipt: true,
        actor_id: 11,
        ...subject,
        saved: false,
        expected_saved: true,
        expected_version: version,
    });
    await screen.findByText(/change undone/);
    expect(
        screen.getByRole('button', { name: 'Save Learning week' }),
    ).toBeEnabled();
});
it('retains Undo when the saved item disappears from a filtered list', async () => {
    request.mockResolvedValueOnce({
        ...receipt,
        bookmarked: false,
        version: null,
        previous_saved: true,
    });
    const view = render(<Harness saved />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Unsave Learning week' }),
    );
    await screen.findByRole('button', { name: 'Undo saved change' });
    view.rerender(<Harness saved show={false} />);
    request.mockResolvedValueOnce(receipt);
    fireEvent.click(screen.getByRole('button', { name: 'Undo saved change' }));
    expect(request).toHaveBeenLastCalledWith('/company-hub/bookmarks', 'POST', {
        hub_receipt: true,
        actor_id: 11,
        ...subject,
        saved: true,
        expected_saved: false,
        expected_version: null,
    });
    await screen.findByText(/change undone/);
});
it('blocks blind repeats after an unknown outcome and recovers with GET without inventing Undo', async () => {
    request.mockRejectedValueOnce(new Error('Connection interrupted.'));
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'Save Learning week' }));
    await screen.findByRole('button', { name: 'Check saved state' });
    expect(
        screen.getByRole('button', { name: 'Save Learning week' }),
    ).toBeDisabled();
    request.mockResolvedValueOnce(state);
    fireEvent.click(screen.getByRole('button', { name: 'Check saved state' }));
    await screen.findByRole('button', { name: 'Unsave Learning week' });
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/bookmarks/state?actor_id=11&subject_type=page&subject_id=50',
        'GET',
    );
    expect(
        screen.queryByRole('button', { name: 'Undo saved change' }),
    ).not.toBeInTheDocument();
});
it('does not reverse another generation when Undo encounters a conflict', async () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'Save Learning week' }));
    await screen.findByRole('button', { name: 'Undo saved change' });
    request.mockRejectedValueOnce(
        new HubRequestError('Saved item changed elsewhere.', 409),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Undo saved change' }));
    await screen.findByRole('button', { name: 'Check saved state' });
    expect(
        screen.getByRole('button', { name: 'Unsave Learning week' }),
    ).toBeDisabled();
    expect(request).toHaveBeenCalledTimes(2);
});
it.each([
    { ...receipt, actor_id: 99 },
    { ...receipt, subject_id: 99 },
    { ...receipt, version: null },
])('rejects a mismatched receipt', async (invalid) => {
    request.mockResolvedValueOnce(invalid);
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'Save Learning week' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
    expect(
        screen.queryByRole('button', { name: 'Undo saved change' }),
    ).not.toBeInTheDocument();
});
it('ignores pending results from a previous account', async () => {
    let finish!: (value: unknown) => void;
    request.mockReturnValueOnce(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const view = render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'Save Learning week' }));
    view.rerender(<Harness actor={99} />);
    await act(async () => finish(receipt));
    expect(
        screen.queryByRole('button', { name: 'Undo saved change' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Save Learning week' }),
    ).toBeEnabled();
});
it.each([true, false])(
    'keeps a confirmed saved state of %s when an older page response arrives',
    async (saved) => {
        request.mockResolvedValueOnce({
            ...receipt,
            bookmarked: saved,
            previous_saved: !saved,
            version: saved ? version : null,
        });
        const view = render(<Harness saved={!saved} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: `${saved ? 'Save' : 'Unsave'} Learning week`,
            }),
        );
        await screen.findByRole('button', { name: 'Undo saved change' });
        view.rerender(<Harness saved={!saved} />);
        expect(
            screen.getByRole('button', {
                name: `${saved ? 'Unsave' : 'Save'} Learning week`,
            }),
        ).toBeEnabled();
    },
);
