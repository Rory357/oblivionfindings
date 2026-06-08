import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import TomorrowPanel from './tomorrow-panel';

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
    router: {
        patch: vi.fn(),
    },
}));

vi.mock('@/hooks/use-my-day-labels', () => ({
    useMyDayLabels: () => (key: string) => key,
}));

describe('TomorrowPanel', () => {
    it('renders the full incoming handover read card for the next shift', () => {
        render(
            <TomorrowPanel
                briefing={{
                    id: 42,
                    starts_at: '2026-06-09T07:30:00+12:00',
                    ends_at: '2026-06-09T15:30:00+12:00',
                    location: 'Rimu House',
                    client: {
                        id: 7,
                        name: 'Mere Wilson',
                        photo_url: null,
                    },
                    minutes_until_start: 90,
                    bullets: ['Morning routine changed.'],
                    what_to_know: null,
                    incoming_handover: {
                        id: 99,
                        handover_notes: 'Mere slept poorly and needs a calm start.',
                        client_mood: 'mixed',
                        medications_due: [
                            { label: 'Morning meds still due', severity: 'high' },
                        ],
                        incidents_to_note: [
                            { label: 'Near fall overnight', severity: 'high' },
                        ],
                        follow_up_items: [
                            { label: 'Call GP after breakfast', priority: 'medium' },
                        ],
                        submitted_at: '2026-06-09T07:00:00+12:00',
                        outgoing_staff_name: 'Alex Taylor',
                        outgoing_shift_ends_at: '2026-06-09T07:00:00+12:00',
                        client_name: 'Mere Wilson',
                    },
                }}
            />,
        );

        expect(screen.getByText('Read handover from last shift')).toBeVisible();
        expect(screen.getByText('Morning meds still due')).toBeVisible();
        expect(screen.getByText('Near fall overnight')).toBeVisible();
        expect(screen.getByText('Call GP after breakfast')).toBeVisible();
        expect(
            screen.getByText('Mere slept poorly and needs a calm start.'),
        ).toBeVisible();
    });
});
