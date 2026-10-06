import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { MyDayMedDue } from '../lib/types';
import { MedicinesCard } from './medicines-card';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn() },
}));
const dose = (extra: Partial<MyDayMedDue> = {}): MyDayMedDue => ({
    id: '1:2026-04-30T10:00:00+12:00',
    medication_id: 1,
    client_id: 2,
    client_name: 'Aroha Ngata',
    medication_name: 'Medicine',
    dose: '1 tablet',
    is_controlled: false,
    can_record: true,
    can_give: true,
    scheduled_for: '2026-04-30T10:00:00+12:00',
    status: 'due',
    state: 'not_due',
    emar_url: null,
    ...extra,
});

describe('My Day medicines card', () => {
    it('does not count a due-soon dose as due now and preserves the source menu', () => {
        render(<MedicinesCard rows={[dose()]} today="2026-04-30" />);
        expect(screen.getByText('0 shown due now')).toBeInTheDocument();
        fireEvent.contextMenu(screen.getByText(/Medicines due 10:00/), {
            clientX: 50,
            clientY: 50,
        });
        expect(
            within(screen.getByRole('menu')).getByRole('menuitem', {
                name: 'Open Meds today',
            }),
        ).toBeInTheDocument();
    });
    it('keeps waiting and concealed late work visible when the shown staff dose is recorded', () => {
        render(
            <MedicinesCard
                rows={[
                    dose({ status: 'given', state: 'given' }),
                    dose({
                        id: 'waiting',
                        status: 'pending_check',
                        state: 'pending_check',
                    }),
                ]}
                today="2026-04-30"
                hidden={1}
                hiddenLate={1}
            />,
        );
        expect(screen.getByText('1 late')).toBeInTheDocument();
        expect(
            screen.getByText('1 waiting for an order check'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Every shown staff dose has an outcome.'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/controlled-medicine dose is not shown/),
        ).toBeInTheDocument();
    });
    it('reports an unavailable feed without a false zero or all-clear', () => {
        render(<MedicinesCard rows={[]} today="2026-04-30" unavailable />);
        expect(
            screen.getByText(/Medicines couldn’t be loaded/),
        ).toBeInTheDocument();
        expect(screen.queryByText('0 late')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Open Meds today/ }),
        ).toHaveAttribute('href', '/meds/today?date=2026-04-30');
    });
});
