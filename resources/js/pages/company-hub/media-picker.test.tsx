import { act, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { MediaUpload } from './_media-picker';

const state = vi.hoisted(() => ({ actor: 11, request: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: state.actor } } } }),
    router: {},
}));
vi.mock('./_api', () => ({ hubRequest: state.request }));
vi.mock('./_media-library', () => ({ MediaLibrary: () => null }));
beforeEach(() => {
    state.actor = 11;
    state.request.mockReset();
});
const receipt = (id = 7, actor = 11) => ({
    actor_id: actor,
    page_id: 5,
    media: { id, name: `Guide ${id}.pdf`, size: 2048 },
});
const picker = (mediaId = 7) => (
    <MediaUpload
        pageId={5}
        mediaId={mediaId}
        onChange={vi.fn()}
        label="Document"
        file
    />
);

it('hydrates the exact saved file name and size when an editor reopens its block', async () => {
    state.request.mockResolvedValue(receipt());
    render(picker());
    expect(
        await screen.findByRole('link', { name: 'Guide 7.pdf · 2 KB' }),
    ).toHaveAttribute('href', '/company-hub/media/7');
    expect(state.request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/7/details?actor_id=11',
        'GET',
    );
});
it('ignores a late previous-file response and clears metadata when the account changes', async () => {
    let resolveOld!: (value: unknown) => void;
    state.request
        .mockReturnValueOnce(
            new Promise((resolve) => {
                resolveOld = resolve;
            }),
        )
        .mockResolvedValueOnce(receipt(8));
    const view = render(picker());
    view.rerender(picker(8));
    await screen.findByRole('link', { name: 'Guide 8.pdf · 2 KB' });
    await act(async () => resolveOld(receipt()));
    expect(screen.queryByText('Guide 7.pdf · 2 KB')).not.toBeInTheDocument();
    state.actor = 22;
    state.request.mockResolvedValue(receipt(8));
    view.rerender(picker(8));
    expect(screen.queryByText('Guide 8.pdf · 2 KB')).not.toBeInTheDocument();
    await act(async () => {});
    expect(
        screen.getByRole('link', { name: 'Download selected file' }),
    ).toBeVisible();
});
it('retains the protected file link when metadata is unavailable', async () => {
    state.request.mockRejectedValue(new Error('No access'));
    render(picker());
    await act(async () => {});
    expect(
        screen.getByRole('link', { name: 'Download selected file' }),
    ).toHaveAttribute('href', '/company-hub/media/7');
});
