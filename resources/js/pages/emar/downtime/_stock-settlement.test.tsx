import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { StockSettlementDialog } from './_stock-settlement';
import type { PaperEntry } from './types';

afterEach(() => vi.restoreAllMocks());
const entry: PaperEntry = {
    id: 8,
    snapshot: {
        person: 'Synthetic person',
        medicine: 'Synthetic medicine',
        dosage: '1 tablet',
        route: 'Oral',
        controlled: false,
        second_person_required: false,
        observation_keys: [],
        dose_unit: 'tablet',
    },
    outcome: 'given',
    given_at: '2026-10-04T09:00:00+13:00',
    entered_at: '2026-10-05T09:00:00+13:00',
    given_by: 'Giver',
    entered_by: 'Recorder',
    witness: null,
    dose_on_paper: null,
    notes: null,
    can_confirm_giver: false,
    can_confirm_witness: false,
    stock_evidence: {
        stock_id: 3,
        unit: 'tablet',
        quantity_removed: '1',
        quantity_wasted: '0',
        waste_reason: '',
        lines: [{ lot_id: 4, quantity: '1', quantity_wasted: '0' }],
    },
    reconciliation: {
        state: 'ready_to_reconcile',
        conflicts: [],
        unavailable: null,
        can_reconcile: false,
        preview_token: 'proof',
        stock_evidence: {
            stock_id: 3,
            unit: 'tablet',
            can_record_settlement: true,
            lots: [
                {
                    id: 4,
                    batch_number: 'TEST',
                    expiry_date: null,
                    state: 'open',
                    quantity_remaining: 10,
                    revision: 2,
                },
            ],
            reviewed_counts: [],
        },
    },
};
it('requires an independently entered closing balance and sends the flat review contract with retained retry identity', () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => {});
    render(
        <StockSettlementDialog
            entry={entry}
            downtimeId={2}
            onClose={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: /^Continue$/ }));
    fireEvent.pointerDown(
        screen.getByRole('combobox', {
            name: 'How this paper dose affects stock',
        }),
        { button: 0, ctrlKey: false, pointerType: 'mouse' },
    );
    fireEvent.click(
        screen.getByRole('option', {
            name: 'The paper removal still needs to be deducted',
        }),
    );
    const closing = screen.getByRole('spinbutton', {
        name: /Closing balance after this dose/,
    });
    expect(closing).toHaveValue(null);
    expect(screen.getByRole('button', { name: /^Continue$/ })).toBeDisabled();
    fireEvent.change(closing, { target: { value: '9' } });
    fireEvent.click(screen.getByRole('button', { name: /^Continue$/ }));
    expect(
        screen.getByRole('button', { name: 'Record stock review' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I checked the signed paper/ }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Record stock review' }),
    );
    expect(post).toHaveBeenCalledWith(
        '/emar/downtime/2/paper/8/stock-evidence',
        expect.objectContaining({
            settlement: 'deduct_now',
            closing_count_id: null,
            lines: [{ lot_id: 4, revision: 2, closing_quantity: '9' }],
            accountable_confirmation: true,
            request_uuid: expect.any(String),
        }),
        expect.any(Object),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Record stock review' }),
    );
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
});
