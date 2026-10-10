import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { dayLabel, formatWindow, timeLabel } from './build-queue';
import { ConflictQueueList } from './conflict-queue-list';
import { ShiftSummaryCard } from './shift-summary-card';
import type { QueueShift } from './types';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const shift: QueueShift = {
    id: 42,
    client: 'Ari Kauri',
    staff: 'Mere Ana',
    location: 'Matai House',
    serviceContext: 'Supported living',
    shiftType: 'awake_night',
    status: 'scheduled',
    startsAt: '2026-10-20T08:00:00Z',
    endsAt: '2026-10-20T18:00:00Z',
    seriesId: null,
    workerTimezone: 'Pacific/Auckland',
    urls: {
        shift: '/operations/shifts/42?return_to=%2Foperations%2Frostering%2Fconflicts',
        client: null,
        roster: null,
    },
    can: { view_shift: true, view_client: false, view_roster: false },
};

describe('Conflict Queue source context', () => {
    it('shows both calendar days for an overnight duty in the configured worker zone', () => {
        render(<ShiftSummaryCard shift={shift} />);
        expect(screen.getByText(/Tue 20 Oct.*Wed 21 Oct/)).toBeVisible();
        expect(screen.getByText(/9:00 pm.*7:00 am/)).toBeVisible();
    });

    it('uses the organisation day even when the instant has a different UTC date', () => {
        expect(dayLabel('2026-10-19T11:00:00Z', 'Pacific/Auckland')).toBe(
            'Tue 20 Oct',
        );
        expect(dayLabel('2026-10-21T00:00:00Z', 'America/Los_Angeles')).toBe(
            'Tue 20 Oct',
        );
        expect(timeLabel('2026-10-21T00:00:00Z', 'America/Los_Angeles')).toBe(
            '5:00 pm',
        );
    });

    it('keeps invalid or incomplete windows understandable without guessing times', () => {
        expect(formatWindow(null, shift.endsAt)).toBe('Time not set');
        expect(formatWindow('invalid', shift.endsAt)).toBe('Time unavailable');
        expect(formatWindow(shift.startsAt, shift.endsAt, 'invalid-zone')).toBe(
            'Time unavailable',
        );
    });

    it('uses the permitted canonical shift link and preserves its return context', () => {
        render(<ShiftSummaryCard shift={shift} />);
        expect(
            screen.getByRole('link', { name: 'View shift' }),
        ).toHaveAttribute('href', shift.urls!.shift);
    });

    it('does not invent a shift link when the source denies details', () => {
        render(
            <ShiftSummaryCard
                shift={{ ...shift, can: { ...shift.can!, view_shift: false } }}
            />,
        );
        expect(screen.queryByRole('link', { name: 'View shift' })).toBeNull();
        expect(
            screen.getByText('Shift details unavailable for this view.'),
        ).toBeVisible();
    });

    it('does not infer publication readiness from an empty queue', () => {
        render(
            <ConflictQueueList
                filter="all"
                visible={[]}
                selectedId={null}
                onSelect={vi.fn()}
                allResolved
            />,
        );
        expect(screen.getByText('No findings returned')).toBeVisible();
        expect(
            screen.getByText(/Publication checks run separately/),
        ).toBeVisible();
        expect(
            screen.queryByText(/ready to publish|every conflict.*resolved/i),
        ).toBeNull();
    });
});
