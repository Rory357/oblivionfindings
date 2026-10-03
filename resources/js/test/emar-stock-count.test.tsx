import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CountWizard } from '@/pages/emar/stock/_dialogs';
import type { ItemDetail } from '@/pages/emar/stock/_types';

vi.mock('@/pages/emar/stock/_requests', () => ({
    useStockCommand: () => ({ saving: false, errors: {}, run: vi.fn() }),
}));

afterEach(cleanup);

const item: ItemDetail = {
    id: 1, name: 'Synthetic medicine', client_name: 'Synthetic person', site_name: 'Synthetic house',
    controlled: false, active: true, stock_id: 1, on_hand: 15, unit: 'tablets', reorder_level: null,
    last_counted_at: null, lots_started: true, pack_count: 2, next_batch: 'TEST-A', next_expiry: null,
    expired_packs: 0, state: 'ok', days_supply: null, outward: [],
    packs: ['TEST-A', 'TEST-B'].map((batch, index) => ({
        id: index + 1, batch_number: batch, batch_not_printed: false, expiry_date: null,
        expiry_not_printed: true, quantity_received: index === 0 ? '10.00' : '5.00',
        quantity_remaining: index === 0 ? '10.00' : '5.00', state: 'open', source: 'pharmacy',
        source_reference: null, received_at: '2026-10-03T08:00:00Z', short_expiry_reason: null,
        notes: null, revision: 1, photos: [],
    })),
};

it('keeps expected quantities hidden until every physical count is entered, including rail navigation', () => {
    render(<CountWizard item={item} onClose={vi.fn()} onSaved={vi.fn()} />);
    const compare = screen.getByRole('button', { name: /Check differences/ });
    const review = screen.getByRole('button', { name: /Review and save/ });
    expect(compare).toBeDisabled();
    expect(review).toBeDisabled();
    fireEvent.click(compare);
    fireEvent.keyDown(review, { key: 'Enter', code: 'Enter' });
    expect(screen.queryByText('Recorded at start')).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/TEST-A/), { target: { value: '9' } });
    expect(compare).toBeDisabled();
    fireEvent.click(review);
    expect(screen.queryByText('Recorded at start')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/TEST-B/), { target: { value: '5' } });
    expect(compare).toBeEnabled();
    expect(review).toBeDisabled();
    fireEvent.click(compare);
    expect(screen.getAllByText('Recorded at start')).toHaveLength(2);
    fireEvent.change(screen.getByLabelText('Explain the difference'), { target: { value: 'Synthetic count discrepancy' } });
    expect(review).toBeEnabled();
    fireEvent.click(review);
    fireEvent.click(screen.getByRole('button', { name: /Count the packs/ }));
    expect(screen.getByLabelText(/TEST-A/)).toHaveValue(9);
    expect(screen.getByLabelText(/TEST-B/)).toHaveValue(5);
    expect(screen.queryByText('Recorded at start')).not.toBeInTheDocument();
});
