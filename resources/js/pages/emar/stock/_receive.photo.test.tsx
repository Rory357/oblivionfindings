import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ReceiveWizard } from './_receive';
import type { ItemDetail, PhotoPolicy } from './_types';

afterEach(() => vi.unstubAllGlobals());

const base: ItemDetail = {
    client_id: 1,
    site_id: 1,
    id: 1,
    name: 'Metformin',
    client_name: 'Synthetic person',
    site_name: 'Synthetic house',
    controlled: false,
    active: true,
    stock_id: 2,
    on_hand: 10,
    unit: 'tablets',
    reorder_level: null,
    last_counted_at: null,
    lots_started: true,
    pack_count: 1,
    next_batch: null,
    next_expiry: null,
    expired_packs: 0,
    state: 'ok',
    days_supply: null,
    packs: [],
    outward: [],
};
const policy = (overrides: Partial<PhotoPolicy> = {}): PhotoPolicy => ({
    can_photograph: true,
    mode: 'needed',
    prompt: true,
    required: false,
    has_photo: false,
    ...overrides,
});

function toPhotoStep(item: ItemDetail) {
    render(<ReceiveWizard item={item} onClose={vi.fn()} onSaved={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Pharmacy' }));
    fireEvent.change(screen.getByLabelText('Source or delivery reference'), {
        target: { value: 'Synthetic delivery' },
    });
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Counted quantity (tablets)'), {
        target: { value: '4' },
    });
    fireEvent.change(screen.getByLabelText('Batch printed on the pack'), {
        target: { value: 'TEST' },
    });
    fireEvent.click(screen.getByLabelText('Expiry not printed on the pack'));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

describe('Receive a delivery › Pack photo (C11, approved P06 Q6)', () => {
    it('prompts for the first photo of a medicine and saves it with the delivery', async () => {
        const fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ success: true, lot_ids: [7], photo_id: 3 }),
        });
        vi.stubGlobal('fetch', fetch);
        toPhotoStep({ ...base, photo_policy: policy() });
        expect(
            screen.getByText('No pack photo of Metformin yet — take one'),
        ).toBeVisible();
        expect(screen.getByText('Pack photo (optional)')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /take a photo/i }),
        ).not.toBeInTheDocument();
        const file = new File(['x'], 'pack.jpg', { type: 'image/jpeg' });
        fireEvent.change(
            document.querySelector('input[type="file"]') as HTMLInputElement,
            { target: { files: [file] } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Receive stock' }));
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        const body = fetch.mock.calls[0][1].body as FormData;
        expect(body).toBeInstanceOf(FormData);
        expect(body.get('photo')).toBe(file);
        expect(body.get('photo_pack')).toBe('0');
        expect(body.get('pack_changed')).toBe('0');
        expect(body.get('packs[0][quantity]')).toBe('4');
        expect(body.get('label_checked')).toBe('1');
        expect(body.get('action')).toBe('receive');
        expect(
            await screen.findByText(
                'The pack photo is saved with this delivery.',
                {
                    exact: false,
                },
            ),
        ).toBeVisible();
    });

    it('only asks again when the pack looks different from last time', () => {
        toPhotoStep({
            ...base,
            photo_policy: policy({ prompt: false, has_photo: true }),
            latest_photo: {
                kind: 'staff',
                url: '/emar/stock/packs/photos/3',
                taken_at: '2026-09-24T02:10:00Z',
                taken_by: 'Priya Shah',
                pack: 'Batch MF2311 · Expires 05/2027',
                pharmacy: 'Kōwhai Pharmacy',
            },
        });
        expect(
            screen.getByText(
                'There’s already a photo of this pack. Take a new one only if it looks different.',
            ),
        ).toBeVisible();
        expect(screen.getByText(/Last photo ·/)).toBeVisible();
        fireEvent.click(
            screen.getByLabelText(
                /The pack or the tablets look different from last time/,
            ),
        );
        expect(
            screen.getByText('The pack looks different — take a photo'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Continue without a photo' }),
        ).toBeEnabled();
    });

    it('needs the photo before a controlled delivery when the organisation requires it', () => {
        toPhotoStep({
            ...base,
            controlled: true,
            photo_policy: policy({ required: true }),
        });
        expect(
            screen.getByText(
                'A photo of Metformin is needed for this controlled delivery',
            ),
        ).toBeVisible();
        expect(
            screen.getByText('Pack photo', { selector: 'label' }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    it('has no photo step when this person cannot take photos or prompting is off', () => {
        toPhotoStep({
            ...base,
            photo_policy: policy({ can_photograph: false }),
        });
        expect(
            screen.getByRole('button', { name: 'Receive stock' }),
        ).toBeVisible();
        expect(screen.queryByText(/Pack photo/)).not.toBeInTheDocument();
    });
});
