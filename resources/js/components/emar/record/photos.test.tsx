import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { PackPhotoCell } from './photo-bits';
import { PhotosSection, type RecordPackPhoto } from './photos';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const photo = (overrides: Partial<RecordPackPhoto>): RecordPackPhoto => ({
    id: 1,
    kind: 'staff',
    url: '/emar/stock/packs/photos/1',
    download_url: '/emar/stock/packs/photos/1/download',
    taken_at: '2026-09-24T02:10:00Z',
    taken_by: 'Priya Shah',
    pack: 'Batch MF2311 · Expires 05/2027',
    pharmacy: 'Kōwhai Pharmacy',
    pack_changed: false,
    medication_id: 10,
    medication_name: 'Metformin',
    medication_strength: '500 mg',
    controlled: false,
    state: 'current',
    original_name: 'pack.jpg',
    mime: 'image/jpeg',
    bytes: 1200,
    description: null,
    ...overrides,
});

function serve(body: unknown) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: true, json: async () => body }),
    );
}

describe('Medicines › Photos (C11, approved P02)', () => {
    it('lists staff photos newest first with the approved status words and actions', async () => {
        serve({
            photos: [
                photo({ id: 3, state: 'changed', pack_changed: true }),
                photo({
                    id: 2,
                    medication_id: 11,
                    medication_name: 'Levetiracetam',
                    medication_strength: '250 mg',
                }),
                photo({ id: 1, state: 'replaced' }),
            ],
            concealed: 1,
        });
        const onMedicine = vi.fn();
        render(
            <PhotosSection
                clientId={5}
                medicationId={null}
                onMedicine={onMedicine}
                onShowAll={vi.fn()}
            />,
        );
        expect(await screen.findByText('Pack photos')).toBeVisible();
        expect(
            screen.getByText('Check the label — the picture is a guide only'),
        ).toBeVisible();
        expect(screen.getByText(/3 photos · newest first/)).toBeVisible();
        expect(
            screen.getByText('Pack or brand changed — check the label'),
        ).toBeVisible();
        expect(screen.getByText('Current pack')).toBeVisible();
        expect(screen.getByText('Replaced')).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/emar/clients/5/record/photos',
            expect.anything(),
        );
        const rows = screen.getAllByRole('row');
        fireEvent.contextMenu(within(rows[1]).getByText('Metformin 500 mg'));
        fireEvent.click(await screen.findByText('View medicine details'));
        expect(onMedicine).toHaveBeenCalledWith(10);
    });

    it('filters to one medicine and offers the way back', async () => {
        serve({ photos: [photo({ id: 2 })], concealed: 0 });
        const onShowAll = vi.fn();
        render(
            <PhotosSection
                clientId={5}
                medicationId={10}
                onMedicine={vi.fn()}
                onShowAll={onShowAll}
            />,
        );
        expect(
            await screen.findByText('Pack photos · Metformin 500 mg'),
        ).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/emar/clients/5/record/photos?medication_id=10',
            expect.anything(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Show every medicine' }),
        );
        expect(onShowAll).toHaveBeenCalled();
    });

    it('shows an honest empty state', async () => {
        serve({ photos: [], concealed: 0 });
        render(
            <PhotosSection
                clientId={5}
                medicationId={null}
                onMedicine={vi.fn()}
                onShowAll={vi.fn()}
            />,
        );
        expect(await screen.findByText('No pack photos yet')).toBeVisible();
    });
});

describe('The approved P02 "Pack photo" column', () => {
    it('reads no photo, a changed pack or the date the photo was taken', () => {
        const { rerender } = render(<PackPhotoCell photo={null} />);
        expect(screen.getByText(/No photo — check the label/)).toBeVisible();
        rerender(
            <PackPhotoCell photo={{ ...photo({}), pack_changed: true }} />,
        );
        expect(screen.getByText(/Pack or brand changed/)).toBeVisible();
        rerender(<PackPhotoCell photo={photo({})} />);
        expect(screen.getByText(/^Photo/)).toBeVisible();
        rerender(
            <PackPhotoCell
                photo={{ ...photo({}), kind: 'reference', taken_at: null }}
            />,
        );
        expect(screen.getByText('Reference image only')).toBeVisible();
    });
});
