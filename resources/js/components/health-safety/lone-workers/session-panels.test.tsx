import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
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
        screen.getByRole('group', { name: 'Planned place' }),
    ).toHaveTextContent('0, 0');
    expect(screen.getByText('Location hidden — you don’t have access')).toBeInTheDocument();
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
    // The history reads newest first (drawing pg-hsn-82e).
    const rows = screen.getAllByRole('listitem');
    expect(
        within(rows[2]).queryByText('Planned location at this change'),
    ).not.toBeInTheDocument();
    expect(rows[1]).toHaveTextContent('Earlier intended place');
    expect(rows[1]).toHaveTextContent('-36, 174');
    expect(rows[0]).toHaveTextContent('No planned location recorded');
    expect(
        screen.queryByText('Current intended place'),
    ).not.toBeInTheDocument();
});

const gpsSession = (shiftGps: SafetySession['shift_gps']) =>
    ({
        started_at: '2026-10-25T01:00:00Z',
        planned_location: null,
        tracker_state: 'unavailable',
        tracker: null,
        location: null,
        shift_gps: shiftGps,
    }) as unknown as SafetySession;

it('shows the linked Shift’s GPS ping with its time to a telemetry viewer', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-25T02:05:00Z'));
    try {
        render(
            <SessionLocation
                detail={gpsSession({
                    latitude: -36.8485,
                    longitude: 174.7633,
                    captured_at: '2026-10-25T02:00:00Z',
                    is_old: false,
                    fresh_minutes: 15,
                })}
            />,
        );
        expect(screen.getByText('Shift GPS position')).toBeInTheDocument();
        expect(
            screen.getByText(/-36\.8485, 174\.7633 · last seen 5 min ago\./),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Old position/)).not.toBeInTheDocument();
    } finally {
        vi.useRealTimers();
    }
});

it('marks an old Shift GPS ping as old, never as current', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-25T03:00:00Z'));
    try {
        render(
            <SessionLocation
                detail={gpsSession({
                    latitude: -36.8485,
                    longitude: 174.7633,
                    captured_at: '2026-10-25T02:00:00Z',
                    is_old: true,
                    fresh_minutes: 15,
                })}
            />,
        );
        expect(screen.getByText('Old position · Shift GPS')).toBeInTheDocument();
        expect(
            screen.getByText(
                /last seen 1 hour ago, so it may not be where the worker is now/,
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Shift GPS position')).not.toBeInTheDocument();
    } finally {
        vi.useRealTimers();
    }
});

it('shows no Shift position when the server sends none', () => {
    render(<SessionLocation detail={gpsSession(null)} />);
    expect(screen.queryByText(/Shift GPS/)).not.toBeInTheDocument();
});
