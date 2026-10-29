import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    can: {} as Record<string, unknown>,
    visit: vi.fn(),
}));
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
    router: { visit: state.visit },
    usePage: () => ({ props: { auth: { can: state.can } } }),
}));

import MyDayHero from './my-day-hero';

const hero = () =>
    render(
        <MyDayHero
            workerFirstName="Mere"
            site={null}
            singleResident={null}
            activeShiftId={12}
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

// MASTER for Stephan (30 Oct, option A): My Day offers the H&S "Report something" chooser, showing only what the
// person may use, beside the one-tap "Report incident".
describe('My Day: Report something', () => {
    it('is offered when the person can report more than an incident, and opens the chosen form', () => {
        state.can = {
            incidents: { create: true },
            safeguarding: { create: true },
        };
        hero();
        expect(
            screen.getByRole('link', { name: /Report incident/i }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: /Report something/i }),
        );
        fireEvent.click(
            screen.getByRole('radio', {
                name: /worried a person is being harmed/,
            }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Raise a safeguarding concern',
            }),
        );
        expect(state.visit).toHaveBeenCalledWith('/safeguarding/create');
    });

    it('keeps an incident on My Day’s own report link with its shift', () => {
        state.can = {
            incidents: { create: true },
            safeguarding: { create: true },
        };
        state.visit.mockClear();
        hero();
        fireEvent.click(
            screen.getByRole('button', { name: /Report something/i }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Report an incident' }),
        );
        expect(state.visit).toHaveBeenCalledWith(
            '/incidents/create?shift_id=12&return_to=%2Fmy-day',
        );
    });

    it('is not offered when an incident is all the person can report', () => {
        state.can = { incidents: { create: true } };
        hero();
        expect(
            screen.queryByRole('button', { name: /Report something/i }),
        ).toBeNull();
    });
});
