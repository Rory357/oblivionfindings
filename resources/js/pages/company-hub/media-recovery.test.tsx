import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { MediaLibrary, type HubMedia } from './_media-library';
import { MediaUpload } from './_media-picker';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
    usePage: () => ({ props: { auth: { user: { id: 21 } } } }),
}));
const item: HubMedia = {
    id: 7,
    hub_page_id: 5,
    name: 'Welcome.png',
    page_title: 'Welcome',
    mime: 'image/png',
    size: 24000,
    url: '/company-hub/media/7',
    alt: 'Colleagues in a garden',
    album_id: null,
    version: 3,
    crop: null,
    caption: 'A welcoming afternoon',
    focal_x: 30,
    focal_y: 60,
    removed_at: null,
    can_remove: true,
    can_restore: false,
};
const library = {
    media: [item],
    albums: [],
    pages: [{ id: 5, title: 'Welcome' }],
    total: 1,
    next_page: null,
    actor_id: 21,
    metadata_enabled: true,
    recovery_enabled: true,
};
beforeEach(() => {
    request.mockReset();
});
async function openDetails() {
    fireEvent.click(
        await screen.findByRole('button', { name: 'Details for Welcome.png' }),
    );
}

it('saves a caption and non-destructive image position with actor/version guards', async () => {
    const updated = {
        ...item,
        caption: 'Our new starters',
        focal_x: 72,
        focal_y: 18,
        version: 4,
    };
    request
        .mockResolvedValueOnce(library)
        .mockResolvedValueOnce(updated)
        .mockResolvedValue({ ...library, media: [updated] });
    render(<MediaLibrary pageId={5} />);
    await openDetails();
    fireEvent.change(screen.getByLabelText('Caption'), {
        target: { value: 'Our new starters' },
    });
    fireEvent.change(
        screen.getByRole('slider', { name: 'Horizontal image position' }),
        { target: { value: 72 } },
    );
    fireEvent.change(
        screen.getByRole('slider', { name: 'Vertical image position' }),
        { target: { value: 18 } },
    );
    expect(
        screen.getByRole('button', { name: 'Remove from library' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Save details' }),
        ).toBeDisabled(),
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/7',
        'PATCH',
        {
            name: item.name,
            alt: item.alt,
            album_id: null,
            version: 3,
            actor_id: 21,
            caption: 'Our new starters',
            focal_x: 72,
            focal_y: 18,
        },
    );
    expect(screen.getByLabelText('Caption')).toHaveValue('Our new starters');
    expect(screen.getByRole('button', { name: 'Close details' })).toBeEnabled();
});

it('retains unsaved caption and position after a stale-version response', async () => {
    request
        .mockResolvedValueOnce(library)
        .mockRejectedValueOnce(new Error('Another editor changed this image.'));
    render(<MediaLibrary pageId={5} />);
    await openDetails();
    fireEvent.change(screen.getByLabelText('Caption'), {
        target: { value: 'Keep these words' },
    });
    fireEvent.change(
        screen.getByRole('slider', { name: 'Horizontal image position' }),
        { target: { value: 84 } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Another editor changed this image.',
    );
    expect(screen.getByLabelText('Caption')).toHaveValue('Keep these words');
    expect(
        screen.getByRole('slider', { name: 'Horizontal image position' }),
    ).toHaveValue('84');
    fireEvent.keyDown(screen.getByRole('region', { name: 'File details' }), {
        key: 'Escape',
    });
    expect(
        screen.getByRole('region', { name: 'File details' }),
    ).toBeInTheDocument();
});

it('confirms removal, prevents duplicates, and keeps removed media unavailable for new selection', async () => {
    const removed = {
        ...item,
        removed_at: '2026-10-14T01:00:00Z',
        can_remove: false,
        can_restore: true,
        version: 4,
    };
    let finish!: (value: HubMedia) => void;
    request
        .mockResolvedValueOnce(library)
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        )
        .mockResolvedValue({ ...library, media: [], total: 0 });
    const onSelect = vi.fn();
    render(<MediaLibrary pageId={5} onSelect={onSelect} />);
    await openDetails();
    fireEvent.click(
        screen.getByRole('button', { name: 'Remove from library' }),
    );
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Pages that already use this file keep working.',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Move to Removed' }));
    expect(screen.getByRole('button', { name: 'Moving…' })).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Close details' }),
    ).toBeDisabled();
    fireEvent.keyDown(screen.getByRole('region', { name: 'File details' }), {
        key: 'Escape',
    });
    expect(
        screen.getByRole('region', { name: 'File details' }),
    ).toBeInTheDocument();
    expect(request.mock.calls.filter((call) => call[1] === 'PUT')).toHaveLength(
        1,
    );
    await act(async () => finish(removed));
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/7/availability',
        'PUT',
        { actor_id: 21, version: 3, action: 'remove' },
    );
    expect(
        await screen.findByRole('button', { name: 'Restore to library' }),
    ).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Crop image' })).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Create a copy' }),
    ).toBeDisabled();
    expect(onSelect).not.toHaveBeenCalled();
});

it('restores a removed item with its current version and returns it to available files', async () => {
    const removed = {
        ...item,
        removed_at: '2026-10-14T01:00:00Z',
        can_remove: false,
        can_restore: true,
        version: 4,
    };
    const restored = { ...item, version: 5 };
    request.mockImplementation((url: string, method: string) => {
        if (method === 'PUT') return Promise.resolve(restored);
        return Promise.resolve({
            ...library,
            media: url.includes('state=removed') ? [removed] : [],
            total: url.includes('state=removed') ? 1 : 0,
        });
    });
    render(<MediaLibrary pageId={5} onSelect={vi.fn()} />);
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Removed' })).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Removed' }));
    await openDetails();
    expect(
        screen.queryByRole('button', { name: 'Use Welcome.png' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Restore to library' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Remove from library' }),
        ).toBeEnabled(),
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/7/availability',
        'PUT',
        { actor_id: 21, version: 4, action: 'restore' },
    );
    expect(screen.getByRole('button', { name: 'Create a copy' })).toBeEnabled();
});

it('keeps legacy metadata usable while the additive media update is unavailable', async () => {
    request.mockResolvedValue({
        ...library,
        metadata_enabled: false,
        recovery_enabled: false,
    });
    render(<MediaLibrary pageId={5} />);
    await openDetails();
    expect(screen.getByLabelText('Image description')).toBeEnabled();
    expect(screen.queryByLabelText('Caption')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Remove from library' }),
    ).not.toBeInTheDocument();
});

it('does not claim success when a removal response is for a different file', async () => {
    request.mockResolvedValueOnce(library).mockResolvedValueOnce({
        ...item,
        id: 900,
        version: 4,
        removed_at: '2026-10-14T01:00:00Z',
    });
    render(<MediaLibrary pageId={5} />);
    await openDetails();
    fireEvent.click(
        screen.getByRole('button', { name: 'Remove from library' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Move to Removed' }));
    expect(
        await screen.findByText(/The file change could not be confirmed/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Restore to library' }),
    ).not.toBeInTheDocument();
});

it('edits beside the library, protects unfinished details, and returns keyboard focus after closing', async () => {
    request.mockResolvedValue(library);
    render(<MediaLibrary pageId={5} />);
    await openDetails();
    const panel = screen.getByRole('region', { name: 'File details' });
    expect(panel.parentElement).toHaveClass(
        'hub-media-selection',
        'has-details',
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(panel).toHaveFocus();
    fireEvent.change(screen.getByLabelText('Caption'), {
        target: { value: 'An unfinished caption' },
    });
    expect(
        screen.getByRole('textbox', { name: 'Search media' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Details for Welcome.png' }),
    ).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Removed' })).toBeDisabled();
    fireEvent.keyDown(panel, { key: 'Escape' });
    expect(screen.getByLabelText('Caption')).toHaveValue(
        'An unfinished caption',
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Discard changes and close' }),
    );
    expect(
        screen.queryByRole('region', { name: 'File details' }),
    ).not.toBeInTheDocument();
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Details for Welcome.png' }),
        ).toHaveFocus(),
    );
    expect(request.mock.calls.filter((call) => call[1] !== 'GET')).toHaveLength(
        0,
    );
});

it('closes only the clean inspector on Escape inside the image chooser and protects unfinished details', async () => {
    request.mockResolvedValue(library);
    render(<MediaUpload pageId={5} label="Hero image" onChange={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Media library' }));
    await openDetails();
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    fireEvent.keyDown(screen.getByRole('region', { name: 'File details' }), {
        key: 'Escape',
    });
    expect(
        screen.queryByRole('region', { name: 'File details' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('dialog', { name: 'Choose from the media library' }),
    ).toBeVisible();
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Details for Welcome.png' }),
        ).toHaveFocus(),
    );
    await openDetails();
    fireEvent.change(screen.getByLabelText('Caption'), {
        target: { value: 'Unfinished draft' },
    });
    fireEvent.keyDown(screen.getByRole('region', { name: 'File details' }), {
        key: 'Escape',
    });
    expect(screen.getByLabelText('Caption')).toHaveValue('Unfinished draft');
    expect(
        screen.getByRole('dialog', { name: 'Choose from the media library' }),
    ).toBeVisible();
});
