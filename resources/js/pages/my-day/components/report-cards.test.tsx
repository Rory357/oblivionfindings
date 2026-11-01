import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    can: {} as Record<string, unknown>,
    url: '/my-day',
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
    usePage: () => ({ url: state.url, props: { auth: { can: state.can } } }),
}));

import { RecordCareActions } from './record-care-actions';
import {
    reportTitle,
    SomethingHappened,
    WhatYouReported,
    YourWorkplaceInjury,
} from './report-cards';

const supportWorker = {
    incidents: { create: true },
    safeguarding: { create: true },
    injuries: { report: true },
    restraints: { report: true },
};

beforeEach(() => {
    state.visit.mockClear();
    state.can = supportWorker;
});

// Board G4-1 (Stephan, 1 Nov: "Keep My Day, add H&S only").
describe('My Day: Something happened?', () => {
    it('opens the one chooser on an incident, and an incident keeps My Day’s own link', () => {
        const onIncident = vi.fn();
        render(<SomethingHappened canSafeguarding onIncident={onIncident} />);
        expect(
            screen.getByRole('heading', { name: 'Something happened?' }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Report something' }),
        );
        const chooser = screen.getByRole('dialog', {
            name: 'Report something',
        });
        expect(
            within(chooser).getByRole('radio', {
                name: /A person was hurt, or nearly hurt/,
            }),
        ).toHaveAttribute('aria-checked', 'true');
        fireEvent.click(
            within(chooser).getByRole('button', { name: 'Report incident' }),
        );
        expect(onIncident).toHaveBeenCalledTimes(1);
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('raises a safeguarding concern straight away, and other choices come back to My Day', () => {
        render(<SomethingHappened canSafeguarding onIncident={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Raise a safeguarding concern',
            }),
        );
        expect(state.visit).toHaveBeenCalledWith('/safeguarding/create');

        fireEvent.click(
            screen.getByRole('button', { name: 'Report something' }),
        );
        fireEvent.click(
            screen.getByRole('radio', {
                name: /movement or freedom was limited/,
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Record a restraint' }),
        );
        expect(state.visit).toHaveBeenLastCalledWith(
            '/health-safety/restraints/report?return_to=%2Fmy-day',
        );
    });

    it('shows nothing to someone with nothing to report, and no safeguarding button without that right', () => {
        state.can = {};
        const { container, rerender } = render(
            <SomethingHappened canSafeguarding={false} onIncident={vi.fn()} />,
        );
        expect(container).toBeEmptyDOMElement();
        state.can = { incidents: { create: true } };
        rerender(
            <SomethingHappened canSafeguarding={false} onIncident={vi.fn()} />,
        );
        expect(
            screen.queryByRole('button', {
                name: 'Raise a safeguarding concern',
            }),
        ).toBeNull();
    });

    it('leaves Record care to the care records', () => {
        render(
            <RecordCareActions
                people={[{ id: 1, name: 'Aroha Kingi' } as never]}
                selectedPerson="all"
                canRecordObservation
                onNote={vi.fn()}
                onMeal={vi.fn()}
                onObservation={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('button', { name: /Daily note/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Report incident/ }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Report something/ }),
        ).toBeNull();
    });
});

describe('My Day: What you reported', () => {
    it('lists her own reports in her four words, with Finish on a draft and where the private ones went', () => {
        render(
            <WhatYouReported
                reports={[
                    {
                        kind: 'incident',
                        id: 401,
                        type: 'property_damage',
                        client_name: 'Noah Williams',
                        site_name: null,
                        at: new Date().toISOString(),
                        status: 'draft',
                        finish_url: '/incidents/401?return_to=%2Fmy-day',
                    },
                    {
                        kind: 'injury',
                        id: 14,
                        injury_type: 'laceration',
                        body_part: 'finger',
                        own: true,
                        at: '2026-10-27T01:00:00Z',
                        status: 'followed_up',
                        finish_url: null,
                    },
                    {
                        kind: 'incident',
                        id: 392,
                        type: 'fall',
                        client_name: 'Aroha Kingi',
                        site_name: null,
                        at: '2026-10-26T06:40:00Z',
                        status: 'closed',
                        finish_url: null,
                    },
                ]}
            />,
        );
        const list = screen.getByRole('list', { name: 'What you reported' });
        const rows = within(list).getAllByRole('listitem');
        expect(rows).toHaveLength(3);
        expect(rows[0]).toHaveTextContent('Today');
        expect(rows[0]).toHaveTextContent('Property damage — Noah Williams');
        expect(rows[0]).toHaveTextContent('Draft');
        expect(
            within(rows[0]).getByRole('link', { name: 'Finish' }),
        ).toHaveAttribute('href', '/incidents/401?return_to=%2Fmy-day');
        expect(rows[1]).toHaveTextContent('Laceration — finger (your injury)');
        expect(rows[1]).toHaveTextContent('Workplace injury');
        expect(rows[1]).toHaveTextContent('Being followed up');
        expect(rows[2]).toHaveTextContent('26 Oct');
        expect(rows[2]).toHaveTextContent('Closed');
        expect(
            within(rows[2]).queryByRole('link', { name: 'Finish' }),
        ).toBeNull();
        expect(
            screen.getByText(
                /Safeguarding concerns and restraints you record go straight to the people who follow them up/,
            ),
        ).toBeInTheDocument();
    });

    it('never names someone else for an injury she recorded', () => {
        expect(
            reportTitle({
                kind: 'injury',
                id: 1,
                injury_type: 'strain',
                body_part: null,
                own: false,
                at: null,
                status: 'sent',
                finish_url: null,
            }),
        ).toBe('Muscle strain');
    });
});

describe('My Day: Your workplace injury', () => {
    it('says duties for now, the review date and who is coordinating', () => {
        render(
            <YourWorkplaceInjury
                injury={{
                    id: 14,
                    injury_type: 'laceration',
                    body_part: 'finger',
                    injury_date: '2026-10-27T01:00:00Z',
                    on_modified_duties: true,
                    next_review_date: '2026-11-03',
                    coordinator: 'Hemi Walker',
                }}
            />,
        );
        expect(
            screen.getByText('Laceration — finger · 27 Oct'),
        ).toBeInTheDocument();
        const line = screen.getByText(
            /Hemi Walker is coordinating your recovery\./,
        );
        expect(line).toHaveTextContent(
            'On modified duties for now · next review Tue 3 Nov. Hemi Walker is coordinating your recovery.',
        );
        expect(
            screen.getByRole('link', {
                name: 'See your care and return-to-work plan',
            }),
        ).toHaveAttribute('href', '/hr/my/injuries');
    });
});
