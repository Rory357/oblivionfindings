import { toDateInput } from '@/lib/datetime';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    addDays,
    EndExemption,
    ExemptionWizard,
    type ExemptionPerson,
} from './_exemptions';

const routerMock = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: routerMock }));

const people: ExemptionPerson[] = [
    {
        id: 7,
        name: 'Ben Carter',
        role: 'Support worker',
        house_id: 3,
        house: 'Kōwhai House',
        status: 'No current assessment',
        ok: true,
    },
    {
        id: 8,
        name: 'Aroha Ngata',
        role: 'Support worker',
        house_id: 3,
        house: 'Kōwhai House',
        status: 'Current',
        ok: false,
        why: 'has a current assessment',
    },
];

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const wizard = () =>
    render(
        <ExemptionWizard
            people={people}
            longestDays={30}
            limitReviewed={false}
            approver="Hana Kereama"
            who={7}
            onClose={vi.fn()}
        />,
    );

describe('Grant an exemption', () => {
    it('asks why, in at least 10 characters, before the dates', () => {
        wizard();
        expect(screen.getByText('Kōwhai House')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(
            screen.getByText('Say why, in at least 10 characters.'),
        ).toBeInTheDocument();
    });

    it('refuses an end date beyond the longest exemption, then grants for their own house', () => {
        wizard();
        fireEvent.change(screen.getByLabelText(/Why/), {
            target: { value: 'Renewal booked — assessor on leave' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(
            screen.getByText(/Longest allowed: 30 days — on or before .*\.$/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/default — not yet reviewed/),
        ).toBeInTheDocument();
        // No end date chosen yet.
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(
            screen.getByText('The end date must be after the start date.'),
        ).toBeInTheDocument();
        expect(routerMock.post).not.toHaveBeenCalled();
    });

    it('works out the last allowed day on the NZ calendar', () => {
        expect(addDays('2026-10-02', 30)).toBe('2026-11-01');
        expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
        expect(toDateInput(new Date())).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });
});

describe('End an exemption early', () => {
    const exemption = {
        id: 12,
        person: 'Ben Carter',
        house: 'Kōwhai House',
        until: '2026-10-20',
    };

    it('asks why before ending it, then ends it with the reason', () => {
        render(<EndExemption exemption={exemption} onClose={vi.fn()} />);
        expect(
            screen.getByText('End Ben Carter’s exemption early?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'From now, Ben can’t record doses as given at Kōwhai House until they have a current assessment.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'End exemption' }));
        expect(
            screen.getByText('Say why, in at least 10 characters.'),
        ).toBeInTheDocument();
        expect(routerMock.post).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText(/Why/), {
            target: { value: 'Renewal done on 2 October' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'End exemption' }));
        expect(routerMock.post).toHaveBeenCalledWith(
            '/emar/competency/exemptions/12/end',
            { reason: 'Renewal done on 2 October' },
            expect.anything(),
        );
    });
});
