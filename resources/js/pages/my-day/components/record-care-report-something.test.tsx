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

import { RecordCareActions } from './record-care-actions';

const onIncident = vi.fn();
const hero = () =>
    render(
        <RecordCareActions
            people={[]}
            selectedPerson="all"
            canRecordObservation={false}
            onNote={vi.fn()}
            onMeal={vi.fn()}
            onObservation={vi.fn()}
            onIncident={onIncident}
        />,
    );

// MASTER for Stephan (30 Oct, option A): My Day's Record care card offers the H&S "Report something" chooser,
// showing only what the person may use, beside the one-tap "Report incident".
describe('My Day: Report something', () => {
    it('is offered when the person can report more than an incident, and opens the chosen form', () => {
        state.can = {
            incidents: { create: true },
            safeguarding: { create: true },
        };
        hero();
        expect(
            screen.getByRole('button', { name: /Report incident/i }),
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
        onIncident.mockClear();
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
        expect(onIncident).toHaveBeenCalledTimes(1);
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('is not offered when an incident is all the person can report', () => {
        state.can = { incidents: { create: true } };
        hero();
        expect(
            screen.queryByRole('button', { name: /Report something/i }),
        ).toBeNull();
    });
});
