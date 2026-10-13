import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { MediaLibrary, type HubMedia } from './_media-library';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => {} } }));
const media: HubMedia = {
    id: 7,
    name: 'Workplace.png',
    mime: 'image/png',
    size: 24000,
    url: '/company-hub/media/7',
    hub_page_id: 5,
    page_title: 'Welcome',
    alt: 'A bright workplace',
    album_id: null,
    version: 3,
    crop: null,
};
const library = {
    media: [media],
    albums: [{ id: 2, name: 'Our company' }],
    pages: [{ id: 5, title: 'Welcome' }],
    total: 1,
    next_page: null,
};
beforeEach(() => {
    request.mockReset();
});
async function ready(
    props: Partial<React.ComponentProps<typeof MediaLibrary>> = {},
) {
    const onSelect = vi.fn();
    const result = render(
        <MediaLibrary pageId={5} onSelect={onSelect} {...props} />,
    );
    await screen.findByRole('button', { name: 'Details for Workplace.png' });
    return { ...result, onSelect };
}

it('rechecks the version and permissions even when selecting media already on this page', async () => {
    request.mockResolvedValueOnce(library).mockResolvedValueOnce(media);
    const { onSelect } = await ready();
    fireEvent.click(screen.getByRole('button', { name: 'Use Workplace.png' }));
    await waitFor(() => expect(onSelect).toHaveBeenCalledWith(media));
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/manage/5/media/reuse',
        'POST',
        { media_id: 7, version: 3 },
    );
});

it('preserves entered metadata on a version conflict and offers explicit discard', async () => {
    request
        .mockResolvedValueOnce(library)
        .mockRejectedValueOnce(new Error('Another editor changed this image.'));
    await ready();
    fireEvent.click(
        screen.getByRole('button', { name: 'Details for Workplace.png' }),
    );
    fireEvent.change(screen.getByLabelText('Image description'), {
        target: { value: 'My new description' },
    });
    expect(screen.getByRole('button', { name: 'Crop image' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await screen.findByRole('alert');
    expect(screen.getByLabelText('Image description')).toHaveValue(
        'My new description',
    );
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/manage/5/media/7',
        'PATCH',
        {
            name: 'Workplace.png',
            alt: 'My new description',
            album_id: null,
            version: 3,
        },
    );
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Discard changes and close' }),
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('saves crop shape and focus as a new file without replacing or selecting the original', async () => {
    const copy = {
        ...media,
        id: 8,
        name: 'Workplace — cropped.png',
        version: 1,
    };
    request
        .mockResolvedValueOnce(library)
        .mockResolvedValueOnce(copy)
        .mockResolvedValueOnce({ ...library, media: [copy, media], total: 2 });
    const { onSelect } = await ready();
    fireEvent.click(
        screen.getByRole('button', { name: 'Details for Workplace.png' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Crop image' }));
    fireEvent.change(screen.getByLabelText('Crop shape'), {
        target: { value: '1:1' },
    });
    fireEvent.change(
        screen.getByRole('slider', { name: 'Horizontal crop focus' }),
        { target: { value: '82' } },
    );
    fireEvent.change(
        screen.getByRole('slider', { name: 'Vertical crop focus' }),
        { target: { value: '26' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save crop as copy' }));
    await waitFor(() =>
        expect(screen.getByLabelText('File name')).toHaveValue(copy.name),
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/7/crop',
        'POST',
        { version: 3, ratio: '1:1', x: 82, y: 26 },
    );
    expect(onSelect).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Close details' }));
    expect(
        screen.getByRole('button', { name: 'Details for Workplace.png' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', {
            name: 'Details for Workplace — cropped.png',
        }),
    ).toBeInTheDocument();
});

it('ignores an older library response after the editor changes the search', async () => {
    let finishOld!: (value: typeof library) => void;
    request.mockImplementation((url: string) =>
        url.includes('q=welcome')
            ? Promise.resolve({ ...library, media: [], total: 0 })
            : new Promise((resolve) => {
                  finishOld = resolve;
              }),
    );
    render(<MediaLibrary />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Search media' }), {
        target: { value: 'welcome' },
    });
    await screen.findByText('No matching files');
    await act(async () => finishOld(library));
    expect(
        screen.queryByRole('button', { name: 'Details for Workplace.png' }),
    ).not.toBeInTheDocument();
});

it('does not apply a delayed selection after the picker unmounts', async () => {
    let finish!: (value: HubMedia) => void;
    request.mockResolvedValueOnce(library).mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const { onSelect, unmount } = await ready();
    fireEvent.click(screen.getByRole('button', { name: 'Use Workplace.png' }));
    unmount();
    await act(async () => finish(media));
    expect(onSelect).not.toHaveBeenCalled();
});

it('creates an album and filters to it without changing any file permissions', async () => {
    request
        .mockResolvedValueOnce(library)
        .mockResolvedValueOnce({ id: 9, name: 'Brand images' })
        .mockResolvedValue({
            ...library,
            media: [],
            albums: [...library.albums, { id: 9, name: 'Brand images' }],
            total: 0,
        });
    await ready();
    fireEvent.click(screen.getByRole('button', { name: 'New album' }));
    fireEvent.change(screen.getByLabelText('Album name'), {
        target: { value: 'Brand images' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create album' }));
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    await waitFor(() =>
        expect(request).toHaveBeenLastCalledWith(
            expect.stringContaining('album_id=9'),
            'GET',
        ),
    );
    expect(
        within(
            screen.getByRole('complementary', { name: 'Media albums' }),
        ).getByRole('button', { name: 'Brand images' }),
    ).toHaveAttribute('aria-pressed', 'true');
});

it('saves an image description and album together and clears the dirty state', async () => {
    const updated = { ...media, alt: '', album_id: 2, version: 4 };
    request
        .mockResolvedValueOnce(library)
        .mockResolvedValueOnce(updated)
        .mockResolvedValueOnce({ ...library, media: [updated] });
    await ready();
    fireEvent.click(
        screen.getByRole('button', { name: 'Details for Workplace.png' }),
    );
    fireEvent.change(screen.getByLabelText('Image description'), {
        target: { value: '' },
    });
    fireEvent.change(screen.getByLabelText('Album', { exact: true }), {
        target: { value: '2' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Close details' }),
        ).toBeEnabled(),
    );
    expect(screen.getByRole('button', { name: 'Save details' })).toBeDisabled();
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/7',
        'PATCH',
        { name: 'Workplace.png', alt: '', album_id: 2, version: 3 },
    );
});

it('creates an independent unmodified copy without replacing the selected file', async () => {
    const copy = { ...media, id: 11, name: 'Workplace — copy.png', version: 1 };
    request
        .mockResolvedValueOnce(library)
        .mockResolvedValueOnce(copy)
        .mockResolvedValueOnce({ ...library, media: [copy, media], total: 2 });
    const { onSelect } = await ready();
    fireEvent.click(
        screen.getByRole('button', { name: 'Details for Workplace.png' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Create a copy' }));
    await waitFor(() =>
        expect(screen.getByLabelText('File name')).toHaveValue(copy.name),
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/media/reuse',
        'POST',
        { media_id: 7, version: 3, copy: true },
    );
    expect(onSelect).not.toHaveBeenCalled();
});

it('keeps successful batch uploads and reports an individual failure after refreshing the library', async () => {
    request
        .mockResolvedValueOnce(library)
        .mockResolvedValueOnce({ ...media, id: 12 })
        .mockRejectedValueOnce(new Error('Unsupported file.'))
        .mockResolvedValueOnce(library);
    await ready();
    fireEvent.click(
        screen.getByRole('button', { name: 'Upload' }),
    );
    fireEvent.drop(
        screen.getByRole('button', { name: /Drag & drop files here/ }),
        {
            dataTransfer: {
                files: [
                    new File(['image'], 'one.png', { type: 'image/png' }),
                    new File(['bad'], 'two.txt', { type: 'text/plain' }),
                ],
            },
        },
    );
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'two.txt: Unsupported file.',
        ),
    );
    await waitFor(() => expect(request).toHaveBeenCalledTimes(4));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'two.txt: Unsupported file.',
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(
        request.mock.calls.filter(([, method]) => method === 'POST'),
    ).toHaveLength(2);
});
