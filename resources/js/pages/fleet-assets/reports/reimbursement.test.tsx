import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MileageReimbursement from './reimbursement';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { visit: vi.fn() },
}));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Reimbursement result states', () => {
    it('shows a retryable error for denied or failed responses rather than an empty report', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
        render(<MileageReimbursement />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Generate Report' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be generated',
        );
        expect(
            screen.queryByText('No trip data found for the selected period.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Export CSV for Payroll' }),
        ).not.toBeInTheDocument();
    });

    it('discards a response when the rate changes during generation', async () => {
        let finish!: (response: unknown) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            ),
        );
        render(<MileageReimbursement />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Generate Report' }),
        );
        fireEvent.change(screen.getByRole('spinbutton'), {
            target: { value: '1.10' },
        });
        await act(async () =>
            finish({
                ok: true,
                json: async () => ({
                    staff: [
                        {
                            name: 'Synthetic staff',
                            trips: 1,
                            distance_km: 10,
                            amount: 9.5,
                        },
                    ],
                }),
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Generate Report' }),
            ).toBeEnabled(),
        );
        expect(screen.queryByText('Synthetic staff')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Export CSV for Payroll' }),
        ).not.toBeInTheDocument();
    });
});
