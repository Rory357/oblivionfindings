import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ClinicalSection } from './clinical';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));
vi.mock('./use-record-json', () => ({
    useRecordJson: () => ({
        load: 'ready',
        reload: vi.fn(),
        data: {
            inr: { rows: [], hidden: 0 },
            drivers: [],
            observations: { rows: [], hidden: 0 },
            medicines: [],
            witnesses: [],
            can_manage: true,
            can_check: true,
        },
    }),
}));

describe('clinical record form', () => {
    it('visibly identifies both INR dates and retains entries when cancelling a discard', () => {
        render(<ClinicalSection clientId={10} view="inr" />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record INR result',
            }),
        );
        const dialog = screen.getByRole('dialog');
        const tested = within(dialog).getByText('Tested on', { exact: true });
        const next = within(dialog).getByText('Next test date', {
            exact: true,
        });
        expect(tested).toBeVisible();
        expect(tested).toHaveAttribute('for', 'inr-tested');
        expect(next).toBeVisible();
        expect(next).toHaveAttribute('for', 'inr-next');
        expect(
            within(dialog).getByLabelText('Tested on', { exact: false }),
        ).toHaveAttribute('data-compact');
        fireEvent.change(
            within(dialog).getByRole('spinbutton', { name: /INR value/ }),
            { target: { value: '2.4' } },
        );
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(
            within(screen.getByRole('dialog')).getByRole('spinbutton', {
                name: /INR value/,
            }),
        ).toHaveValue(2.4);
    });
});
