import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import MyDayHero from './my-day-hero';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: {} }),
}));

describe('MyDayHero audit fixes', () => {
    it('omits the shift time meta when there is no active shift', () => {
        render(
            <MyDayHero
                workerFirstName="Sheila"
                site={null}
                singleResident={null}
                activeShiftId={null}
                shiftStartLabel=""
                shiftEndLabel=""
                shiftDurationHours={8}
                clockedLabel="Not clocked in"
                tasksDone={0}
                totalTasks={0}
                medsGiven={0}
                totalMeds={0}
                medsOverdue={0}
                openItemsCount={0}
                overdueMeds={[]}
                openItems={[]}
                clockedIn={false}
                onClockToggle={vi.fn()}
                activeResidentId="all"
                onResidentChange={vi.fn()}
                residentTaskCounts={new Map()}
                liveSinceLabel="Not clocked in"
            />,
        );

        expect(screen.getByText('Today')).toBeVisible();
        expect(screen.queryByText(/–\s*·\s*8h/)).not.toBeInTheDocument();
    });

    it('links workers to their own availability editor when provided', () => {
        render(
            <MyDayHero
                workerFirstName="Sheila"
                site={null}
                singleResident={null}
                activeShiftId={null}
                shiftStartLabel=""
                shiftEndLabel=""
                shiftDurationHours={8}
                clockedLabel="Not clocked in"
                tasksDone={0}
                totalTasks={0}
                medsGiven={0}
                totalMeds={0}
                medsOverdue={0}
                openItemsCount={0}
                overdueMeds={[]}
                openItems={[]}
                clockedIn={false}
                onClockToggle={vi.fn()}
                activeResidentId="all"
                onResidentChange={vi.fn()}
                residentTaskCounts={new Map()}
                liveSinceLabel="Not clocked in"
                availabilityHref="/staff/7/availability"
            />,
        );

        expect(
            screen.getByRole('link', { name: /Set availability/i }),
        ).toHaveAttribute('href', '/staff/7/availability');
    });

    it('uses semantic tones for frontline hero stats', () => {
        render(
            <MyDayHero
                workerFirstName="Sheila"
                site={null}
                singleResident={null}
                activeShiftId={12}
                shiftStartLabel="09:00"
                shiftEndLabel="17:00"
                shiftDurationHours={8}
                clockedLabel="Clocked in"
                tasksDone={2}
                totalTasks={4}
                medsGiven={1}
                totalMeds={3}
                medsOverdue={1}
                openItemsCount={2}
                overdueMeds={[]}
                openItems={[]}
                clockedIn
                onClockToggle={vi.fn()}
                activeResidentId="all"
                onResidentChange={vi.fn()}
                residentTaskCounts={new Map()}
                liveSinceLabel="Since 09:00"
            />,
        );

        expect(screen.getByText('Clocked in')).toHaveClass(
            'text-status-success',
        );
        expect(screen.getByText('1/3')).toHaveClass('text-status-critical');
        expect(
            screen
                .getAllByText('2')
                .some((element) =>
                    element.classList.contains('text-status-warning'),
                ),
        ).toBe(true);
    });
});
