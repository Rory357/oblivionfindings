import { Button } from '@/components/ui/button';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { ReceiveWizard } from './_receive';
import type { ItemDetail } from './_types';

vi.mock('@/components/emar/controlled/product-ui', async (importOriginal) => ({
    ...(await importOriginal<
        typeof import('@/components/emar/controlled/product-ui')
    >()),
    WitnessField: ({
        onChange,
    }: {
        onChange: (value: { id: string; pin: string }) => void;
    }) => (
        <Button onClick={() => onChange({ id: '5', pin: '123456' })}>
            Use synthetic witness
        </Button>
    ),
}));
afterEach(() => vi.unstubAllGlobals());

const item: ItemDetail = {
    client_id: 1,
    site_id: 1,
    id: 1,
    name: 'Synthetic medicine',
    client_name: 'Synthetic person',
    site_name: 'Synthetic house',
    controlled: true,
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
    current_user_id: 4,
    witnesses: [
        {
            id: 5,
            name: 'Synthetic witness',
            eligible: true,
            reason: null,
            pin_status: 'set',
        },
    ],
};

it('lets the same witness retry an unconfirmed receipt without changing its stock instruction', async () => {
    const fetch = vi
        .fn()
        .mockRejectedValueOnce(new TypeError('connection lost'))
        .mockResolvedValue({
            ok: true,
            json: async () => ({ success: true, lot_ids: [7] }),
        });
    vi.stubGlobal('fetch', fetch);
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
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Continue without a photo',
        }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Use synthetic witness' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Receive stock' }));
    const pin = await screen.findByLabelText(/Same witness’s 6-digit PIN/);
    expect(pin).toBeEnabled();
    fireEvent.change(pin, { target: { value: '654321' } });
    fireEvent.click(screen.getByRole('button', { name: 'Receive stock' }));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
    const first = JSON.parse(fetch.mock.calls[0][1].body);
    const retry = JSON.parse(fetch.mock.calls[1][1].body);
    expect(retry).toEqual({ ...first, witness_credential: '654321' });
    expect(await screen.findByText('Stock received')).toBeVisible();
});
