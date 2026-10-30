import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    emptyEventHubContent,
    EventHubFields,
    type EventHubContentInput,
} from './event-hub-fields';
import {
    EventImageLibrary,
    type EventLibraryPhoto,
} from './event-image-library';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('@/pages/company-hub/_api', () => ({ hubRequest: request }));
const image = {
    id: 4,
    name: 'Garden.jpg',
    mime: 'image/jpeg',
    size: 120,
    url: '/company-hub/media/4',
    hub_page_id: 2,
    page_title: 'Community',
    alt: 'People in a garden',
    album_id: 3,
    version: 7,
    crop: null,
    caption: 'A shared moment.',
};
const listing = {
    actor_id: 11,
    media: [image],
    albums: [{ id: 3, name: 'Afternoons' }],
    next_page: 2,
};
beforeEach(() => vi.clearAllMocks());
it('reuses a document as an attachment without offering it as a cover or gallery photo', async () => {
    const document = {
        ...image,
        id: 7,
        name: 'Event guide.pdf',
        mime: 'application/pdf',
        url: '/company-hub/media/7',
        alt: '',
    };
    request.mockResolvedValue({
        ...listing,
        media: [image, document],
        next_page: null,
    });
    const change = vi.fn();
    function Editor() {
        const [selected, setSelected] = useState<EventLibraryPhoto[]>([]);
        return (
            <EventHubFields
                value={emptyEventHubContent()}
                onChange={change}
                files={[]}
                attachments={[]}
                staff={[]}
                stories={[]}
                libraryActorId={11}
                libraryPhotos={selected}
                onLibraryPhotosChange={setSelected}
            />
        );
    }
    render(<Editor />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Choose from company library' }),
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Select Event guide.pdf' }),
    );
    expect(request).toHaveBeenCalledWith(
        expect.stringContaining('type=all'),
        'GET',
    );
    expect(
        screen.getByRole('button', {
            name: 'Remove selected library file Event guide.pdf',
        }),
    ).toBeVisible();
    expect(
        screen.queryByRole('radio', { name: 'Use Event guide.pdf as cover' }),
    ).not.toBeInTheDocument();
    expect(change).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Remove selected library file Event guide.pdf',
        }),
    );
    expect(
        screen.getByRole('button', { name: 'Select Event guide.pdf' }),
    ).toBeEnabled();
});
it('refreshes a selected source version while preserving authored details, cover and ordered gallery', async () => {
    request.mockResolvedValue(listing);
    function Editor() {
        const [selected, setSelected] = useState<EventLibraryPhoto[]>([
            { ...image, version: 6, albumName: 'Afternoons' },
        ]);
        const [value, setValue] = useState<EventHubContentInput>(() => ({
            ...emptyEventHubContent(),
            cover_ref: 'library:4',
            gallery_refs: ['file:8', 'library:4'],
            photo_details: [
                {
                    ref: 'library:4',
                    title: 'My title',
                    alt: 'My alt',
                    caption: 'My caption',
                    album: 'My album',
                },
            ],
        }));
        return (
            <>
                <EventHubFields
                    value={value}
                    onChange={setValue}
                    files={[]}
                    attachments={[]}
                    staff={[]}
                    stories={[]}
                    libraryActorId={11}
                    libraryPhotos={selected}
                    onLibraryPhotosChange={setSelected}
                    retainedDraftFiles={9}
                />
                <output aria-label="Editor state">
                    {JSON.stringify({ selected, value })}
                </output>
            </>
        );
    }
    render(<Editor />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Choose from company library' }),
    );
    const refresh = await screen.findByRole('button', {
        name: 'Refresh selected Garden.jpg',
    });
    expect(refresh).toBeEnabled();
    fireEvent.click(refresh);
    expect(
        JSON.parse(
            screen.getByRole('status', { name: 'Editor state' }).textContent!,
        ),
    ).toMatchObject({
        selected: [{ id: 4, version: 7 }],
        value: {
            cover_ref: 'library:4',
            gallery_refs: ['file:8', 'library:4'],
            photo_details: [
                {
                    ref: 'library:4',
                    title: 'My title',
                    alt: 'My alt',
                    caption: 'My caption',
                    album: 'My album',
                },
            ],
        },
    });
    expect(
        screen.getByRole('button', { name: 'Select Garden.jpg' }),
    ).toBeDisabled();
});
it('selects the current source version without copying or saving, including source metadata', async () => {
    request.mockResolvedValue(listing);
    const choose = vi.fn();
    render(<EventImageLibrary actorId={11} selected={[]} onSelect={choose} />);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Select Garden.jpg' }),
    );
    expect(choose).toHaveBeenCalledWith({ ...image, albumName: 'Afternoons' });
    expect(request).toHaveBeenCalledTimes(1);
    expect(request).toHaveBeenCalledWith(
        expect.stringContaining('/company-hub/editor/media?'),
        'GET',
    );
});
it('rejects a different-account listing and allows a fresh retry', async () => {
    request
        .mockResolvedValueOnce({ ...listing, actor_id: 12 })
        .mockResolvedValueOnce(listing);
    render(<EventImageLibrary actorId={11} selected={[]} onSelect={vi.fn()} />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Refresh');
    expect(
        screen.queryByRole('button', { name: 'Select Garden.jpg' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Refresh company library' }),
    );
    expect(
        await screen.findByRole('button', { name: 'Select Garden.jpg' }),
    ).toBeEnabled();
});
it('uses the canonical cursor for another page and resets it for a new search', async () => {
    request
        .mockResolvedValueOnce(listing)
        .mockResolvedValueOnce({
            ...listing,
            media: [{ ...image, id: 7, name: 'Second.jpg' }],
            next_page: null,
        })
        .mockResolvedValueOnce({ ...listing, media: [], next_page: null });
    render(<EventImageLibrary actorId={11} selected={[]} onSelect={vi.fn()} />);
    await screen.findByRole('button', { name: 'Select Garden.jpg' });
    fireEvent.click(screen.getByRole('button', { name: 'More files' }));
    await screen.findByRole('button', { name: 'Select Second.jpg' });
    expect(request).toHaveBeenLastCalledWith(
        expect.stringContaining('cursor_page=2'),
        'GET',
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search event file library' }),
        { target: { value: 'Herbs' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Search files' }));
    await screen.findByText(/No files match/);
    expect(request).toHaveBeenLastCalledWith(
        expect.stringMatching(/q=Herbs&cursor_page=1$/),
        'GET',
    );
});
it('disables duplicate and over-limit selection and leaves removed images unavailable', async () => {
    request.mockResolvedValue({
        ...listing,
        media: [
            image,
            { ...image, id: 5, name: 'Removed.jpg', removed_at: '2026-10-10' },
            {
                ...image,
                id: 6,
                name: 'Too large.pdf',
                mime: 'application/pdf',
                size: 10485761,
            },
        ],
    });
    const view = render(
        <EventImageLibrary
            actorId={11}
            selected={[{ ...image, albumName: 'Afternoons' }]}
            onSelect={vi.fn()}
        />,
    );
    expect(
        await screen.findByRole('button', { name: 'Select Garden.jpg' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Select Removed.jpg' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Select Too large.pdf' }),
    ).toBeDisabled();
    expect(
        screen.getByText('Exceeds the 10 MB event file limit'),
    ).toBeVisible();
    view.rerender(
        <EventImageLibrary
            actorId={11}
            selected={[]}
            onSelect={vi.fn()}
            atLimit
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Select Garden.jpg' }),
    ).toBeDisabled();
});
it('does not accept a late search result over a newer request', async () => {
    let resolve!: (value: unknown) => void;
    request
        .mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        )
        .mockResolvedValueOnce({ ...listing, media: [], next_page: null });
    render(<EventImageLibrary actorId={11} selected={[]} onSelect={vi.fn()} />);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search event file library' }),
        { target: { value: 'Another image' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Search files' }));
    await screen.findByText(/No files match/);
    await act(async () => resolve(listing));
    expect(
        screen.queryByRole('button', { name: 'Select Garden.jpg' }),
    ).not.toBeInTheDocument();
});
