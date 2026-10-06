import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { LeaveActionDialog } from './leave-action-dialog';
import type { LeaveItem } from './leave-excursions';
const { put } = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { put } }));
vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (v: string) => void;
    }) => (
        <label>
            {label}
            <input value={value} onChange={(e) => onChange(e.target.value)} />
        </label>
    ),
}));
const item = {
    id: 21,
    version: 1,
    medication_away_enabled: true,
    destination: 'Family home',
    allowed_actions: ['approve', 'depart', 'return', 'withdraw'],
} as LeaveItem;
const props = { clientId: 10, item, onClose: vi.fn() };
describe('actual client leave action', () => {
    beforeEach(() => put.mockReset());
    it('sends an actual early return only after review and confirmation and retains a rejected draft', () => {
        render(<LeaveActionDialog {...props} action="return" />);
        fireEvent.change(screen.getByLabelText('Actually returned'), {
            target: { value: '2026-01-03T10:15' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Record return' }));
        expect(put).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Record return',
            }),
        );
        expect(put).toHaveBeenCalledWith(
            '/operations/clients/10/leave/21',
            expect.objectContaining({
                action: 'return',
                version: 1,
                occurred_at: '2026-01-03T10:15:00+13:00',
            }),
            expect.any(Object),
        );
        const callbacks = put.mock.calls[0][2];
        act(() => {
            callbacks.onError({
                version: 'The leave changed. Refresh before trying again.',
            });
            callbacks.onFinish();
        });
        expect(
            screen.getByText('The leave changed. Refresh before trying again.'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText('Actually returned')).toHaveValue(
            '2026-01-03T10:15',
        );
    });
    it('requires a withdrawal reason and preserves planned-only approval', () => {
        render(<LeaveActionDialog {...props} action="withdraw" />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Explain why the leave is being withdrawn.'),
        ).toBeInTheDocument();
        expect(put).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Reason for withdrawal'), {
            target: { value: 'Family visit cancelled' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Withdraw leave' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Withdraw leave',
            }),
        );
        expect(put.mock.calls[0][1]).toEqual({
            action: 'withdraw',
            version: 1,
            reason: 'Family visit cancelled',
        });
    });
    it('cannot proceed when the server did not offer the action', () => {
        render(
            <LeaveActionDialog
                {...props}
                item={{ ...item, allowed_actions: [] }}
                action="approve"
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText(
                'This action is no longer available. Close and refresh the person’s record.',
            ),
        ).toBeInTheDocument();
        expect(put).not.toHaveBeenCalled();
    });
    it('does not promise automatic Away when the server reports the connection disabled', () => {
        render(
            <LeaveActionDialog
                {...props}
                item={{ ...item, medication_away_enabled: false }}
                action="depart"
            />,
        );
        expect(
            screen.getAllByText(/doses will not automatically show Away/)
                .length,
        ).toBeGreaterThan(0);
        expect(
            screen.queryByText(/Doses due during this absence show Away/),
        ).not.toBeInTheDocument();
    });

    it('reviews and sends both actual times atomically, retaining a rejected closed-interval draft', () => {
        render(<LeaveActionDialog {...props} action="depart" />);
        fireEvent.change(screen.getByLabelText('Actually left'), {
            target: { value: '2026-01-01T09:00' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This leave has already ended',
            }),
        );
        fireEvent.change(screen.getByLabelText('Actually returned'), {
            target: { value: '2026-01-02T12:00' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Actually left · Pacific/Auckland'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Actually returned · Pacific/Auckland'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record departure and return' }),
        );
        expect(put).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Record departure and return',
            }),
        );
        expect(put.mock.calls[0][1]).toEqual({
            action: 'depart',
            version: 1,
            occurred_at: '2026-01-01T09:00:00+13:00',
            returned_at: '2026-01-02T12:00:00+13:00',
            approval_notes: null,
        });
        const callbacks = put.mock.calls[0][2];
        act(() => {
            callbacks.onError({
                returned_at: 'This interval overlaps another recorded leave.',
            });
            callbacks.onFinish();
        });
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(
            screen.getByRole('checkbox', {
                name: 'This leave has already ended',
            }),
        ).toBeChecked();
        expect(screen.getByLabelText('Actually left')).toHaveValue(
            '2026-01-01T09:00',
        );
        expect(screen.getByLabelText('Actually returned')).toHaveValue(
            '2026-01-02T12:00',
        );
    });
    it.each(['', '2025-12-31T09:00', '2099-01-02T12:00'])(
        'rejects an invalid paired return %s before submission',
        (value) => {
            render(<LeaveActionDialog {...props} action="depart" />);
            fireEvent.change(screen.getByLabelText('Actually left'), {
                target: { value: '2026-01-01T09:00' },
            });
            fireEvent.click(
                screen.getByRole('checkbox', {
                    name: 'This leave has already ended',
                }),
            );
            fireEvent.change(screen.getByLabelText('Actually returned'), {
                target: { value },
            });
            fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
            expect(screen.getByLabelText('Actually left')).toBeInTheDocument();
            expect(screen.getByRole('alert')).toHaveTextContent(
                /actual return/i,
            );
            expect(put).not.toHaveBeenCalled();
        },
    );
    it('omits a retained return when the closed-leave option is switched off', () => {
        render(<LeaveActionDialog {...props} action="depart" />);
        fireEvent.change(screen.getByLabelText('Actually left'), {
            target: { value: '2026-01-01T09:00' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This leave has already ended',
            }),
        );
        fireEvent.change(screen.getByLabelText('Actually returned'), {
            target: { value: '2026-01-02T12:00' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This leave has already ended',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Record departure' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Record departure',
            }),
        );
        expect(put.mock.calls[0][1]).not.toHaveProperty('returned_at');
        expect(put.mock.calls[0][1]).toHaveProperty(
            'occurred_at',
            '2026-01-01T09:00:00+13:00',
        );
    });
});
