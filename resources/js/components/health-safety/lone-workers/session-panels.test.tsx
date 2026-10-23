import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { SessionHistory, SessionLocation } from './session-panels';
import type { SafetySession } from './types';

afterEach(cleanup);
const plan = {
    kind: 'planned' as const,
    method: 'manual' as const,
    description: 'Current intended place',
    lat: 0,
    lng: 0,
    recorded_at: '2026-10-08T01:00:00Z',
    recorded_by: { id: 7, name: 'Mere' },
    source_site_id: null,
    source_shift_id: null,
};
it('shows a permitted plan separately when actual tracker location is restricted', () => {
    render(
        <SessionLocation
            detail={
                {
                    planned_location: plan,
                    tracker_state: 'restricted',
                    tracker: null,
                    location: null,
                    location_lat: 45,
                    location_lng: 46,
                } as unknown as SafetySession
            }
        />,
    );
    expect(
        screen.getByRole('region', { name: 'Planned place' }),
    ).toHaveTextContent('0, 0');
    expect(screen.getByText('Location is restricted')).toBeInTheDocument();
    expect(screen.queryByText('45, 46')).not.toBeInTheDocument();
});
it('uses each historical plan and never backfills the current plan into older events', () => {
    render(
        <SessionHistory
            detail={
                {
                    planned_location: plan,
                    history: [
                        {
                            id: 'old',
                            title: 'Legacy change',
                            actor: null,
                            occurred_at: null,
                            legacy: true,
                            snapshot: {},
                        },
                        {
                            id: 'saved',
                            title: 'Earlier plan',
                            actor: null,
                            occurred_at: null,
                            snapshot: {
                                planned_location: {
                                    ...plan,
                                    description: 'Earlier intended place',
                                    lat: -36,
                                    lng: 174,
                                },
                            },
                        },
                        {
                            id: 'cleared',
                            title: 'Plan cleared',
                            actor: null,
                            occurred_at: null,
                            snapshot: { planned_location: null },
                        },
                    ],
                } as unknown as SafetySession
            }
        />,
    );
    const rows = screen.getAllByRole('listitem');
    expect(
        within(rows[0]).queryByText('Planned location at this change'),
    ).not.toBeInTheDocument();
    expect(rows[1]).toHaveTextContent('Earlier intended place');
    expect(rows[1]).toHaveTextContent('-36, 174');
    expect(rows[2]).toHaveTextContent('No planned location recorded');
    expect(
        screen.queryByText('Current intended place'),
    ).not.toBeInTheDocument();
});
