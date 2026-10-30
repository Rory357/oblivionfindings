import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { RosterShift } from './types';
import WeekGridOverview from './week-grid-overview';

afterEach(cleanup);

describe('roster week uses the worker NZ calendar', () => {
    it.each([
        ['2026-10-05', '2026-10-04T19:00:00Z'],
        ['2026-08-31', '2026-08-30T20:00:00Z'],
    ])(
        'keeps a %s Monday shift whose stored instant is UTC Sunday',
        (today, startsAt) => {
            const shift: RosterShift = {
                id: 81,
                starts_at: startsAt,
                ends_at: null,
                actual_starts_at: null,
                actual_ends_at: null,
                status: 'scheduled',
                status_state: 'upcoming',
                location: 'Preview house',
                service_type: 'support',
                client: { id: 41, name: 'Preview person', photo_url: null },
                tasks: [],
                task_progress: 0,
                is_today: true,
                day_key: today,
                timesheet: null,
            };
            const onSelect = vi.fn();
            render(
                <WeekGridOverview
                    today={today}
                    todayShifts={[shift]}
                    upcomingShifts={[]}
                    recentShifts={[]}
                    onSelect={onSelect}
                />,
            );
            const block = screen.getByRole('button', {
                name: /8:00 am.*Preview person/,
            });
            fireEvent.click(block);
            expect(onSelect).toHaveBeenCalledWith(shift);
            expect(screen.getAllByRole('button')).toHaveLength(1);
        },
    );
});
